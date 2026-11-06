<?php
declare(strict_types=1);
namespace app\services\mobile\warehouse;
use think\facade\Db;

/** 数据页唯一可见目录；与组织/门店权限正交，不能从客户端传入可见节点或扩大数据范围。 */
final class MerchantDataPermissionServices
{
    public const MARKER = 402000;
    /** 永久权限身份显式登记；新增项分配新ID，不能因插入页面或指标改变既有岗位授权。 */
    private const IDS = [
        'warehouse' => 402001,
        'warehouse.metrics' => 402002,
        'warehouse.metrics.cash_performance' => 402003,
        'warehouse.metrics.refund_performance' => 402004,
        'warehouse.metrics.actual_performance' => 402005,
        'warehouse.metrics.consume_amount' => 402006,
        'warehouse.tabs' => 402007,
        'warehouse.tabs.organization' => 402008,
        'warehouse.tabs.store' => 402009,
        'warehouse.tabs.management_board' => 402010,
        'warehouse.tabs.staff_cash_performance' => 402011,
        'warehouse.tabs.staff_labor_performance' => 402012,
        'warehouse.tabs.staff_designated_customer' => 402013,
        'warehouse.tabs.project_count' => 402014,
        'employee' => 402015,
        'employee.metrics' => 402016,
        'employee.metrics.staff_sales_yeji' => 402017,
        'employee.metrics.staff_labor_yeji' => 402018,
        'employee.metrics.staff_point_visit_num' => 402019,
        'employee.metrics.staff_service_num' => 402020,
        'employee.metrics.staff_project_num' => 402021,
        'employee.metrics.staff_labor_fee' => 402022,
        'employee.metrics.project_labor_commission' => 402023,
        'employee.tabs' => 402024,
        'employee.tabs.board' => 402025,
        'employee.tabs.cash' => 402026,
        'employee.tabs.labor' => 402027,
    ];

    public static function definitions(): array
    {
        $out=[];
        foreach (['warehouse'=>'数据总览','employee'=>'员工业绩'] as $page=>$title) {
            $pageId=self::IDS[$page];$out[]=['id'=>$pageId,'parent_id'=>0,'title'=>$title,'feature_code'=>$page,'grantable'=>true];
            foreach (['metrics'=>'顶部指标','tabs'=>'页签'] as $kind=>$label) {
                $groupId=self::IDS[$page.'.'.$kind];$out[]=['id'=>$groupId,'parent_id'=>$pageId,'title'=>$label,'feature_code'=>$page.'.'.$kind,'grantable'=>false];
                $items=$page==='warehouse'
                    ? ($kind==='metrics'?['cash_performance'=>'现金业绩','refund_performance'=>'退款金额','actual_performance'=>'实际业绩','consume_amount'=>'消耗业绩']:['organization'=>'组织排行','store'=>'门店排行','management_board'=>'看板','staff_cash_performance'=>'员工销售','staff_labor_performance'=>'员工劳动','staff_designated_customer'=>'员工点客','project_count'=>'服务项目'])
                    : ($kind==='metrics'?['staff_sales_yeji'=>'现金业绩','staff_labor_yeji'=>'劳动业绩','staff_point_visit_num'=>'点客数','staff_service_num'=>'服务人次','staff_project_num'=>'项目数','staff_labor_fee'=>'手工费','project_labor_commission'=>'劳动提成（预计）']:['board'=>'看板','cash'=>'现金业绩','labor'=>'劳动业绩']);
                foreach($items as $code=>$name)$out[]=['id'=>self::IDS[$page.'.'.$kind.'.'.$code],'parent_id'=>$groupId,'title'=>$name,'feature_code'=>$page.'.'.$kind.'.'.$code,'grantable'=>true,'new'=>$page==='employee'&&$code==='board'];
            }
        }
        foreach($out as $i=>&$row)$row+=['sort'=>$i*10,'enabled'=>1,'actions'=>[]];unset($row);return $out;
    }
    /** 只读配置不初始化数据库；迁移初始空清单采用源码目录，数据库故障与损坏配置必须明确失败。 */
    public function snapshot(): array
    {
        // 运行环境读取失败必须报错，不能用默认开启掩盖全局停用；纯规则测试无Db类时不访问数据库。
        try {$saved=class_exists(Db::class)?Db::name('merchant_data_permission_catalog')->where('id',1)->find():null;}
        catch(\Throwable $error){throw new \RuntimeException('暂时无法读取商家端权限配置，请稍后重试',0,$error);}
        try {$overrides=$saved?json_decode($saved['rules_json'],true,512,JSON_THROW_ON_ERROR):[];}
        catch(\JsonException $error){throw new \RuntimeException('商家端权限配置损坏，请联系管理员核对',0,$error);}
        if(!is_array($overrides)||($saved&&(int)$saved['revision']>1&&!$overrides))throw new \RuntimeException('商家端权限配置损坏，请联系管理员核对');
        $map=array_column($overrides,null,'id');$rows=self::definitions();
        if($overrides&&(count($map)!==count($rows)||array_diff(array_column($rows,'id'),array_keys($map))))throw new \RuntimeException('商家端权限目录不完整，请先完成升级');
        foreach($map as $row)if(!in_array($row['enabled']??null,[0,1],true)||!is_int($row['sort']??null)||trim((string)($row['title']??''))==='')throw new \RuntimeException('商家端权限配置不正确');
        foreach($rows as &$r)foreach(['title','sort','enabled'] as $field)if(isset($map[$r['id']][$field]))$r[$field]=$map[$r['id']][$field];unset($r);
        return ['rules'=>$rows,'revision'=>(int)($saved['revision']??0)];
    }
    /** 目录身份和层级由源码固定，保存仅允许名称/排序/启停；行锁与revision保护并发编辑，审计同事务。 */
    public function save(array $input,int $operator): array
    {
        return Db::transaction(function()use($input,$operator){
            $before=Db::name('merchant_data_permission_catalog')->where('id',1)->lock(true)->find();
            if(!$before)throw new \RuntimeException('请先执行商家端权限规则升级');
            if((int)($input['revision']??-1)!==(int)$before['revision'])throw new \RuntimeException('规则已被其他管理员修改，请刷新后再保存');
            $given=(array)($input['rules']??[]);$known=self::definitions();$byId=array_column($given,null,'id');
            if(count($given)!==count($known)||count($byId)!==count($known))throw new \InvalidArgumentException('规则清单不完整，请刷新');
            foreach($known as &$r){$v=$byId[$r['id']]??null;$name=trim((string)($v['title']??''));
                if(!$v||$name===''||mb_strlen($name)>30||!in_array($v['enabled'],[0,1],true)||!is_int($v['sort'])||$v['sort']<0||$v['sort']>99999)throw new \InvalidArgumentException('规则名称、排序或状态不正确');
                $r['title']=$name;$r['sort']=$v['sort'];$r['enabled']=$v['enabled'];}unset($r);
            $json=json_encode($known,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            Db::name('merchant_data_permission_catalog')->where('id',1)->update(['rules_json'=>$json,'revision'=>(int)$before['revision']+1,'updated_at'=>time()]);
            Db::name('merchant_data_permission_audit')->insert(['operator_id'=>$operator,'before_json'=>$before['rules_json'],'after_json'=>$json,'created_at'=>time()]);
            return $this->snapshot();
        });
    }
    public function tree(bool $enabledOnly=false): array
    {
        $rows=$this->snapshot()['rules'];$build=function(int $parent)use(&$build,$rows,$enabledOnly){$out=[];foreach($rows as $row)if($row['parent_id']===$parent&&(!$enabledOnly||$row['enabled'])){$row['children']=$build($row['id']);$out[]=$row;}usort($out,static fn($a,$b)=>[$a['sort'],$a['id']]<=>[$b['sort'],$b['id']]);return $out;};return $build(0);
    }
    /** 旧岗位只有“数据”时展开既有节点；显式标记后逐项独立勾选，新个人看板绝不随旧授权自动开放。 */
    public static function legacyRules(array $ids): array
    {
        $hasGranular=(bool)array_intersect($ids,array_column(self::definitions(),'id'));
        if(in_array(401007,$ids,true)&&!in_array(self::MARKER,$ids,true)&&!$hasGranular)foreach(self::definitions() as $row)if(empty($row['new']))$ids[]=$row['id'];
        return array_values(array_unique($ids));
    }
    /** 树的半选父级可能不随表单提交；已选子节点补齐入口祖先，但绝不补选兄弟指标或页签。 */
    public static function ancestors(array $ids): array
    {
        $nodes=array_column(self::definitions(),null,'id');$out=array_fill_keys($ids,true);
        foreach($ids as $id)if(isset($nodes[$id])){
            $out[401007]=true;$out[self::MARKER]=true;$at=$nodes[$id];
            while($at['parent_id']){$out[$at['parent_id']]=true;$at=$nodes[$at['parent_id']];}
        }
        return array_map('intval',array_keys($out));
    }
    public function policy(int $employee,string $page): array
    {
        $text=(string)Db::name('employee_mobile_auth')->where('employee_id',$employee)->where('status',1)->where('is_del',0)->value('rules');
        return $this->resolve(array_map('intval',explode(',',$text)),$page,$this->snapshot()['rules']);
    }
    /** 首次进入优先看板，否则按已授权目录顺序选择；显式点击仍尊重用户选择，全隐藏返回none。 */
    public static function selectedTab(array $allowed,string $requested,string $board): string
    {
        if(in_array($requested,$allowed,true))return $requested;
        return in_array($board,$allowed,true)?$board:($allowed[0]??'none');
    }
    /** 纯规则出口，便于覆盖停用父级、全隐藏和旧岗位兼容；隐藏指标不连带关闭同名页签。 */
    public function resolve(array $ids,string $page,array $rows): array
    {
        $ids=self::ancestors(self::legacyRules($ids));$grants=array_fill_keys($ids,true);$visible=[];$parents=[];
        foreach($rows as $r)$parents[$r['id']]=$r;
        foreach($rows as $row){$ok=isset($grants[401007]);$at=$row;while($ok){if(!$at['enabled']||($at['grantable']&&!isset($grants[$at['id']]))){$ok=false;break;}if(!$at['parent_id'])break;$at=$parents[$at['parent_id']];}
            if($ok)$visible[$row['feature_code']]=$row;}
        $result=['page'=>isset($visible[$page]),'metrics'=>[],'tabs'=>[]];
        foreach($visible as $code=>$row)foreach(['metrics','tabs'] as $kind)if(strpos($code,$page.'.'.$kind.'.')===0)$result[$kind][]= ['code'=>substr($code,strlen($page.'.'.$kind.'.')),'name'=>$row['title'],'sort'=>$row['sort']];
        foreach(['metrics','tabs'] as $kind)usort($result[$kind],static fn($a,$b)=>[$a['sort'],$a['code']]<=>[$b['sort'],$b['code']]);return $result;
    }
}
