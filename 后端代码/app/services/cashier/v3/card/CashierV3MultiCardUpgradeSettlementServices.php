<?php
declare(strict_types=1);

namespace app\services\cashier\v3\card;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\event\CashierV3BusinessEventExecution;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use think\facade\Db;

/**
 * One final immutable snapshot for a many-source card upgrade.
 *
 * This deliberately has no browser draft, no business version history and no
 * partial source settlement. Resource state versions still provide technical
 * optimistic-concurrency protection while this transaction is executing.
 */
final class CashierV3MultiCardUpgradeSettlementServices
{
    private const HEADER_TABLE = 'cashier_v3_multi_card_upgrade';
    private const SOURCE_TABLE = 'cashier_v3_multi_card_upgrade_source';
    private const STATE_TABLE = 'cashier_v3_card_state';

    public function prepareCreditInTx(
        array $aggregate,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        int $now
    ): array {
        CashierV3TransactionGuard::assertInTransaction('multiCardUpgradePrepare');
        $request = (array)($aggregate['request'] ?? []);
        $requestId = trim((string)($request['request_id'] ?? ''));
        $target = $this->targetFromAggregate($aggregate);
        if ($target === []) return [];
        if ($requestId === '' || (int)($request['member_id'] ?? 0) <= 0) throw self::failure('multi_card_upgrade_request_invalid');
        $upgrade = $target['upgrade'];
        $sourceIds = $this->sourceIds($upgrade);
        if ($sourceIds === []) throw self::failure('multi_card_upgrade_sources_missing');
        // The upgrade header is the one immutable target-card price snapshot.
        // The normal sale pipeline intentionally stores the post-credit
        // receivable on its working line, so that intermediate field cannot
        // be treated as the target-card price (especially after manual price
        // edits, coupons or debt split the normal checkout).
        $targetSale = self::money($upgrade['targetSaleAmountCents'] ?? null);
        $payable = self::money($target['line']['sale_amount_cents'] ?? null);
        $couponDiscount = self::money($target['line']['coupon_discount_cents'] ?? null);
        $expectedSourceValue = self::money($upgrade['sourceRemainingValueCents'] ?? null);

        $existing = Db::name(self::HEADER_TABLE)
            ->where('tenant_id', $dataScope->tenantId())->where('checkout_request_id', $requestId)->lock(true)->find();
        if ($existing) {
            if ((string)($existing['immutable_fingerprint'] ?? '') !== $this->fingerprint($upgrade)
                || (string)($existing['upgrade_status'] ?? '') !== 'settled') {
                throw CashierV3CommandException::versionConflict('该卡升级结账资料已变化，请重新打开后办理。', ['reason' => 'multi_card_upgrade_replay_mismatch']);
            }
            return $this->credit((string)$existing['upgrade_id'], $requestId, (int)$existing['entitlement_credit_cents'], (int)$existing['target_sale_amount_cents']);
        }

        $sources = [];
        foreach ($sourceIds as $holderId) $sources[] = $this->lockSource($holderId, $request, $operatorScope, $dataScope);
        $sourceValue = array_sum(array_column($sources, 'remainingValueCents'));
        $targetAfterCoupon = $targetSale - $couponDiscount;
        $credit = min($sourceValue, $targetAfterCoupon);
        if ($sourceValue !== $expectedSourceValue || $payable !== $targetAfterCoupon - $credit) {
            throw CashierV3CommandException::versionConflict('原卡余额或目标卡价格已变化，请重新核对后结账。', ['reason' => 'multi_card_upgrade_amount_changed']);
        }
        // Persist only the final canonical snapshot.  Never retain the
        // earlier browser-selected target amount when an operator changed
        // the price or applied a coupon before checkout.
        $upgrade['targetSaleAmountCents'] = $targetSale;
        $upgrade['entitlementCreditCents'] = $credit;
        $upgrade['excessWriteoffCents'] = max(0, $sourceValue - $credit);
        $upgrade['settlementDeltaCents'] = $payable;
        $upgradeId = 'MCU-' . strtoupper(substr(hash('sha256', $dataScope->tenantId() . ':' . $requestId), 0, 40));
        $header = [
            'upgrade_id' => $upgradeId, 'tenant_id' => $dataScope->tenantId(), 'checkout_request_id' => $requestId,
            'store_id' => $operatorScope->storeId(), 'member_id' => (int)$request['member_id'],
            'target_sale_amount_cents' => $targetSale, 'entitlement_credit_cents' => $credit,
            'excess_writeoff_cents' => max(0, $sourceValue - $targetSale), 'upgrade_status' => 'prepared',
            'immutable_fingerprint' => $this->fingerprint($upgrade),
            'snapshot_json' => self::json(['contractVersion' => 'cashier-v3-multi-card-upgrade-v1', 'target' => $upgrade, 'sources' => $sources]),
            'created_at' => $now, 'settled_at' => 0,
        ];
        if ((int)Db::name(self::HEADER_TABLE)->insertGetId($header) <= 0) throw self::failure('multi_card_upgrade_header_insert_failed');
        $remainingCredit = $credit;
        foreach ($sources as $index => $source) {
            $allocated = min($remainingCredit, $source['remainingValueCents']);
            $remainingCredit -= $allocated;
            $row = [
                'upgrade_id' => $upgradeId, 'tenant_id' => $dataScope->tenantId(), 'line_no' => $index + 1,
                'source_card_holder_id' => $source['holderId'], 'source_legacy_order_id' => $source['legacyOrderId'],
                'source_card_name_snapshot' => $source['cardName'], 'source_card_no_snapshot' => $source['cardNo'],
                'source_remaining_value_cents' => $source['remainingValueCents'], 'credit_cents' => $allocated,
                'excess_writeoff_cents' => $source['remainingValueCents'] - $allocated, 'source_snapshot_json' => self::json($source),
                'immutable_fingerprint' => hash('sha256', self::json($source)), 'created_at' => $now,
            ];
            if ((int)Db::name(self::SOURCE_TABLE)->insertGetId($row) <= 0) throw self::failure('multi_card_upgrade_source_insert_failed');
        }
        return $this->credit($upgradeId, $requestId, $credit, $targetSale);
    }

    public function settleInTx(
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $salesResult,
        array $cardPurchaseResult,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope,
        CashierV3BusinessEventRecorder $eventRecorder,
        CashierV3BusinessEventExecution $eventExecution,
        array $eventContract,
        int $now
    ): array {
        CashierV3TransactionGuard::assertInTransaction('multiCardUpgradeSettle');
        $requestId = $salesPlan->checkoutRequestId();
        $header = Db::name(self::HEADER_TABLE)->where('tenant_id', $dataScope->tenantId())->where('checkout_request_id', $requestId)->lock(true)->find();
        if (!$header) return ['settledUpgradeCount' => 0, 'upgrades' => []];
        if ((string)$header['upgrade_status'] === 'settled') return ['settledUpgradeCount' => 1, 'upgrades' => [(array)$header], 'replayed' => true];
        $lines = $salesPlan->lines(); $receipts = array_values((array)($cardPurchaseResult['receipts'] ?? []));
        if (count($lines) !== 1 || count($receipts) !== 1 || (int)($receipts[0]['legacyOrderId'] ?? 0) <= 0) throw self::failure('multi_card_upgrade_target_receipt_missing');
        $sources = $this->rows(Db::name(self::SOURCE_TABLE)->where('tenant_id', $dataScope->tenantId())->where('upgrade_id', (string)$header['upgrade_id'])->order('line_no asc')->lock(true)->select());
        if ($sources === []) throw self::failure('multi_card_upgrade_sources_missing');
        $targetLegacyOrderId = (int)$receipts[0]['legacyOrderId'];
        foreach ($sources as $source) $this->closeSource($source, $targetLegacyOrderId, $dataScope, $now);
        $updated = Db::name(self::HEADER_TABLE)->where('id', (int)$header['id'])->where('upgrade_status', 'prepared')->update([
            'upgrade_status' => 'settled', 'sales_order_id' => (string)($salesResult['orderId'] ?? ''),
            'sales_order_line_id' => (string)($lines[0]['order_line_id'] ?? ''), 'target_card_holder_id' => (int)($receipts[0]['holderId'] ?? 0),
            'target_legacy_order_id' => $targetLegacyOrderId, 'settled_at' => $now,
        ]);
        if ((int)$updated !== 1) throw CashierV3CommandException::versionConflict('原卡状态已变化，结账未提交。', ['reason' => 'multi_card_upgrade_settle_race']);
        $eventRecorder->recordInTx($eventExecution, $eventContract, [
            'event_type' => 'card.multi_upgrade.settled', 'aggregate_type' => 'multi_card_upgrade', 'aggregate_id' => (string)$header['upgrade_id'],
            'aggregate_version' => 1, 'event_version' => 1, 'source_type' => 'submit-checkout', 'source_id' => $requestId,
            'member_id' => (int)$header['member_id'], 'business_date' => (string)$salesPlan->header()['business_date'],
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'aggregate_name_snapshot' => '多卡升级', 'store_name_snapshot' => (string)$salesPlan->header()['store_name_snapshot'],
            'payload' => ['upgradeId' => (string)$header['upgrade_id'], 'checkoutRequestId' => $requestId, 'sourceCount' => count($sources),
                'targetSaleAmountCents' => (int)$header['target_sale_amount_cents'], 'entitlementCreditCents' => (int)$header['entitlement_credit_cents'],
                'excessWriteoffCents' => (int)$header['excess_writeoff_cents']],
        ]);
        $header['upgrade_status'] = 'settled'; $header['target_legacy_order_id'] = $targetLegacyOrderId;
        return ['settledUpgradeCount' => 1, 'upgrades' => [$header], 'replayed' => false];
    }

    private function targetFromAggregate(array $aggregate): array {
        $found = [];
        foreach ((array)($aggregate['lines'] ?? []) as $line) {
            if ((string)($line['line_role'] ?? '') !== 'sale') continue;
            $snapshot = json_decode((string)($line['card_purchase_snapshot_json'] ?? ''), true);
            if (is_array($snapshot['multiCardUpgrade'] ?? null)) $found[] = ['line' => (array)$line, 'upgrade' => $snapshot['multiCardUpgrade']];
        }
        if ($found === []) return [];
        if (count($found) !== 1 || count((array)($aggregate['lines'] ?? [])) !== 1) throw self::failure('multi_card_upgrade_target_not_unique');
        return $found[0];
    }

    private function lockSource(int $holderId, array $request, CashierV3OperatorScope $operatorScope, CashierV3DataScopeContext $dataScope): array {
        $holder = Db::name('user_card_holder')->where('id', $holderId)->where('uid', (int)$request['member_id'])->where('is_del', 0)->lock(true)->find();
        // 原卡购卡门店不限制升级；会员归属仍由上面的 uid 条件锁定。
        // 新卡由当前门店销售签发，不能把原卡历史门店复制为新卡归属。
        if (!$holder) throw self::conflict('multi_card_upgrade_holder_changed');
        // Card-state is only an optional read overlay.  A multi-card upgrade
        // never creates or mutates that overlay: the locked legacy holder,
        // order and benefit rows are both the authority and the concurrency
        // boundary, while this upgrade keeps only its final snapshot.
        $state = Db::name(self::STATE_TABLE)->where('tenant_id', $dataScope->tenantId())->where('card_holder_id', $holderId)->lock(true)->find();
        $order = Db::name('store_order')->where('id', (int)$holder['oid'])->lock(true)->find();
        if (!$order || ($state && ((string)($state['card_status'] ?? '') !== 'enabled' || (int)($state['origin_order_id'] ?? 0) !== (int)$holder['oid']
            || (int)($state['current_member_id'] ?? 0) !== (int)$request['member_id'])) || (int)($order['paid'] ?? 0) !== 1
            || (int)($order['card_upgrade_use_oid'] ?? -1) !== 0 || (int)($order['refund_status'] ?? -1) !== 0
            || (int)($order['terminal_action'] ?? -1) !== 0 || (int)($order['is_del'] ?? 1) !== 0) throw self::conflict('multi_card_upgrade_source_changed');
        $value = $this->remainingValue((int)$holder['oid']);
        return ['holderId' => $holderId, 'legacyOrderId' => (int)$holder['oid'],
            'cardName' => trim((string)($holder['card_name'] ?? '')) ?: '会员卡项', 'cardNo' => (string)($holder['card_no'] ?? ''), 'remainingValueCents' => $value];
    }

    private function closeSource(array $source, int $targetOrderId, CashierV3DataScopeContext $dataScope, int $now): void {
        $orderUpdated = Db::name('store_order')->where('id', (int)$source['source_legacy_order_id'])->where('card_upgrade_use_oid', 0)->update(['card_upgrade_use_oid' => $targetOrderId]);
        if ((int)$orderUpdated !== 1) throw self::conflict('multi_card_upgrade_source_close_race');
    }

    private function remainingValue(int $orderId): int {
        // product_id is required by CardSaleActualAmountServices to map a
        // receipt allocation to its historic benefit line.  Falling back to
        // configured cart price here would silently over-credit repriced
        // cards, so retain this exact projection shape.
        $rows = $this->rows(Db::name('store_order_cart_info')->where('oid', $orderId)->where('cart_type', 2)->where('product_type', 6)->where('is_writeoff', 0)->where('write_surplus_times', '>', 0)->field('id,oid,product_id,pay_price,write_times,write_surplus_times,cart_info')->lock(true)->select());
        $actualSaleAmounts = (new CashierV3CardSaleActualAmountServices())->forOrders([$orderId], $rows);
        $total = 0;
        foreach ($rows as $row) {
            $times = (int)($row['write_times'] ?? 0); $remaining = (int)($row['write_surplus_times'] ?? 0);
            if ($times <= 0 || $remaining < 0 || $remaining > $times) throw self::failure('multi_card_upgrade_remaining_invalid');
            $purchaseCents = (int)($actualSaleAmounts[(int)($row['id'] ?? 0)] ?? -1);
            if ($purchaseCents < 0) $purchaseCents = self::decimalCents($row['pay_price'] ?? null);
            $snapshot = json_decode((string)($row['cart_info'] ?? ''), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $remainingMoney = CashierV3EntitlementActualAmountAllocator::remainingForSnapshot(
                number_format($purchaseCents / 100, 2, '.', ''),
                $times,
                $times - $remaining,
                $snapshot
            );
            $total += self::decimalCents($remainingMoney);
        }
        return $total;
    }


    private function sourceIds(array $upgrade): array {
        $ids = array_values(array_unique(array_map('intval', (array)($upgrade['sourceCardHolderIds'] ?? [])))); sort($ids, SORT_NUMERIC);
        if (count($ids) < 1 || count($ids) > 20 || min($ids) <= 0) return [];
        return $ids;
    }
    private function credit(string $id, string $requestId, int $amount, int $target): array { return ['operationId' => $id, 'operationType' => 'card_upgrade', 'checkoutRequestId' => $requestId, 'amountCents' => $amount, 'targetPriceCents' => $target, 'isMultiCardUpgrade' => true]; }
    private function fingerprint(array $upgrade): string { return hash('sha256', self::json($upgrade)); }
    private function rows($rows): array { return is_object($rows) && method_exists($rows, 'toArray') ? array_values($rows->toArray()) : (is_array($rows) ? array_values($rows) : []); }
    private static function decimalCents($value): int { if (!is_string($value) && !is_numeric($value)) throw self::failure('multi_card_upgrade_money_invalid'); $raw = trim((string)$value); if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $raw) !== 1) throw self::failure('multi_card_upgrade_money_invalid'); [$whole,$fraction] = array_pad(explode('.', $raw, 2), 2, ''); return (int)$whole * 100 + (int)str_pad($fraction,2,'0'); }
    private static function money($value): int { if (!is_int($value) && !(is_string($value) && ctype_digit($value))) throw self::failure('multi_card_upgrade_money_invalid'); $value=(int)$value; if ($value < 0) throw self::failure('multi_card_upgrade_money_invalid'); return $value; }
    private static function json(array $value): string { $json=json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); if (!is_string($json)) throw self::failure('multi_card_upgrade_json_invalid'); return $json; }
    private static function conflict(string $reason): CashierV3CommandException { return CashierV3CommandException::versionConflict('原卡权益已经变化，请重新核对后结账。', ['reason'=>$reason]); }
    private static function failure(string $reason): CashierV3CommandException { return new CashierV3CommandException(CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, '多张原卡升级资料不完整，本次结账未提交。', CashierV3ResultCode::STATUS_FAILED, ['reason'=>$reason]); }
}
