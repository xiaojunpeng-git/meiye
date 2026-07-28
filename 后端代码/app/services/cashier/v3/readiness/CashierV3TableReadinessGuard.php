<?php
namespace app\services\cashier\v3\readiness;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use think\facade\Db;

/**
 * 统一表就绪门禁：command 与 projection 共用。
 *
 * 禁止 SHOW TABLES LIKE 'eb_cashier_v3_...'：`_` 在 LIKE 中是单字符通配符。
 * 使用 information_schema 对 TABLE_SCHEMA=DATABASE() 与完整 TABLE_NAME 等值检查。
 * 不使用常驻进程 static 真值永久缓存。
 */
class CashierV3TableReadinessGuard
{
    public const REQUIRED_TABLES = [
        'eb_cashier_v3_command_receipt',
        'eb_cashier_v3_resource_version',
        'eb_cashier_v3_state_context',
    ];

    /** @var callable|null function(): array 表名列表；测试可替换 */
    protected $inspector;

    public function setInspector(callable $inspector): void
    {
        $this->inspector = $inspector;
    }

    public function assertReady(): void
    {
        $existing = $this->inspector !== null
            ? (array)call_user_func($this->inspector)
            : $this->queryExistingTables();

        $missing = [];
        foreach (self::REQUIRED_TABLES as $table) {
            if (!in_array($table, $existing, true)) {
                $missing[] = $table;
            }
        }
        if ($missing) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::COMMAND_TABLE_NOT_READY,
                '收银命令底座尚未就绪，请联系管理员完成升级后再试。',
                CashierV3ResultCode::STATUS_FAILED,
                ['missing_tables' => $missing]
            );
        }
    }

    /**
     * @return string[]
     */
    protected function queryExistingTables(): array
    {
        $rows = Db::query(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?,?,?)",
            self::REQUIRED_TABLES
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
