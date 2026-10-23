<?php
namespace app\services\organization;

use think\facade\Db;

/**
 * 门店端唯一权限目录。功能编号/代码/业务父级由源码登记，数据库只维护展示层级、名称、排序、启停。
 * 不允许管理员输入任意路由或接口；岗位授权仍保存原有 store_v3 编号，元数据不会授予新业务权限。
 */
final class StorePermissionCatalogServices
{
    public const TABLE = 'store_v3_permission_catalog';

    /** 源码登记的可执行能力；旧树保留作一次性迁移证据，不再作为运行时授权目录。 */
    public static function definitions(): array
    {
        $tree = JobPositionPolicyServices::STORE_V3_MENU_TREE;
        foreach ($tree as &$node) {
            // 默认沿用已确认导航简称，权限目录的旧全称不应截断窄侧栏。
            if ($node['id'] === 301006) $node['title'] = '订单';
            if ($node['id'] === 301100) $node['title'] = '库存';
            if ($node['id'] === 301008) $node['feature_code'] = 'cashier.v3.care';
            if ($node['id'] === 301007) {
                $node['children'] = [
                    ['id'=>301060,'title'=>'人员管理','feature_code'=>'cashier.v3.management.staff','children'=>$node['children']],
                    ['id'=>301061,'title'=>'房间设置','feature_code'=>'cashier.v3.management.rooms'],
                    ['id'=>301062,'title'=>'经营看板','feature_code'=>'cashier.v3.management.dashboard'],
                ];
            }
        }
        unset($node);
        array_splice($tree, 5, 0, [['id'=>301009,'title'=>'费用','feature_code'=>'cashier.v3.fund']]);
        array_splice($tree, count($tree)-1, 0, [
            ['id'=>301200,'title'=>'数据','feature_code'=>'cashier.v3.data'],
            ['id'=>301201,'title'=>'目标','feature_code'=>'cashier.v3.targets'],
        ]);
        $flat = [];
        $walk = static function ($nodes, $parent = 0) use (&$walk, &$flat): void {
            foreach ($nodes as $sort => $node) {
                $flat[(int)$node['id']] = ['id'=>(int)$node['id'],'title'=>$node['title'],
                    'feature_code'=>$node['feature_code'],'parent_id'=>$parent,'sort'=>$sort*10,'enabled'=>1];
                $walk($node['children'] ?? [], (int)$node['id']);
            }
        };
        $walk($tree);
        return $flat;
    }

    /** 默认大类仅用于显式安装；运行时名称、顺序、启停和归属全部读取数据库。负编号不参与岗位授权。 */
    public static function navigationGroups(): array
    {
        $groups=[];
        foreach (['运营管理','库存管理','财务管理','数据中心','基础设置'] as $index=>$title) {
            $id=-2801-$index;
            $groups[$id]=['id'=>$id,'title'=>$title,'feature_code'=>'','parent_id'=>0,'sort'=>$index*10,
                'enabled'=>1,'grantable'=>false,'account_menu'=>$index===4];
        }
        // 新增大类只登记稳定身份；运行时继续读取持久化名称与排序。
        $groups[-2806]=['id'=>-2806,'title'=>'订单管理','feature_code'=>'','parent_id'=>0,'sort'=>5,
            'enabled'=>1,'grantable'=>false,'account_menu'=>false];
        return $groups;
    }

    /** 菜单子入口只维护展示，不能写入岗位授权；业务能力及路由身份仍由白名单约束。 */
    public static function navigationItems(): array
    {
        $items=[];
        $items[-29001]=['id'=>-29001,'title'=>'合作方品项汇总','navigation_key'=>'report.partner_item_summary','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>0];
        $items[-29002]=['id'=>-29002,'title'=>'合作方品项明细','navigation_key'=>'report.partner_item_detail','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>10];
        $items[-29003]=['id'=>-29003,'title'=>'会员消费明细','navigation_key'=>'report.member_consumption_detail','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>20];
        $items[-29004]=['id'=>-29004,'title'=>'门店品项分析','navigation_key'=>'report.store_item_analysis','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>30];
        $items[-29005]=['id'=>-29005,'title'=>'门店手艺人消耗','navigation_key'=>'report.store_craftsman_consumption','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>40];
        $items[-29006]=['id'=>-29006,'title'=>'门店销售人业绩','navigation_key'=>'report.store_salesperson_performance','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>50];
        $items[-29007]=['id'=>-29007,'title'=>'市场业绩表','navigation_key'=>'report.market_performance','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>60];
        $items[-29008]=['id'=>-29008,'title'=>'市场明细表','navigation_key'=>'report.market_detail','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>70];
        $items[-29009]=['id'=>-29009,'title'=>'会员进店分析表','navigation_key'=>'report.member_visit_analysis','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>80];
        $items[-29010]=['id'=>-29010,'title'=>'会员进店年度汇总表','navigation_key'=>'report.member_visit_annual_summary','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>90];
        $items[-29011]=['id'=>-29011,'title'=>'地推拓客明细表','navigation_key'=>'report.field_acquisition_detail','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>100];
        $items[-29012]=['id'=>-29012,'title'=>'地推拓客汇总表','navigation_key'=>'report.field_acquisition_summary','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>110];
        $items[-29013]=['id'=>-29013,'title'=>'异业收客汇总表','navigation_key'=>'report.cross_industry_customer_summary','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>120];
        $items[-29014]=['id'=>-29014,'title'=>'异业收客明细表','navigation_key'=>'report.cross_industry_customer_detail','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>130];
        $items[-29015]=['id'=>-29015,'title'=>'新客汇总表','navigation_key'=>'report.new_customer_analysis_summary','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>140];
        $items[-29016]=['id'=>-29016,'title'=>'新客明细表','navigation_key'=>'report.new_customer_analysis','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>150];
        $items[-29017]=['id'=>-29017,'title'=>'销售人生美大单统计表','navigation_key'=>'report.salesperson_large_order_statistics','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>160];
        $items[-29018]=['id'=>-29018,'title'=>'院店退款台账','navigation_key'=>'report.store_refund_ledger','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>170];
        $items[-29019]=['id'=>-29019,'title'=>'其他多收款业绩表','navigation_key'=>'report.phase_six_other_multi_payment','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>180];
        $items[-29020]=['id'=>-29020,'title'=>'员工薪资汇总月报表','navigation_key'=>'report.phase_six_salary_summary','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>190];
        $items[-29021]=['id'=>-29021,'title'=>'员工薪资明细月报表','navigation_key'=>'report.phase_six_salary_detail','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>200];
        $items[-29022]=['id'=>-29022,'title'=>'六维数据分析表','navigation_key'=>'report.six_dimension_analysis','owner_feature'=>'cashier.v3.data','parent_id'=>301200,'sort'=>210];
        $items[-30001]=['id'=>-30001,'title'=>'全部订单','navigation_key'=>'order.all','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>0];
        $items[-30002]=['id'=>-30002,'title'=>'消费订单','navigation_key'=>'order.sales','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>10];
        $items[-30003]=['id'=>-30003,'title'=>'充值订单','navigation_key'=>'order.recharge','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>20];
        $items[-30004]=['id'=>-30004,'title'=>'退款记录','navigation_key'=>'order.refund','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>30];
        $items[-30005]=['id'=>-30005,'title'=>'欠款管理','navigation_key'=>'order.debt','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>40];
        $items[-30006]=['id'=>-30006,'title'=>'服务记录','navigation_key'=>'order.service','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>50];
        $items[-30007]=['id'=>-30007,'title'=>'补交记录','navigation_key'=>'order.supplement','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>60];
        $items[-30008]=['id'=>-30008,'title'=>'赠送记录','navigation_key'=>'order.gift','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>70];
        $items[-30009]=['id'=>-30009,'title'=>'卡操作记录','navigation_key'=>'order.card_operation','owner_feature'=>'cashier.v3.order_center','parent_id'=>301006,'sort'=>80];
        $items[-31001]=['id'=>-31001,'title'=>'修改密码','navigation_key'=>'account.password','owner_feature'=>'','parent_id'=>-2805,'sort'=>80];
        $items[-31002]=['id'=>-31002,'title'=>'退出系统','navigation_key'=>'account.logout','owner_feature'=>'','parent_id'=>-2805,'sort'=>90];
        $items[-32001]=['id'=>-32001,'title'=>'会员领用','navigation_key'=>'inventory.presale_claim','owner_feature'=>'cashier.v3.inventory.outbound','parent_id'=>301100,'sort'=>25];
        foreach ($items as &$item) $item+=['feature_code'=>'','enabled'=>1,'grantable'=>false];
        unset($item);
        return $items;
    }

    /** 显式补齐导航子入口，不改岗位及业务事实；再次执行只补缺项，不覆盖已配置层级。 */
    public function initializeNavigationItems(): void
    {
        Db::transaction(function () {
            $before=Db::name(self::TABLE)->where('id',1)->lock(true)->find();
            if (!$before) throw new \RuntimeException('请先初始化门店端权限规则');
            $rows=json_decode($before['rules_json'],true); $byId=array_column($rows,null,'id');
            $missing=array_diff_key(self::navigationItems(),$byId);
            if (!$missing) return;
            $rows=$this->validateRows(array_merge($rows,array_values($missing)));
            $json=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            Db::name(self::TABLE)->where('id',1)->update(['rules_json'=>$json,'revision'=>(int)$before['revision']+1,'updated_at'=>time()]);
            Db::name('store_v3_permission_audit')->insert(['operator_id'=>0,'action'=>'INITIALIZE_NAVIGATION_ITEMS','before_json'=>$before['rules_json'],'after_json'=>$json,'created_at'=>time()]);
        });
    }

    /** 显式、幂等的元数据升级；不改岗位授权，只为旧目录建立可配置的顶部大类。 */
    public function initializeNavigationGroups(): void
    {
        Db::transaction(function () {
            $before=Db::name(self::TABLE)->where('id',1)->lock(true)->find();
            if (!$before) throw new \RuntimeException('请先初始化门店端权限规则');
            $rows=json_decode($before['rules_json'],true);
            foreach ($rows as $row) if ((int)$row['id']<0) return;
            $parents=[301001=>-2801,301002=>-2801,301003=>-2801,301004=>-2801,301005=>-2801,301006=>-2801,301008=>-2801,
                301100=>-2802,301009=>-2803,301062=>-2804,301201=>-2804,301200=>-2804,301007=>-2805];
            foreach ($rows as &$row) {
                if (isset($parents[$row['id']])) $row['parent_id']=$parents[$row['id']];
                if ($row['id']===301062) $row['sort']=0;
                if ($row['id']===301201) $row['sort']=10;
                if ($row['id']===301200) $row['sort']=20;
            }
            unset($row);
            $rows=$this->validateRows(array_merge(array_values(self::navigationGroups()),array_values(self::navigationItems()),$rows));
            $json=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            Db::name(self::TABLE)->where('id',1)->update(['rules_json'=>$json,'revision'=>(int)$before['revision']+1,'updated_at'=>time()]);
            Db::name('store_v3_permission_audit')->insert(['operator_id'=>0,'action'=>'INITIALIZE_NAVIGATION_GROUPS',
                'before_json'=>$before['rules_json'],'after_json'=>$json,'created_at'=>time()]);
        });
    }

    /** 显式升级本轮确认的菜单归属；只改展示配置，保留原岗位、业务事实和完整审计。 */
    public function initializeOrderNavigation(): void
    {
        Db::transaction(function () {
            $before=Db::name(self::TABLE)->where('id',1)->lock(true)->find();
            if (!$before) throw new \RuntimeException('请先初始化门店端权限规则');
            $rows=json_decode($before['rules_json'],true);
            // 一次升级后管理员可继续编辑，不在后续启动或重复部署时覆盖其配置。
            if (Db::name('store_v3_permission_audit')->where('action','INITIALIZE_ORDER_NAVIGATION')->count()) return;
            $hasOrderGroup=false;
            foreach ($rows as &$row) {
                if ($row['id']===-2806) $hasOrderGroup=true;
                if ($row['id']===301006) $row['parent_id']=-2806;
                if ($row['id']===301009) $row['parent_id']=-2801;
            }
            unset($row);
            if (!$hasOrderGroup) $rows[]=self::navigationGroups()[-2806];
            $rows=$this->validateRows($rows);
            $json=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            Db::name(self::TABLE)->where('id',1)->update(['rules_json'=>$json,'revision'=>(int)$before['revision']+1,'updated_at'=>time()]);
            Db::name('store_v3_permission_audit')->insert(['operator_id'=>0,'action'=>'INITIALIZE_ORDER_NAVIGATION',
                'before_json'=>$before['rules_json'],'after_json'=>$json,'created_at'=>time()]);
        });
    }

    /** 一次性迁移把旧默认权限展开成显式编号，原先共用的页面能力按原可用范围继承。 */
    public static function migrateLegacyIds(array $ids): array
    {
        $features=JobPositionPolicyServices::legacyStoreV3FeaturesFromRuleIds($ids);
        if (in_array('cashier.v3.member',$features,true)) $features=array_merge($features,['cashier.v3.care','cashier.v3.fund']);
        if (in_array('cashier.v3.management_center',$features,true)) $features=array_merge($features,['cashier.v3.data','cashier.v3.targets','cashier.v3.management.staff','cashier.v3.management.rooms','cashier.v3.management.dashboard']);
        $map=array_column(self::definitions(),'id','feature_code'); $new=[];
        foreach ($features as $f) if (isset($map[$f])) $new[]=$map[$f];
        $new=array_values(array_unique($new)); sort($new);
        return $new;
    }

    /**
     * 仅升级器调用：同实例锁、同事务迁移，保留原行审计；不改其他渠道。
     * 可在回滚事务中完整验证首次升级，不需要修改配置或复制业务源码。
     */
    public function initializeLegacyCatalog(string $sqlFile): void
    {
        // 单实例初始化互斥，避免两个发布进程重复展开授权；不在普通页面查询中隐式迁移。
        $lock=Db::query("SELECT GET_LOCK('store_permission_r28_init', 10) AS acquired");
        if ((int)$lock[0]['acquired']!==1) throw new \RuntimeException('权限升级正在执行，请稍后重试');
        try {
            Db::transaction(function () {
                if (Db::name(self::TABLE)->where('id',1)->lock(true)->find()) {  return; }
                $defs=self::definitions();
                $rows=Db::name('job_position_channel_rule')->where('channel','store_v3')->order('id')->lock(true)->select()->toArray();
                $plan=[]; $oldByPosition=[]; $rowByPosition=[];
                foreach ($rows as $row) {
                    $ids=array_filter(array_map('intval',explode(',',(string)$row['rules'])));
                    $oldByPosition[$row['position_id']]=$ids;
                    $rowByPosition[$row['position_id']]=$row;
                    $plan[$row['position_id']]=self::migrateLegacyIds($ids);
                }
                // 旧父级默认逻辑在多岗位组合时可能互相抑制。若逐岗位展开会增加原有操作，
                // 必须回滚并提示人工确认该实例的岗位选择，不能静默扩大员工权限。
                $groups=[];
                $jobs=Db::name('staff_job_position')->alias('j')->join('position p','p.id=j.position_id')
                    ->where('j.status',1)->where('j.is_del',0)->where('j.end_time',0)->where('p.status',1)->where('p.use_store',1)
                    ->field('j.staff_id,j.employee_id,j.position_id')->select()->toArray();
                foreach ($jobs as $job) {
                    $pid=(int)$job['position_id'];
                    if (empty($rowByPosition[$pid]['status'])) continue;
                    $key=$job['staff_id'].':'.$job['employee_id'];
                    $groups[$key]['old']=array_merge($groups[$key]['old'] ?? [],$oldByPosition[$pid] ?? []);
                    $groups[$key]['new']=array_merge($groups[$key]['new'] ?? [],$plan[$pid] ?? []);
                }
                $legacyCodes=array_values(JobPositionPolicyServices::storeV3FeatureMap(true));
                foreach ($groups as $key=>$group) {
                    $old=JobPositionPolicyServices::legacyStoreV3FeaturesFromRuleIds($group['old']);
                    $new=array_intersect(self::explicitFeatures($group['new']),$legacyCodes);
                    if (array_diff($new,$old) || array_diff($old,$new)) throw new \RuntimeException('员工岗位组合存在旧默认授权歧义，升级已回滚，请核对任职 '.$key);
                }
                foreach ($rows as $row) {
                    $rules=implode(',',$plan[$row['position_id']]);
                    Db::name('store_v3_permission_audit')->insert(['operator_id'=>0,'action'=>'MIGRATE_POSITION','before_json'=>json_encode($row,JSON_UNESCAPED_UNICODE),'after_json'=>json_encode(['id'=>$row['id'],'rules'=>$rules]),'created_at'=>time()]);
                    Db::name('job_position_channel_rule')->where('id',$row['id'])->update(['rules'=>$rules,'version'=>(int)$row['version']+1,'update_time'=>time()]);
                }
                Db::name(self::TABLE)->insert(['id'=>1,'rules_json'=>json_encode(array_values($defs),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'revision'=>1,'updated_at'=>time()]);
                
            });
            // 升级日志属于本实例，重复执行只读回已完成结果，不重新展开任何新岗位授权。
            if (!Db::name('database_upgrade_log')->where('upgrade_key','20261023-r28-store-permission-catalog')->count()) {
                Db::name('database_upgrade_log')->insert(['upgrade_key'=>'20261023-r28-store-permission-catalog',
                    'title'=>'门店端独立权限规则','task_file'=>'2026-10-23-门店端独立权限规则/03-初始化.php',
                    'sql_checksum'=>hash_file('sha256',$sqlFile),'code_version'=>'',
                    'executed_at'=>date('Y-m-d H:i:s'),'executed_by'=>'codex-local-or-authorized-release',
                    'result_note'=>'metadata initialized; store_v3 grants migrated with row audit; platform/mobile untouched']);
            }
        } finally { Db::query("SELECT RELEASE_LOCK('store_permission_r28_init')"); }
    }

    /** 每次读取权威数据库，不缓存到常驻 Swoole，避免停用后旧进程继续放行。 */
    public function snapshot(): array
    {
        $query = Db::name(self::TABLE)->where('id', 1);
        // 正式命令在事务内重建权限时锁同一配置行，停用与业务落账按提交顺序互斥。
        if (\app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction()) $query->lock(true);
        $row = $query->find();
        if (!$row) throw new \RuntimeException('门店端权限规则尚未初始化，请先执行本轮升级。');
        $rows = json_decode((string)$row['rules_json'], true);
        if (!is_array($rows) || count($rows) !== count(self::definitions()) + count(self::navigationGroups()) + count(self::navigationItems())) throw new \RuntimeException('门店端权限目录不完整，请联系管理员。');
        return ['revision'=>(int)$row['revision'], 'rules'=>array_values($rows)];
    }

    /** 展示树可重排，功能祖先关系始终使用源码，防止挪动节点意外获得其他模块权限。 */
    public function menuTree(): array
    {
        $snapshot = $this->snapshot();
        $build = static function ($parent) use (&$build, $snapshot): array {
            $nodes = array_values(array_filter($snapshot['rules'], static function ($r) use ($parent) { return empty($r['navigation_key']) && (int)$r['parent_id'] === $parent; }));
            usort($nodes, static function ($a,$b) { return [$a['sort'],$a['id']] <=> [$b['sort'],$b['id']]; });
            return array_map(static function ($r) use (&$build) {
                $r['children'] = $build((int)$r['id']);
                // 停用不删除岗位勾选，显示提示；实际授权由服务端裁剪，重新启用可恢复原授权。
                $r['title'] .= (int)$r['enabled'] === 1 ? '' : '（已停用）';
                return $r;
            }, $nodes);
        };
        return $build(0);
    }

    /** 把显式岗位编号转换为能力，并仅补齐源码业务祖先；禁止父权限自动勾回被取消的子功能。 */
    public static function explicitFeatures(array $ids): array
    {
        $defs = self::definitions(); $set = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            while (isset($defs[$id])) {
                $set[$defs[$id]['feature_code']] = true;
                $id = $defs[$id]['parent_id'];
            }
        }
        return array_keys($set);
    }

    /** 停用业务祖先会关闭其所有业务子项；展示层级改变不影响这条鉴权关系。 */
    public function filterEnabled(array $features): array
    {
        $rows = array_column($this->snapshot()['rules'], null, 'id');
        $allowed = []; $definitions=self::definitions();
        foreach ($definitions as $id=>$def) {
            $enabled = true;
            while ($id) {
                if (empty($rows[$id]['enabled'])) { $enabled=false; break; }
                $id = $definitions[$id]['parent_id'];
            }
            // 显示大类停用也必须在服务端生效；挪动显示节点不能绕过原业务祖先校验。
            $displayId=$def['id']; $seen=[];
            while ($displayId && $enabled) {
                if (isset($seen[$displayId]) || empty($rows[$displayId]['enabled'])) { $enabled=false; break; }
                $seen[$displayId]=true; $displayId=(int)$rows[$displayId]['parent_id'];
            }
            if ($enabled) $allowed[$def['feature_code']] = true;
        }
        // writeoff 是现有收银内部入口，没有独立导航；保持原能力但仍受收银模块启停约束。
        if (isset($allowed['cashier.v3.cashier'])) $allowed['cashier.v3.writeoff'] = true;
        return array_values(array_filter($features, static function ($f) use ($allowed) { return isset($allowed[$f]); }));
    }

    /** 整份目录用单行锁及 revision 防止两个管理员覆盖；审计与目录更新同事务提交。 */
    public function save(array $input, int $operatorId): array
    {
        return Db::transaction(function () use ($input,$operatorId) {
            $before = Db::name(self::TABLE)->where('id',1)->lock(true)->find();
            if (!$before || (int)($input['revision'] ?? 0) !== (int)$before['revision']) throw new \InvalidArgumentException('权限规则已被其他人修改，请刷新后重试。');
            $rows = $this->validateRows((array)($input['rules'] ?? []));
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            Db::name(self::TABLE)->where('id',1)->update(['rules_json'=>$json,'revision'=>(int)$before['revision']+1,'updated_at'=>time()]);
            Db::name('store_v3_permission_audit')->insert(['operator_id'=>$operatorId,'action'=>'UPDATE_CATALOG','before_json'=>$before['rules_json'],'after_json'=>$json,'created_at'=>time()]);
            return $this->snapshot();
        });
    }

    /** 全量白名单校验：不漏节点、不重复、不伪造功能码、不循环；业务编号永久稳定。 */
    public function validateRows(array $rows): array
    {
        $defs=self::definitions()+self::navigationGroups()+self::navigationItems(); $out=[];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['id']) || !is_int($r['id']) || !isset($r['parent_id']) || !is_int($r['parent_id'])) throw new \InvalidArgumentException('功能编号及上级编号必须为整数');
            $id=(int)($r['id'] ?? 0); $title=trim((string)($r['title'] ?? ''));
            if (!isset($defs[$id]) || isset($out[$id]) || $title==='' || mb_strlen($title)>30) throw new \InvalidArgumentException('功能编号或名称无效，请重新加载规则。');
            if (!isset($r['enabled']) || !in_array($r['enabled'],[0,1],true) || !isset($r['sort']) || !is_int($r['sort']) || $r['sort']<0 || $r['sort']>99999) throw new \InvalidArgumentException('排序必须为0至99999的整数，状态必须为开启或停用。');
            $pid=(int)($r['parent_id'] ?? 0);
            if (!empty($defs[$id]['navigation_key']) && $pid===0) throw new \InvalidArgumentException('菜单入口必须归属于菜单大类或业务功能。');
            if (isset(self::navigationGroups()[$id]) && $pid!==0) throw new \InvalidArgumentException('菜单大类必须保持顶级。');
            if (!empty($defs[$pid]['navigation_key'])) throw new \InvalidArgumentException('具体菜单入口不能再作为上级，请选择业务功能或菜单大类。');
            if ($pid && !isset($defs[$pid])) throw new \InvalidArgumentException('上级规则无效。');
            $out[$id]=array_merge($defs[$id],['title'=>$title,'sort'=>$r['sort'],'enabled'=>$r['enabled'],'parent_id'=>$pid]);
        }
        if (count($out)!==count($defs)) throw new \InvalidArgumentException('请保留完整功能目录，不能删除已登记的业务功能。');
        foreach ($out as $id=>$r) {
            $seen=[];
            while ($id) {
                if (isset($seen[$id])) throw new \InvalidArgumentException('上级规则不能选择自身或子级。');
                $seen[$id]=true; $id=$out[$id]['parent_id'];
            }
        }
        // 每个业务功能必须归属于一个已登记大类，防止保存成功却从顶部菜单消失。
        foreach ($out as $id=>$r) {
            if ($id<0) continue;
            $ancestor=$id;
            while ($out[$ancestor]['parent_id']) $ancestor=$out[$ancestor]['parent_id'];
            if ($ancestor>0) throw new \InvalidArgumentException('业务功能必须归属于菜单大类。');
        }
        return array_values($out);
    }
}
