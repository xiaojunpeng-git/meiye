<?php

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/**
 * Vue3 多专属服务人的当前选择。旧单人表仍是兼容首位人员投影；本表
 * 只用于本次默认人员，绝不直接生成预约、销售或劳动业绩。
 */
final class CashierV3MemberExclusiveSelectionServices
{
    private const TABLE = 'member_exclusive_service_selection';
    private const CHANGE_TABLE = 'member_exclusive_service_selection_change';

    /** @return array<int,array<string,mixed>> */
    public function records(int $memberId): array
    {
        if ($memberId <= 0) return [];
        // 迁移是新版本前置条件；查询失败必须暴露，不能静默退回旧单人投影。
        $rows = Db::name(self::TABLE)->where('member_id', $memberId)
            ->where('status', 1)->order('sort_order asc,id asc')->select()->toArray();
        if ($rows) return $rows;
        $legacy = Db::name('member_exclusive_service')->where('member_id', $memberId)
            ->where('status', 1)->find();
        return $legacy ? [$legacy] : [];
    }

    /**
     * Replace the complete current selection in the member command transaction.
     * Rows are versioned and deactivated, never deleted; a single audit row
     * records the before/after member-level selection for this command key.
     * @param array<int,array<string,mixed>> $people
     */
    public function replace(int $memberId, array $people, int $operatorId, string $idempotencyKey, int $now): void
    {
        CashierV3TransactionGuard::assertInTransaction('memberExclusiveSelectionReplace');
        $rows = Db::name(self::TABLE)->where('member_id', $memberId)
            ->order('sort_order asc,id asc')->lock(true)->select()->toArray();
        $before = array_values(array_map('intval', array_column(array_values(array_filter($rows, static function (array $row): bool {
            return (int)$row['status'] === 1;
        })), 'staff_id')));
        $after = array_values(array_map('intval', array_column($people, 'staff_id')));
        if ($before === $after) return;
        $byStaff = array_column($rows, null, 'staff_id');
        foreach ($rows as $row) {
            if ((int)$row['status'] !== 1 || in_array((int)$row['staff_id'], $after, true)) continue;
            Db::name(self::TABLE)->where('id', (int)$row['id'])->where('version', (int)$row['version'])
                ->update(['status' => 0, 'version' => (int)$row['version'] + 1, 'updated_at' => $now]);
        }
        foreach ($people as $index => $person) {
            $staffId = (int)$person['staff_id'];
            $values = [
                'employee_id' => (int)$person['employee_id'],
                'store_id' => (int)$person['store_id'],
                'staff_name' => (string)$person['staff_name'],
                'sort_order' => $index + 1,
                'status' => 1,
                'updated_at' => $now,
            ];
            if (isset($byStaff[$staffId])) {
                $row = $byStaff[$staffId];
                Db::name(self::TABLE)->where('id', (int)$row['id'])->where('version', (int)$row['version'])
                    ->update($values + ['version' => (int)$row['version'] + 1]);
            } else {
                Db::name(self::TABLE)->insert($values + [
                    'member_id' => $memberId, 'staff_id' => $staffId,
                    'version' => 1, 'created_at' => $now,
                ]);
            }
        }
        Db::name(self::CHANGE_TABLE)->insert([
            'change_key' => 'exclusive_selection:' . $memberId . ':' . $idempotencyKey,
            'member_id' => $memberId,
            'previous_json' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'current_json' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'operator_id' => $operatorId,
            'occurred_at' => $now,
        ]);
    }
}
