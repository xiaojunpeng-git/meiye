<?php
declare(strict_types=1);

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
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
        array $salespeopleByCheckoutLine,
        string $commandIdempotencyKey,
        int $occurredAt,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $dataScope
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

        $allocations = self::exactAllocations($amount, $salesPlan->lines());
        $debtOrderRecordId = $v3OrderRecordId;
        $debtOrderNo = (string)$header['order_no'];
        // Completed historical V3 debts retain their D3 number. New debts use
        // the customer-visible QK daily sequence and are stable on retries.
        $debtNo = (string)Db::name('cashier_v3_debt_authority')
            ->where('tenant_id', (string)$header['tenant_id'])
            ->where('checkout_request_id', (string)$header['checkout_request_id'])
            ->lock(true)
            ->value('debt_no');
        if ($debtNo === '') {
            $debtNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
                (string)$header['tenant_id'],
                CashierV3BusinessDocumentNumberServices::DEBT,
                'checkout_debt',
                (string)$header['checkout_request_id'],
                (string)$header['business_date'],
                $occurredAt
            );
        }
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
        $expectedPersonnel = [];
        foreach ($salesPlan->lines() as $line) {
            $lineId = (string)($line['order_line_id'] ?? '');
            $checkoutLineId = (string)($line['checkout_line_id'] ?? '');
            $lineDebt = (int)($allocations[$lineId] ?? 0);
            if ($lineDebt <= 0) {
                continue;
            }
            if ($checkoutLineId === '' || !array_key_exists($checkoutLineId, $salespeopleByCheckoutLine)) {
                throw self::failure('checkout_debt_personnel_snapshot_missing');
            }
            $salespeople = self::salespeopleSnapshot((array)$salespeopleByCheckoutLine[$checkoutLineId]);
            $expectedItems[] = [
                'debt_id' => $debtId,
                'order_id' => $debtOrderRecordId,
                'cart_info_id' => 0,
                'product_id' => (int)$line['item_id'],
                'product_type' => self::legacyProductType((string)$line['item_type']),
                'product_name' => (string)$line['item_name_snapshot'],
                'cart_num' => (int)$line['quantity'],
                'debt_amount' => self::money($lineDebt),
                'repaid_debt' => '0.00',
            ];
            $expectedPersonnel[] = [
                'order_line_id' => $lineId,
                'checkout_line_id' => $checkoutLineId,
                'line_debt_amount_cents' => $lineDebt,
                'salespeople_snapshot_json' => self::encodeJson($salespeople),
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
        $storedItems = self::rows(Db::name('store_debt_item')
            ->where('debt_id', $debtId)->order('id asc')->lock(true)->select());
        if (count($storedItems) !== count($expectedPersonnel)) {
            throw self::failure('checkout_debt_personnel_item_count_mismatch');
        }
        foreach ($expectedPersonnel as $index => $personnel) {
            $debtItemId = (int)($storedItems[$index]['id'] ?? 0);
            $row = array_merge($personnel, [
                'debt_item_id' => $debtItemId,
                'debt_id' => $debtId,
                'tenant_id' => (string)$header['tenant_id'],
                'store_id' => (int)$header['store_id'],
                'member_id' => (int)$header['member_id'],
            ]);
            $row['snapshot_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES));
            $storedPersonnel = Db::name('cashier_v3_debt_item_personnel_authority')
                ->where('debt_item_id', $debtItemId)->lock(true)->find();
            if ($storedPersonnel) {
                self::assertRow($row, (array)$storedPersonnel, 'checkout_debt_personnel_replay_conflict');
                $replayed = true;
                continue;
            }
            if ((int)Db::name('cashier_v3_debt_item_personnel_authority')->insert(array_merge($row, [
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ])) !== 1) {
                throw self::failure('checkout_debt_personnel_insert_failed');
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
            'personnelSnapshotCount' => count($expectedPersonnel),
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
    public static function exactAllocations(int $amount, array $lines): array
    {
        if ($amount <= 0) {
            throw self::failure('checkout_debt_allocation_total_invalid');
        }
        $allocated = 0;
        $result = [];
        foreach ($lines as $line) {
            $lineId = trim((string)($line['order_line_id'] ?? ''));
            $lineAmount = (int)($line['sale_amount_cents'] ?? 0);
            $lineDebt = (int)($line['debt_amount_cents'] ?? 0);
            if ($lineId === '' || $lineAmount <= 0 || $lineDebt < 0
                || $lineDebt > $lineAmount || isset($result[$lineId])) {
                throw self::failure('checkout_debt_allocation_line_invalid');
            }
            if ($allocated > PHP_INT_MAX - $lineDebt) {
                throw self::failure('checkout_debt_allocation_total_invalid');
            }
            $result[$lineId] = $lineDebt;
            $allocated += $lineDebt;
        }
        if ($allocated !== $amount) {
            throw self::failure('checkout_debt_allocation_total_invalid');
        }
        return $result;
    }

    private static function salespeopleSnapshot(array $rows): array
    {
        $out = [];
        $weightByGroup = [];
        foreach (array_values($rows) as $index => $row) {
            $normalized = [
                'employeeId' => (int)($row['employeeId'] ?? 0),
                'name' => trim((string)($row['name'] ?? '')),
                'employeeTypeCodeSnapshot' => trim((string)($row['employeeTypeCodeSnapshot'] ?? '')),
                'employeeTypeAuthorityVersion' => (int)($row['employeeTypeAuthorityVersion'] ?? 0),
                'allocationWeight' => (int)($row['allocationWeight'] ?? 0),
                'sequence' => (int)($row['sequence'] ?? ($index + 1)),
            ];
            $positionId = max(0, (int)($row['positionId'] ?? $row['position_id'] ?? 0));
            $performanceIndependent = !empty($row['performanceIndependent']) || !empty($row['performance_independent']);
            $groupKey = $performanceIndependent && $positionId > 0 ? 'independent:' . $positionId : 'normal';
            if ($normalized['employeeId'] <= 0 || $normalized['name'] === ''
                || !in_array($normalized['employeeTypeCodeSnapshot'], ['internal', 'partner', 'outsourced'], true)
                || $normalized['employeeTypeAuthorityVersion'] <= 0 || $normalized['allocationWeight'] <= 0
                || $normalized['sequence'] <= 0) {
                throw self::failure('checkout_debt_personnel_snapshot_invalid');
            }
            if ($positionId > 0) {
                $normalized['positionId'] = $positionId;
                $normalized['positionName'] = trim((string)($row['positionName'] ?? $row['position_name'] ?? ''));
            }
            if ($performanceIndependent && $positionId > 0) {
                $normalized['performanceIndependent'] = true;
                $normalized['allocationGroupKey'] = $groupKey;
            }
            $weightByGroup[$groupKey] = ($weightByGroup[$groupKey] ?? 0) + $normalized['allocationWeight'];
            $out[] = $normalized;
        }
        if ($out && array_filter($weightByGroup, static fn (int $sum): bool => $sum !== 100)) {
            throw self::failure('checkout_debt_personnel_weight_invalid');
        }
        return $out;
    }

    private static function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw self::failure('checkout_debt_personnel_json_invalid');
        }
        return $json;
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
