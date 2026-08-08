<?php
declare(strict_types=1);

namespace app\services\cashier\v3;

use think\facade\Db;

/**
 * Allocates customer-visible cashier documents within the successful business
 * transaction. Technical ids and idempotency keys deliberately remain opaque.
 */
final class CashierV3BusinessDocumentNumberServices
{
    public const SALES_ORDER = 'sales_order';
    public const RECHARGE = 'recharge';
    public const REFUND = 'refund';
    public const SERVICE = 'service';
    public const DEBT_REPAYMENT = 'debt_repayment';
    public const GIFT = 'gift';
    public const CARD_OPERATION = 'card_operation';
    public const RESERVATION = 'reservation';
    public const DEBT = 'debt';

    private const PREFIXES = [
        self::SALES_ORDER => 'XS',
        self::RECHARGE => 'CZ',
        self::REFUND => 'TH',
        self::SERVICE => 'FW',
        self::DEBT_REPAYMENT => 'BJ',
        self::GIFT => 'ZS',
        self::CARD_OPERATION => 'CK',
        self::RESERVATION => 'YY',
        self::DEBT => 'QK',
    ];

    /** Customer-visible sales receipts are XS + YYMMDD + five-digit sequence. */
    public static function isSalesOrderNo(string $value): bool
    {
        return preg_match('/^XS[0-9]{11}$/D', trim($value)) === 1;
    }

    public function allocateForSourceInTx(
        string $tenantId,
        string $documentType,
        string $sourceType,
        string $sourceId,
        string $businessDate,
        int $now
    ): string {
        CashierV3TransactionGuard::assertInTransaction('cashierBusinessDocumentNumber.allocate');
        $tenantId = trim($tenantId);
        $sourceType = trim($sourceType);
        $sourceId = trim($sourceId);
        $prefix = self::PREFIXES[$documentType] ?? null;
        if ($tenantId === '' || $prefix === null || $sourceType === '' || $sourceId === '') {
            throw new \InvalidArgumentException('cashier_business_document_number_identity_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if (!$date || $date->format('Y-m-d') !== $businessDate) {
            throw new \InvalidArgumentException('cashier_business_document_number_date_invalid');
        }

        $existing = Db::name('cashier_v3_business_document_no')
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lock(true)
            ->find();
        if ($existing) {
            if ((string)$existing['document_type'] !== $documentType) {
                throw new \RuntimeException('cashier_business_document_number_source_type_conflict');
            }
            return (string)$existing['document_no'];
        }

        Db::execute(
            'INSERT INTO `eb_cashier_v3_business_document_sequence` (`tenant_id`,`document_type`,`business_date`,`current_value`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `current_value`=LAST_INSERT_ID(`current_value`+1),`updated_at`=VALUES(`updated_at`)',
            [$tenantId, $documentType, $businessDate, 1, $now, $now]
        );
        $sequence = (int)Db::name('cashier_v3_business_document_sequence')
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('business_date', $businessDate)
            ->lock(true)
            ->value('current_value');
        $sequenceWidth = in_array($documentType, [self::RESERVATION, self::DEBT], true) ? 4 : 5;
        $sequenceLimit = (10 ** $sequenceWidth) - 1;
        if ($sequence < 1 || $sequence > $sequenceLimit) {
            throw new \RuntimeException('cashier_business_document_number_daily_limit_reached');
        }
        // 服务记录是门店工作人员与会员共同使用的短单号：FW + MMDD + 五位流水。
        // 其他业务单据保持既有 YYMMDD 编号口径，避免改变已确认的销售、充值等规则。
        $datePart = $documentType === self::SERVICE ? $date->format('md') : $date->format('ymd');
        $number = $prefix . $datePart . str_pad((string)$sequence, $sequenceWidth, '0', STR_PAD_LEFT);
        Db::name('cashier_v3_business_document_no')->insert([
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'document_type' => $documentType,
            'business_date' => $businessDate,
            'document_no' => $number,
            'created_at' => $now,
        ]);
        return $number;
    }

    /**
     * An old V3 checkout can be retried after this upgrade. Its immutable
     * header wins and is deliberately not backfilled into the new mapping.
     */
    public function salesOrderNoForCheckoutInTx(
        string $tenantId,
        string $checkoutRequestId,
        string $businessDate,
        int $now
    ): string {
        CashierV3TransactionGuard::assertInTransaction('cashierBusinessDocumentNumber.salesOrder');
        $existing = Db::name('cashier_v3_sales_order')
            ->where('tenant_id', trim($tenantId))
            ->where('checkout_request_id', trim($checkoutRequestId))
            ->lock(true)
            ->value('order_no');
        if (is_string($existing) && trim($existing) !== '') {
            return trim($existing);
        }
        return $this->allocateForSourceInTx(
            $tenantId,
            self::SALES_ORDER,
            'checkout_request',
            $checkoutRequestId,
            $businessDate,
            $now
        );
    }

    /** Same rule for immutable card-operation audit rows created before upgrade. */
    public function cardOperationNoForCommandInTx(
        string $tenantId,
        string $idempotencyKey,
        string $businessDate,
        int $now
    ): string {
        CashierV3TransactionGuard::assertInTransaction('cashierBusinessDocumentNumber.cardOperation');
        $existing = Db::name('cashier_v3_card_operation')
            ->where('tenant_id', trim($tenantId))
            ->where('command_idempotency_key', trim($idempotencyKey))
            ->lock(true)
            ->value('operation_no');
        if (is_string($existing) && trim($existing) !== '') {
            return trim($existing);
        }
        return $this->allocateForSourceInTx(
            $tenantId,
            self::CARD_OPERATION,
            'card_operation',
            $idempotencyKey,
            $businessDate,
            $now
        );
    }
}
