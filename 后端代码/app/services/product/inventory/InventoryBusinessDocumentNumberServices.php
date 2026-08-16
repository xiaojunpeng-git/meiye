<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/**
 * Allocates the user-visible inventory document number inside the caller's
 * business transaction. Idempotency keys remain technical identifiers.
 */
final class InventoryBusinessDocumentNumberServices
{
    public const INBOUND = 'INBOUND';
    public const OUTBOUND = 'OUTBOUND';
    public const COUNT = 'COUNT';
    public const REQUEST = 'REQUEST';
    public const TRANSFER = 'TRANSFER';
    public const SALON_ISSUE = 'SALON_ISSUE';
    public const SALON_RETURN = 'SALON_RETURN';
    public const PRESALE_CLAIM = 'PRESALE_CLAIM';

    private const PREFIXES = [
        self::INBOUND => 'RK',
        self::OUTBOUND => 'CK',
        self::COUNT => 'PD',
        self::REQUEST => 'QH',
        self::TRANSFER => 'DB',
        self::SALON_ISSUE => 'YZLY',
        self::SALON_RETURN => 'YZTH',
        self::PRESALE_CLAIM => 'PSLY',
    ];

    /**
     * Returns a new number such as RK2608030001. The sequence is independent
     * per tenant, business function and business date.
     */
    public function next(string $tenantId, string $documentType, string $businessDate, int $now): string
    {
        $tenantId = trim($tenantId);
        $prefix = self::PREFIXES[$documentType] ?? null;
        if ($tenantId === '' || $prefix === null) {
            throw new \InvalidArgumentException('inventory_document_number_type_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if (!$date || $date->format('Y-m-d') !== $businessDate) {
            throw new \InvalidArgumentException('inventory_document_number_date_invalid');
        }

        Db::execute(
            'INSERT INTO `eb_inventory_document_sequence` (`tenant_id`,`document_type`,`business_date`,`current_value`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `current_value`=LAST_INSERT_ID(`current_value`+1),`updated_at`=VALUES(`updated_at`)',
            [$tenantId, $documentType, $businessDate, 1, $now, $now]
        );
        $row = Db::name('inventory_document_sequence')
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('business_date', $businessDate)
            ->lock(true)
            ->find();
        $sequence = (int)($row['current_value'] ?? 0);
        if ($sequence < 1 || $sequence > 9999) {
            throw new \RuntimeException('inventory_document_number_daily_limit_reached');
        }
        return $prefix . $date->format('ymd') . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Manual inbound/outbound have no document header. New commands receive a
     * lightweight immutable mapping; legacy facts deliberately remain unmapped.
     */
    public function manual(string $tenantId, string $sourceType, string $idempotencyKey, string $businessDate, int $now): ?string
    {
        $type = $sourceType === 'manual_inbound' ? self::INBOUND : ($sourceType === 'manual_outbound' ? self::OUTBOUND : null);
        if ($type === null || trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('inventory_document_number_manual_source_invalid');
        }
        $existing = Db::name('inventory_business_document_no')
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $idempotencyKey)
            ->lock(true)
            ->find();
        if ($existing) {
            return (string)$existing['document_no'];
        }

        // Never backfill or mutate pre-existing facts. A retry of a historic
        // technical command continues to expose its original technical source.
        if (Db::name('inventory_batch_movement_fact')
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $idempotencyKey)
            ->lock(true)
            ->find()) {
            return null;
        }

        $number = $this->next($tenantId, $type, $businessDate, $now);
        Db::name('inventory_business_document_no')->insert([
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $idempotencyKey,
            'business_date' => $businessDate,
            'document_no' => $number,
            'created_at' => $now,
        ]);
        return $number;
    }

    public function manualExisting(string $tenantId, string $sourceType, string $idempotencyKey): ?string
    {
        $number = Db::name('inventory_business_document_no')
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)
            ->where('source_id', $idempotencyKey)
            ->value('document_no');
        return $number === null ? null : (string)$number;
    }
}
