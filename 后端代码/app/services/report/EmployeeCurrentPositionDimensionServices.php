<?php

namespace app\services\report;

use think\facade\Db;

/**
 * 报表当前岗位维度读取器。
 *
 * 输入只包含已经通过报表权限与事实筛选的业务门店、员工组合；输出仅补充当前岗位名称，
 * 不改变历史人员归属、金额、统计分组或数据权限。跨组织人员依次读取业务门店任职、
 * 员工直属岗位和其他有效任职，避免因没有当前门店 staff 关系而错误显示“-”。
 */
final class EmployeeCurrentPositionDimensionServices
{
    /**
     * @param array<int,array{store_id:int,employee_id:int}> $pairs
     * @return array<string,string> `store_id|employee_id` => 当前岗位名称
     */
    public function resolve(array $pairs): array
    {
        $requested = [];
        $employeeIds = [];
        foreach ($pairs as $pair) {
            $storeId = max(0, (int)($pair['store_id'] ?? 0));
            $employeeId = max(0, (int)($pair['employee_id'] ?? 0));
            if ($employeeId <= 0) continue;
            $requested[$storeId . '|' . $employeeId] = ['store_id' => $storeId, 'employee_id' => $employeeId];
            $employeeIds[$employeeId] = true;
        }
        if ($requested === []) return [];

        $exact = [];
        $direct = [];
        $fallback = [];
        foreach (array_chunk(array_keys($employeeIds), 300) as $employeeChunk) {
            $rows = Db::name('staff_job_position')->alias('j')
                ->join('position p', 'p.id=j.position_id AND p.status=1')
                ->leftJoin('system_store_staff ss', 'ss.id=j.staff_id')
                ->whereIn('j.employee_id', $employeeChunk)
                ->where('j.status', 1)->where('j.is_del', 0)->where('j.end_time', 0)
                ->field('j.id,j.employee_id,j.store_id,j.staff_id,p.name position_name,'
                    . 'ss.store_id staff_store_id,ss.status staff_status,ss.is_del staff_is_del')
                ->order('j.id', 'asc')->select()->toArray();
            foreach ($rows as $row) {
                $employeeId = (int)($row['employee_id'] ?? 0);
                $staffId = (int)($row['staff_id'] ?? 0);
                $label = trim((string)($row['position_name'] ?? ''));
                // 已失效的门店任职不能借仍为 active 的旧岗位记录污染当前报表。
                if ($employeeId <= 0 || $label === '' || ($staffId > 0
                    && ((int)($row['staff_status'] ?? 0) !== 1 || (int)($row['staff_is_del'] ?? 1) !== 0))) {
                    continue;
                }
                $storeId = max(0, (int)($row['store_id'] ?? 0));
                if ($storeId <= 0) $storeId = max(0, (int)($row['staff_store_id'] ?? 0));
                $fallback[$employeeId][$label] = true;
                if ($staffId === 0) $direct[$employeeId][$label] = true;
                if ($storeId > 0) $exact[$storeId . '|' . $employeeId][$label] = true;
            }
        }

        $resolved = [];
        foreach ($requested as $key => $pair) {
            $employeeId = $pair['employee_id'];
            $labels = $exact[$key] ?? $direct[$employeeId] ?? $fallback[$employeeId] ?? [];
            if ($labels !== []) $resolved[$key] = implode('、', array_keys($labels));
        }
        return $resolved;
    }
}
