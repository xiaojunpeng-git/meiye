<?php
// +----------------------------------------------------------------------
// | 瑞昊历史迁移数据保护：历史充值禁止退款/作废
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace app\services\migration;

use think\exception\ValidateException;
use think\facade\Db;

/**
 * 通过 mig_rh_source_map(target_type=user_recharge) 标识历史导入充值。
 */
class RhMigrationProtectServices
{
    public const TARGET_USER_RECHARGE = 'user_recharge';

    /**
     * 历史迁移充值不可变更（退款/作废）。
     */
    public function assertRechargeMutable(int $rechargeId): void
    {
        if ($rechargeId <= 0) {
            return;
        }
        if ($this->isMigratedRecharge($rechargeId)) {
            throw new ValidateException('历史迁移充值仅供查询展示，禁止退款或作废');
        }
    }

    public function isMigratedRecharge(int $rechargeId): bool
    {
        if ($rechargeId <= 0 || !$this->sourceMapTableExists()) {
            return false;
        }
        $hit = Db::name('mig_rh_source_map')
            ->where('target_type', self::TARGET_USER_RECHARGE)
            ->where('target_id', $rechargeId)
            ->value('id');
        return !empty($hit);
    }

    private function sourceMapTableExists(): bool
    {
        try {
            $prefix = (string)config('database.connections.' . config('database.default') . '.prefix');
            $table = $prefix . 'mig_rh_source_map';
            $rows = Db::query("SHOW TABLES LIKE '" . str_replace("'", "''", $table) . "'");
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
