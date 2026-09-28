<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Stores an editable count snapshot separately from confirmed stock and movement facts. */
final class InventoryStockCountDraftServices
{
    /** Only the creating active store operator may resume a draft; warehouse scope is resolved server-side. */
    public function save(int $storeId, int $operatorId, array $input): array
    {
        $scope = $this->scope($storeId, $operatorId);
        $date = trim((string)($input['business_date'] ?? ''));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('inventory_stock_count_date_invalid');
        $lines = $this->lines($input['lines'] ?? null);
        $remark = mb_substr(trim((string)($input['remark'] ?? '')), 0, 500);
        $encoded = json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $id = (int)($input['draft_id'] ?? 0);
        $version = (int)($input['expected_version'] ?? 0);
        $now = time();
        return Db::transaction(function () use ($scope, $id, $version, $date, $remark, $lines, $encoded, $now): array {
            // 列表的“差异项”只统计已填写且与加载时账面数不同的行；草稿正文仍保留全部选中 SKU。
            $differenceCount = count(array_filter($lines, static fn(array $line): bool =>
                $line['counted_quantity'] !== '' && (float)$line['counted_quantity'] !== (float)$line['book_quantity']));
            $fields = ['business_date' => $date, 'remark' => $remark, 'lines_json' => $encoded,
                'line_count' => $differenceCount, 'updated_at' => $now];
            if ($id > 0) {
                $draft = $this->scopedDraft($scope, $id, true);
                if ((string)$draft['document_status'] !== 'DRAFT' || (int)$draft['version'] !== $version) {
                    throw new \RuntimeException('inventory_stock_count_draft_changed');
                }
                // A version guard prevents a second tab from silently overwriting entered quantities.
                $updated = Db::name('inventory_stock_count_draft')->where('id', $id)->where('version', $version)
                    ->where('document_status', 'DRAFT')->update(array_merge($fields, ['version' => $version + 1]));
                if ($updated !== 1) throw new \RuntimeException('inventory_stock_count_draft_changed');
                return ['draft_id' => $id, 'version' => $version + 1, 'document_status' => 'DRAFT'];
            }
            if ($version !== 0) throw new \InvalidArgumentException('inventory_stock_count_draft_version_invalid');
            $id = (int)Db::name('inventory_stock_count_draft')->insertGetId(array_merge([
                'tenant_id' => $scope['tenant_id'], 'store_id' => $scope['store_id'],
                'location_id' => $scope['location_id'], 'operator_id' => $scope['operator_id'],
                'document_status' => 'DRAFT', 'version' => 1, 'confirmed_document_id' => 0,
                'created_at' => $now,
            ], $fields));
            return ['draft_id' => $id, 'version' => 1, 'document_status' => 'DRAFT'];
        });
    }

    /** Reads only the saved form fields; no mutable stock balance is presented as a settled fact. */
    public function detail(int $storeId, int $operatorId, int $id): array
    {
        $scope = $this->scope($storeId, $operatorId);
        $draft = $this->scopedDraft($scope, $id);
        if ((string)$draft['document_status'] !== 'DRAFT') throw new \RuntimeException('inventory_stock_count_draft_changed');
        return ['draft_id' => (int)$draft['id'], 'version' => (int)$draft['version'],
            'business_date' => (string)$draft['business_date'], 'remark' => (string)$draft['remark'],
            'lines' => json_decode((string)$draft['lines_json'], true, 512, JSON_THROW_ON_ERROR)];
    }

    /** Called inside the confirmation transaction so a draft cannot be confirmed twice. */
    public function lockForConfirmation(array $scope, int $draftId, int $version): array
    {
        $draft = $this->scopedDraft([
            'tenant_id' => $scope['tenantId'], 'store_id' => $scope['storeId'],
            'location_id' => $scope['locationId'], 'operator_id' => $scope['operatorId'],
        ], $draftId, true);
        if ((string)$draft['document_status'] === 'CONFIRMED' && (int)$draft['version'] === $version + 1) return $draft;
        if ((string)$draft['document_status'] !== 'DRAFT' || (int)$draft['version'] !== $version) {
            throw new \RuntimeException('inventory_stock_count_draft_changed');
        }
        return $draft;
    }

    public function markConfirmed(int $draftId, int $documentId, int $version): void
    {
        $updated = Db::name('inventory_stock_count_draft')->where('id', $draftId)->where('version', $version)
            ->where('document_status', 'DRAFT')->update([
                'document_status' => 'CONFIRMED', 'confirmed_document_id' => $documentId,
                'version' => $version + 1, 'updated_at' => time(),
            ]);
        if ($updated !== 1) throw new \RuntimeException('inventory_stock_count_draft_changed');
    }

    private function scope(int $storeId, int $operatorId): array
    {
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)
            ->where('status', 1)->where('is_del', 0)->find();
        if (!$staff) throw new \RuntimeException('inventory_stock_count_query_scope_denied');
        $locations = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)
            ->where('location_status', 'ACTIVE')->limit(2)->select()->toArray();
        if (count($locations) !== 1) throw new \RuntimeException('inventory_stock_count_location_invalid');
        return ['tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'store_id' => $storeId,
            'location_id' => (int)$locations[0]['id'], 'operator_id' => $operatorId];
    }

    private function scopedDraft(array $scope, int $id, bool $lock = false): array
    {
        if ($id <= 0) throw new \InvalidArgumentException('inventory_stock_count_draft_invalid');
        $query = Db::name('inventory_stock_count_draft')->where('id', $id)
            ->where('tenant_id', $scope['tenant_id'])->where('store_id', $scope['store_id'])
            ->where('location_id', $scope['location_id'])->where('operator_id', $scope['operator_id']);
        $draft = ($lock ? $query->lock(true) : $query)->find();
        if (!$draft) throw new \RuntimeException('inventory_stock_count_draft_missing');
        return (array)$draft;
    }

    /** A draft keeps every selected SKU (including unchanged rows), but never accepts arbitrary client columns. */
    private function lines($input): array
    {
        if (!is_array($input) || !$input) throw new \InvalidArgumentException('inventory_stock_count_draft_lines_invalid');
        $result = []; $seen = [];
        foreach (array_values($input) as $line) {
            if (!is_array($line)) throw new \InvalidArgumentException('inventory_stock_count_draft_lines_invalid');
            $productId = (int)($line['product_id'] ?? 0);
            $skuId = (int)($line['sku_id'] ?? 0);
            $unique = trim((string)($line['sku_unique'] ?? ''));
            $book = trim((string)($line['book_quantity'] ?? ''));
            $counted = trim((string)($line['counted_quantity'] ?? ''));
            $identity = $productId . ':' . $skuId . ':' . $unique;
            if ($productId <= 0 || $skuId <= 0 || $unique === '' || isset($seen[$identity])
                || !preg_match('/^\d+(?:\.\d{1,4})?$/D', $book)
                || ($counted !== '' && !preg_match('/^\d+(?:\.\d{1,4})?$/D', $counted))) {
                throw new \InvalidArgumentException('inventory_stock_count_draft_lines_invalid');
            }
            $seen[$identity] = true;
            $cost = trim((string)($line['surplus_unit_cost'] ?? ''));
            if ($cost !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/D', $cost)) throw new \InvalidArgumentException('inventory_stock_count_cost_invalid');
            $result[] = [
                'product_id' => $productId, 'sku_id' => $skuId, 'sku_unique' => $unique,
                'product_name' => mb_substr((string)($line['product_name'] ?? ''), 0, 191),
                'sku_name' => mb_substr((string)($line['sku_name'] ?? ''), 0, 191),
                'barcode' => mb_substr((string)($line['barcode'] ?? ''), 0, 96),
                'book_quantity' => $book, 'counted_quantity' => $counted,
                'surplus_batch_no' => mb_substr(trim((string)($line['surplus_batch_no'] ?? '')), 0, 64),
                'surplus_unit_cost' => $cost,
                'surplus_manufactured_date' => (string)($line['surplus_manufactured_date'] ?? ''),
                'surplus_expire_date' => (string)($line['surplus_expire_date'] ?? ''),
            ];
        }
        return $result;
    }
}
