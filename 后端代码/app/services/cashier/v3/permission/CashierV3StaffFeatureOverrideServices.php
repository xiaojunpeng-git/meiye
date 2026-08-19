<?php
namespace app\services\cashier\v3\permission;

use app\services\organization\JobPositionPolicyServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * 门店员工的操作权限覆盖。
 *
 * 岗位仍是默认来源；本服务只保存员工当前门店任职上的 allow / deny 差异，
 * 未保存行等同 inherit。所有写入与员工审计、auth_version 在同一事务内完成。
 */
class CashierV3StaffFeatureOverrideServices
{
    /** @return string[] */
    public static function operationFeatureCodes(): array
    {
        return [
            'cashier.v3.cashier.recharge', 'cashier.v3.cashier.gift', 'cashier.v3.cashier.checkout',
            'cashier.v3.cashier.card.upgrade', 'cashier.v3.cashier.card.extend',
            'cashier.v3.cashier.card.transfer', 'cashier.v3.cashier.card.disable',
            'cashier.v3.cashier.card.enable', 'cashier.v3.cashier.card.project_replace',
            'cashier.v3.cashier.card.project_upgrade', 'cashier.v3.member.create', 'cashier.v3.member.edit',
            'cashier.v3.order.staff_adjust', 'cashier.v3.order.refund', 'cashier.v3.order.void',
            'cashier.v3.order.reopen', 'cashier.v3.order.receipt_print', 'cashier.v3.order.debt_view',
            'cashier.v3.order.service_detail', 'cashier.v3.order.service_void', 'cashier.v3.staff.create',
            'cashier.v3.staff.edit', 'cashier.v3.staff.permission_edit', 'cashier.v3.staff.export',
            'cashier.v3.inventory.presale_claim.create', 'cashier.v3.inventory.presale_claim.detail',
            'cashier.v3.inventory.presale_claim.void',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function read(int $staffId, int $storeId): array
    {
        $staff = $this->requireStaff($staffId, $storeId);
        $rows = Db::name('staff_store_v3_feature_override')
            ->where('staff_id', $staffId)->where('employee_id', (int)$staff['employee_id'])
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)
            ->select()->toArray();
        $overrides = [];
        foreach ($rows as $row) {
            $overrides[(string)$row['feature_code']] = (string)$row['effect'];
        }
        // auth_version 是员工权限快照的统一版本，不能以某一权限行的版本代替，
        // 否则两个管理员首次修改不同功能时无法互相检测冲突。
        $version = max(1, (int)Db::name('employee')->where('id', (int)$staff['employee_id'])->value('auth_version'));
        $catalog = [];
        foreach ($this->operationCatalog() as $item) {
            $code = $item['code'];
            $catalog[] = $item + ['effect' => $overrides[$code] ?? 'inherit'];
        }
        return [
            'staff_id' => $staffId,
            'employee_id' => (int)$staff['employee_id'],
            'version' => $version,
            'items' => $catalog,
        ];
    }

    /** @return array{version:int,items:array<int,array<string,mixed>>} */
    public function save(int $staffId, int $storeId, array $effects, int $expectedVersion, array $operator): array
    {
        $allowed = array_fill_keys(self::operationFeatureCodes(), true);
        $normalized = [];
        foreach ($effects as $code => $effect) {
            $code = trim((string)$code);
            $effect = trim((string)$effect);
            if (!isset($allowed[$code]) || !in_array($effect, ['inherit', 'allow', 'deny'], true)) {
                throw new AdminException('员工功能权限参数无效，请刷新后重试');
            }
            $normalized[$code] = $effect;
        }
        foreach (self::operationFeatureCodes() as $code) {
            if (!isset($normalized[$code])) $normalized[$code] = 'inherit';
        }

        return Db::transaction(function () use ($staffId, $storeId, $normalized, $expectedVersion, $operator) {
            $staff = $this->requireStaff($staffId, $storeId, true);
            $employeeId = (int)$staff['employee_id'];
            $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
            if (!$employee || (int)($employee['status'] ?? 0) !== 1 || (int)($employee['is_del'] ?? 1) !== 0) {
                throw new AdminException('该员工档案已失效，请刷新后重试');
            }
            $actualVersion = max(1, (int)($employee['auth_version'] ?? 1));
            if ($expectedVersion > 0 && $expectedVersion !== $actualVersion) {
                throw new AdminException('该员工权限已被其他人修改，请刷新后重新保存');
            }
            $existingRows = Db::name('staff_store_v3_feature_override')
                ->where('staff_id', $staffId)->where('employee_id', $employeeId)->where('store_id', $storeId)
                ->where('is_del', 0)->lock(true)->select()->toArray();
            $existing = [];
            foreach ($existingRows as $row) {
                $existing[(string)$row['feature_code']] = $row;
            }
            $now = time();
            $before = [];
            $after = [];
            foreach (self::operationFeatureCodes() as $code) {
                $old = (string)($existing[$code]['effect'] ?? 'inherit');
                $next = $normalized[$code];
                $before[$code] = $old;
                $after[$code] = $next;
                $row = $existing[$code] ?? null;
                if ($next === 'inherit') {
                    if ($row) Db::name('staff_store_v3_feature_override')->where('id', (int)$row['id'])->update([
                        'effect' => 'inherit', 'status' => 1, 'is_del' => 0,
                        'version' => (int)$row['version'] + 1, 'update_time' => $now,
                    ]);
                    continue;
                }
                if ($row) {
                    Db::name('staff_store_v3_feature_override')->where('id', (int)$row['id'])->update([
                        'effect' => $next, 'status' => 1, 'version' => (int)$row['version'] + 1, 'update_time' => $now,
                    ]);
                } else {
                    Db::name('staff_store_v3_feature_override')->insert([
                        'staff_id' => $staffId, 'employee_id' => $employeeId, 'store_id' => $storeId,
                        'feature_code' => $code, 'effect' => $next, 'version' => 1,
                        'status' => 1, 'is_del' => 0, 'add_time' => $now, 'update_time' => $now,
                    ]);
                }
            }
            if ($before !== $after) {
                Db::name('employee')->where('id', $employeeId)->update([
                    'auth_version' => $actualVersion + 1,
                    'update_time' => $now,
                ]);
                Db::name('employee_change_log')->insert([
                    'employee_id' => $employeeId, 'action' => 'store_v3_feature_override_save',
                    'target_type' => 'store_v3_feature_override', 'target_id' => $staffId, 'source' => 'store_v3',
                    'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE),
                    'after_data' => json_encode($after, JSON_UNESCAPED_UNICODE), 'reason' => '门店端员工功能权限编辑',
                    'operator_type' => 'store', 'operator_id' => (int)($operator['id'] ?? 0),
                    'operator_name' => (string)($operator['name'] ?? ''), 'operator_ip' => (string)($operator['ip'] ?? ''),
                    'request_id' => (string)($operator['request_id'] ?? ''), 'add_time' => $now,
                ]);
            }
            $result = $this->read($staffId, $storeId);
            return ['version' => (int)$result['version'], 'items' => $result['items']];
        });
    }

    /** @return array<string,mixed> */
    private function requireStaff(int $staffId, int $storeId, bool $lock = false): array
    {
        if ($staffId <= 0 || $storeId <= 0) throw new AdminException('员工或当前门店无效');
        $query = Db::name('system_store_staff')->where('id', $staffId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0);
        if ($lock) $query->lock(true);
        $staff = $query->find();
        if (!$staff || (int)($staff['employee_id'] ?? 0) <= 0) throw new AdminException('该员工不属于当前门店或已失效');
        return $staff;
    }

    /** @return array<int,array{code:string,label:string,module:string}> */
    private function operationCatalog(): array
    {
        $names = [];
        $walk = static function (array $nodes, string $module = '') use (&$walk, &$names): void {
            foreach ($nodes as $node) {
                $title = (string)($node['title'] ?? '');
                $code = (string)($node['feature_code'] ?? '');
                $children = (array)($node['children'] ?? []);
                if ($children) $walk($children, $title ?: $module);
                elseif (in_array($code, self::operationFeatureCodes(), true)) $names[$code] = ['code' => $code, 'label' => $title, 'module' => $module];
            }
        };
        $walk(JobPositionPolicyServices::STORE_V3_MENU_TREE);
        return array_values($names);
    }
}
