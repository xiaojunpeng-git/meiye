<?php

namespace app\services\cashier\v3\event;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use think\facade\Db;

/** Action-aware readiness guard for the event and outbox tables. */
final class CashierV3EventOutboxReadinessGuard
{
    public const REQUIRED_TABLES = [
        'eb_cashier_v3_business_event',
        'eb_cashier_v3_outbox',
        'eb_cashier_v3_outbox_attempt',
        'eb_cashier_v3_consumer_once',
    ];

    private $inspector;

    public function setInspector(callable $inspector): void { $this->inspector = $inspector; }

    public function assertReadyForAction(array $definition): void
    {
        $contract = CashierV3BusinessEventContractRegistry::normalize($definition, (string)($definition['canonical'] ?? ''));
        if (!$contract['required_event_types']) {
            return;
        }
        $existing = $this->existingTables();
        $missing = array_values(array_diff(self::REQUIRED_TABLES, $existing));
        if ($missing) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::EVENT_OUTBOX_NOT_READY,
                '业务事件底座尚未就绪，请联系管理员完成升级后再试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['missing_tables' => $missing]
            );
        }
        $legacy = 'eb_cashier_v3_member_event_outbox';
        if (in_array($legacy, $existing, true)) {
            $count = (int)Db::name('cashier_v3_member_event_outbox')->count();
            if ($count > 0) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::EVENT_OUTBOX_NOT_READY,
                    '检测到未收敛的会员事件数据，请先完成数据迁移。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['legacy_table' => $legacy, 'rows' => $count]
                );
            }
        }
    }

    private function existingTables(): array
    {
        if ($this->inspector !== null) {
            return array_values(array_unique(array_map('strval', (array)call_user_func($this->inspector))));
        }
        $names = array_merge(self::REQUIRED_TABLES, ['eb_cashier_v3_member_event_outbox']);
        $marks = implode(',', array_fill(0, count($names), '?'));
        $rows = Db::query(
            'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $marks . ')',
            $names
        );
        $out = [];
        foreach ((array)$rows as $row) {
            $name = (string)($row['t'] ?? $row['TABLE_NAME'] ?? '');
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }
}
