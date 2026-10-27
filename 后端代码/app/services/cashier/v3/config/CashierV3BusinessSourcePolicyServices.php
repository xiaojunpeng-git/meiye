<?php
declare(strict_types=1);

namespace app\services\cashier\v3\config;

use app\services\cashier\v3\settlement\CashierV3CheckoutBusinessSourceSelectionServices;
use think\exception\ValidateException;
use think\facade\Db;

/** Instance-local source policy shared by cashier, reservations and merchant UI.
 * It resolves initial choices, not final checkout snapshots: settlement must not
 * reread this policy to replace the source already confirmed in its sole snapshot.
 */
class CashierV3BusinessSourcePolicyServices
{
    /** Metadata is refreshed per read, never cached across long-lived workers. */
    public function read(): array
    {
        $row = $this->settingsRow();
        $ready = array_key_exists('source_enabled', $row) && array_key_exists('inherit_previous', $row);
        // Unmigrated instances preserve their original behavior until an explicit
        // local/customer migration; missing schema must not silently hide fields.
        $enabled = !$ready || (int)$row['source_enabled'] === 1;
        $inherit = !$ready || (int)$row['inherit_previous'] === 1;
        $defaults = [];
        foreach (['purchase', 'recharge', 'repayment'] as $kind) {
            $defaults[$kind] = array_replace(self::emptyChoice(), [
                'primarySourceId' => (int)($row[$kind . '_primary_id'] ?? 0),
                'secondarySourceId' => (int)($row[$kind . '_secondary_id'] ?? 0),
            ]);
        }
        return ['enabled' => $enabled, 'inheritPrevious' => $enabled && $inherit,
            'mode' => !$enabled ? 'fixed' : ($inherit ? 'previous' : 'manual'),
            'configured' => $ready, 'defaults' => $defaults];
    }

    /** Same-member/store/kind defaults; no operator/browser-wide remembered value. */
    public function initialChoice(string $kind, string $tenantId, int $storeId, int $memberId): array
    {
        $this->assertKind($kind);
        $policy = $this->read();
        if (!$policy['enabled']) return $this->resolveConfigured($policy['defaults'][$kind]);
        if (!$policy['inheritPrevious'] || $tenantId === '' || $storeId <= 0 || $memberId <= 0) return self::emptyChoice();
        $previous = $this->latestChoice($kind, $tenantId, $storeId, $memberId);
        if ((int)($previous['primarySourceId'] ?? 0) <= 0) return self::emptyChoice();
        try { return $this->resolveConfigured($previous); }
        catch (ValidateException $error) { return self::emptyChoice(); }
    }

    /** Authenticated projection callers supply an already authorized member.
     * Invalid fixed configuration is visible on the new business, not fatal to
     * login or history. Never use this projection to replace a confirmed choice.
     */
    public function initialChoices(string $tenantId, int $storeId, int $memberId): array
    {
        $choices = [];
        foreach (['purchase', 'recharge', 'repayment'] as $kind) {
            try { $choices[$kind] = $this->initialChoice($kind, $tenantId, $storeId, $memberId); }
            catch (ValidateException $error) {
                $choices[$kind] = self::emptyChoice() + ['configurationError' => $error->getMessage()];
            }
        }
        return $choices;
    }

    /** Apply this only when preparing a NEW business choice, not during a
     * confirmed checkout's final settlement or an idempotent replay.
     */
    public function resolveChoice(string $kind, string $tenantId, int $storeId, int $memberId, array $candidate): array
    {
        $this->assertKind($kind);
        if ($tenantId === '' || $storeId <= 0) throw new ValidateException('来源缺少有效门店范围。');
        $policy = $this->read();
        if (!$policy['enabled']) return $this->resolveConfigured($policy['defaults'][$kind]);
        $source = $this->resolveConfigured($candidate);
        // Only an explicitly confirmed current G-source reward may survive;
        // initial/history/fixed choices always start at zero, never inherit it.
        if (array_key_exists('rewardAmountCents', $candidate)) {
            $reward = $candidate['rewardAmountCents'];
            if (!is_int($reward) || $reward < 0 || $reward % 100 !== 0) throw new ValidateException('来源奖励必须为整数元。');
            if (preg_match('/^G(?:\s|异业|$)/u', trim((string)$source['primarySourceNameSnapshot'])) === 1) $source['rewardAmountCents'] = $reward;
        }
        return $source;
    }

    public static function emptyChoice(): array
    {
        return ['primarySourceId' => 0, 'secondarySourceId' => 0,
            'primarySourceNameSnapshot' => '', 'secondarySourceNameSnapshot' => '',
            'displayNameSnapshot' => '', 'rewardAmountCents' => 0];
    }

    protected function settingsRow(): array
    {
        if (!Db::query("SHOW TABLES LIKE 'eb_member_commerce_settings'")) return [];
        return (array)Db::name('member_commerce_settings')->where('id', 1)->find();
    }

    /** Resolve names/secondary requirements from the authoritative directory;
     * fixed defaults never invent a source, revive K, or inherit reward amounts.
     */
    protected function resolveConfigured(array $candidate): array
    {
        foreach (['primarySourceId', 'secondarySourceId'] as $key) {
            $value = $candidate[$key] ?? 0;
            if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value))) throw new ValidateException('来源编号必须为有效整数。');
        }
        $primary = (int)($candidate['primarySourceId'] ?? 0);
        $secondary = (int)($candidate['secondarySourceId'] ?? 0);
        if ($primary <= 0 || $secondary < 0) throw new ValidateException('请选择有效的客户来源；关闭来源时请先配置固定来源。');
        $config = new CashierV3BusinessConfigServices();
        $config->assertCheckoutSourceAllowed($primary);
        return array_replace(self::emptyChoice(), $config->resolveSourceSnapshot($primary, $secondary, false));
    }

    protected function latestChoice(string $kind, string $tenantId, int $storeId, int $memberId): array
    {
        if ($kind === 'purchase') {
            // Keep the existing pure history reader. It must never call back to
            // this policy, or previous-mode selection would recurse indefinitely.
            return (new CashierV3CheckoutBusinessSourceSelectionServices())->latestNormalMemberSource($tenantId, $storeId, $memberId);
        }
        if ($kind === 'recharge') {
            $row = Db::name('user_recharge')->alias('r')->where('r.uid', $memberId)->where('r.store_id', $storeId)
                ->where('r.paid', 1)->where('r.terminal_action', 0)->where('r.refund_price', '<=', 0)
                ->whereExists(function ($q) use ($tenantId, $storeId, $memberId): void {
                    $q->name('cashier_v3_business_event')->alias('e')->where('e.tenant_id', $tenantId)
                        ->where('e.store_id', $storeId)->where('e.member_id', $memberId)
                        ->where('e.event_type', 'recharge.completed')->whereRaw("e.aggregate_id = CONCAT('RCH:', r.id)");
                })->order('r.pay_time desc,r.id desc')->find();
            return $this->sourceColumns((array)$row);
        }
        // Sales-debt and recharge-debt repayments share one business kind; read
        // at most one succeeded row per domain, then choose by a stable time key.
        $rows = [];
        foreach (['cashier_v3_debt_repayment', 'cashier_v3_recharge_debt_repayment'] as $table) {
            $row = Db::name($table)->where('tenant_id', $tenantId)->where('store_id', $storeId)
                ->where('member_id', $memberId)->where('status', 'succeeded')->order('settled_at desc,id desc')->find();
            if ($row) $rows[] = $row + ['_domain' => $table];
        }
        usort($rows, static function (array $a, array $b): int {
            return ((int)$b['settled_at'] <=> (int)$a['settled_at'])
                ?: ((int)$b['id'] <=> (int)$a['id']) ?: strcmp($b['_domain'], $a['_domain']);
        });
        return $this->sourceColumns($rows[0] ?? []);
    }

    private function sourceColumns(array $row): array
    {
        return ['primarySourceId' => (int)($row['business_source_primary_id'] ?? 0),
            'secondarySourceId' => (int)($row['business_source_secondary_id'] ?? 0)];
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, ['purchase', 'recharge', 'repayment'], true)) throw new ValidateException('来源业务类型无效。');
    }
}
