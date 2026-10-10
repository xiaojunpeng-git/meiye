<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use think\facade\Db;

/** Immutable manual-outbound attributes; stock quantities remain authoritative in batch facts. */
final class InventoryOutboundHeaderServices
{
    public const TYPES = ['过期退货', '试用出库', '报废出库', '其他出库', '对内出库', '对外出库'];

    /** Old callers omit attributes; do not guess a category for their historic documents. */
    public function normalize(array $input): array
    {
        $type = $input['outbound_type'] ?? '';
        $recipient = $input['outbound_recipient'] ?? '';
        if (!is_string($type) || ($type !== '' && !in_array($type, self::TYPES, true))) {
            throw new \InvalidArgumentException('inventory_manual_outbound_type_invalid');
        }
        if (!is_string($recipient) || mb_strlen(trim($recipient)) > 100) {
            throw new \InvalidArgumentException('inventory_manual_outbound_recipient_invalid');
        }
        return ['outbound_type' => $type, 'outbound_recipient' => trim($recipient)];
    }

    /** Called only inside the authorized, locked stock transaction; failure rolls back the header too. */
    public function insert(array $scope, array $location, array $command): void
    {
        Db::name('inventory_manual_outbound_header')->insert([
            'tenant_id' => $scope['tenantId'], 'source_id' => $command['idempotencyKey'],
            'store_id' => $scope['storeId'], 'location_id' => $location['id'],
            'outbound_type' => $command['header']['outbound_type'],
            'outbound_recipient' => $command['header']['outbound_recipient'],
            'remark' => $command['remark'], 'created_at' => $command['recordedAt'],
        ]);
    }

    /** Reusing a command must never silently change a recipient/type or deduct stock twice. */
    public function assertReplay(array $scope, array $location, array $command): void
    {
        $header = Db::name('inventory_manual_outbound_header')->where('tenant_id', $scope['tenantId'])
            ->where('source_id', $command['idempotencyKey'])->lock(true)->find();
        if (!$header) {
            if ($command['header']['outbound_type'] === '' && $command['header']['outbound_recipient'] === '') return;
        } elseif ((int)$header['store_id'] === $scope['storeId'] && (int)$header['location_id'] === (int)$location['id']
            && $header['outbound_type'] === $command['header']['outbound_type']
            && $header['outbound_recipient'] === $command['header']['outbound_recipient']
            && $header['remark'] === $command['remark']) return;
        throw new \RuntimeException('inventory_manual_outbound_idempotency_conflict');
    }

    /** Enrich already scoped documents in one query. Unrecorded historical types remain 手工出库. */
    public function apply(array $rows, string $tenantId, array $locationIds): array
    {
        $ids = [];
        foreach ($rows as $row) if (($row['source_type'] ?? '') === 'manual_outbound') $ids[] = (string)$row['source_id'];
        $headers = $ids && $locationIds ? Db::name('inventory_manual_outbound_header')->where('tenant_id', $tenantId)
            ->whereIn('location_id', $locationIds)->whereIn('source_id', array_unique($ids))->select()->toArray() : [];
        $index = array_column($headers, null, 'source_id');
        foreach ($rows as &$row) {
            $header = ($row['source_type'] ?? '') === 'manual_outbound' ? ($index[(string)$row['source_id']] ?? []) : [];
            $row['outbound_recipient'] = (string)($header['outbound_recipient'] ?? $row['outbound_recipient'] ?? '');
            $row['remark'] = (string)($header['remark'] ?? $row['remark'] ?? '');
            if (!empty($header['outbound_type'])) $row['order_type_name'] = $header['outbound_type'];
        }
        unset($row);
        return $rows;
    }
}
