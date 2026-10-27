<?php
declare(strict_types=1);
namespace app\services\growth;

use think\facade\Db;
use mohe\exceptions\AdminException;
use app\services\organization\EmployeeDataScopeServices;
use app\services\system\SystemRoleServices;

/** 菜单入口与数据范围共用解析；通用操作不注册按钮权限。 */
final class GrowthAccessServices
{
    /** 平台必须已通过登录中间件；菜单授予操作入口，不扩大组织和员工范围。 */
    public function platform(array $admin, string $menu): array
    {
        $id=(int)($admin['id']??0); if ($id<=0) throw new AdminException('请先登录平台');
        $super=(int)($admin['level']??1)===0 && (int)($admin['admin_type']??0)!==3;
        if (!$super) {
            $roles=$admin['roles']??[]; $roles=is_string($roles)?array_filter(explode(',',$roles)):(array)$roles;
            $allowed=false;
            foreach (app()->make(SystemRoleServices::class)->getRolesByAuth($roles,1) as $row)
                if ((string)($row['unique_auth']??'')===$menu) $allowed=true;
            if (!$allowed) throw new AdminException('当前岗位未授权此成长菜单');
        }
        $employee=(int)($admin['employee_id']??0);
        if (!$super && $employee<=0) throw new AdminException('请关联员工身份及数据范围');
        return $this->principal($employee,'admin:'.$id,$super,true);
    }

    /** 当前员工范围复用既有权威解析，同时保留组织直属员工，不把组织缩成门店列表。 */
    public function principal(int $employee, string $actor, bool $super=false, bool $manager=false): array
    {
        $scope=app()->make(EmployeeDataScopeServices::class);
        $stores=$super?null:$scope->resolveEffectiveStoreIds($employee,0);
        $organizations=[];
        if (!$super && $employee>0) {
            $row=Db::name('employee_data_scope')->where('employee_id',$employee)->where('source_type','hq')->where('status',1)->where('is_del',0)->order('id','desc')->find();
            if (($row['scope_mode']??'')==='org') {
                $roots=GrowthCommandServices::decode((string)$row['org_ids']);
                $all=Db::name('organization')->where('is_del',0)->field('id,pid')->select()->toArray();
                $organizations=array_map('intval',$roots);
                do { $before=count($organizations); foreach($all as $org) if(in_array((int)$org['pid'],$organizations,true)) $organizations[]=(int)$org['id']; $organizations=array_values(array_unique($organizations)); } while(count($organizations)>$before);
            }
        }
        return ['employee_id'=>$employee,'actor_key'=>$actor,'super'=>$super,'manager'=>$manager,
            'store_ids'=>$stores,'organization_ids'=>$organizations,'organization_id'=>0,'store_id'=>0];
    }

    /** 仅返回当前权限内人员；个人范围永远只包含本人，适用于名单、详情、任务和导出。 */
    public function employees(array $context): array
    {
        if ($context['super']) return array_map('intval',Db::name('employee')->where('is_del',0)->column('id'));
        $ids=[(int)$context['employee_id']];
        if ($context['store_ids']) $ids=array_merge($ids,Db::name('system_store_staff')->whereIn('store_id',$context['store_ids'])->where('is_del',0)->where('status',1)->column('employee_id'));
        if ($context['organization_ids']) {
            // 组织员工关系由原人事主档提供；表结构在准备阶段验证。
            $organizationPeople=Db::name('organization_employee')->whereIn('org_id',$context['organization_ids'])->where('is_del',0)->where('status',1)->column('employee_id');
            // 批量读取任职，避免组织中每名员工各查一次数据库；门店隔离口径保持不变。
            $appointments=Db::name('system_store_staff')->whereIn('employee_id',$organizationPeople)->where('status',1)->where('is_del',0)->field('employee_id,store_id')->select()->toArray();$byEmployee=[];foreach($appointments as $job)$byEmployee[(int)$job['employee_id']][]=(int)$job['store_id'];
            foreach($organizationPeople as $person){$stores=$byEmployee[(int)$person]??[];if(!$stores||array_intersect($stores,(array)$context['store_ids']))$ids[]=(int)$person;}

        }
        return array_values(array_unique(array_filter(array_map('intval',$ids))));
    }

    /** 管理者读取发生时归属，而非随员工调店自动迁入全部历史；员工本人保留个人历史。 */
    public function taskScope($query,array $c,string $alias=''): void
    {
        if($c['super'])return;$prefix=$alias!==''?$alias.'.':'';
        $query->where(function($w)use($c,$prefix){
            $w->where($prefix.'employee_id',$c['employee_id']);
            if($c['store_ids'])$w->whereOr(function($store)use($c,$prefix){$store->whereIn($prefix.'store_id',$c['store_ids']);});
            if($c['organization_ids'])$w->whereOr(function($org)use($c,$prefix){$org->where($prefix.'store_id',0)->whereIn($prefix.'organization_id',$c['organization_ids']);});
        });
    }
    /** 奖励以冻结任务或手工发生归属授权；未知历史归属只允许本人及超级管理员读取。 */
    public function rewardScope($query,array $c,string $alias='r'): void
    {
        if($c['super'])return;$query->where(function($w)use($c,$alias){
            $w->where($alias.'.employee_id',$c['employee_id'])->whereOr(function($task)use($c){$this->taskScope($task,$c,'t');});
            $store="CAST(JSON_UNQUOTE(JSON_EXTRACT({$alias}.evidence_json,'$.scope.store_id')) AS UNSIGNED)";$org="CAST(JSON_UNQUOTE(JSON_EXTRACT({$alias}.evidence_json,'$.scope.organization_id')) AS UNSIGNED)";
            if($c['store_ids'])$w->whereOr(function($q)use($c,$store){$q->whereRaw($store.' IN ('.implode(',',array_map('intval',$c['store_ids'])).')');});
            if($c['organization_ids'])$w->whereOr(function($q)use($c,$store,$org){$q->whereRaw($store.'=0 AND '.$org.' IN ('.implode(',',array_map('intval',$c['organization_ids'])).')');});
        });
    }
    /** 冲正沿原流水的不可变任务证据继承归属，不能因冲正行未重复存归属而漏算负数。 */
    /** 奖励查询沿原任务或手工发生归属授权；调用方只可选择两张固定奖励表。 */
    public function rewardQuery(array $c,string $table,int $employee=0)
    {
        if(!in_array($table,['growth_point_ledger','growth_certificate'],true))throw new \InvalidArgumentException('奖励查询类型无效');
        $q=Db::name($table)->alias('r');$proof='r.evidence_json';if($table==='growth_point_ledger'){$q->leftJoin('growth_point_ledger original','original.id=r.reversal_of');$proof='CASE WHEN r.reversal_of>0 AND original.id IS NOT NULL THEN original.evidence_json ELSE r.evidence_json END';}$q->leftJoin('growth_task t',"t.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(".$proof.",'$.task_id')) AS UNSIGNED)");if($employee)$q->where('r.employee_id',$employee);$this->rewardScope($q,$c);return $q;
    }
    /** 历史任务按发生时归属校验，本人始终保留自己的记录。 */
    public function assertTaskFact(array $c,array $task): void
    {
        if($c['super']||(int)$task['employee_id']===$c['employee_id'])return;
        $store=(int)$task['store_id'];$org=(int)$task['organization_id'];
        if(($store>0&&in_array($store,(array)$c['store_ids'],true))||($store===0&&in_array($org,$c['organization_ids'],true)))return;
        throw new AdminException('该历史任务不在当前组织或门店数据范围');
    }

    /** 仅供提问/联络选择姓名，当前同店或同组织人员不因此获得彼此业务数据访问权。 */
    public function contacts(array $c): array
    {
        if($c['manager'])return $this->employees($c);$employee=$c['employee_id'];$ids=[$employee];$stores=Db::name('system_store_staff')->where('employee_id',$employee)->where('status',1)->where('is_del',0)->column('store_id');if($stores)$ids=array_merge($ids,Db::name('system_store_staff')->whereIn('store_id',$stores)->where('status',1)->where('is_del',0)->column('employee_id'));else{$orgs=Db::name('organization_employee')->where('employee_id',$employee)->where('status',1)->where('is_del',0)->column('org_id');if($orgs)$ids=array_merge($ids,Db::name('organization_employee')->whereIn('org_id',$orgs)->where('status',1)->where('is_del',0)->column('employee_id'));}return array_values(array_unique(array_map('intval',$ids)));
    }
    /** 新的执行/处理指派必须同时在授权范围且仍在职；读取历史不使用本校验。 */
    public function assertActiveEmployee(array $c,int $employee): void
    {
        $this->assertEmployee($c,$employee);if(!Db::name('employee')->where('id',$employee)->where('status',1)->where('is_del',0)->find())throw new AdminException('接收或处理员工已离职、停用或不存在');
    }
    /** 新分配与配置候选只接受当前范围内员工；历史读取另用发生时权限。 */
    public function assertEmployee(array $context,int $employee): void
    {
        if (!in_array($employee,$this->employees($context),true)) throw new AdminException('员工不在当前数据范围');
    }

    /** 默认关闭与缺表均拒绝成长写入；旧模块不调用本服务，不受迁移状态影响。 */
    /** 仅供成长内部按阶段安装检查；不扫描旧业务数据或要求全局bootstrap访问成长表。 */
    public function hasTable(string $table): bool
    {
        if(!preg_match('/^growth_[a-z_]+$/D',$table))throw new \InvalidArgumentException('成长表名无效');
        return !empty(Db::query('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1',[(string)config('database.connections.mysql.prefix').$table]));
    }

    /** 供既有登录只读探测，未安装或部分迁移返回关闭而不阻断旧业务。 */
    public function isEnabled(): bool
    {
        try{return (int)Db::name('growth_setting')->where('id',1)->value('enabled')===1&&app()->make(GrowthSchemaServices::class)->status()['ready'];}catch(\Throwable $e){return false;}
    }

    /** 成长请求同时校验总开关和完整迁移，缺表不能继续写业务。 */
    public function assertEnabled(): void
    {
        try { $enabled=(int)Db::name('growth_setting')->where('id',1)->value('enabled'); }
        catch (\Throwable $e) { throw new AdminException('成长管理尚未安装，请完成本地升级'); }
        if ($enabled!==1) throw new AdminException('成长管理尚未启用');app()->make(GrowthSchemaServices::class)->assertReady();
    }
}
