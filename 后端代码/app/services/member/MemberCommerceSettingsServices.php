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
            foreach (['purchase','recharge'] as $kind) {
                $primary = (int)($input[$kind . '_primary_id'] ?? 0);
                $secondary = (int)($input[$kind . '_secondary_id'] ?? 0);
                $this->validateSource($primary, $secondary);
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
        if (!in_array($kind, ['purchase','recharge'], true)) throw new ValidateException('交易类型无效。');
        $row = Db::name('member_commerce_settings')->where('id', 1)->lock(true)->find();
        if (!$row) throw new ValidateException('会员交易默认来源尚未配置。');
        return $this->validateSource((int)$row[$kind . '_primary_id'], (int)$row[$kind . '_secondary_id']);
    }

    private function validateSource(int $primary, int $secondary): array
    {
        $config = new CashierV3BusinessConfigServices();
        $config->assertCheckoutSourceAllowed($primary);
        return $config->resolveSourceSnapshot($primary, $secondary, true);
    }
}
