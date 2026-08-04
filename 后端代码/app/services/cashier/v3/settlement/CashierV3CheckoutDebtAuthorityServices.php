<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use think\facade\Db;

/** Transaction-only authority writer for debt created by a successful checkout. */
final class CashierV3CheckoutDebtAuthorityServices
{
    public const CONTRACT_VERSION = 'cashier-v3-checkout-debt-authority-v1';
    public const POLICY_VERSION = 1;

    public function persistInTx(
        array $lockedRequest,
        CashierV3SalesOrderPlanV1 $salesPlan,
        array $salesResult,
        string $commandIdempotencyKey,
        int $occurredAt,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope,
        array $cardPurchaseResult = []
    ): array {
        CashierV3TransactionGuard::assertInTransaction('checkoutDebtAuthority.persistInTx');
        $amount = (int)($lockedRequest['debt_amount_cents'] ?? 0);
        if ($amount === 0) {
            return $this->emptyResult();
        }
        $header = $salesPlan->header();
        $authorityKey = self::authorityKey($operator->storeId());
        if ($amount < 0
            || (int)($lockedRequest['member_id'] ?? 0) <= 0
            || !hash_equals($authorityKey, (string)($lockedRequest['debt_authority_key'] ?? ''))
            || (int)($lockedRequest['debt_policy_version'] ?? 0) !== self::POLICY_VERSION
            || (string)($lockedRequest['tenant_id'] ?? '') !== $dataScope->tenantId()
            || (int)($lockedRequest['store_id'] ?? 0) !== $operator->storeId()
            || (string)($salesResult['orderId'] ?? '') !== (string)($header['order_id'] ?? '')) {
            throw self::failure('checkout_debt_authority_mismatch');
        }
        $v3OrderRecordId = (int)Db::name('cashier_v3_sales_order')
            ->where('tenant_id', (string)$header['tenant_id'])
            ->where('order_id', (string)$header['order_id'])
            ->lock(true)
            ->value('id');
        if ($v3OrderRecordId <= 0) {
            throw self::failure('checkout_debt_sales_order_missing');
        }

        $allocations = self::allocate($amount, $salesPlan->lines());
        $legacyOrderId = 0;
        $legacyOrderNo = '';
        $legacyCartInfoId = 0;
        $receipts = array_values((array)($cardPurchaseResult['receipts'] ?? []));
        if ($receipts) {
            if (count($receipts) !== 1 || count($salesPlan->lines()) !== 1) {
                throw self::failure('checkout_debt_multi_card_legacy_projection_not_supported');
            }
            $legacyOrderId = (int)($receipts[0]['legacyOrderId'] ?? 0);
            $legacyCartInfoId = (int)($receipts[0]['baseCartId'] ?? 0);
            $legacy = Db::name('store_order')
                ->where('id', $legacyOrderId)
                ->where('uid', (int)$header['member_id'])
                ->where('store_id', (int)$header['store_id'])
                ->lock(true)
                ->field('id,order_id,debt_amount')
                ->find();
            if (!$legacy
                || $legacyCartInfoId <= 0
                || self::cents((string)($legacy['debt_amount'] ?? '')) !== $amount) {
                throw self::failure('checkout_debt_legacy_card_projection_mismatch');
            }
            $legacyOrderNo = (string)$legacy['order_id'];
        }
        $debtOrderRecordId = $legacyOrderId > 0 ? $legacyOrderId : $v3OrderRecordId;
        $debtOrderNo = $legacyOrderNo !== '' ? $legacyOrderNo : (string)$header['order_no'];
        $debtNo = 'D3' . strtoupper(substr(hash('sha256', implode('|', [
            (string)$header['tenant_id'],
            (string)$header['checkout_request_id'],
            (string)$header['order_id'],
        ])), 0, 30));
        $expected = [
            'debt_no' => $debtNo,
            'order_id' => $debtOrderRecordId,
            'order_sn' => $debtOrderNo,
            'uid' => (int)$header['member_id'],
            'store_id' => (int)$header['store_id'],
            'staff_id' => (int)$header['operator_id'],
            'total_debt' => self::money($amount),
            'repaid_debt' => '0.00',
            'status' => 0,
            'remark' => '收银V3结账欠款',
        ];
        $existing = Db::name('store_debt')->where('debt_no', $debtNo)->lock(true)->find();
        $replayed = false;
        if ($existing) {
            self::assertRow($expected, (array)$existing, 'checkout_debt_replay_conflict');
            $debtId = (int)$existing['id'];
            $replayed = true;
        } else {
            $debtId = (int)Db::name('store_debt')->insertGetId(array_merge($expected, [
                'add_time' => $occurredAt,
                'update_time' => $occurredAt,
            ]));
            if ($debtId <= 0) {
                throw self::failure('checkout_debt_insert_failed');
            }
        }

        $expectedItems = [];
        foreach ($salesPlan->lines() as $line) {
            $lineId = (string)($line['order_line_id'] ?? '');
            $lineDebt = (int)($allocations[$lineId] ?? 0);
            if ($lineDebt <= 0) {
                continue;
            }
            $expectedItems[] = [
                'debt_id' => $debtId,
                'order_id' => $debtOrderRecordId,
                'cart_info_id' => $legacyCartInfoId,
                'product_id' => (int)$line['item_id'],
                'product_type' => self::legacyProductType((string)$line['item_type']),
                'product_name' => (string)$line['item_name_snapshot'],
                'cart_num' => (int)$line['quantity'],
                'debt_amount' => self::money($lineDebt),
                'repaid_debt' => '0.00',
            ];
        }
        $storedItems = self::rows(Db::name('store_debt_item')
            ->where('debt_id', $debtId)
            ->order('id asc')
            ->lock(true)
            ->select());
        if ($storedItems) {
            if (count($storedItems) !== count($expectedItems)) {
                throw self::failure('checkout_debt_item_replay_conflict');
            }
            foreach ($expectedItems as $index => $expectedItem) {
                self::assertRow(
                    $expectedItem,
                    (array)$storedItems[$index],
                    'checkout_debt_item_replay_conflict'
                );
            }
            $replayed = true;
        } elseif ($expectedItems) {
            $rows = [];
            foreach ($expectedItems as $item) {
                $rows[] = array_merge($item, [
                    'add_time' => $occurredAt,
                    'update_time' => $occurredAt,
                ]);
            }
            if ((int)Db::name('store_debt_item')->insertAll($rows) !== count($rows)) {
                throw self::failure('checkout_debt_item_insert_failed');
            }
        }

        $authorityRow = [
            'debt_id' => $debtId,
            'debt_no' => $debtNo,
            'tenant_id' => (string)$header['tenant_id'],
            'store_id' => (int)$header['store_id'],
            'member_id' => (int)$header['member_id'],
            'sales_order_record_id' => $v3OrderRecordId,
            'sales_order_id' => (string)$header['order_id'],
            'sales_order_no_snapshot' => (string)$header['order_no'],
            'checkout_request_id' => (string)$header['checkout_request_id'],
            'checkout_command_idempotency_key' => $commandIdempotencyKey,
            'policy_version' => self::POLICY_VERSION,
        ];
        $authorityRow['authority_fingerprint'] = hash(
            'sha256',
            json_encode($authorityRow, JSON_UNESCAPED_SLASHES)
        );
        $storedAuthority = Db::name('cashier_v3_debt_authority')
            ->where('debt_id', $debtId)
            ->lock(true)
            ->find();
        if ($storedAuthority) {
            self::assertRow(
                $authorityRow,
                (array)$storedAuthority,
                'checkout_debt_v3_authority_replay_conflict'
            );
            $replayed = true;
        } else {
            $insertedAuthority = (int)Db::name('cashier_v3_debt_authority')->insert(
                array_merge($authorityRow, [
                    'created_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ])
            );
            if ($insertedAuthority !== 1) {
                throw self::failure('checkout_debt_v3_authority_insert_failed');
            }
        }

        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'authorityKey' => $authorityKey,
            'policyVersion' => self::POLICY_VERSION,
            'debtId' => $debtId,
            'debtNo' => $debtNo,
            'amountCents' => $amount,
            'orderLineAllocations' => $allocations,
            'replayed' => $replayed,
            'commandIdempotencyKey' => $commandIdempotencyKey,
            'salesOrderId' => (string)$header['order_id'],
        ];
    }

    public static function authorityKey(int $storeId): string
    {
        if ($storeId <= 0) {
            throw self::failure('checkout_debt_store_invalid');
        }
        return 'checkout-debt-policy:store:' . $storeId;
    }

    /** @return array<string,int> */
    public static function allocate(int $amount, array $lines): array
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += (int)($line['sale_amount_cents'] ?? 0);
        }
        if ($amount <= 0 || $total <= 0 || $amount > $total) {
            throw self::failure('checkout_debt_allocation_total_invalid');
        }
        $result = [];
        $remainders = [];
        $allocated = 0;
        foreach (array_values($lines) as $index => $line) {
            $lineId = trim((string)($line['order_line_id'] ?? ''));
            $lineAmount = (int)($line['sale_amount_cents'] ?? 0);
            if ($lineId === '' || $lineAmount <= 0 || isset($result[$lineId])) {
                throw self::failure('checkout_debt_allocation_line_invalid');
            }
            $product = bcmul((string)$amount, (string)$lineAmount, 0);
            $share = (int)bcdiv($product, (string)$total, 0);
            $remainder = (int)bcmod($product, (string)$total);
            $result[$lineId] = $share;
            $remainders[] = ['lineId' => $lineId, 'remainder' => $remainder, 'index' => $index];
            $allocated += $share;
        }
        usort($remainders, static function (array $left, array $right): int {
            return $right['remainder'] <=> $left['remainder']
                ?: $left['index'] <=> $right['index'];
        });
        for ($remaining = $amount - $allocated, $index = 0; $remaining > 0; $remaining--, $index++) {
            $result[$remainders[$index]['lineId']]++;
        }
        return $result;
    }

    private static function legacyProductType(string $itemType): int
    {
        if ($itemType === 'product') {
            return 0;
        }
        if ($itemType === 'card') {
            return 5;
        }
        if ($itemType === 'project') {
            return 6;
        }
        throw self::failure('checkout_debt_item_type_invalid');
    }

    private function emptyResult(): array
    {
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'authorityKey' => '',
            'policyVersion' => 0,
            'debtId' => 0,
            'debtNo' => '',
            'amountCents' => 0,
            'orderLineAllocations' => [],
            'replayed' => false,
            'commandIdempotencyKey' => '',
        ];
    }

    private static function assertRow(array $expected, array $actual, string $reason): void
    {
        foreach ($expected as $column => $value) {
            if (!array_key_exists($column, $actual)
                || (string)$actual[$column] !== (string)$value) {
                throw self::failure($reason, ['column' => $column]);
            }
        }
    }

    private static function rows($result): array
    {
        if (is_array($result)) {
            return array_values($result);
        }
        if (is_object($result) && method_exists($result, 'toArray')) {
            return array_values($result->toArray());
        }
        return [];
    }

    private static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function cents(string $money): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/D', $money) !== 1) {
            throw self::failure('checkout_debt_legacy_money_invalid');
        }
        [$yuan, $fraction] = explode('.', $money, 2);
        return (int)$yuan * 100 + (int)$fraction;
    }

    private static function failure(string $reason, array $detail = []): CashierV3CommandException
    {
        return new CashierV3CommandException(
            CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE,
            '欠款结账资料不完整，请刷新后重试。',
            CashierV3ResultCode::STATUS_FAILED,
            array_merge(['reason' => $reason], $detail)
        );
    }
}
