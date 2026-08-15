<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use think\facade\Db;

/**
 * Freezes every successful bookkeeping payment on each completed sale line.
 *
 * The report reads this fact instead of repeating an order-level payment on
 * every item row. Each payment fact is allocated independently, so mixed
 * payment methods and cent remainders remain traceable and total-conserving.
 */
final class StoreReportPaymentSaleAllocationFactServices
{
    public const TABLE = 'cashier_v3_payment_sale_allocation_fact';
    public const CONTRACT_VERSION = 'cashier-v3-payment-sale-allocation-v1';

    /** @return array{inserted:int,replayed:int} */
    public function persistInTx(CashierV3CheckoutFactPlanV1 $plan): array
    {
        CashierV3TransactionGuard::assertInTransaction('storeReportPaymentSaleAllocation');
        $sales = array_values((array)($plan->rows()['sale'] ?? []));
        if ($sales === []) {
            return ['inserted' => 0, 'replayed' => 0];
        }

        $context = $plan->context();
        $inserted = 0;
        $replayed = 0;
        foreach ((array)($plan->rows()['payment'] ?? []) as $payment) {
            $allocations = self::allocatePaymentToSales((int)$payment['amount_cents'], $sales);
            foreach ($sales as $sale) {
                $result = $this->persistAllocation(
                    $context,
                    $payment,
                    $sale,
                    (int)($allocations[(string)$sale['fact_id']] ?? 0)
                );
                $result === 'inserted' ? $inserted++ : $replayed++;
            }
        }
        return compact('inserted', 'replayed');
    }

    /**
     * Records a sales-debt repayment against the original sale facts.  The
     * repayment payment fact has its own repayment document id, while reports
     * need the amount on the original sale line that incurred the debt.
     *
     * @param array<string,mixed> $context Original sales-order snapshots.
     * @param array<int,array<string,mixed>> $paymentFacts Successful repayment payment facts.
     * @param array<int,array<string,mixed>> $sales Original sale facts with this repayment's line allocation base.
     * @return array{inserted:int,replayed:int}
     */
    public function persistDebtRepaymentInTx(array $context, array $paymentFacts, array $sales): array
    {
        CashierV3TransactionGuard::assertInTransaction('storeReportDebtRepaymentPaymentAllocation');
        if ($paymentFacts === [] || $sales === []) return ['inserted' => 0, 'replayed' => 0];

        $weights = [];
        foreach ($sales as $sale) {
            $factId = trim((string)($sale['fact_id'] ?? ''));
            $base = (int)($sale['allocation_base_amount_cents'] ?? 0);
            if ($factId === '' || $base <= 0 || isset($weights[$factId])) {
                throw new \InvalidArgumentException('debt_repayment_payment_allocation_sale_invalid');
            }
            $weights[$factId] = ['fact_id' => $factId, 'sale_amount_cents' => $base];
        }

        $inserted = 0;
        $replayed = 0;
        foreach ($paymentFacts as $payment) {
            $paymentFactId = trim((string)($payment['fact_id'] ?? ''));
            $amount = (int)($payment['amount_cents'] ?? 0);
            if ($paymentFactId === '' || $amount <= 0) {
                throw new \InvalidArgumentException('debt_repayment_payment_allocation_payment_invalid');
            }
            $allocations = self::allocatePaymentToSales($amount, array_values($weights));
            foreach ($sales as $sale) {
                $factId = (string)$sale['fact_id'];
                $result = $this->persistAllocation($context, $payment, $sale, (int)($allocations[$factId] ?? 0));
                $result === 'inserted' ? $inserted++ : $replayed++;
            }
        }
        return compact('inserted', 'replayed');
    }

    /**
     * Largest-remainder allocation in cents. The sign comes from the payment
     * fact, allowing an immutable reversal to produce matching negative rows.
     *
     * @param array<int,array> $sales
     * @return array<string,int> sale fact id => allocated cents
     */
    public static function allocatePaymentToSales(int $paymentAmountCents, array $sales): array
    {
        if ($sales === []) {
            return [];
        }
        return StoreReportPartnerCategorySnapshotServices::allocateAmountBySaleFact($paymentAmountCents, $sales);
    }

    private function persistAllocation(array $context, array $payment, array $sale, int $amountCents): string
    {
        $paymentFactId = trim((string)$payment['fact_id']);
        $saleFactId = trim((string)$sale['fact_id']);
        $naturalKey = 'payment-sale-allocation:' . $paymentFactId . ':' . $saleFactId;
        $reversalOf = '';
        if ((string)$payment['fact_direction'] === CashierV3CheckoutFactPlanV1::DIRECTION_REVERSAL) {
            $reversalOf = $this->originalAllocationId(
                (string)$context['tenant_id'],
                (string)$payment['reversal_of'],
                (string)$sale['source_line_id']
            );
        }
        $row = [
            'allocation_fact_id' => hash('sha256', $naturalKey),
            'natural_key' => $naturalKey,
            'contract_version' => self::CONTRACT_VERSION,
            'fact_version' => 1,
            'status' => (string)$payment['status'],
            'fact_direction' => (string)$payment['fact_direction'],
            'reversal_of' => $reversalOf,
            'tenant_id' => (string)$context['tenant_id'],
            'organization_id' => (string)$context['organization_id'],
            'store_id' => (int)$context['store_id'],
            'member_id' => (int)$context['member_id'],
            'order_id' => (string)$context['order_id'],
            'order_no_snapshot' => (string)$context['order_no_snapshot'],
            'payment_fact_id' => $paymentFactId,
            'payment_method' => (string)$payment['payment_method'],
            'payment_amount_cents' => (int)$payment['amount_cents'],
            'sale_fact_id' => $saleFactId,
            'source_line_id' => (string)$sale['source_line_id'],
            'sale_amount_cents' => (int)$sale['sale_amount_cents'],
            'debt_amount_cents' => (int)$sale['debt_amount_cents'],
            'allocation_base_amount_cents' => array_key_exists('allocation_base_amount_cents', $sale)
                ? (int)$sale['allocation_base_amount_cents']
                : abs((int)$sale['sale_amount_cents']) - abs((int)$sale['debt_amount_cents']),
            'amount_cents' => $amountCents,
            'business_date' => (string)$context['business_date'],
            'occurred_at' => (int)$context['occurred_at'],
            'settled_at' => (int)$context['settled_at'],
            'recorded_at' => (int)$context['recorded_at'],
            'business_event_no' => (string)$context['business_event_no'],
            'checkout_request_id' => (string)$context['checkout_request_id'],
            'command_idempotency_key' => (string)$context['checkout_request_id'],
            'add_time' => (int)$context['recorded_at'],
            'update_time' => (int)$context['recorded_at'],
        ];
        $row['command_idempotency_key'] = (string)$payment['command_idempotency_key'];
        $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            if ((int)Db::name(self::TABLE)->insert($row) !== 1) {
                throw new \RuntimeException('payment_sale_allocation_insert_failed');
            }
            return 'inserted';
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            $existing = Db::name(self::TABLE)->where('tenant_id', $row['tenant_id'])
                ->where('natural_key', $naturalKey)->lock(true)->find();
            if (!$existing) {
                throw new \LogicException('payment_sale_allocation_identity_conflict');
            }
            foreach ($row as $column => $value) {
                if (!array_key_exists($column, $existing) || (string)$existing[$column] !== (string)$value) {
                    throw new \LogicException('payment_sale_allocation_replay_conflict');
                }
            }
            return 'replayed';
        }
    }

    private function originalAllocationId(string $tenantId, string $paymentFactId, string $sourceLineId): string
    {
        $row = Db::name(self::TABLE)->where('tenant_id', $tenantId)
            ->where('payment_fact_id', $paymentFactId)->where('source_line_id', $sourceLineId)
            ->where('status', CashierV3CheckoutFactPlanV1::STATUS_EFFECTIVE)->lock(true)
            ->field('allocation_fact_id')->find();
        $id = trim((string)($row['allocation_fact_id'] ?? ''));
        if ($id === '') {
            throw new \LogicException('payment_sale_allocation_reversal_target_missing');
        }
        return $id;
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        for ($cursor = $exception; $cursor instanceof \Throwable; $cursor = $cursor->getPrevious()) {
            $message = strtolower($cursor->getMessage());
            if ((int)$cursor->getCode() === 1062 || strpos($message, '1062') !== false || strpos($message, 'duplicate entry') !== false) {
                return true;
            }
        }
        return false;
    }
}
