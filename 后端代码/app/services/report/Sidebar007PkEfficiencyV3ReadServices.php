<?php
declare(strict_types=1);

namespace app\services\report;

use think\facade\Db;
use app\services\query\metric\RegisteredMetricReadServices;

/**
 * 007 PK 数据与门店效能的只读 V3 迁移边界。所有动态门店先经 Host 授权；
 * 旧手录目标、旧六个月底标及旧订单现金口径不得从名称相近的 V3 指标猜值。
 */
final class Sidebar007PkEfficiencyV3ReadServices
{
    private const PK_KEYS = [
        'store_name', 'goal_cents', 'bottom_cents', 'month_cash_cents',
        'sharing_cents', 'actual_cents', 'growth_cents', 'completion_percent',
    ];

    /** 报表列身份与旧版来源标签保持一一对应，供新投影按键选择而非按位置猜测。 */
    public static function pkKeys(): array { return self::PK_KEYS; }

    /**
     * 007 PK 按门店纵行，旧目标从已持久化 yeji_pk 只读接入；这里不写目标。
     * $certifiedByStore 只允许服务端测试注入目标、底标及现金业绩，分成由本类
     * 从历史人员类型快照自行读取；客户端不能提交这些基础指标。
     * payment coverage 仅是 V3 迁移诊断，不能冒充 PK 现金业绩。
     *
     * @param int[] $authorizedStoreIds
     * @param array<int,array{id:int,name:string}> $stores
     * @param array<int,array<string,int>> $certifiedByStore
     */
    public function readPk(
        array $authorizedStoreIds,
        array $stores,
        string $month,
        array $certifiedByStore = [],
        ?callable $coverageReader = null,
        ?callable $partnerFactQuery = null,
        ?callable $atomicReader = null,
        ?callable $originalPaymentReader = null,
        ?callable $originalLegacyReader = null,
        ?callable $goalRowsReader = null,
        ?string $cutoffDate = null,
        ?callable $bottomReader = null,
        ?array $visibleKeys = null
    ): array {
        $selectedKeys = $visibleKeys ?? self::PK_KEYS;
        if (!$selectedKeys || count($selectedKeys) !== count(array_unique($selectedKeys))
            || array_diff($selectedKeys, self::PK_KEYS) || !in_array('store_name', $selectedKeys, true))
            throw new \InvalidArgumentException('PK 展示列无效');
        // 只读四项经营值时跳过已经不展示的目标、六个月底标、覆盖诊断及原子指标扫描。
        $cashOnly = $selectedKeys === ['store_name','month_cash_cents','sharing_cents','actual_cents'];
        [$columns, $ids] = $this->stores($authorizedStoreIds, $stores);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) throw new \InvalidArgumentException('PK 月份无效');
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if (!$first) throw new \InvalidArgumentException('PK 月份无效');
        $start = $first->format('Y-m-d');
        $end = $first->modify('last day of this month')->format('Y-m-d');
        // 本月报表只读查询截止业务日；未来月份没有可确认的发生事实。
        // 调用方应传平台上下文 query_cutoff_date，独立测试默认本地业务日。
        $cutoff=$cutoffDate ?? (new \DateTimeImmutable('now',new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $cutoffObject=\DateTimeImmutable::createFromFormat('!Y-m-d',$cutoff);
        if(!$cutoffObject||$cutoffObject->format('Y-m-d')!==$cutoff||$start>$cutoff)
            throw new \InvalidArgumentException('PK 查询月份超过业务截止日');
        if($end>$cutoff)$end=$cutoff;
        $this->assertCertified($certifiedByStore, $ids, ['goal_cents','bottom_cents','month_cash_cents']);
        // 合作方分成仅取实际分配给 partner 人员的历史类型快照；
        // 分类比例或旧 is_fencheng 标记均不能作为这项 V3 数值来源。
        $partner = (new Sidebar007PartnerAllocationReadServices())->read($ids,
            ['start'=>$start,'end'=>$end],$partnerFactQuery);
        // 真实 V3 销售收款、现金、消耗等原子指标单独带来源返回；
        // 007 月现金仍需原单付款资格证明，不把宽口径指标静默填入。
        $atoms = $cashOnly ? [] : (new Sidebar007V3AtomicReadServices($atomicReader))->read($ids,
            ['start'=>$start,'end'=>$end]);
        $original=(new Sidebar007OriginalCashReadServices(
            $originalPaymentReader,$originalLegacyReader))->read($ids,['start'=>$start,'end'=>$end]);
        $legacyGoal=$cashOnly ? ['by_store'=>[]] : (new Sidebar007PkGoalReadServices($goalRowsReader))->read($ids,$month);
        // 六个月前覆盖不足时底标与增长同时留空；历史调整仅按本店
        // 手录记录读取，避免 007 旧全店求和越过门店授权边界。
        $bottom=$cashOnly ? ['by_store'=>[],'complete'=>true] : ($bottomReader?$bottomReader($ids,$month)
            :(new Sidebar007PkBottomReadServices())->read($ids,$month));
        if(!is_array($bottom)||!is_array($bottom['by_store']??null)
            ||!is_bool($bottom['complete']??null))
            throw new \LogicException('PK 底标来源无效');
        foreach($cashOnly ? [] : $ids as $id)if(!array_key_exists('bottom_cents',$certifiedByStore[$id]??[]))
            $certifiedByStore[$id]['bottom_cents']=$bottom['by_store'][$id]??null;
        foreach($cashOnly ? [] : $ids as $id)if(!array_key_exists('goal_cents',$certifiedByStore[$id]??[]))
            $certifiedByStore[$id]['goal_cents']=$legacyGoal['by_store'][$id];
        foreach($ids as $id) if(!array_key_exists('month_cash_cents',$certifiedByStore[$id]??[])
            &&($original['by_store'][$id]['complete']??false))
            $certifiedByStore[$id]['month_cash_cents']=$original['by_store'][$id]['amount_cents'];
        foreach ($ids as $id) $certifiedByStore[$id]['sharing_cents'] = $partner['by_store'][$id];
        $coverage = $cashOnly ? [] : ($coverageReader ? $coverageReader($ids, $start, $end) : $this->paymentCoverage($ids, $start, $end));
        if (!$cashOnly) $coverage = $this->coverage($coverage, $ids, 'payment_fact_rows');

        $labels = Sidebar007ParityPlanServices::layout('sales_pk')['columns'];
        $labelsByKey = array_combine(self::PK_KEYS, $labels);
        $fields = [];
        foreach ($selectedKeys as $key) $fields[] = [
            'key' => $key, 'label' => $labelsByKey[$key],
            'unit' => $key === 'store_name' ? 'text' : ($key === 'completion_percent' ? 'percent_integer' : 'money_cents'),
            'manual' => $key === 'goal_cents',
        ];
        $rows = []; $gaps = [];
        foreach ($columns as $store) {
            $id = $store['store_id']; $v = $certifiedByStore[$id] ?? [];
            $goal = $v['goal_cents'] ?? null; $bottom = $v['bottom_cents'] ?? null;
            $cash = $v['month_cash_cents'] ?? null; $share = $v['sharing_cents'] ?? null;
            // 产品已确认本张 007 对齐表的专用“本月实际分成”=原单现金业绩
            // 减业务时 partner 人员实配；不混入 outsourced 或退款，亦不
            // 修改其他统一报表“实际业绩=现金－成功现金退款”的定义。
            $actual = $cash !== null && $share !== null ? $cash - $share : null;
            $growth = $actual !== null && $bottom !== null ? $actual - $bottom : null;
            // 007 先 bcdiv(实际分成,目标) 再 bcmul(...,100)，PHP 7.4
            // 默认 bcscale=0；完成率按整倍数截断后乘100，而非普通百分比。
            $rate = $goal === null || $actual === null ? null
                : ($goal === 0 ? 0 : intdiv($actual,$goal)*100);
            $cells = array_combine(self::PK_KEYS, [$store['label'], $goal, $bottom, $cash, $share, $actual, $growth, $rate]);
            $row = ['row_id' => 'sales_pk:' . $month . ':' . $id, 'store_id' => $id, 'cells' => []];
            foreach ($selectedKeys as $key) {
                $i = array_search($key, self::PK_KEYS, true);
                $row['cells'][$key] = $cells[$key];
                if ($i > 0 && $cells[$key] === null) $gaps[] = ['store_id' => $id, 'metric' => $labelsByKey[$key],
                    'reason' => $i === 1 ? '旧手录目标存在重复或金额无效，不能任取一条；新编辑尚待版本化迁移'
                        : ($i === 4 ? '人员类型快照缺失，合作方实际分配业绩不能核实'
                            : ($i === 7 ? '目标或实际分成缺少已认证基础值，完成率不能计算'
                                : '缺少经认证的 007 同口径 V3 基础事实'))];
            }
            $rows[] = $row;
        }
        return ['report' => 'sales_pk', 'orientation' => 'store_rows', 'columns' => $fields,
            'rows' => $rows, 'gaps' => $gaps, 'complete' => !$gaps,
            'field_sources' => [
                'store_name'=>'当前授权门店名称；每行按门店身份固定。',
                'goal_cents'=>'该门店当月保存的目标金额；没有目标记录时按原页显示零。',
                'bottom_cents'=>'此前六个月原单现金减合作方实配，加入历史调整后取月均。',
                'month_cash_cents'=>'本月有效原销售及原充值实收；不含后补款、旧卡录入、已退款和已作废原单。',
                'sharing_cents'=>'成交时属于合作方人员的实际分配业绩，不含外包人员与退款扣减。',
                'actual_cents'=>'本月实际分成等于本月业绩减去业绩分成款。',
                'growth_cents'=>'本月实际分成减去本月底标。',
                'completion_percent'=>'先将本月实际分成除以目标并截为整数，再乘百分之百；目标为零时显示零。',
            ],
            'coverage' => $coverage, 'coverage_source' => 'cashier_v3_payment_fact',
            'coverage_note' => 'V3 收款事实行数仅供排查迁移，不是 PK 本月业绩',
            'partner_allocation' => $partner,
            'v3_atoms' => $atoms,
            'original_cash' => $original,
            'legacy_goal' => $legacyGoal,
            'bottom_source' => $bottom,
            // 现有目标方案只支持已登记的报表和指标；007 手录 PK 目标
            // 不能绑定全局 cash_performance，否则实际分成分母口径会漂移。
            'goal_migration' => [
                'source' => 'yeji_pk 按 store_id 和自然月保存的 goal，当前只读接入',
                'identity' => ['store_id','month'],
                'target' => '需新增 PK 专属报表绑定及“现金减 partner 实配”指标版本，再由只读对账迁入带版本审计的 target_scheme_line；本类不写库。',
                'status' => 'legacy_read_only; versioned_edit_unmapped',
            ],
            'scope' => ['store_ids' => $ids, 'month' => $month,
                'date_range'=>['start'=>$start,'end'=>$end],'query_cutoff_date'=>$cutoff]];
    }

    /**
     * 007 效能按指标纵行。床位、点客员工和服务子单金额/客数分别从
     * 受授权的原始来源认证；仅项目核销聚合不能冒充服务子单口径。
     */
    public function readEfficiency(
        array $authorizedStoreIds,
        array $stores,
        string $startDate,
        string $endDate,
        array $certifiedByStore = [],
        ?callable $bedReader = null,
        ?callable $staffReader = null,
        ?callable $consumptionReader = null,
        ?callable $serviceOrderReader = null
    ): array {
        [$columns, $ids] = $this->stores($authorizedStoreIds, $stores);
        $this->assertCertified($certifiedByStore, $ids,
            ['consumption_amount_cents','service_customer_count','staff_count']);
        $consumption=$consumptionReader
            ? $consumptionReader($ids,['start'=>$startDate,'end'=>$endDate])
            : (new Sidebar007ConsumptionAmountReadServices())->read($ids,
                ['start'=>$startDate,'end'=>$endDate]);
        if(!is_array($consumption)||!is_array($consumption['by_store']??null)
            ||!is_bool($consumption['complete']??null))
            throw new \LogicException('门店效能消耗结果无效');
        $serviceOrders=$serviceOrderReader
            ?$serviceOrderReader($ids,['start'=>$startDate,'end'=>$endDate])
            :(new Sidebar007ServiceOrderReadServices())->read($ids,
                ['start'=>$startDate,'end'=>$endDate]);
        if(!is_array($serviceOrders)||!is_array($serviceOrders['by_store']??null)
            ||!is_bool($serviceOrders['complete']??null))
            throw new \LogicException('门店效能服务子单结果无效');
        $beds = $bedReader ? $bedReader($ids) : $this->storeBeds($ids);
        $seen = []; $base = $certifiedByStore;
        // 项目消耗按核销执行店和单条核销计额；007 效能按服务订单
        // 归属店、每订单一笔。旧快照有跨店及一核销对应两订单实例，
        // 所以本来源仅供对账，不直接填“总消耗”。
        if($serviceOrders['complete'])foreach($ids as $id){
            $pair=$serviceOrders['by_store'][$id]??null;
            if(!is_array($pair)||!is_int($pair['consumption_amount_cents']??null)
                ||!is_int($pair['service_customer_count']??null)
                ||$pair['consumption_amount_cents']<0||$pair['service_customer_count']<0)
                throw new \LogicException('门店效能服务子单金额或客数缺失');
            foreach(['consumption_amount_cents','service_customer_count'] as $key){
                if(array_key_exists($key,$base[$id]??[])&&$base[$id][$key]!==$pair[$key])
                    throw new \LogicException('门店效能认证服务子单来源矛盾');
                $base[$id][$key]=$pair[$key];
            }
        }
        $staffCounts=$staffReader ? $staffReader($ids,['start'=>$startDate,'end'=>$endDate])
            : $this->pointStaffCounts($ids,['start'=>$startDate,'end'=>$endDate]);
        $staffSeen=[];
        foreach($staffCounts as $staff){
            $id=filter_var($staff['store_id']??null,FILTER_VALIDATE_INT);
            $count=filter_var($staff['staff_count']??null,FILTER_VALIDATE_INT);
            if($id===false||!in_array($id,$ids,true)||isset($staffSeen[$id])
                ||$count===false||$count<0)throw new \LogicException('点客员工数越权或无效');
            $staffSeen[$id]=true;
            if(!array_key_exists('staff_count',$base[$id]??[]))$base[$id]['staff_count']=$count;
        }
        foreach ($beds as $bed) {
            $id = filter_var($bed['id'] ?? null, FILTER_VALIDATE_INT);
            $count = filter_var($bed['space_num'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || !in_array($id, $ids, true) || isset($seen[$id]) || $count === false || $count < 0) {
                throw new \LogicException('床位设置结果越权或无效');
            }
            $seen[$id] = true;
            $base[$id]['bed_count'] = $count;
        }
        $matrix = Sidebar007ParityPlanServices::projectMatrix('store_efficiency', $authorizedStoreIds,
            $stores, $base, $startDate, $endDate);
        return $matrix + ['report' => 'store_efficiency', 'orientation' => 'metric_rows',
            'row_sources' => Sidebar007ParityPlanServices::rowSources('store_efficiency'),
            'bed_source' => 'system_store.space_num',
            'staff_source' => 'V3 完成服务的点客人员快照，按门店统计期间内有点客客次的不同手艺人',
            'consumption_source' => $consumption,
            'service_order_source' => $serviceOrders,
            'scope' => ['store_ids' => $ids, 'date_range' => ['start' => $startDate, 'end' => $endDate]]];
    }

    /** 授权门店是唯一的可展示/读取范围，输入顺序决定 007 动态列顺序。 */
    private function stores(array $authorized, array $stores): array
    {
        $allowed = [];
        foreach ($authorized as $id) {
            if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('门店授权范围无效');
            $allowed[$id] = true;
        }
        if (!$allowed) throw new \InvalidArgumentException('门店授权范围为空');
        $columns = []; $ids = [];
        foreach ($stores as $store) {
            $id = $store['id'] ?? null; $name = trim((string)($store['name'] ?? ''));
            if (!is_int($id) || !isset($allowed[$id]) || in_array($id, $ids, true) || $name === '') {
                throw new \InvalidArgumentException('门店列超出授权范围或身份不完整');
            }
            $columns[] = ['key' => 'store_' . $id, 'store_id' => $id, 'label' => $name];
            $ids[] = $id;
        }
        return [$columns, $ids];
    }

    /** 后端认证输入只接受已声明基础指标，防止把旁路字段暗中参与旧口径计算。 */
    private function assertCertified(array $byStore, array $ids, array $keys): void
    {
        foreach ($byStore as $id => $values) {
            if (!in_array((int)$id, $ids, true) || !is_array($values)) throw new \InvalidArgumentException('认证指标越权');
            foreach ($values as $key => $value) {
                if (!in_array($key, $keys, true) || ($value !== null && (!is_int($value) || $value < 0))) {
                    throw new \InvalidArgumentException('认证指标字段或值无效');
                }
            }
        }
    }

    /** V3 收款事实覆盖只作诊断，不直接用于旧 PK 月现金业绩。 */
    private function paymentCoverage(array $ids, string $start, string $end): array
    {
        if (!$ids) return [];
        return Db::name('cashier_v3_payment_fact')->where('tenant_id', '0')
            ->whereIn('store_id', $ids)->whereBetween('business_date', [$start, $end])
            ->where('status', 'effective')->where('fact_direction', 'forward')
            ->fieldRaw('store_id,COUNT(*) AS payment_fact_rows')->group('store_id')->select()->toArray();
    }

    /** 门店空间数是配置维度，必须随权限范围读；缺记录不伪装为 0 张床。 */
    private function storeBeds(array $ids): array
    {
        if (!$ids) return [];
        return Db::name('system_store')->whereIn('id', $ids)->where('is_del', 0)
            ->field('id,space_num')->select()->toArray();
    }

    /**
     * 007 ygCount 是期间内有点客服务业绩的不同员工。先从授权门店的
     * V3 劳动分配找候选人，再让已登记点客指标处理冲销、朋友与同日去重；
     * 人数只统计点客客次大于零的员工，不以当前员工职务或名单代替历史服务。
     */
    private function pointStaffCounts(array $ids,array $range):array
    {
        $registered=new RegisteredMetricReadServices();$out=[];
        foreach($ids as $id){
            $rows=Db::name('cashier_v3_performance_fact')
                ->where('tenant_id','0')->where('store_id',$id)
                ->whereBetween('business_date',[$range['start'],$range['end']])
                ->where('performance_type','labor_performance_allocated')
                ->where('status','effective')->where('employee_id','>',0)
                ->field('employee_id')->group('employee_id')->limit(10001)->select()->toArray();
            if(count($rows)>10000)throw new \RuntimeException('点客员工候选超过安全范围');
            $pairs=array_map(static fn(array $row):array=>
                ['store_id'=>$id,'employee_id'=>(int)$row['employee_id']],$rows);
            $count=0;
            if($pairs!==[])foreach($registered->personnelTotals('staff_point_visit_num','0',[$id],$range,$pairs) as $point)
                if((int)($point['metric_value']??0)>0)++$count;
            $out[]=['store_id'=>$id,'staff_count'=>$count];
        }
        return $out;
    }

    private function coverage(array $rows, array $ids, string $countKey): array
    {
        $seen = []; $out = [];
        foreach ($rows as $row) {
            $id = filter_var($row['store_id'] ?? null, FILTER_VALIDATE_INT);
            $count = filter_var($row[$countKey] ?? null, FILTER_VALIDATE_INT);
            if ($id === false || !in_array($id, $ids, true) || isset($seen[$id]) || $count === false || $count < 0) {
                throw new \LogicException('V3 事实覆盖结果越权或无效');
            }
            $seen[$id] = true; $out[$id] = ['store_id' => $id, $countKey => $count];
        }
        foreach ($ids as $id) $out[$id] ??= ['store_id' => $id, $countKey => 0];
        return array_values($out);
    }
}
