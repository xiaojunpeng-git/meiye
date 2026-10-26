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
        return $this->cardDebtAmount($originOrder, $currentMemberId, $lock, false);
    }

    /** 取消整笔欠款后，每张卡只展示所属销售明细的未偿金额，不能重复展示整单取消额。 */
    public function cancelledV3CardDebt(array $originOrder, int $currentMemberId, string $tenantId, bool $lock = false): ?string
    {
        return $this->cardDebtAmount($originOrder, $currentMemberId, $lock, true, $tenantId);
    }

    /** 当前最后一次取消金额仍是不可使用的权益；恢复后状态归零，旧审计不再限制次数。 */
    public function cancelledCardDebt(array $order, int $memberId, string $tenantId = '0', bool $allowRowLock = true): string
    {
        if ((int)($order['uid'] ?? 0) !== $memberId || $memberId <= 0) return '0.00';
        // 核销事务读取最新债务终态并与取消命令共用行锁，避免旧快照在取消后放行。
        // AI's repeatable-read transaction is READ ONLY. It needs the same
        // debt facts without FOR UPDATE; checkout callers retain row locks.
        $lock = $allowRowLock && \app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction();
        $v3 = $this->cancelledV3CardDebt($order, $memberId, $tenantId, $lock);
        if ($v3 !== null) return $v3;
        $rows = Db::name('store_debt')->where('order_id', (int)($order['id'] ?? 0))->lock($lock)->select()->toArray();
        if (count($rows) > 1) throw new \LogicException('cashier_v3_card_origin_debt_duplicate');
        $debt = $rows[0] ?? [];
        if ((int)($debt['status'] ?? 0) !== 3) return '0.00';
        $audit = Db::name('cashier_v3_debt_cancellation')->where('debt_id', (int)$debt['id'])
            ->where('tenant_id', $tenantId)->where('member_id', $memberId)
            ->where('store_id', (int)$debt['store_id'])->order('id desc')->find();
        if (!$audit || (int)$debt['uid'] !== $memberId) throw new \LogicException('cashier_v3_card_origin_debt_cancellation_missing');
        return bcdiv((string)$audit['cancelled_debt_cents'], '100', 2);
    }

    /**
     * 共享次数卡的不可用金额以当前债务终态为准：待还与取消均限制核销，
     * 恢复只切换来源事实，不释放尚未补交的次数；结清后才解除限制。
     */
    public function unavailableCardDebt(array $order, int $memberId, string $tenantId = '0', bool $allowRowLock = true): string
    {
        $cancelled = $this->cancelledCardDebt($order, $memberId, $tenantId, $allowRowLock);
        if (bccomp($cancelled, '0', 2) > 0) return $cancelled;
        if ($memberId <= 0 || (int)($order['uid'] ?? 0) !== $memberId) return '0.00';
        $lock = $allowRowLock && \app\services\cashier\v3\CashierV3TransactionGuard::isInTransaction();
        $v3 = $this->pendingV3CardDebt($order, $memberId, $lock);
        if ($v3 !== null) return $v3;
        $rows = Db::name('store_debt')->where('order_id', (int)($order['id'] ?? 0))
            ->lock($lock)->select()->toArray();
        if (count($rows) > 1) throw new \LogicException('cashier_v3_card_origin_debt_duplicate');
        $debt = $rows[0] ?? [];
        if ((int)($debt['status'] ?? -1) !== 0) return '0.00';
        if ((int)($debt['uid'] ?? 0) !== $memberId) {
            throw new \LogicException('cashier_v3_card_origin_debt_member_mismatch');
        }
        return $this->positiveDifference($debt['total_debt'] ?? '0', $debt['repaid_debt'] ?? '0');
    }

    /** 两种展示共享来源解析；取消金额必须同时具备取消状态和 R49 不可变审计事实。 */
    private function cardDebtAmount(array $originOrder, int $currentMemberId, bool $lock, bool $cancelled, string $tenantId = ''): ?string
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
            ->field('a.debt_id,d.status,d.store_id')
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
        if ((int)($authorities[0]['status'] ?? 0) !== ($cancelled ? 3 : 0)) {
            return '0.00';
        }

        $debtId = (int)$authorities[0]['debt_id'];
        if ($cancelled) {
            // 已取消时欠款明细保留原金额和已还金额；审计存在后才允许读取其差额。
            $audit = Db::name('cashier_v3_debt_cancellation')
                ->where('debt_id', $debtId)->where('tenant_id', $tenantId)
                ->where('member_id', $currentMemberId)
                ->where('store_id', (int)$authorities[0]['store_id'])->order('id desc')->find();
            if (!$audit) {
                throw new \LogicException('cashier_v3_card_origin_debt_cancellation_missing');
            }
        }
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
