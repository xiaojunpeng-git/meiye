<?php
declare(strict_types=1);

namespace app\services\member;

use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use think\exception\ValidateException;
use think\facade\Db;

/** Versioned member defaults reference the existing business-source directory. */
final class MemberCommerceSettingsServices
{
    /** Schema/config activation is instance-local; customers not migrated keep
     * their existing entry until their explicitly authorized rollout. */
    public static function configured(): bool
    {
        static $present;
        if ($present === null) $present = (bool)Db::query("SHOW TABLES LIKE 'eb_member_commerce_settings'");
        return $present && (bool)Db::name('member_commerce_settings')->where('id',1)->find();
    }

    public function read(): array
    {
        $row = Db::name('member_commerce_settings')->where('id', 1)->find();
        if (!$row) throw new ValidateException('会员交易默认来源尚未配置。');
        // Missing policy columns identify an unmigrated instance, not a request
        // to turn sources off. New migrations explicitly start in manual mode.
        $row += ['source_enabled' => 1, 'inherit_previous' => 1,
            'repayment_primary_id' => 0, 'repayment_secondary_id' => 0];
        $row['sources'] = (new CashierV3BusinessConfigServices())->checkoutCatalog()['sources'];
        return $row;
    }

    /** Validate IDs, including required secondary sources, before committing defaults. */
    public function save(array $input, int $adminId): array
    {
        return Db::transaction(function () use ($input, $adminId): array {
            $key = trim((string)($input['idempotency_key'] ?? ''));
            if ($adminId <= 0 || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) throw new ValidateException('保存标识无效。');
            $hashInput = [];
            foreach (['purchase_primary_id','purchase_secondary_id','recharge_primary_id','recharge_secondary_id','version'] as $field) {
                $hashInput[$field] = (int)($input[$field] ?? 0);
            }
            // Include only submitted policy fields in the idempotency hash;
            // an old client omitting them must preserve, never reset, the policy.
            foreach (['source_enabled', 'inherit_previous', 'repayment_primary_id', 'repayment_secondary_id'] as $field) {
                if (array_key_exists($field, $input)) $hashInput[$field] = $this->policyInteger($field, $input[$field]);
            }
            $hash = hash('sha256', json_encode($hashInput));
            $row = Db::name('member_commerce_settings')->where('id', 1)->lock(true)->find();
            $audit = Db::name('member_commerce_settings_audit')->where('command_key', $key)->find();
            if ($audit) {
                if ((int)$audit['admin_id'] !== $adminId || !hash_equals($audit['input_hash'], $hash)) throw new ValidateException('保存标识已被其他内容使用。');
                return json_decode($audit['after_json'], true) + ['sources' => $this->read()['sources']];
            }
            if (!$row || (int)($input['version'] ?? 0) !== (int)$row['version']) {
                throw new ValidateException('默认来源已变化，请刷新后重试。');
            }
            $changes = [];
            foreach (['source_enabled', 'inherit_previous', 'repayment_primary_id', 'repayment_secondary_id'] as $field) {
                if (!array_key_exists($field, $input)) continue;
                if (!array_key_exists($field, $row)) throw new ValidateException('请先完成业务来源设置升级，再保存。');
                $changes[$field] = $this->policyInteger($field, $input[$field]);
            }
            $enabled = (int)($changes['source_enabled'] ?? $row['source_enabled'] ?? 1) === 1;
            foreach (['purchase','recharge','repayment'] as $kind) {
                if ($kind === 'repayment' && !array_key_exists('repayment_primary_id', $row)) continue;
                $primary = (int)($input[$kind . '_primary_id'] ?? $row[$kind . '_primary_id'] ?? 0);
                $secondary = (int)($input[$kind . '_secondary_id'] ?? $row[$kind . '_secondary_id'] ?? 0);
                // Fixed mode must be executable without any hidden manual
                // prompt; active manual mode may keep unused defaults empty.
                if (!$enabled || $primary > 0 || $secondary > 0) $this->validateSource($primary, $secondary);
                if ($primary < 0 || $secondary < 0) throw new ValidateException('来源配置无效。');
                $changes[$kind . '_primary_id'] = $primary;
                $changes[$kind . '_secondary_id'] = $secondary;
            }
            $changes['version'] = (int)$row['version'] + 1;
            $changes['updated_by'] = $adminId;
            $changes['updated_at'] = time();
            Db::name('member_commerce_settings')->where('id', 1)->update($changes);
            Db::name('member_commerce_settings_audit')->insert(['command_key' => $key, 'admin_id' => $adminId,
                'input_hash' => $hash, 'before_json' => json_encode($row), 'after_json' => json_encode(array_merge($row, $changes)),
                'created_at' => time()]);
            return $this->read();
        });
    }

    /** Freeze a resolved source on the order; callbacks never reread defaults. */
    public function source(string $kind): array
    {
        if (!in_array($kind, ['purchase','recharge','repayment'], true)) throw new ValidateException('交易类型无效。');
        $row = Db::name('member_commerce_settings')->where('id', 1)->lock(true)->find();
        if (!$row) throw new ValidateException('会员交易默认来源尚未配置。');
        return $this->validateSource((int)$row[$kind . '_primary_id'], (int)$row[$kind . '_secondary_id']);
    }

    private function policyInteger(string $field, $value): int
    {
        if (is_bool($value)) $value = (int)$value;
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value))) throw new ValidateException('来源设置必须使用有效整数。');
        $value = (int)$value;
        if ($value < 0 || (in_array($field, ['source_enabled', 'inherit_previous'], true) && $value > 1)) throw new ValidateException('来源设置开关无效。');
        return $value;
    }

    private function validateSource(int $primary, int $secondary): array
    {
        $config = new CashierV3BusinessConfigServices();
        $config->assertCheckoutSourceAllowed($primary);
        return $config->resolveSourceSnapshot($primary, $secondary, true);
    }
}
