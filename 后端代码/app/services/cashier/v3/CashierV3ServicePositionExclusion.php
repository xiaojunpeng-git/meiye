<?php

namespace app\services\cashier\v3;

use app\services\cashier\v3\member\CashierV3OtherCraftsmanQueryServices;
use think\facade\Db;

/** 岗位免计只冻结到新服务的人员分配；查询历史时不读取当前配置。 */
final class CashierV3ServicePositionExclusion
{
    public const CONFIG_KEY = 'cashier_service_excluded_position_ids';

    /** 标准化岗位编号；空数组表示全部按原规则计。 */
    public static function positionIds($value): array
    {
        if (!is_array($value) || array_values($value) !== $value) {
            throw new \InvalidArgumentException('请选择有效岗位。');
        }
        $ids = [];
        foreach ($value as $id) {
            if ((!is_int($id) && (!is_string($id) || !ctype_digit($id))) || (int)$id <= 0) {
                throw new \InvalidArgumentException('请选择有效岗位。');
            }
            $ids[(int)$id] = (int)$id;
        }
        ksort($ids);
        return array_values($ids);
    }

    /** 配置表单和保存校验使用同一岗位目录；停用岗位可从已选项中移除。 */
    public static function options(): array
    {
        return Db::name('position')->order('status desc,id asc')->field('id,name,status')->select()->toArray();
    }

    /**
     * 以实际任职判断岗位，普通人员限当前门店，支援人员可取授权集团内的任职。
     * 最终写入锁定配置和任职，收银候选只使用同一规则的只读投影。
     */
    public static function matches(array $staffIds, int $storeId, bool $lock = false): array
    {
        if (!$staffIds) return [];
        if ($lock) {
            CashierV3TransactionGuard::assertInTransaction('cashierServicePositionExclusion');
            $raw = Db::name('system_config')->where('menu_name', self::CONFIG_KEY)
                ->where('is_store', 0)->lock(true)->value('value');
            $configured = json_decode((string)$raw, true) ?? [];
        } else {
            $configured = sys_config(self::CONFIG_KEY, []) ?: [];
        }
        $positionIds = self::positionIds($configured);
        if (!$positionIds) return [];
        $storeStaffIds = [];
        $directEmployeeIds = [];
        foreach (array_unique(array_map('intval', $staffIds)) as $staffId) {
            if (CashierV3PersonnelIdentity::isOrganizationStaffId($staffId)) {
                $directEmployeeIds[] = CashierV3PersonnelIdentity::employeeIdFromStaffId($staffId);
            } elseif ($staffId > 0) {
                $storeStaffIds[] = $staffId;
            }
        }
        $jobs = static function () use ($positionIds, $lock) {
            $query = Db::name('staff_job_position')->alias('j')
                ->join('position p', 'p.id=j.position_id AND p.status=1')
                ->where('j.status', 1)->where('j.is_del', 0)->where('j.end_time', 0)
                ->whereIn('j.position_id', $positionIds)->order('j.id', 'asc')
                ->field('j.staff_id,j.employee_id,j.position_id,p.name as position_name');
            if ($lock) $query->lock(true);
            return $query;
        };
        $matches = [];
        if ($storeStaffIds) {
            $rows = $jobs()->join('system_store_staff s', 's.id=j.staff_id AND s.status=1 AND s.is_del=0')
                ->whereIn('j.staff_id', $storeStaffIds)->where('s.store_id', $storeId)->select();
            foreach ($rows as $row) $matches[(int)$row['staff_id']] = $matches[(int)$row['staff_id']] ?? $row;
        }
        if ($directEmployeeIds) {
            $rows = $jobs()->where('j.staff_id', 0)->whereIn('j.employee_id', $directEmployeeIds)->select();
            foreach ($rows as $row) {
                $staffId = CashierV3PersonnelIdentity::organizationStaffId((int)$row['employee_id']);
                $matches[$staffId] = $matches[$staffId] ?? $row;
            }
            // 跨店支援可来自门店任职而没有直属岗位，沿用现有支援选择器的集团范围。
            $orgId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
            $stores = (new CashierV3OtherCraftsmanQueryServices())->groupScope($orgId)['stores'];
            if ($stores) {
                $rows = $jobs()->join('system_store_staff s', 's.id=j.staff_id AND s.status=1 AND s.is_del=0')
                    ->whereIn('j.employee_id', $directEmployeeIds)->whereIn('s.store_id', $stores)->select();
                foreach ($rows as $row) {
                    $staffId = CashierV3PersonnelIdentity::organizationStaffId((int)$row['employee_id']);
                    $matches[$staffId] = $matches[$staffId] ?? $row;
                }
            }
        }
        return $matches;
    }

    /** 用完成服务当时的员工身份解释免计；没有标记的旧单继续使用原口径。 */
    public static function frozenEmployeeIds(string $json, int $storeId): array
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) return [];
        $excluded = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['serviceCountsExcluded'] ?? false) !== true) continue;
            $employeeId = (int)($row['employee_id'] ?? $row['employeeId'] ?? 0);
            if ($employeeId > 0 && (int)($row['store_id'] ?? $row['storeId'] ?? $storeId) === $storeId) {
                $excluded[$employeeId] = true;
            }
        }
        return $excluded;
    }

    /** 冻结人员资格，不修改服务数量、客户身份、点客或劳动业绩。 */
    public static function freeze(array &$craftsman, array $position): void
    {
        $craftsman['serviceCountsExcluded'] = true;
        $craftsman['excludedServicePositionId'] = (int)$position['position_id'];
        $craftsman['laborFeeCents'] = 0;
        $craftsman['projectCount'] = '0';
        unset($craftsman['projectCountHalfUnits'], $craftsman['project_count_half_units'], $craftsman['project_count']);
    }
}
