<?php
declare(strict_types=1);

namespace app\services\cashier\v3\cashier;

use think\facade\Db;

/**
 * Resolves debt created by a V3 card sale back to its issued legacy card order.
 *
 * Imported cards keep their historic store_order -> store_debt relationship.
 * New V3 card sales deliberately keep that legacy projection financially empty;
 * their debt belongs to the V3 sales line and is linked through the base-card
 * cart snapshot.  Keeping that branch explicit prevents a new-card fix from
 * changing the imported-card debt path.
 */
final class CashierV3CardOriginDebtResolver
{
    /**
     * Returns null when the origin is not a V3-issued card, so callers must
     * retain their existing historical order-debt resolver unchanged.
     */
    public function pendingV3CardDebt(array $originOrder, int $currentMemberId, bool $lock = false): ?string
    {
        $originOrderId = (int)($originOrder['id'] ?? 0);
        if ($originOrderId <= 0 || $currentMemberId <= 0
            || (int)($originOrder['uid'] ?? 0) !== $currentMemberId) {
            return null;
        }

        $baseCartQuery = Db::name('store_order_cart_info')
            ->where('oid', $originOrderId)
            ->where('uid', $currentMemberId)
            ->where('cart_type', 0)
            ->where('product_type', 5)
            ->where('source_type', 'cashier_v3')
            ->field('id,cart_info')
            ->order('id asc');
        if ($lock) {
            $baseCartQuery->lock(true);
        }
        $baseCarts = $baseCartQuery->select()->toArray();
        if ($baseCarts === []) {
            return null;
        }
        if (count($baseCarts) !== 1) {
            throw new \LogicException('cashier_v3_card_origin_base_cart_duplicate');
        }

        $snapshot = json_decode((string)($baseCarts[0]['cart_info'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $salesOrderId = trim((string)($snapshot['salesOrderId'] ?? ''));
        $salesOrderLineId = trim((string)($snapshot['salesOrderLineId'] ?? ''));
        if ($salesOrderId === '' || $salesOrderLineId === '') {
            throw new \LogicException('cashier_v3_card_origin_snapshot_incomplete');
        }

        $authorityQuery = Db::name('cashier_v3_debt_authority')->alias('a')
            ->join('store_debt d', 'd.id=a.debt_id')
            ->where('a.member_id', $currentMemberId)
            ->where('a.sales_order_id', $salesOrderId)
            ->field('a.debt_id,d.status')
            ->order('a.id asc');
        if ($lock) {
            $authorityQuery->lock(true);
        }
        $authorities = $authorityQuery->select()->toArray();
        if ($authorities === []) {
            return null;
        }
        if (count($authorities) !== 1) {
            throw new \LogicException('cashier_v3_card_origin_debt_authority_duplicate');
        }
        if ((int)($authorities[0]['status'] ?? 0) !== 0) {
            return '0.00';
        }

        $debtId = (int)$authorities[0]['debt_id'];
        $lineQuery = Db::name('cashier_v3_sales_order_line')
            ->where('order_id', $salesOrderId)
            ->where('order_line_id', $salesOrderLineId)
            ->field('debt_amount_cents');
        if ($lock) {
            $lineQuery->lock(true);
        }
        $salesLine = (array)$lineQuery->find();
        if ($salesLine === []) {
            throw new \LogicException('cashier_v3_card_origin_sales_line_missing');
        }
        if ((int)($salesLine['debt_amount_cents'] ?? 0) === 0) {
            return '0.00';
        }

        $itemQuery = Db::name('cashier_v3_debt_item_personnel_authority')->alias('p')
            ->join('store_debt_item i', 'i.id=p.debt_item_id AND i.debt_id=p.debt_id')
            ->where('p.debt_id', $debtId)
            ->where('p.order_line_id', $salesOrderLineId)
            ->field('i.debt_amount,i.repaid_debt')
            ->order('p.id asc');
        if ($lock) {
            $itemQuery->lock(true);
        }
        $items = $itemQuery->select()->toArray();
        if (count($items) !== 1) {
            throw new \LogicException('cashier_v3_card_origin_debt_item_missing');
        }
        return $this->positiveDifference($items[0]['debt_amount'] ?? '0', $items[0]['repaid_debt'] ?? '0');
    }

    private function positiveDifference($total, $repaid): string
    {
        $pending = bcsub((string)$total, (string)$repaid, 2);
        return bccomp($pending, '0', 2) > 0 ? $pending : '0.00';
    }
}
