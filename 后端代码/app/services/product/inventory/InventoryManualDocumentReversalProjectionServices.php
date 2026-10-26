<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** Shared status projection for store, headquarters, list and detail readers. */
final class InventoryManualDocumentReversalProjectionServices
{
    /** @return array<string,array<string,mixed>> keyed by the original source_id */
    public function settledIndex(string $tenantId, string $sourceType, array $sourceIds, array $locationIds): array
    {
        $sourceIds = array_values(array_unique(array_filter(array_map('strval', $sourceIds), static function (string $id): bool {
            return trim($id) !== '' && strlen($id) <= 96;
        })));
        $locationIds = array_values(array_unique(array_filter(array_map('intval', $locationIds), static function (int $id): bool {
            return $id > 0;
        })));
        if ($tenantId === '' || !in_array($sourceType, [InventoryManualDocumentReversalServices::INBOUND, InventoryManualDocumentReversalServices::OUTBOUND], true)
            || !$sourceIds || !$locationIds) {
            return [];
        }
        $rows = Db::name('inventory_manual_document_reversal')->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType)->whereIn('source_id', $sourceIds)->whereIn('location_id', $locationIds)
            ->where('reversal_status', 'SETTLED')
            ->field('id,source_id,reason,operator_type,operator_id,business_date,occurred_at,settled_at,recorded_at')
            ->select()->toArray();
        $index = [];
        foreach ($rows as $row) $index[(string)$row['source_id']] = (array)$row;
        return $index;
    }

    public function apply(array $rows, string $tenantId, string $sourceType, array $locationIds): array
    {
        $index = $this->settledIndex($tenantId, $sourceType, array_column($rows, 'source_id'), $locationIds);
        foreach ($rows as &$row) {
            $reversal = $index[(string)($row['source_id'] ?? '')] ?? null;
            $row['status_name'] = $reversal ? '已作废' : '已完成';
            $row['can_void'] = $reversal === null;
            $row['void_reason'] = $reversal ? (string)$reversal['reason'] : '';
            $row['voided_at'] = $reversal ? (int)$reversal['settled_at'] : 0;
        }
        unset($row);
        return $rows;
    }
}
