<?php

declare(strict_types=1);
namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\query\metric\MetricDefinitionRegistry;
use app\services\query\metric\RegisteredMetricReadServices;
use app\services\target\TargetSchemeServices;
use think\facade\Db;

/** 人效读取边界：只读取调用方已经裁剪的门店，保留历史人员与事实身份；不得从前端接收SQL或金额。 */
final class HumanEfficiencyFactReader
{
    /** 注册人员指标的逐批事实读取，正常单据/服务过滤与现有报表一致，无静默截断。 */
    public function personnel(array $stores, array $range, string $metric): \Generator
    {
        $this->assertScope($stores, $range);
        if ($metric === 'staff_sales_yeji') {
            foreach ((new StoreSalespersonNetReadServices())->factRows($stores, $range) as $row) {
                $row['metric_value'] = (int)$row['amount_cents'];
                yield $row;
            }
            return;
        }
        $source = MetricDefinitionRegistry::get($metric)['source'];
        if (!in_array($metric, ['staff_sales_yeji', 'staff_labor_yeji', 'staff_project_num'], true)) {
            throw new \InvalidArgumentException('人效人员指标不支持');
        }
        $query = Db::name($source['table'])->alias('p')->where('p.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('p.store_id', $stores)->whereBetween('p.business_date', [$range['start'], $range['end']]);
        foreach ($source['filters'] as $field => $value) $query->where('p.' . $field, $value);
        $normal = new StoreReportNormalDataScopeServices();
        $normal->excludeVoidedSalesOrderFacts($query, 'p.tenant_id', 'p.order_id');
        if ($metric !== 'staff_sales_yeji') $normal->excludeVoidedServicePerformanceFacts($query, 'p');
        $last = 0;
        do {
            $rows = (clone $query)->where('p.id', '>', $last)
                ->fieldRaw('p.*, ' . $source['amount'] . ' AS metric_value')->order('p.id')->limit(1000)->select()->toArray();
            foreach ($rows as $row) { $last = (int)$row['id']; yield $row; }
        } while (count($rows) === 1000);
    }

    /** 分类金额使用统一现金投影，包括卡项组成项目和当前分类，禁止按分类名称猜分摊。 */
    public function cash(array $stores, array $range): array
    {
        $this->assertScope($stores, $range);
        return RegisteredMetricReadServices::withCurrentReportProductCategory()
            ->categoryRows('cash_performance', CashierV3ScopeResolver::TENANT_SCOPE_ID, $stores, $range);
    }

    /** 完成服务是活客的事实来源；作废服务不能因人员分配仍在而重新出现。 */
    public function services(array $stores, array $range): \Generator
    {
        $this->assertScope($stores, $range);
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('sv.store_id', $stores)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']])->where('sv.service_status', 'completed');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
        $last = 0;
        do {
            $rows = (clone $query)->where('sv.id', '>', $last)->field('sv.*')->order('sv.id')->limit(1000)->select()->toArray();
            foreach ($rows as $row) { $last = (int)$row['id']; yield $row; }
        } while (count($rows) === 1000);
    }

    /** 管理看板预约口径：按预约服务日取有效预约，排班与实际服务人员严格分开。只读，不修写历史预约。 */
    public function managementAppointments(array $stores, array $range): array
    {
        $this->assertScope($stores, $range);
        $base = Db::name('cashier_v3_reservation')->alias('r')
            ->where('r.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->whereIn('r.store_id', $stores)
            ->whereBetween('r.business_date', [$range['start'], $range['end']])
            ->where('r.lifecycle_generation', \app\services\cashier\v3\reservation\CashierV3ReservationLifecycleServices::GENERATION)
            ->whereIn('r.status', ['UNSTARTED','IN_SERVICE','COMPLETED'])->where('r.member_id', '>', 0);
        $booked = (clone $base)->join('cashier_v3_reservation_staff_schedule ss', 'ss.tenant_id=r.tenant_id AND ss.reservation_id=r.id')
            ->join('system_store_staff st', 'st.id=ss.staff_id AND st.store_id=r.store_id')
            ->field('r.store_id,r.member_id,r.member_name_snapshot member_name,r.reservation_no document_no,r.business_date,st.employee_id')->select()->toArray();
        // 预约完成状态本身不证明服务有效：必须关联仍有效的服务事实，并读取其实际人员快照。
        $actual = [];
        foreach (['direct','checkout'] as $source) {
            $query = clone $base;
            if ($source === 'checkout') {
                $query->join('cashier_v3_reservation_checkout_service_link l', 'l.tenant_id=r.tenant_id AND l.reservation_id=r.id')
                    ->join('cashier_v3_entitlement_service_fact sv', 'sv.tenant_id=l.tenant_id AND sv.service_fact_id=l.service_fact_id AND sv.store_id=r.store_id AND sv.member_id=r.member_id');
            } else {
                $query->join('cashier_v3_entitlement_service_fact sv', "sv.tenant_id=r.tenant_id AND sv.source_document_type='reservation' AND sv.source_document_id=r.id AND sv.store_id=r.store_id AND sv.member_id=r.member_id");
            }
            $query->where('sv.service_status', 'completed');
            (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'sv');
            foreach ($query->field('r.store_id,r.member_id,r.member_name_snapshot member_name,r.reservation_no document_no,r.business_date,sv.craftsmen_snapshot_json')->select()->toArray() as $row) {
                foreach ((array)json_decode((string)$row['craftsmen_snapshot_json'], true) as $craftsman) {
                    $employee = (int)($craftsman['employeeId'] ?? $craftsman['employee_id'] ?? 0);
                    if ($employee <= 0) continue;
                    $row['employee_id'] = $employee; unset($row['craftsmen_snapshot_json']); $actual[] = $row;
                }
            }
        }
        $sets = [];
        foreach (['booked'=>$booked, 'arrived'=>$actual] as $kind=>$rows) foreach ($rows as $row) {
            if ((int)$row['employee_id'] <= 0) continue;
            $key = $row['store_id'].':'.$row['employee_id'];
            $sets[$key][$kind][(int)$row['member_id']] = $row;
        }
        return $sets;
    }

    /** 任职事实包含已离职人员；人数由期末区间裁剪，不能预先过滤当前status。 */
    public function staff(array $stores): array
    {
        $this->assertStores($stores);
        return [
            'staff' => Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')
                ->whereIn('s.store_id', $stores)
                ->field('s.id staff_id,s.employee_id,s.store_id,s.join_date,s.status,s.is_del,s.cashier_craftsman_enabled,e.name')->select()->toArray(),
            'tenures' => Db::name('staff_tenure_period')->whereIn('store_id', $stores)->where('is_del', 0)->select()->toArray(),
            'jobs' => Db::name('staff_job_position')->alias('j')->join('position p', 'p.id=j.position_id')
                ->whereIn('j.store_id', $stores)->where('j.is_del', 0)
                ->field('j.*,p.name position_name,p.is_store_manager')->select()->toArray(),
            'stores' => Db::name('system_store')->whereIn('id', $stores)->field('id,name')->select()->toArray(),
        ];
    }

    /** 目标只读取已绑定门店目标报表的现金指标，不把实际业绩或分类目标误当全店目标。 */
    public function targets(array $stores, array $range): array
    {
        $this->assertScope($stores, $range);
        $months = [];
        for ($month = new \DateTimeImmutable(substr($range['start'], 0, 7) . '-01'); $month->format('Y-m') <= substr($range['end'], 0, 7); $month = $month->modify('+1 month')) {
            $months[] = $month->format('Y-m');
        }
        $result = (new TargetSchemeServices())->effective(['tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'store_ids' => $stores], 'store_target', $months);
        return array_values(array_filter($result['lines'], static fn(array $r): bool => $r['metric_code'] === 'cash_performance' && $r['scope_type'] === 'store' && (int)$r['scope_id'] === 0));
    }

    /** 当前组织口径：按授权门店的现有绑定逐级找最近经理，不读取历史业务快照或扩大门店权限。 */
    public function currentManagers(array $stores): array
    {
        $this->assertStores($stores);
        $nodes=Db::name('organization')->where('is_del',0)->column('pid','id');
        $dimensions=(new StoreUnifiedReportPhaseThreeFoundationServices())->organizationDimensions('0','city_manager',date('Y-m-d'));
        $byId=[];foreach($dimensions as $d)$byId[(int)$d['organization_id']]=$d;
        $bindings=Db::name('organization_store')->whereIn('store_id',$stores)->column('org_id','store_id');
        $out=[];
        foreach($stores as $store){
            $id=(int)($bindings[$store]??0);$seen=[];$manager=null;
            while($id>0&&!isset($seen[$id])){
                $seen[$id]=true;
                if(isset($byId[$id])){$manager=$byId[$id];break;}
                $id=(int)($nodes[$id]??0);
            }
            $out[$store]=$manager??['organization_id'=>'unknown','organization_name_snapshot'=>'未配置当前城市经理'];
        }
        return $out;
    }

    private function assertScope(array $stores, array $range): void
    {
        $this->assertStores($stores);
        foreach (['start', 'end'] as $key) {
            $day = $range[$key] ?? ''; $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if (!$date || $date->format('Y-m-d') !== $day) throw new \InvalidArgumentException('人效查询日期不正确');
        }
        if ($range['start'] > $range['end']) throw new \InvalidArgumentException('人效查询日期不正确');
    }

    private function assertStores(array $stores): void
    {
        if (!$stores) throw new \InvalidArgumentException('当前账号没有可查看的门店范围');
        foreach ($stores as $id) if (!is_int($id) || $id <= 0) throw new \InvalidArgumentException('人效查询门店范围不正确');
    }
}
