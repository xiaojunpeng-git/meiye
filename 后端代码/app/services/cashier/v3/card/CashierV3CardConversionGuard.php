<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** Prevent older reversals from revaluing rights already converted to gift balance. */
final class CashierV3CardConversionGuard
{
    /** Resolve only explicit legacy debt links; ambiguous historical links fail closed. */
    public static function legacyOriginOrderId(int $orderId, bool $lock = false): int
    {
        if ($lock) CashierV3TransactionGuard::assertInTransaction('cardConversion.legacyOrigin');
        $order = Db::name('store_order')->where('id', $orderId)
            ->field('id,is_debt_repay,debt_repay_origin_order_id')->lock($lock)->find();
        $origins = [];
        if ($order && (int)($order['is_debt_repay'] ?? 0) === 1) {
            $explicit = (int)($order['debt_repay_origin_order_id'] ?? 0);
            if ($explicit <= 0 || $explicit === $orderId) {
                throw new \think\exception\ValidateException('补交订单的原欠款关联不完整，不能退款或作废。');
            }
            $origins[] = $explicit;
        }
        // Some older successful repayments have only immutable ledger links.
        // The channel gate and the final debt writer must resolve the same origin.
        if (self::tableExists('store_debt_repay') && self::tableExists('store_debt')) {
            foreach (Db::name('store_debt_repay')->alias('r')->join('store_debt d', 'd.id=r.debt_id')
                ->where('r.repay_order_id', $orderId)->field('d.order_id')->lock($lock)->select()->toArray() as $link) {
                $origins[] = (int)$link['order_id'];
            }
        }
        $origins = array_values(array_unique($origins));
        if (count($origins) > 1 || ($origins && ($origins[0] <= 0 || $origins[0] === $orderId))) {
            throw new \think\exception\ValidateException('补交订单的原欠款关联存在冲突，不能退款或作废。');
        }
        return $origins ? $origins[0] : $orderId;
    }

    /**
     * A legacy refund/void can resume after its channel call. Any existing
     * terminal operation therefore excludes conversion until that workflow
     * is resolved; a zero terminal_action on the order is not proof of safety.
     */
    public static function legacyTerminalOrderIds(array $orderIds, bool $lock = false): array
    {
        if ($lock) CashierV3TransactionGuard::assertInTransaction('cardConversion.legacyTerminal');
        $orderIds = array_values(array_filter(array_unique(array_map('intval', $orderIds)),
            static fn(int $id): bool => $id > 0));
        if (!$orderIds || !self::tableExists('store_order_terminal_operation')) return [];
        return array_map('intval', Db::name('store_order_terminal_operation')->whereIn('store_order_id', $orderIds)
            ->lock($lock)->column('store_order_id'));
    }

    /**
     * Legacy rollback has no V3 envelope. One independent database still has
     * one tenant identity; lock every holder of the exact physical order before
     * restoring anything. Older instances without conversion authority keep
     * their existing behavior because no conversion can have been recorded.
     */
    public static function assertLegacyOrderNotConverted(int $orderId): void
    {
        CashierV3TransactionGuard::assertInTransaction('cardConversion.legacyReversal');
        if ($orderId <= 0) return;
        if (!self::tableExists('cashier_v3_card_operation')) return;
        // Repayment documents carry an explicit legacy origin. Refunding that
        // document restores the origin's debt, so it shares the same asset guard.
        $documentId = $orderId;
        $orderId = self::legacyOriginOrderId($documentId);
        Db::name('store_order')->where('id', $orderId)->lock(true)->find();
        if (self::legacyOriginOrderId($documentId, true) !== $orderId) {
            throw new \think\exception\ValidateException('补交订单关联已变化，请重新办理。');
        }
        Db::name('user_card_holder')->where('oid', $orderId)->order('id asc')->lock(true)->select();
        if (self::operations(\app\services\cashier\v3\CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('origin_order_id', $orderId)->lock(true)->find()) {
            // Legacy controllers expose ValidateException business messages;
            // a V3 envelope exception here would become an opaque HTTP error.
            throw new \think\exception\ValidateException('关联卡项已转入赠金并作废，不能再退款、作废原单或恢复核销权益。');
        }
    }

    /** Caller holds the source holder/detail before attempting to restore used rights. */
    public static function assertHolderNotConverted(int $holderId, string $tenantId): void
    {
        CashierV3TransactionGuard::assertInTransaction('cardConversion.holderReversal');
        if ($holderId <= 0) return;
        $converted = self::operations($tenantId)->where('source_card_holder_id', $holderId)->lock(true)->find();
        if ($converted) throw self::failure();
    }

    /**
     * Caller holds the debt row shared with conversion's actual-value reader.
     * Native V3 and legacy order IDs are separate namespaces: only a receipt or
     * explicit migration authority may bridge them; numeric equality is not identity.
     */
    public static function assertDebtNotConverted(array $debt, string $tenantId, string $repaymentId = ''): void
    {
        CashierV3TransactionGuard::assertInTransaction('cardConversion.debtReversal');
        $authority = Db::name('cashier_v3_debt_authority')->where('tenant_id', $tenantId)
            ->where('debt_id', (int)$debt['id'])->lock(true)->find();
        $origins = [];
        if ($authority) {
            $receipts = Db::name('cashier_v3_card_purchase_receipt')->alias('r')
                ->join('cashier_v3_sales_order_line l', 'l.tenant_id=r.tenant_id AND l.order_line_id=r.sales_order_line_id')
                ->where('r.tenant_id', $tenantId)->where('r.sales_order_id', (string)$authority['sales_order_id'])
                ->where('r.status', 'completed')->where('l.debt_amount_cents', '>', 0);
            if ($repaymentId !== '') {
                // Frozen increments allow reversing a payment that affected
                // only another card, without reopening this converted card's debt.
                $settlements = Db::name('cashier_v3_debt_repayment_item_settlement')
                    ->where('tenant_id', $tenantId)->where('repayment_id', $repaymentId)->select()->toArray();
                if ($settlements) {
                    $lines = array_column(array_filter($settlements, static fn(array $item): bool =>
                        (int)$item['amount_cents'] > 0), 'order_line_id');
                    if (!$lines) return;
                    $receipts->whereIn('r.sales_order_line_id', $lines);
                }
            }
            $origins = array_map('intval', $receipts->column('r.legacy_order_id'));
        }
        if (!$authority || (int)$authority['sales_order_record_id'] === 0) {
            $origins[] = (int)$debt['order_id'];
        }
        $origins = array_values(array_filter(array_unique($origins), static fn(int $id): bool => $id > 0));
        if ($origins && self::operations($tenantId)->whereIn('origin_order_id', $origins)->lock(true)->find()) {
            throw self::failure();
        }
    }

    private static function operations(string $tenantId)
    {
        return Db::name('cashier_v3_card_operation')->where('tenant_id', $tenantId)
            ->where('operation_type', 'card_to_balance')->whereIn('operation_status', ['completed', 'succeeded']);
    }

    private static function tableExists(string $name): bool
    {
        $table = (string)Db::connect()->getConfig('prefix') . $name;
        $rows = Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        return (int)($rows[0]['n'] ?? 0) > 0;
    }

    private static function failure(): CashierV3CommandException
    {
        return CashierV3CommandException::invalidContext(
            '关联卡项已转入赠金并作废，不能再通过原记录恢复权益或欠款。',
            ['reason' => 'card_to_balance_later_reversal_blocked']
        );
    }
}
