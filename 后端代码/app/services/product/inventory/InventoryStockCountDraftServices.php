<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/** Stores an editable count snapshot separately from confirmed stock and movement facts. */
final class InventoryStockCountDraftServices
{
    /** Only the creating active store operator may resume a draft; warehouse scope is resolved server-side. */
    public function save(int $storeId, int $operatorId, array $input, array $sessionInfo = []): array
    {
        $scope = $this->scope($storeId, $operatorId, $sessionInfo);
        return $this->saveAtScope($scope, $input);
    }

    /** 总部草稿绑定真实总部仓和平台创建人；绝不伪造门店身份或产生库存事实。 */
    public function saveForHeadquarters(array $adminInfo, int $locationId, array $input): array
    {
        return $this->saveAtScope($this->headquartersScope($adminInfo, $locationId), $input);
    }

    private function saveAtScope(array $scope, array $input): array
    {
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
                'operator_type' => $scope['operator_type'],
                'document_status' => 'DRAFT', 'version' => 1, 'confirmed_document_id' => 0,
                'created_at' => $now,
            ], $fields));
            return ['draft_id' => $id, 'version' => 1, 'document_status' => 'DRAFT'];
        });
    }

    /** Reads only the saved form fields; no mutable stock balance is presented as a settled fact. */
    public function detail(int $storeId, int $operatorId, int $id, array $sessionInfo = []): array
    {
        $scope = $this->scope($storeId, $operatorId, $sessionInfo);
        return $this->detailAtScope($scope, $id);
    }

    /** 总部续编只读取当前平台创建人的同仓草稿。 */
    public function detailForHeadquarters(array $adminInfo, int $locationId, int $id): array
    {
        return $this->detailAtScope($this->headquartersScope($adminInfo, $locationId), $id);
    }

    private function detailAtScope(array $scope, int $id): array
    {
        $draft = $this->scopedDraft($scope, $id);
        if ((string)$draft['document_status'] !== 'DRAFT') throw new \RuntimeException('inventory_stock_count_draft_changed');
        return ['draft_id' => (int)$draft['id'], 'version' => (int)$draft['version'],
            'business_date' => (string)$draft['business_date'], 'remark' => (string)$draft['remark'],
            'lines' => json_decode((string)$draft['lines_json'], true, 512, JSON_THROW_ON_ERROR)];
    }

    /** Permanently removes only an unconfirmed creator-owned draft; settled documents and facts are never deleted. */
    public function delete(int $storeId, int $operatorId, int $id, int $expectedVersion, array $sessionInfo = []): array
    {
        $scope = $this->scope($storeId, $operatorId, $sessionInfo);
        return $this->deleteAtScope($scope, $id, $expectedVersion);
    }

    /** 删除仍限创建人、仓库和当前版本，不触及已确认单据。 */
    public function deleteForHeadquarters(array $adminInfo, int $locationId, int $id, int $expectedVersion): array
    {
        return $this->deleteAtScope($this->headquartersScope($adminInfo, $locationId), $id, $expectedVersion);
    }

    private function deleteAtScope(array $scope, int $id, int $expectedVersion): array
    {
        if ($expectedVersion <= 0) throw new \InvalidArgumentException('inventory_stock_count_draft_changed');
        return Db::transaction(function () use ($scope, $id, $expectedVersion): array {
            $draft = $this->scopedDraft($scope, $id, true);
            if ((string)$draft['document_status'] !== 'DRAFT' || (int)$draft['version'] !== $expectedVersion) {
                throw new \RuntimeException('inventory_stock_count_draft_changed');
            }
            // The lock and version predicate stop a simultaneous save/confirm from turning deletion into a lost update.
            $deleted = Db::name('inventory_stock_count_draft')->where('id', $id)
                ->where('tenant_id', $scope['tenant_id'])->where('store_id', $scope['store_id'])
                ->where('location_id', $scope['location_id'])->where('operator_id', $scope['operator_id'])
                ->where('operator_type', $scope['operator_type'])
                ->where('document_status', 'DRAFT')->where('version', $expectedVersion)->delete();
            if ($deleted !== 1) throw new \RuntimeException('inventory_stock_count_draft_changed');
            return ['draft_id' => $id, 'deleted' => true];
        });
    }

    /** Called inside the confirmation transaction so a draft cannot be confirmed twice. */
    public function lockForConfirmation(array $scope, int $draftId, int $version): array
    {
        $draft = $this->scopedDraft([
            'tenant_id' => $scope['tenantId'], 'store_id' => $scope['storeId'],
            'location_id' => $scope['locationId'], 'operator_id' => $scope['operatorId'],
            'operator_type' => $scope['operatorType'],
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

    private function scope(int $storeId, int $operatorId, array $sessionInfo): array
    {
        $actor = (new InventoryStockCountActorServices())->resolve($storeId, $operatorId, $sessionInfo);
        $locations = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('store_id', $storeId)->where('location_type', 'STORE')->where('is_default', 1)
            ->where('location_status', 'ACTIVE')->limit(2)->select()->toArray();
        if (count($locations) !== 1) throw new \RuntimeException('inventory_stock_count_location_invalid');
        return ['tenant_id' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'store_id' => $storeId,
            'location_id' => (int)$locations[0]['id'], 'operator_id' => $actor['id'],
            'operator_type' => $actor['type']];
    }

    /** 使用现有平台仓库管理授权；ID 相同的门店人员与平台账号也不能串读。 */
    private function headquartersScope(array $adminInfo, int $locationId): array
    {
        $resolved = (new InventoryHqLocationServices())->writableLocation($adminInfo, $locationId);
        return ['tenant_id' => (string)$resolved['location']['tenant_id'], 'store_id' => 0,
            'location_id' => (int)$resolved['location']['id'], 'operator_id' => (int)$resolved['access']['admin_id'],
            'operator_type' => 'PLATFORM_ADMIN'];
    }

    private function scopedDraft(array $scope, int $id, bool $lock = false): array
    {
        if ($id <= 0) throw new \InvalidArgumentException('inventory_stock_count_draft_invalid');
        $query = Db::name('inventory_stock_count_draft')->where('id', $id)
            ->where('tenant_id', $scope['tenant_id'])->where('store_id', $scope['store_id'])
            ->where('location_id', $scope['location_id'])->where('operator_id', $scope['operator_id'])
            ->where('operator_type', $scope['operator_type']);
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
                // 单位随盘点草稿保存为当时的 SKU 快照，恢复页面时不得根据名称猜测或串用其他规格单位。
                'stock_unit' => mb_substr(trim((string)($line['stock_unit'] ?? '')), 0, 32),
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
