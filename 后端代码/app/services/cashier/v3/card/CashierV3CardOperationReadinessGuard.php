<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use think\facade\Db;

/**
 * Card operations are optional to the cashier base. Their tables therefore
 * have a domain-local readiness gate instead of extending the global gate.
 */
final class CashierV3CardOperationReadinessGuard
{
    private const TABLES = [
        // Card-operation writes rely on the V3 Gateway receipt/event path
        // and the entitlement version projection in the same transaction.
        // Treat this as one atomic capability: a card-only DDL install must
        // not fall through to a database exception after a command starts.
        'eb_cashier_v3_command_receipt',
        'eb_cashier_v3_entitlement_resource_version',
        'eb_cashier_v3_business_event',
        'eb_cashier_v3_outbox',
        'eb_cashier_v3_card_state',
        'eb_cashier_v3_card_operation',
        'eb_cashier_v3_card_operation_line',
        'eb_cashier_v3_card_operation_settlement',
    ];

    /** @var callable|null */
    private $inspector;

    public function setInspector(callable $inspector): void
    {
        $this->inspector = $inspector;
    }

    public function assertReady(): void
    {
        $tables = $this->inspector !== null
            ? (array)call_user_func($this->inspector)
            : $this->existingTables();
        $missing = array_values(array_diff(self::TABLES, $tables));
        if ($missing !== []) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_TABLE_NOT_READY,
                '卡操作底座尚未完成升级，当前不能办理，请联系管理员完成升级后再试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['missing_tables' => $missing, 'domain' => 'card_operation']
            );
        }
    }

    /** @return string[] */
    private function existingTables(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));
        $rows = Db::query(
            'SELECT TABLE_NAME AS table_name FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')',
            self::TABLES
        );
        $out = [];
        foreach ((array)$rows as $row) {
            $name = trim((string)($row['table_name'] ?? $row['TABLE_NAME'] ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }
}
