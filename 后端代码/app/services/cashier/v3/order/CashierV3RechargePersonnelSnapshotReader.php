<?php

namespace app\services\cashier\v3\order;

use think\facade\Db;

/**
 * 读取充值单最近一次成功人员调整的冻结快照，不用现金业绩事实推断零收款单的人员归属。
 * 输入仅为已由调用方权限过滤的充值 ID/门店；输出按充值 ID 返回人员身份与分配比例，不返回业绩金额。
 */
final class CashierV3RechargePersonnelSnapshotReader
{
    public function latest(array $recharges, string $tenantId): array
    {
        $stores = [];
        foreach ($recharges as $recharge) {
            $id = (int)($recharge['id'] ?? 0);
            $storeId = (int)($recharge['store_id'] ?? 0);
            if ($id > 0 && $storeId > 0) $stores[$id] = $storeId;
        }
        if ($stores === []) return [];
        $rows = Db::name(CashierV3OrderLifecycleServices::OPERATION_TABLE)
            ->where('tenant_id', $tenantId)->where('source_type', 'recharge')
            ->where('operation_type', 'personnel_adjustment')->where('status', 'succeeded')
            ->whereIn('source_order_id', array_map('strval', array_keys($stores)))
            ->field('source_order_id,store_id,request_json')->order('id', 'desc')->select()->toArray();
        $latest = [];
        foreach ($rows as $row) {
            $id = (int)($row['source_order_id'] ?? 0);
            if ($id <= 0 || isset($latest[$id]) || (int)($row['store_id'] ?? 0) !== ($stores[$id] ?? 0)) continue;
            $request = json_decode((string)($row['request_json'] ?? ''), true);
            if (!is_array($request) || !is_array($request['salespeople'] ?? null)) continue;
            $people = [];
            foreach ($request['salespeople'] as $person) {
                if (!is_array($person)) continue;
                $staff = (array)($person['_staff'] ?? []);
                $staffId = (int)($person['staffId'] ?? 0);
                $employeeId = (int)($staff['employee_id'] ?? 0);
                $name = trim((string)($staff['name'] ?? '')) ?: trim((string)($staff['staff_name'] ?? ''));
                if ($staffId <= 0 || $employeeId <= 0 || $name === '') continue;
                $people[] = [
                    'staffId' => $staffId, 'employeeId' => $employeeId, 'name' => $name,
                    'allocationWeight' => (int)($person['allocationWeight'] ?? 0),
                    'isPreSale' => !empty($person['isPreSale']),
                    'positionId' => (int)($staff['position_id'] ?? 0),
                    'positionName' => (string)($staff['position_name'] ?? ''),
                    'performanceIndependent' => !empty($staff['performance_independent']),
                ];
            }
            if ($people !== []) $latest[$id] = $people;
        }
        return $latest;
    }
}
