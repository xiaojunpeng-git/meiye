<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/**
 * Request documents reserve no quantity. They are a traceable demand signal
 * for a later batch transfer, which owns the inventory-changing transaction.
 */
final class InventoryStockRequestServices
{
    /** Supplier selection is an instance-local directory, never an account data scope. */
    public function suppliersForStore(int $storeId, int $operatorId): array
    {
        $scope = $this->lockScope($storeId, $operatorId);
        return $this->suppliers($scope);
    }

    public function suppliersForHeadquarters(array $adminInfo, int $hqLocationId, string $requestPartyType = 'HQ', int $requestPartyId = 0): array
    {
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $hqLocationId);
        $scope = $this->requestPartyScope($adminInfo, $resolved, $requestPartyType, $requestPartyId);
        return $this->suppliers($scope);
    }

    public function requestPartiesForHeadquarters(array $adminInfo, int $hqLocationId): array
    {
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $hqLocationId);
        $scope = $hq->scope((array)$resolved['location'], (int)($resolved['access']['admin_id'] ?? 0));
        $root = explode('/', trim((string)$scope['organizationPath'], '/'))[0] ?? '';
        $query = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->field('id,name')->order('id asc');
        if (empty($resolved['access']['is_super_admin'])) $query->whereIn('id', array_map('intval', (array)($resolved['access']['store_ids'] ?? [])));
        $list = [['key' => 'HQ:0', 'type' => 'HQ', 'id' => 0, 'name' => '总部仓']];
        foreach ($query->select()->toArray() as $store) {
            $binding = Db::name('organization_store')->where('store_id', (int)$store['id'])->value('org_id');
            $path = $binding ? $this->organizationPath((int)$binding) : '';
            if ($path !== '' && (explode('/', trim($path, '/'))[0] ?? '') === $root) $list[] = ['key' => 'STORE:' . (int)$store['id'], 'type' => 'STORE', 'id' => (int)$store['id'], 'name' => mb_substr((string)$store['name'], 0, 120)];
        }
        return ['list' => $list, 'default' => 'HQ:0'];
    }

    /** The editable requester defaults to the authenticated operator, never a client claim. */
    public function requesterForStore(int $storeId, int $operatorId): array
    {
        $scope = $this->lockScope($storeId, $operatorId);
        $list = Db::name('system_store_staff')->alias('ss')->leftJoin('employee e', 'e.id=ss.employee_id')
            ->where('ss.store_id', $storeId)->where('ss.status', 1)->where('ss.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)
            ->field('ss.id,ss.employee_id,ss.staff_name,e.name')->order('ss.id asc')->select()->toArray();
        $candidates = array_map(static fn (array $row): array => ['key' => 'STORE_STAFF:' . (int)$row['id'], 'type' => 'STORE_STAFF', 'id' => (int)$row['id'], 'employee_id' => (int)$row['employee_id'], 'name' => trim((string)($row['name'] ?: $row['staff_name']))], $list);
        return ['requester_name' => $scope['operatorName'], 'operator_name' => $scope['operatorName'], 'list' => $candidates, 'default_key' => 'STORE_STAFF:' . $operatorId];
    }

    public function requesterForHeadquarters(array $adminInfo, int $hqLocationId, string $requestPartyType = 'HQ', int $requestPartyId = 0): array
    {
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $hqLocationId);
        $scope = $this->requestPartyScope($adminInfo, $resolved, $requestPartyType, $requestPartyId);
        $list = $this->requesterCandidates($scope, $resolved);
        $currentName = mb_substr(trim((string)($adminInfo['real_name'] ?? $adminInfo['name'] ?? $adminInfo['account'] ?? '')), 0, 64);
        $selected = $list[0] ?? ['name' => $currentName];
        foreach ($list as $candidate) if ((int)($candidate['employee_id'] ?? 0) === (int)($adminInfo['employee_id'] ?? 0)) { $selected = $candidate; break; }
        return ['requester_name' => (string)$selected['name'], 'operator_name' => $currentName, 'list' => $list, 'default_key' => (string)($selected['key'] ?? '')];
    }

    public function apply(int $storeId, int $operatorId, array $input): array
    {
        $input += ['request_party_type' => 'STORE', 'request_party_id' => $storeId];
        $command = $this->normalize($input);
        if ($storeId <= 0 || $operatorId <= 0) throw new \InvalidArgumentException('inventory_stock_request_scope_invalid');
        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $scope = $this->lockScope($storeId, $operatorId);
            $location = $this->lockDefaultLocation($scope);
            return $this->applyAtScope($scope, $location, $command);
        });
    }

    /** Platform HQ request boundary. The account and HQ location are resolved before write. */
    public function applyForHeadquarters(array $adminInfo, int $hqLocationId, array $input): array
    {
        $command = $this->normalize($input);
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $hqLocationId);
        $scope = $this->requestPartyScope($adminInfo, $resolved, (string)($command['requestPartyType'] ?? 'HQ'), (int)($command['requestPartyId'] ?? 0));
        return Db::transaction(function () use ($scope, $resolved, $command): array {
            $location = ($scope['requestPartyType'] ?? 'HQ') === 'STORE'
                ? $this->lockDefaultLocation($scope)
                : Db::name('inventory_location')->where('id', (int)$resolved['location']['id'])->lock(true)->find();
            if (!$location || (string)$location['location_status'] !== 'ACTIVE') throw new \RuntimeException('inventory_hq_location_invalid');
            return $this->applyAtScope($scope, (array)$location, $command);
        });
    }

    private function applyAtScope(array $scope, array $location, array $command): array
    {
            $this->assertRequesterSelection($scope, $command['requesterName']);
            $supplier = $this->supplier($scope, $command);
            $existing = Db::name('inventory_stock_request_document')->where('tenant_id', $scope['tenantId'])->where('idempotency_key', $command['idempotencyKey'])->lock(true)->find();
            if ($existing) return $this->replay($existing, $scope, $location, $command);
            $now = $command['recordedAt'];
            $requestNo = (new InventoryBusinessDocumentNumberServices())->next($scope['tenantId'], InventoryBusinessDocumentNumberServices::REQUEST, $command['businessDate'], $now);
            $documentId = Db::name('inventory_stock_request_document')->insertGetId([
                'request_no' => $requestNo,
                'idempotency_key' => $command['idempotencyKey'], 'request_fingerprint' => $command['fingerprint'],
                'tenant_id' => $scope['tenantId'], 'organization_id' => $scope['organizationId'], 'organization_path' => $scope['organizationPath'],
                'location_id' => (int)$location['id'], 'store_id' => $scope['storeId'], 'request_party_type' => $scope['requestPartyType'], 'request_party_id' => $scope['requestPartyId'], 'request_party_name_snapshot' => $scope['requestPartyName'], 'supply_party_type' => $supplier['type'], 'supply_party_id' => $supplier['id'], 'supply_party_name_snapshot' => $supplier['name'], 'requester_name_snapshot' => $command['requesterName'], 'operator_id' => $scope['operatorId'], 'operator_name_snapshot' => $scope['operatorName'],
                'document_status' => 'APPLIED', 'remark' => $command['remark'], 'business_date' => $command['businessDate'],
                'applied_at' => $now, 'recorded_at' => $now,
            ]);
            // ThinkPHP's MySQL driver can return INSERT IDs as strings; this is
            // a numeric domain identifier before it reaches the typed writer.
            $documentId = (int)$documentId;
            $this->insertLines($documentId, $scope, $location, $command['lines'], $now);
            return ['request_id' => $documentId, 'request_no' => $requestNo, 'document_status' => 'APPLIED', 'request_party_type' => $scope['requestPartyType'], 'request_party_id' => $scope['requestPartyId'], 'supply_party_type' => $supplier['type'], 'supply_party_id' => $supplier['id'], 'idempotent' => false];
    }

    private function assertRequesterSelection(array $scope, string $name): void
    {
        $name = trim($name);
        if ($name === '') throw new \InvalidArgumentException('inventory_stock_request_requester_invalid');
        if (($scope['requestPartyType'] ?? 'STORE') === 'STORE') {
            $exists = Db::name('system_store_staff')->alias('ss')->leftJoin('employee e', 'e.id=ss.employee_id')->where('ss.store_id', (int)$scope['storeId'])->where('ss.status', 1)->where('ss.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->where(function ($query) use ($name): void { $query->where('e.name', $name)->whereOr('ss.staff_name', $name); })->value('ss.id');
            if (!$exists) throw new \InvalidArgumentException('inventory_stock_request_requester_invalid');
            return;
        }
        $allowed = array_map(static fn (array $item): string => (string)$item['name'], $this->requesterCandidates($scope, []));
        if (!in_array($name, $allowed, true)) throw new \InvalidArgumentException('inventory_stock_request_requester_invalid');
    }

    public function cancel(int $storeId, int $operatorId, int $requestId): array
    {
        return $this->cancelForStore($storeId, $operatorId, $requestId, [
            'idempotency_key' => 'legacy-request-cancel-' . $requestId,
            'reason' => '旧接口取消',
        ]);
    }

    public function cancelForStore(int $storeId, int $operatorId, int $requestId, array $input): array
    {
        $command = $this->normalizeLifecycle($input, 'CANCEL');
        if ($storeId <= 0 || $operatorId <= 0 || $requestId <= 0) throw new \InvalidArgumentException('inventory_stock_request_cancel_input_invalid');
        return Db::transaction(function () use ($storeId, $operatorId, $requestId, $command): array {
            return $this->transition($this->lockScope($storeId, $operatorId), $requestId, $command);
        });
    }

    public function cancelForHeadquarters(array $adminInfo, int $hqLocationId, int $requestId, array $input): array
    {
        return $this->transitionForHeadquarters($adminInfo, $hqLocationId, $requestId, $input, 'CANCEL');
    }

    public function terminateForStore(int $storeId, int $operatorId, int $requestId, array $input): array
    {
        $command = $this->normalizeLifecycle($input, 'TERMINATE');
        if ($storeId <= 0 || $operatorId <= 0 || $requestId <= 0) throw new \InvalidArgumentException('inventory_stock_request_terminate_input_invalid');
        return Db::transaction(function () use ($storeId, $operatorId, $requestId, $command): array {
            return $this->transition($this->lockScope($storeId, $operatorId), $requestId, $command);
        });
    }

    public function terminateForHeadquarters(array $adminInfo, int $hqLocationId, int $requestId, array $input): array
    {
        return $this->transitionForHeadquarters($adminInfo, $hqLocationId, $requestId, $input, 'TERMINATE');
    }

    private function transitionForHeadquarters(array $adminInfo, int $hqLocationId, int $requestId, array $input, string $operationType): array
    {
        $command = $this->normalizeLifecycle($input, $operationType);
        if ($requestId <= 0) throw new \InvalidArgumentException('inventory_stock_request_lifecycle_input_invalid');
        $hq = new InventoryHqLocationServices();
        $resolved = $hq->writableLocation($adminInfo, $hqLocationId);
        return Db::transaction(function () use ($adminInfo, $resolved, $requestId, $command): array {
            // The platform can now manage a request whose request party is an
            // authorised store. Resolve that subject from the locked document
            // before transition(), rather than treating every platform request
            // as a headquarters request.
            $document = Db::name('inventory_stock_request_document')->where('id', $requestId)->lock(true)->find();
            if (!$document) throw new \RuntimeException('inventory_stock_request_not_found');
            $legacyStore = (int)($document['store_id'] ?? 0) > 0 && (int)($document['request_party_id'] ?? 0) === 0;
            $partyType = $legacyStore ? 'STORE' : strtoupper((string)($document['request_party_type'] ?? 'HQ'));
            $partyId = $legacyStore ? (int)$document['store_id'] : (int)($document['request_party_id'] ?? 0);
            $scope = $this->requestPartyScope($adminInfo, $resolved, $partyType, $partyId);
            return $this->transition($scope, $requestId, $command);
        });
    }

    private function transition(array $scope, int $requestId, array $command): array
    {
        $document = Db::name('inventory_stock_request_document')->where('id', $requestId)->lock(true)->find();
        if (!$document || !$this->ownsRequest($document, $scope)) throw new \RuntimeException('inventory_stock_request_not_found');
        $existing = Db::name('inventory_stock_request_lifecycle_operation')->where('tenant_id', $scope['tenantId'])->where('idempotency_key', $command['idempotencyKey'])->lock(true)->find();
        if ($existing) {
            if ((int)$existing['request_document_id'] !== $requestId || (string)$existing['operation_type'] !== $command['operationType'] || (string)$existing['request_fingerprint'] !== $command['fingerprint']) throw new \RuntimeException('inventory_stock_request_lifecycle_idempotency_conflict');
            return ['request_id' => $requestId, 'document_status' => (string)$existing['next_status'], 'idempotent' => true];
        }
        $previousStatus = (string)$document['document_status'];
        $expectedStatus = $command['operationType'] === 'CANCEL' ? 'APPLIED' : 'PARTIAL';
        $nextStatus = $command['operationType'] === 'CANCEL' ? 'CANCELLED' : 'TERMINATED';
        if ($previousStatus !== $expectedStatus) throw new \RuntimeException($command['operationType'] === 'CANCEL' ? 'inventory_stock_request_cancel_state_invalid' : 'inventory_stock_request_terminate_state_invalid');
        $fulfilledUnits = $this->fulfilledUnitsForDocument($requestId);
        if ($command['operationType'] === 'CANCEL' && $fulfilledUnits !== 0) throw new \RuntimeException('inventory_stock_request_cancel_fulfilled');
        if ($command['operationType'] === 'TERMINATE' && $fulfilledUnits <= 0) throw new \RuntimeException('inventory_stock_request_terminate_state_invalid');
        $now = time();
        $operationId = (int)Db::name('inventory_stock_request_lifecycle_operation')->insertGetId([
            'tenant_id' => $scope['tenantId'], 'request_document_id' => $requestId,
            'idempotency_key' => $command['idempotencyKey'], 'request_fingerprint' => $command['fingerprint'],
            'operation_type' => $command['operationType'], 'previous_status' => $previousStatus, 'next_status' => $nextStatus,
            'fulfilled_quantity_units_snapshot' => $fulfilledUnits, 'reason' => $command['reason'],
            'operator_id' => (int)$scope['operatorId'], 'occurred_at' => $now, 'recorded_at' => $now,
        ]);
        $changes = ['document_status' => $nextStatus];
        if ($nextStatus === 'CANCELLED') $changes += ['cancelled_at' => $now, 'cancelled_by_operator_id' => (int)$scope['operatorId']];
        if (Db::name('inventory_stock_request_document')->where('id', $requestId)->where('document_status', $previousStatus)->update($changes) !== 1) throw new \RuntimeException('inventory_stock_request_changed');
        return ['request_id' => $requestId, 'document_status' => $nextStatus, 'operation_id' => $operationId, 'idempotent' => false];
    }

    private function normalizeLifecycle(array $input, string $operationType): array
    {
        if (array_keys($input) !== ['idempotency_key', 'reason']) throw new \InvalidArgumentException('inventory_stock_request_lifecycle_input_invalid');
        $key = trim((string)$input['idempotency_key']);
        $reason = trim((string)$input['reason']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1 || $reason === '' || mb_strlen($reason) > 500) throw new \InvalidArgumentException('inventory_stock_request_lifecycle_input_invalid');
        return ['idempotencyKey' => $key, 'reason' => $reason, 'operationType' => $operationType, 'fingerprint' => hash('sha256', $operationType . "\n" . $reason)];
    }

    private function ownsRequest(array $document, array $scope): bool
    {
        $legacyStore = (int)($document['store_id'] ?? 0) > 0 && (int)($document['request_party_id'] ?? 0) === 0;
        $type = $legacyStore ? 'STORE' : strtoupper((string)($document['request_party_type'] ?? 'STORE'));
        $partyId = $legacyStore ? (int)$document['store_id'] : (int)($document['request_party_id'] ?? 0);
        return (string)$document['tenant_id'] === (string)$scope['tenantId']
            && (int)$document['location_id'] === (int)($scope['locationId'] ?? $document['location_id'])
            && $type === (string)$scope['requestPartyType']
            && $partyId === (int)$scope['requestPartyId']
            && ($type !== 'STORE' || (int)$document['store_id'] === (int)$scope['storeId']);
    }

    private function fulfilledUnitsForDocument(int $requestId): int
    {
        $fulfilled = (int)Db::name('inventory_stock_request_fulfillment')->where('request_document_id', $requestId)->sum('fulfilled_quantity_units');
        $reversed = (int)Db::name('inventory_stock_request_fulfillment_reversal')->where('request_document_id', $requestId)->sum('reversed_quantity_units');
        return max(0, $fulfilled - $reversed);
    }

    /**
     * APPLIED requests have not changed inventory yet. A revision is only
     * allowed before a transfer references the request, and keeps an audit
     * snapshot instead of silently replacing the original demand signal.
     */
    public function updateApplied(int $storeId, int $operatorId, int $requestId, array $input): array
    {
        if ($requestId <= 0) throw new \InvalidArgumentException('inventory_stock_request_update_input_invalid');
        $input += ['request_party_type' => 'STORE', 'request_party_id' => $storeId];
        $command = $this->normalize($input);
        return Db::transaction(function () use ($storeId, $operatorId, $requestId, $command): array {
            $scope = $this->lockScope($storeId, $operatorId);
            $location = $this->lockDefaultLocation($scope);
            $document = Db::name('inventory_stock_request_document')->where('id', $requestId)->lock(true)->find();
            if (!$document || (string)$document['tenant_id'] !== $scope['tenantId'] || (string)$document['organization_id'] !== $scope['organizationId'] || (string)$document['organization_path'] !== $scope['organizationPath'] || (int)$document['store_id'] !== $scope['storeId'] || (int)$document['location_id'] !== (int)$location['id']) throw new \RuntimeException('inventory_stock_request_not_found');
            if ((string)$document['document_status'] !== 'APPLIED') throw new \RuntimeException('inventory_stock_request_edit_state_invalid');

            $revision = Db::name('inventory_stock_request_revision')->where('document_id', $requestId)->where('idempotency_key', $command['idempotencyKey'])->lock(true)->find();
            if ($revision) {
                if ((string)$revision['next_fingerprint'] !== $command['fingerprint']) throw new \RuntimeException('inventory_stock_request_idempotency_conflict');
                return ['request_id' => $requestId, 'request_no' => (string)$document['request_no'], 'document_status' => 'APPLIED', 'idempotent' => true];
            }

            $transferId = Db::name('inventory_cross_transfer_document')->where('request_document_id', $requestId)->whereNotIn('document_status', ['CANCELLED', 'REVERSED'])->lock(true)->value('id');
            $previousLines = Db::name('inventory_stock_request_line')->where('document_id', $requestId)->order('line_no asc')->lock(true)->select()->toArray();
            if ($transferId || array_filter($previousLines, static fn(array $line): bool => (int)$line['fulfilled_transfer_id'] > 0)) throw new \RuntimeException('inventory_stock_request_edit_fulfillment_started');

            $supplier = $this->supplier($scope, $command);
            $revisionNo = (int)Db::name('inventory_stock_request_revision')->where('document_id', $requestId)->max('revision_no') + 1;
            Db::name('inventory_stock_request_revision')->insert([
                'document_id' => $requestId, 'revision_no' => $revisionNo, 'idempotency_key' => $command['idempotencyKey'],
                'previous_fingerprint' => (string)$document['request_fingerprint'], 'next_fingerprint' => $command['fingerprint'],
                'previous_snapshot_json' => json_encode(['document' => $document, 'lines' => $previousLines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'changed_by_operator_id' => $scope['operatorId'], 'changed_at' => $command['recordedAt'],
            ]);
            Db::name('inventory_stock_request_document')->where('id', $requestId)->update([
                'request_fingerprint' => $command['fingerprint'], 'supply_party_type' => $supplier['type'], 'supply_party_id' => $supplier['id'],
                'supply_party_name_snapshot' => $supplier['name'], 'requester_name_snapshot' => $command['requesterName'], 'remark' => $command['remark'], 'business_date' => $command['businessDate'],
            ]);
            Db::name('inventory_stock_request_line')->where('document_id', $requestId)->delete();
            $this->insertLines($requestId, $scope, $location, $command['lines'], $command['recordedAt']);
            return ['request_id' => $requestId, 'request_no' => (string)$document['request_no'], 'document_status' => 'APPLIED', 'idempotent' => false];
        });
    }

    private function normalize(array $input): array
    {
        $required = ['idempotency_key', 'business_date', 'remark', 'requester_name', 'supply_party_type', 'supply_party_id', 'lines'];
        if (array_diff($required, array_keys($input)) || !is_array($input['lines']) || !$input['lines'] || count($input['lines']) > 100) throw new \InvalidArgumentException('inventory_stock_request_input_invalid');
        $key = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) throw new \InvalidArgumentException('inventory_stock_request_idempotency_invalid');
        $lines = [];
        foreach (array_values($input['lines']) as $index => $line) {
            if (!is_array($line) || array_keys($line) !== ['product_id', 'sku_id', 'sku_unique', 'quantity'] || !is_int($line['product_id']) || !is_int($line['sku_id']) || $line['product_id'] <= 0 || $line['sku_id'] <= 0) throw new \InvalidArgumentException('inventory_stock_request_line_invalid');
            $unique = trim((string)$line['sku_unique']); $quantity = trim((string)$line['quantity']);
            if ($unique === '' || strlen($unique) > 64 || preg_match('/^\d+(?:\.\d{1,4})?$/D', $quantity) !== 1 || (float)$quantity <= 0) throw new \InvalidArgumentException('inventory_stock_request_line_invalid');
            $lines[] = ['index' => $index, 'productId' => $line['product_id'], 'skuId' => $line['sku_id'], 'skuUnique' => $unique, 'quantity' => $quantity];
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string)$input['business_date'])); $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== trim((string)$input['business_date'])) throw new \InvalidArgumentException('inventory_stock_request_date_invalid');
        $supplierType = strtoupper(trim((string)$input['supply_party_type']));
        $supplierId = $input['supply_party_id'];
        if (!in_array($supplierType, ['HQ', 'STORE'], true) || !is_int($supplierId) || ($supplierType === 'HQ' && $supplierId !== 0) || ($supplierType === 'STORE' && $supplierId <= 0)) throw new \InvalidArgumentException('inventory_stock_request_supplier_invalid');
        $requesterName = mb_substr(trim((string)$input['requester_name']), 0, 64);
        if ($requesterName === '') throw new \InvalidArgumentException('inventory_stock_request_requester_invalid');
        $requestPartyType = strtoupper(trim((string)($input['request_party_type'] ?? 'STORE'))); $requestPartyId = (int)($input['request_party_id'] ?? 0);
        if (!in_array($requestPartyType, ['HQ', 'STORE'], true) || ($requestPartyType === 'STORE' && $requestPartyId <= 0) || ($requestPartyType === 'HQ' && $requestPartyId !== 0)) throw new \InvalidArgumentException('inventory_stock_request_party_invalid');
        $fingerprint = hash('sha256', json_encode([$date->format('Y-m-d'), trim((string)$input['remark']), $requesterName, $requestPartyType, $requestPartyId, $supplierType, $supplierId, $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['idempotencyKey' => $key, 'businessDate' => $date->format('Y-m-d'), 'remark' => mb_substr(trim((string)$input['remark']), 0, 500), 'requesterName' => $requesterName, 'requestPartyType' => $requestPartyType, 'requestPartyId' => $requestPartyId, 'supplierType' => $supplierType, 'supplierId' => $supplierId, 'lines' => $lines, 'fingerprint' => $fingerprint, 'recordedAt' => time()];
    }

    private function lockScope(int $storeId, int $operatorId): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)->field('id,name')->lock(true)->find();
        $operator = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->field('id,staff_name')->lock(true)->find();
        $binding = Db::name('organization_store')->where('store_id', $storeId)->field('org_id')->lock(true)->find();
        if (!$store || !$operator || !$binding || (int)$binding['org_id'] <= 0) throw new \RuntimeException('inventory_stock_request_scope_denied');
        $path = []; $seen = []; $current = (int)$binding['org_id']; $name = '';
        for ($depth = 0; $depth < 64; $depth++) { if (isset($seen[$current])) throw new \RuntimeException('inventory_stock_request_organization_invalid'); $seen[$current] = true; $node = Db::name('organization')->where('id', $current)->where('is_del', 0)->field('id,pid,name')->lock(true)->find(); if (!$node || trim((string)$node['name']) === '') throw new \RuntimeException('inventory_stock_request_organization_invalid'); if ($name === '') $name = (string)$node['name']; $path[] = (int)$node['id']; if ((int)$node['pid'] === 0) break; $current = (int)$node['pid']; }
        if (!$path || end($path) !== $current) throw new \RuntimeException('inventory_stock_request_organization_invalid');
        $operatorName = mb_substr(trim((string)$operator['staff_name']), 0, 64);
        if ($operatorName === '') throw new \RuntimeException('inventory_stock_request_operator_name_invalid');
        return ['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'organizationId' => (string)$binding['org_id'], 'organizationPath' => '/' . implode('/', array_reverse($path)) . '/', 'storeId' => $storeId, 'storeName' => mb_substr((string)$store['name'], 0, 120), 'operatorId' => $operatorId, 'operatorName' => $operatorName, 'requestPartyType' => 'STORE', 'requestPartyId' => $storeId, 'requestPartyName' => mb_substr((string)$store['name'], 0, 120)];
    }

    private function headquartersScope(array $scope, array $adminInfo): array
    {
        $name = mb_substr(trim((string)($adminInfo['real_name'] ?? $adminInfo['account'] ?? '')), 0, 64);
        if ($name === '') throw new \RuntimeException('inventory_stock_request_operator_name_invalid');
        $scope['operatorName'] = $name;
        $scope['requestPartyType'] = 'HQ';
        $scope['requestPartyId'] = 0;
        $scope['requestPartyName'] = '总部仓';
        return $scope;
    }

    private function requestPartyScope(array $adminInfo, array $resolved, string $partyType, int $partyId): array
    {
        $hq = new InventoryHqLocationServices();
        $base = $this->headquartersScope($hq->scope((array)$resolved['location'], (int)($resolved['access']['admin_id'] ?? 0)), $adminInfo);
        $base['allowedStoreIds'] = array_values(array_map('intval', (array)($resolved['access']['store_ids'] ?? [])));
        $base['isSuperAdmin'] = !empty($resolved['access']['is_super_admin']);
        $partyType = strtoupper(trim($partyType));
        if ($partyType === 'HQ') return $base;
        if ($partyType !== 'STORE' || $partyId <= 0) throw new \InvalidArgumentException('inventory_stock_request_party_invalid');
        $store = Db::name('system_store')->where('id', $partyId)->where('is_del', 0)->where('is_show', 1)->field('id,name')->lock(true)->find();
        $binding = Db::name('organization_store')->where('store_id', $partyId)->field('org_id')->lock(true)->find();
        if (!$store || !$binding || (!$resolved['access']['is_super_admin'] && !in_array($partyId, array_map('intval', (array)($resolved['access']['store_ids'] ?? [])), true))) throw new \RuntimeException('inventory_stock_request_party_invalid');
        $path = $this->organizationPath((int)$binding['org_id']);
        if (($pathRoot = (explode('/', trim($path, '/'))[0] ?? '')) === '' || $pathRoot !== (explode('/', trim((string)$base['organizationPath'], '/'))[0] ?? '')) throw new \RuntimeException('inventory_stock_request_party_invalid');
        $base['storeId'] = $partyId; $base['storeName'] = mb_substr((string)$store['name'], 0, 120); $base['requestPartyType'] = 'STORE'; $base['requestPartyId'] = $partyId; $base['requestPartyName'] = $base['storeName']; $base['organizationId'] = (string)$binding['org_id']; $base['organizationPath'] = $path;
        return $base;
    }

    private function requesterCandidates(array $scope, array $resolved): array
    {
        if (($scope['requestPartyType'] ?? 'HQ') === 'STORE') {
            $rows = Db::name('system_store_staff')->alias('ss')->leftJoin('employee e', 'e.id=ss.employee_id')->where('ss.store_id', (int)$scope['storeId'])->where('ss.status', 1)->where('ss.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->field('ss.id,ss.employee_id,ss.staff_name,e.name')->order('ss.id asc')->select()->toArray();
            return array_map(static fn (array $row): array => ['key' => 'STORE_STAFF:' . (int)$row['id'], 'type' => 'STORE_STAFF', 'id' => (int)$row['id'], 'employee_id' => (int)$row['employee_id'], 'name' => trim((string)($row['name'] ?: $row['staff_name']))], $rows);
        }
        $pathIds = array_values(array_filter(array_map('intval', explode('/', trim((string)$scope['organizationPath'], '/')))));
        $rootId = (int)($pathIds[0] ?? 0); $orgIds = $rootId > 0 ? [$rootId] : [];
        if ($rootId > 0) {
            $children = [];
            foreach (Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray() as $org) $children[(int)$org['pid']][] = (int)$org['id'];
            for ($i = 0; $i < count($orgIds); $i++) foreach ($children[$orgIds[$i]] ?? [] as $child) if (!in_array($child, $orgIds, true)) $orgIds[] = $child;
        }
        $rows = Db::name('organization_employee')->alias('oe')->leftJoin('employee e', 'e.id=oe.employee_id')->whereIn('oe.org_id', $orgIds)->where('oe.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->field('oe.id,oe.employee_id,e.name')->order('oe.id asc')->select()->toArray();
        $staffIds = array_flip(array_map('intval', Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->column('employee_id')));
        $result = [];
        foreach ($rows as $row) if (!isset($staffIds[(int)$row['employee_id']])) $result[] = ['key' => 'ORG_EMPLOYEE:' . (int)$row['id'], 'type' => 'ORG_EMPLOYEE', 'id' => (int)$row['id'], 'employee_id' => (int)$row['employee_id'], 'name' => (string)$row['name']];
        if (!$result && trim((string)($scope['operatorName'] ?? '')) !== '') $result[] = ['key' => 'ORG_ACCOUNT:0', 'type' => 'ORG_ACCOUNT', 'id' => 0, 'employee_id' => 0, 'name' => (string)$scope['operatorName']];
        return $result;
    }

    private function lockDefaultLocation(array $scope): array
    {
        $rows = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('store_id', $scope['storeId'])->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->limit(2)->lock(true)->select()->toArray();
        if (count($rows) !== 1 || (string)$rows[0]['organization_id'] !== $scope['organizationId'] || (string)$rows[0]['organization_path'] !== $scope['organizationPath'] || (int)$rows[0]['owner_id'] !== $scope['storeId']) throw new \RuntimeException('inventory_stock_request_location_invalid');
        return (array)$rows[0];
    }

    private function supplier(array $scope, array $command): array
    {
        if ($command['supplierType'] === 'HQ') return ['type' => 'HQ', 'id' => 0, 'name' => '总部仓'];
        if ($command['supplierId'] === $scope['storeId']) throw new \RuntimeException('inventory_stock_request_supplier_invalid');
        $store = Db::name('system_store')->where('id', $command['supplierId'])->where('is_del', 0)->where('is_show', 1)->field('id,name')->lock(true)->find();
        if (!$store) throw new \RuntimeException('inventory_stock_request_supplier_invalid');
        return ['type' => 'STORE', 'id' => (int)$store['id'], 'name' => mb_substr((string)$store['name'], 0, 120)];
    }

    private function suppliers(array $scope): array
    {
        $query = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->field('id,name')->order('id asc');
        if (!empty($scope['allowedStoreIds']) && empty($scope['isSuperAdmin'])) $query->whereIn('id', $scope['allowedStoreIds']);
        if (($scope['requestPartyType'] ?? 'STORE') === 'STORE') $query->where('id', '<>', (int)$scope['storeId']);
        // 总部仓既是平台端默认请货方的合法供货方，也是门店请货时的合法供货方。
        // 供货目录必须始终显式返回 HQ，不能只在门店请货场景返回。
        $list = [['party_type' => 'HQ', 'party_id' => 0, 'name' => '总部仓']];
        foreach ($query->select()->toArray() as $store) {
            $binding = Db::name('organization_store')->where('store_id', (int)$store['id'])->value('org_id');
            if (!$binding || !$this->sameRoot((string)$scope['organizationPath'], $this->organizationPath((int)$binding))) continue;
            $list[] = ['party_type' => 'STORE', 'party_id' => (int)$store['id'], 'name' => mb_substr((string)$store['name'], 0, 120)];
        }
        return ['list' => $list];
    }

    private function organizationPath(int $organizationId): string
    {
        $ids = []; $seen = []; $current = $organizationId;
        for ($depth = 0; $depth < 64; $depth++) { if (isset($seen[$current])) break; $seen[$current] = true; $node = Db::name('organization')->where('id', $current)->where('is_del', 0)->field('id,pid')->lock(true)->find(); if (!$node) break; $ids[] = (int)$node['id']; if ((int)$node['pid'] === 0) return '/' . implode('/', array_reverse($ids)) . '/'; $current = (int)$node['pid']; }
        throw new \RuntimeException('inventory_stock_request_supplier_invalid');
    }

    private function sameRoot(string $left, string $right): bool
    {
        return (explode('/', trim($left, '/'))[0] ?? '') !== '' && (explode('/', trim($left, '/'))[0] ?? '') === (explode('/', trim($right, '/'))[0] ?? '');
    }

    private function lockCatalogSku(array $scope, array $line): array
    {
        $query = Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')
            ->where('p.id', $line['productId'])->where('p.is_del', 0)->where('p.is_inventory', 1)
            ->where('a.id', $line['skuId'])->where('a.unique', $line['skuUnique'])->where('a.type', 0);
        if (($scope['requestPartyType'] ?? 'STORE') === 'HQ') $query->where('p.type', 0)->where('p.relation_id', 0);
        else $query->where('p.type', 1)->where('p.relation_id', (int)$scope['storeId']);
        $catalog = $query->field('p.id product_id,p.store_name product_name,p.code product_code,p.salon_stock_enabled,a.id sku_id,a.unique sku_unique,a.suk sku_name,a.bar_code barcode,a.stock_unit')->lock(true)->find();
        if (!$catalog) throw new \RuntimeException('inventory_stock_request_sku_not_found');
        $catalog['quantity_scale'] = (int)$catalog['salon_stock_enabled'] === 1 ? 2 : 0;
        return (array)$catalog;
    }

    private function lockReferenceCost(array $location, array $catalog): array
    {
        $stock = Db::name('inventory_stock')->where('tenant_id', (string)$location['tenant_id'])->where('location_id', (int)$location['id'])->where('consumable_product_id', (int)$catalog['product_id'])->where('sku_id', (int)$catalog['sku_id'])->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find();
        if (!$stock || (int)$stock['estimated_unit_cost_cents'] <= 0) return ['cents' => 0, 'status' => 'UNKNOWN'];
        return ['cents' => (int)$stock['estimated_unit_cost_cents'], 'status' => 'SNAPSHOT'];
    }

    private function insertLines(int $documentId, array $scope, array $location, array $lines, int $recordedAt): void
    {
        foreach ($lines as $line) {
            $catalog = $this->lockCatalogSku($scope, $line);
            $reference = $this->lockReferenceCost($location, $catalog);
            Db::name('inventory_stock_request_line')->insert([
                'document_id' => $documentId, 'line_no' => $line['index'], 'product_id' => (int)$catalog['product_id'], 'sku_id' => (int)$catalog['sku_id'], 'sku_unique' => $catalog['sku_unique'],
                'product_name_snapshot' => mb_substr((string)$catalog['product_name'], 0, 120), 'sku_name_snapshot' => mb_substr((string)$catalog['sku_name'], 0, 120),
                'product_code_snapshot' => mb_substr((string)$catalog['product_code'], 0, 64), 'barcode_snapshot' => mb_substr((string)$catalog['barcode'], 0, 64),
                'stock_unit_snapshot' => mb_substr((string)$catalog['stock_unit'], 0, 32), 'quantity_scale' => (int)$catalog['quantity_scale'],
                'requested_quantity_units' => InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], (int)$catalog['quantity_scale']),
                'reference_unit_cost_cents' => $reference['cents'], 'reference_cost_status' => $reference['status'], 'created_at' => $recordedAt,
            ]);
        }
    }

    private function replay(array $document, array $scope, array $location, array $command): array
    {
        $legacyStoreParty = (int)$document['store_id'] > 0 && (int)($document['request_party_id'] ?? 0) === 0;
        $requestPartyType = $legacyStoreParty ? 'STORE' : strtoupper((string)($document['request_party_type'] ?? ''));
        $requestPartyId = $legacyStoreParty ? (int)$document['store_id'] : (int)($document['request_party_id'] ?? 0);
        if ((string)$document['request_fingerprint'] !== $command['fingerprint'] || (string)$document['organization_id'] !== $scope['organizationId'] || (string)$document['organization_path'] !== $scope['organizationPath'] || (int)$document['location_id'] !== (int)$location['id'] || (int)$document['store_id'] !== $scope['storeId'] || $requestPartyType !== (string)$scope['requestPartyType'] || $requestPartyId !== (int)$scope['requestPartyId']) throw new \RuntimeException('inventory_stock_request_idempotency_conflict');
        return ['request_id' => (int)$document['id'], 'request_no' => (string)$document['request_no'], 'document_status' => (string)$document['document_status'], 'request_party_type' => (string)$scope['requestPartyType'], 'request_party_id' => (int)$scope['requestPartyId'], 'idempotent' => true];
    }
}
