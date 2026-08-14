<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/**
 * Cross-store transfer authority. A draft records a product request; dispatch
 * consumes the source batches, and receipt is the only operation that adds to
 * the receiving store. The legacy same-store warehouse transfer is excluded.
 */
final class InventoryCrossSubjectTransferServices
{
    public function listForStore(int $storeId, int $operatorId, string $keyword, int $page = 1, int $limit = 20, bool $canViewCost = false, string $dateFrom = '', string $dateTo = ''): array
    {
        $scope = $this->scope($storeId, $operatorId, false);
        $query = Db::name('inventory_cross_transfer_document')->alias('d')
            ->leftJoin('inventory_cross_transfer_line l', 'l.document_id=d.id')
            ->where('d.tenant_id', $scope['tenantId'])
            ->where(function ($query) use ($scope): void {
                $query->where(function ($inner) use ($scope): void { $inner->where('d.from_party_type', 'STORE')->where('d.from_party_id', $scope['storeId']); })
                    ->whereOr(function ($inner) use ($scope): void { $inner->where('d.to_party_type', 'STORE')->where('d.to_party_id', $scope['storeId']); });
            });
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('d.business_date', [$dateFrom, $dateTo]);
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($inner) use ($like): void {
                $inner->whereLike('d.transfer_no', $like)->whereOr('d.remark', 'like', $like);
            });
        }
        $count = (int)(clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.transfer_no order_sn,d.from_party_type,d.from_party_id,d.from_party_name_snapshot from_party_name,d.to_party_type,d.to_party_id,d.to_party_name_snapshot to_party_name,d.document_status,d.document_status status_name,d.initiator_store_id,d.business_date transfer_date,d.recorded_at add_time,d.recorded_at operation_at,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.quantity_scale*0)+0) quantity_placeholder')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        foreach ($list as &$row) {
            $amount = (int)Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', (int)$row['id'])
                ->sum(Db::raw('(quantity_units * unit_cost_cents) / POW(10, quantity_scale)'));
            $row['transfer_amount_cents'] = $canViewCost ? $amount : null;
            $row['status_name'] = $this->statusName((string)$row['status_name']);
            $row['can_dispatch'] = (string)$row['document_status'] === 'DRAFT' && (int)$row['from_party_id'] === $scope['storeId'];
            $row['can_receive'] = (string)$row['document_status'] === 'DISPATCHED' && (int)$row['to_party_id'] === $scope['storeId'];
            $row['can_cancel'] = (string)$row['document_status'] === 'DRAFT' && (int)$row['initiator_store_id'] === $scope['storeId'];
            $row['can_reverse'] = in_array((string)$row['document_status'], ['DISPATCHED', 'RECEIVED'], true) && (string)$row['from_party_type'] === 'STORE' && (int)$row['from_party_id'] === $scope['storeId'];
        }
        unset($row);
        return ['count' => $count, 'list' => $list];
    }

    public function detailForStore(int $storeId, int $operatorId, int $documentId, bool $canViewCost = false): array
    {
        $scope = $this->scope($storeId, $operatorId, false);
        $document = $this->lockedStoreDocument($scope, $documentId, false);
        $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->order('line_no asc')->select()->toArray();
        foreach ($lines as &$line) {
            $allocations = Db::name('inventory_cross_transfer_batch_allocation')->where('line_id', (int)$line['id'])->order('id asc')->select()->toArray();
            if (!$canViewCost) foreach ($allocations as &$allocation) $allocation['unit_cost_cents'] = null;
            unset($allocation);
            $line['allocations'] = $allocations;
        }
        unset($line);
        $document['status_name'] = $this->statusName((string)$document['document_status']);
        $document['lines'] = $lines;
        return $document;
    }

    public function counterpartiesForStore(int $storeId, int $operatorId): array
    {
        $scope = $this->scope($storeId, $operatorId, false);
        $root = explode('/', trim((string)$scope['organizationPath'], '/'))[0] ?? '';
        $rows = Db::name('system_store')->where('id', '<>', $scope['storeId'])->where('is_del', 0)->where('is_show', 1)->field('id,name')->order('id asc')->select()->toArray();
        // A store must share the authenticated store's root organization. This
        // protects the target selector from becoming a cross-tenant directory.
        $allowed = [];
        foreach ($rows as $row) {
            $binding = Db::name('organization_store')->where('store_id', (int)$row['id'])->value('org_id');
            $path = $binding ? $this->organizationPath((int)$binding) : '';
            if ($path !== '' && (explode('/', trim($path, '/'))[0] ?? '') === $root) $allowed[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'party_type' => 'STORE'];
        }
        $headquarters = $this->headquartersForScope($scope);
        if ($headquarters !== null) {
            $allowed[] = [
                'id' => 0,
                'name' => (string)$headquarters['partyName'],
                'party_type' => 'HQ',
                'location_id' => (int)$headquarters['location']['id'],
            ];
        }
        return ['list' => $allowed];
    }

    public function incomingRequestsForStore(int $storeId, int $operatorId, bool $canViewCost = false): array
    {
        $scope = $this->scope($storeId, $operatorId, false);
        $documents = Db::name('inventory_stock_request_document')->alias('d')->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', $scope['tenantId'])->where('d.supply_party_type', 'STORE')->where('d.supply_party_id', $scope['storeId'])
            ->whereIn('d.document_status', ['APPLIED', 'PARTIAL'])->field('d.id,d.request_no,d.store_id,d.request_party_type,d.request_party_id,d.request_party_name_snapshot,s.name request_store_name,d.business_date,d.document_status')->order('d.id desc')->select()->toArray();
        foreach ($documents as &$document) {
            if ((int)($document['request_party_id'] ?? 0) === 0 && (int)($document['store_id'] ?? 0) > 0) {
                $document['request_party_type'] = 'STORE';
                $document['request_party_id'] = (int)$document['store_id'];
                $document['request_party_name_snapshot'] = (string)($document['request_store_name'] ?? '');
            }
            try {
                $target = strtoupper((string)($document['request_party_type'] ?? 'STORE')) === 'HQ'
                    ? $this->headquartersForScope($scope)
                    : $this->scope((int)($document['request_party_id'] ?? $document['store_id'] ?? 0), 0, false);
            } catch (\RuntimeException $exception) {
                $document['lines'] = [];
                continue;
            }
            if ($target === null || !$this->sameOrganizationRoot($scope, $target)) {
                $document['lines'] = [];
                continue;
            }
            $lines = Db::name('inventory_stock_request_line')->where('document_id', (int)$document['id'])->order('line_no asc')->select()->toArray();
            $document['lines'] = $this->incomingRequestSourceLines($lines, $target, $scope, $canViewCost);
        }
        unset($document);
        return ['list' => array_values(array_filter($documents, static fn (array $document): bool => (bool)$document['lines']))];
    }

    public function createDraftForStore(int $storeId, int $operatorId, array $input): array
    {
        $command = $this->normalize($input);
        return Db::transaction(function () use ($storeId, $operatorId, $command): array {
            $sourceScope = $this->scope($storeId, $operatorId, true);
            return $this->createDraft($sourceScope, $this->targetForStore($sourceScope, $command), $command);
        });
    }

    /** Platform-only: an HQ warehouse can dispatch to a store. */
    public function createDraftForHeadquarters(array $adminInfo, int $hqLocationId, array $input): array
    {
        $command = $this->normalize($input);
        if (!in_array($command['targetPartyType'], ['STORE', 'HQ'], true)) throw new \InvalidArgumentException('inventory_cross_transfer_target_invalid');
        $hqScope = $this->headquartersScope($adminInfo, $hqLocationId);
        $sourceScope = $hqScope;
        if ($command['sourcePartyType'] === 'STORE') {
            $sourceStoreId = (int)$command['sourceStoreId'];
            if ($sourceStoreId <= 0 || (!$hqScope['isSuperAdmin'] && !in_array($sourceStoreId, $hqScope['allowedStoreIds'], true))) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
            $sourceScope = $this->scope($sourceStoreId, (int)$hqScope['operatorId'], false);
            $hqRoot = explode('/', trim((string)$hqScope['organizationPath'], '/'))[0] ?? '';
            $sourceRoot = explode('/', trim((string)$sourceScope['organizationPath'], '/'))[0] ?? '';
            if ($hqRoot === '' || $sourceRoot === '' || $hqRoot !== $sourceRoot) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
        }
        return Db::transaction(function () use ($sourceScope, $hqScope, $command): array {
            $target = $command['targetPartyType'] === 'HQ' ? $this->headquartersForScope($sourceScope) : $this->scope($command['targetStoreId'], 0, false);
            if ($target === null) throw new \RuntimeException('inventory_cross_transfer_hq_location_missing');
            if ($command['targetPartyType'] === 'STORE' && !$hqScope['isSuperAdmin'] && !in_array((int)$target['storeId'], $hqScope['allowedStoreIds'], true)) throw new \RuntimeException('inventory_cross_transfer_target_scope_denied');
            $sourceRoot = explode('/', trim((string)$sourceScope['organizationPath'], '/'))[0] ?? '';
            $targetRoot = explode('/', trim((string)$target['organizationPath'], '/'))[0] ?? '';
            if ($sourceRoot === '' || $targetRoot === '' || $sourceRoot !== $targetRoot) throw new \RuntimeException('inventory_cross_transfer_target_scope_denied');
            $command['transferStaff'] = $this->platformTransferStaff($hqScope, $sourceScope, $target, (int)$command['transferStaffId']);
            return $this->createDraft($sourceScope, $target, $command);
        });
    }

    /** 返回当前平台组织范围内可记名的在职员工；平台账号关联员工默认选中。 */
    public function transferStaffCandidatesForHeadquarters(array $adminInfo, int $hqLocationId, string $sourcePartyType, int $sourceStoreId, int $targetStoreId): array
    {
        $hqScope = $this->headquartersScope($adminInfo, $hqLocationId);
        $sourcePartyType = strtoupper(trim($sourcePartyType));
        if (!in_array($sourcePartyType, ['HQ', 'STORE'], true) || ($sourcePartyType === 'HQ' && $sourceStoreId !== 0) || ($sourcePartyType === 'STORE' && $sourceStoreId <= 0) || $targetStoreId < 0) throw new \InvalidArgumentException('inventory_cross_transfer_transfer_staff_invalid');
        $sourceScope = $hqScope;
        if ($sourcePartyType === 'STORE') {
            if (!$hqScope['isSuperAdmin'] && !in_array($sourceStoreId, $hqScope['allowedStoreIds'], true)) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
            $sourceScope = $this->scope($sourceStoreId, 0, false);
            if (!$this->sameOrganizationRoot($hqScope, $sourceScope)) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
        }
        $targetScope = $targetStoreId > 0 ? $this->scope($targetStoreId, 0, false) : $this->headquartersForScope($sourceScope);
        if ($targetScope === null || ($targetStoreId > 0 && !$hqScope['isSuperAdmin'] && !in_array($targetStoreId, $hqScope['allowedStoreIds'], true)) || !$this->sameOrganizationRoot($sourceScope, $targetScope)) throw new \RuntimeException('inventory_cross_transfer_target_scope_denied');
        $staff = $this->platformTransferStaffRows($hqScope);
        $currentEmployeeId = (int)($adminInfo['employee_id'] ?? 0);
        $currentStaffId = 0;
        foreach ($staff as &$row) {
            $row['is_current'] = $currentEmployeeId > 0 && (int)$row['employee_id'] === $currentEmployeeId;
            if ($row['is_current']) $currentStaffId = (int)$row['id'];
        }
        unset($row);
        if ($currentStaffId <= 0) {
            array_unshift($staff, $this->platformOperatorTransferPerson($hqScope));
        }
        return ['list' => $staff, 'current_staff_id' => $currentStaffId];
    }

    public function counterpartiesForHeadquarters(array $adminInfo, int $hqLocationId, string $sourcePartyType = 'HQ', int $sourceStoreId = 0): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId);
        $root = explode('/', trim((string)$scope['organizationPath'], '/'))[0] ?? '';
        $query = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->field('id,name')->order('id asc');
        if (!$scope['isSuperAdmin']) $query->whereIn('id', $scope['allowedStoreIds']);
        $rows = $query->select()->toArray();
        $list = [];
        if (strtoupper($sourcePartyType) === 'STORE') {
            $headquarters = $this->headquartersForScope($scope);
            if ($headquarters !== null) $list[] = ['id' => 0, 'name' => '总部仓', 'party_type' => 'HQ', 'location_id' => (int)$headquarters['location']['id']];
        }
        foreach ($rows as $row) {
            if (strtoupper($sourcePartyType) === 'STORE' && (int)$row['id'] === $sourceStoreId) continue;
            $binding = Db::name('organization_store')->where('store_id', (int)$row['id'])->value('org_id');
            $path = $binding ? $this->organizationPath((int)$binding) : '';
            if ($path !== '' && (explode('/', trim($path, '/'))[0] ?? '') === $root) $list[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'party_type' => 'STORE'];
        }
        return ['list' => $list];
    }

    public function incomingRequestsForHeadquarters(array $adminInfo, int $hqLocationId, bool $canViewCost = false, string $sourcePartyType = 'HQ', int $sourceStoreId = 0): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId);
        $source = $scope;
        $sourcePartyType = strtoupper(trim($sourcePartyType));
        if (!in_array($sourcePartyType, ['HQ', 'STORE'], true) || ($sourcePartyType === 'HQ' && $sourceStoreId !== 0) || ($sourcePartyType === 'STORE' && $sourceStoreId <= 0)) throw new \InvalidArgumentException('inventory_cross_transfer_source_invalid');
        if ($sourcePartyType === 'STORE') {
            if (!$scope['isSuperAdmin'] && !in_array($sourceStoreId, $scope['allowedStoreIds'], true)) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
            $source = $this->scope($sourceStoreId, 0, false);
            if (!$this->sameOrganizationRoot($scope, $source)) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
        }
        $root = explode('/', trim((string)$scope['organizationPath'], '/'))[0] ?? '';
        if ($root === '') throw new \RuntimeException('inventory_cross_transfer_organization_invalid');
        $query = Db::name('inventory_stock_request_document')->alias('d')->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', $scope['tenantId'])->where('d.supply_party_type', $sourcePartyType)->where('d.supply_party_id', $sourceStoreId)
            ->whereLike('d.organization_path', '/' . $root . '/%')
            ->whereIn('d.document_status', ['APPLIED', 'PARTIAL'])->field('d.id,d.request_no,d.store_id,d.request_party_type,d.request_party_id,d.request_party_name_snapshot,s.name request_store_name,d.business_date,d.document_status')->order('d.id desc');
        if (!$scope['isSuperAdmin']) $query->where(function ($inner) use ($scope): void {
            $inner->whereIn('d.store_id', $scope['allowedStoreIds'])->whereOr(function ($hq) { $hq->where('d.request_party_type', 'HQ')->where('d.request_party_id', 0); });
        });
        $documents = $query->select()->toArray();
        foreach ($documents as &$document) {
            try {
                $target = strtoupper((string)($document['request_party_type'] ?? 'STORE')) === 'HQ'
                    ? $this->headquartersForScope($scope)
                    : $this->scope((int)($document['request_party_id'] ?? $document['store_id'] ?? 0), 0, false);
            } catch (\RuntimeException $exception) {
                // A malformed/obsolete request row must not hide valid manual
                // transfer counterparties or block the whole selector.
                $document['lines'] = [];
                continue;
            }
            if ($target === null) { $document['lines'] = []; continue; }
            if ((explode('/', trim($target['organizationPath'], '/'))[0] ?? '') !== (explode('/', trim($scope['organizationPath'], '/'))[0] ?? '')) { $document['lines'] = []; continue; }
            $lines = Db::name('inventory_stock_request_line')->where('document_id', (int)$document['id'])->order('line_no asc')->select()->toArray();
            $document['lines'] = $this->incomingRequestSourceLines($lines, $target, $source, $canViewCost);
        }
        unset($document);
        return ['list' => array_values(array_filter($documents, static fn (array $document): bool => (bool)$document['lines']))];
    }

    public function listForHeadquarters(array $adminInfo, int $hqLocationId, string $keyword, int $page = 1, int $limit = 20, string $fromPartyType = '', int $fromPartyId = 0, string $toPartyType = '', int $toPartyId = 0, string $status = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId);
        $query = Db::name('inventory_cross_transfer_document')->alias('d')->leftJoin('inventory_cross_transfer_line l', 'l.document_id=d.id')
            ->where('d.tenant_id', $scope['tenantId'])->where(function ($inner) use ($scope): void {
                $inner->where('d.from_location_id', (int)$scope['location']['id'])->whereOr('d.to_location_id', (int)$scope['location']['id']);
                if ($scope['allowedStoreIds']) {
                    $inner->whereOr(function ($source) use ($scope): void { $source->where('d.from_party_type', 'STORE')->whereIn('d.from_party_id', $scope['allowedStoreIds']); });
                }
            });
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('d.business_date', [$dateFrom, $dateTo]);
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(function ($inner) use ($like): void {
                $inner->whereLike('d.transfer_no', $like)->whereOr('d.remark', 'like', $like);
            });
        }
        if (in_array(strtoupper(trim($fromPartyType)), ['HQ', 'STORE'], true)) $query->where('d.from_party_type', strtoupper(trim($fromPartyType)))->where('d.from_party_id', $fromPartyId);
        if (in_array(strtoupper(trim($toPartyType)), ['HQ', 'STORE'], true)) $query->where('d.to_party_type', strtoupper(trim($toPartyType)))->where('d.to_party_id', $toPartyId);
        if ($status !== '') $query->where('d.document_status', $status);
        $count = (int)(clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.transfer_no order_sn,d.from_party_type,d.from_party_id,d.from_location_id,d.from_party_name_snapshot from_party_name,d.to_party_type,d.to_party_id,d.to_location_id,d.to_party_name_snapshot to_party_name,d.document_status,d.document_status status_name,d.initiator_store_id,d.business_date transfer_date,d.recorded_at add_time,d.recorded_at operation_at,COUNT(l.id) detail_count')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        foreach ($list as &$row) {
            $row['status_name'] = $this->statusName((string)$row['status_name']);
            $sourceAllowed = (int)$row['from_location_id'] === (int)$scope['location']['id']
                ? true
                : ((string)$row['from_party_type'] === 'STORE' && ($scope['isSuperAdmin'] || in_array((int)$row['from_party_id'], $scope['allowedStoreIds'], true)));
            $row['can_dispatch'] = (string)$row['document_status'] === 'DRAFT' && $sourceAllowed;
            $row['can_receive'] = (string)$row['document_status'] === 'DISPATCHED' && (int)$row['to_location_id'] === (int)$scope['location']['id'];
            $row['can_cancel'] = (string)$row['document_status'] === 'DRAFT' && $sourceAllowed;
            $row['can_reverse'] = in_array((string)$row['document_status'], ['DISPATCHED', 'RECEIVED'], true) && $sourceAllowed;
        }
        unset($row);
        return ['count' => $count, 'list' => $list];
    }

    public function detailForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId);
        $documentScope = $this->platformDocumentAccessScope($scope, $documentId, false);
        $document = $this->lockedSubjectDocument($documentScope, $documentId, false);
        $canViewCost = in_array('inventory.cost.view', (array)((new InventoryPlatformAccessPolicy())->resolve($adminInfo)['features'] ?? []), true);
        $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->order('line_no asc')->select()->toArray();
        foreach ($lines as &$line) {
            $line['allocations'] = Db::name('inventory_cross_transfer_batch_allocation')->where('line_id', (int)$line['id'])->order('id asc')->select()->toArray();
            if (!$canViewCost) foreach ($line['allocations'] as &$allocation) $allocation['unit_cost_cents'] = null;
            unset($allocation);
        }
        unset($line);
        $document['status_name'] = $this->statusName((string)$document['document_status']);
        $document['lines'] = $lines;
        return $document;
    }

    public function dispatchForStore(int $storeId, int $operatorId, int $documentId): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $documentId): array {
            $scope = $this->scope($storeId, $operatorId, true);
            return $this->dispatch($scope, $documentId);
        });
    }

    public function dispatchForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId);
        return Db::transaction(fn (): array => $this->dispatch($this->platformSourceScope($scope, $documentId, true), $documentId));
    }

    public function receiveForStore(int $storeId, int $operatorId, int $documentId): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $documentId): array {
            $scope = $this->scope($storeId, $operatorId, true);
            return $this->receive($scope, $documentId);
        });
    }

    public function receiveForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId);
        return Db::transaction(fn (): array => $this->receive($scope, $documentId));
    }

    public function cancelForStore(int $storeId, int $operatorId, int $documentId): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $documentId): array {
            $scope = $this->scope($storeId, $operatorId, true);
            return $this->cancel($scope, $documentId);
        });
    }

    public function cancelForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId);
        return Db::transaction(fn (): array => $this->cancel($this->platformSourceScope($scope, $documentId, true), $documentId));
    }

    public function reverseForStore(int $storeId, int $operatorId, int $documentId, array $input): array
    {
        $command = $this->normalizeReversal($input);
        return Db::transaction(function () use ($storeId, $operatorId, $documentId, $command): array {
            return $this->reverse($this->scope($storeId, $operatorId, true), $documentId, $command);
        });
    }

    public function reverseForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId, array $input): array
    {
        $command = $this->normalizeReversal($input);
        $scope = $this->headquartersScope($adminInfo, $hqLocationId);
        return Db::transaction(fn (): array => $this->reverse($this->platformSourceScope($scope, $documentId, true), $documentId, $command));
    }

    private function createDraft(array $source, array $target, array $command): array
    {
        $this->assertSameTenant($source, $target);
        if ($source['partyType'] === $target['partyType'] && (int)$source['partyId'] === (int)$target['partyId']) {
            throw new \RuntimeException('inventory_cross_transfer_target_invalid');
        }
        $sourceLocation = $this->subjectLocation($source, $command['now']);
        $targetLocation = $this->subjectLocation($target, $command['now']);
        $existing = Db::name('inventory_cross_transfer_document')->where('tenant_id', $source['tenantId'])->where('idempotency_key', $command['key'])->lock(true)->find();
        if ($existing) return $this->replayDraft((array)$existing, $source, $target, $command);
        $request = $this->requestDocument($target, $command['requestDocumentId'], $source);
        $transferNo = (new InventoryBusinessDocumentNumberServices())->next($source['tenantId'], InventoryBusinessDocumentNumberServices::TRANSFER, $command['date'], $command['now']);
        $documentId = (int)Db::name('inventory_cross_transfer_document')->insertGetId([
            'transfer_no' => $transferNo, 'idempotency_key' => $command['key'], 'request_fingerprint' => $command['fingerprint'], 'tenant_id' => $source['tenantId'],
            'from_party_type' => $source['partyType'], 'from_party_id' => (int)$source['partyId'], 'from_party_name_snapshot' => $source['partyName'], 'from_location_id' => (int)$sourceLocation['id'],
            'to_party_type' => $target['partyType'], 'to_party_id' => (int)$target['partyId'], 'to_party_name_snapshot' => $target['partyName'], 'to_location_id' => (int)$targetLocation['id'],
            'request_document_id' => $request ? (int)$request['id'] : 0, 'initiator_store_id' => $source['partyType'] === 'STORE' ? (int)$source['storeId'] : 0,
            'transfer_staff_id' => (int)($command['transferStaff']['id'] ?? 0), 'transfer_employee_id' => (int)($command['transferStaff']['employee_id'] ?? 0), 'transfer_staff_name_snapshot' => (string)($command['transferStaff']['employee_name'] ?? $command['transferStaff']['staff_name'] ?? $command['transferStaff']['name'] ?? ''),
            'created_by_operator_id' => (int)$source['operatorId'], 'document_status' => 'DRAFT', 'remark' => $command['remark'], 'business_date' => $command['date'], 'recorded_at' => $command['now'],
        ]);
        foreach ($command['lines'] as $line) {
            $catalog = $this->crossCatalog($source, $target, $line);
            $requestLine = $this->requestLine($request, $line['requestLineId'], $catalog, $line['quantity']);
            Db::name('inventory_cross_transfer_line')->insert([
                'document_id' => $documentId, 'line_no' => $line['index'] + 1, 'request_line_id' => $requestLine ? (int)$requestLine['id'] : 0,
                'from_product_id' => $catalog['fromProductId'], 'from_sku_id' => $catalog['fromSkuId'], 'from_sku_unique' => $catalog['fromSkuUnique'],
                'to_product_id' => $catalog['toProductId'], 'to_sku_id' => $catalog['toSkuId'], 'to_sku_unique' => $catalog['toSkuUnique'],
                'requested_quantity_units' => $catalog['units'], 'quantity_scale' => $catalog['scale'], 'product_name_snapshot' => $catalog['productName'],
                'sku_name_snapshot' => $catalog['skuName'], 'stock_unit_snapshot' => $catalog['stockUnit'], 'created_at' => $command['now'],
            ]);
        }
        return ['transfer_id' => $documentId, 'transfer_no' => $transferNo, 'document_status' => 'DRAFT', 'idempotent' => false];
    }

    private function dispatch(array $scope, int $documentId): array
    {
        $document = $this->lockedSubjectDocument($scope, $documentId, true);
        if ((string)$document['document_status'] === 'DISPATCHED') return ['transfer_id' => $documentId, 'document_status' => 'DISPATCHED', 'idempotent' => true];
        if ((string)$document['document_status'] !== 'DRAFT' || (string)$document['from_party_type'] !== $scope['partyType'] || (int)$document['from_party_id'] !== (int)$scope['partyId']) throw new \RuntimeException('inventory_cross_transfer_dispatch_state_invalid');
        $sourceLocation = $this->subjectLocation($scope, time());
        if ((int)$sourceLocation['id'] !== (int)$document['from_location_id']) throw new \RuntimeException('inventory_cross_transfer_source_location_changed');
        $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->order('from_product_id asc,from_sku_id asc,id asc')->lock(true)->select()->toArray();
        if (!$lines) throw new \RuntimeException('inventory_cross_transfer_line_missing');
        $now = time();
        foreach ($lines as $line) {
            $this->assertRequestRemaining((int)$line['request_line_id'], (int)$line['requested_quantity_units']);
            $stock = $this->sourceStock($sourceLocation, $line);
            $allocations = $this->allocate($this->sourceBatches((int)$stock['id']), (int)$line['requested_quantity_units']);
            $this->decreaseSource($stock, $allocations, (int)$line['requested_quantity_units'], $now);
            foreach ($allocations as $allocation) {
                $batch = $allocation['batch'];
                $allocationId = (int)Db::name('inventory_cross_transfer_batch_allocation')->insertGetId([
                    'document_id' => $documentId, 'line_id' => (int)$line['id'], 'from_stock_id' => (int)$stock['id'], 'from_batch_id' => (int)$batch['id'],
                    'origin_batch_id' => (int)$batch['origin_batch_id'], 'quantity_units' => $allocation['units'], 'unit_cost_cents' => (int)$batch['unit_cost_cents'],
                    'quantity_scale' => (int)$stock['quantity_scale'], 'dispatched_at' => $now,
                ]);
                $this->appendFact($scope, $stock, $batch, -1, $allocation['units'], 'cross_transfer_out', (string)$document['transfer_no'], $allocationId, (string)$document['business_date'], $now);
            }
        }
        Db::name('inventory_cross_transfer_document')->where('id', $documentId)->where('document_status', 'DRAFT')->update(['document_status' => 'DISPATCHED', 'dispatched_by_operator_id' => $scope['operatorId'], 'dispatched_at' => $now]);
        return ['transfer_id' => $documentId, 'document_status' => 'DISPATCHED', 'idempotent' => false];
    }

    private function receive(array $scope, int $documentId): array
    {
        $document = $this->lockedSubjectDocument($scope, $documentId, true);
        if ((string)$document['document_status'] === 'RECEIVED') return ['transfer_id' => $documentId, 'document_status' => 'RECEIVED', 'idempotent' => true];
        if ((string)$document['document_status'] !== 'DISPATCHED' || (string)$document['to_party_type'] !== $scope['partyType'] || (int)$document['to_party_id'] !== (int)$scope['partyId']) throw new \RuntimeException('inventory_cross_transfer_receive_state_invalid');
        $location = $this->subjectLocation($scope, time());
        if ((int)$location['id'] !== (int)$document['to_location_id']) throw new \RuntimeException('inventory_cross_transfer_target_location_changed');
        $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->order('to_product_id asc,to_sku_id asc,id asc')->lock(true)->select()->toArray();
        $now = time();
        foreach ($lines as $line) {
            $stock = $this->targetStock($location, $line, $now);
            $allocations = Db::name('inventory_cross_transfer_batch_allocation')->where('line_id', (int)$line['id'])->where('received_at', 0)->order('id asc')->lock(true)->select()->toArray();
            if (!$allocations) throw new \RuntimeException('inventory_cross_transfer_allocation_missing');
            foreach ($allocations as $allocation) {
                $sourceBatch = Db::name('inventory_batch')->where('id', (int)$allocation['from_batch_id'])->find();
                if (!$sourceBatch) throw new \RuntimeException('inventory_cross_transfer_source_batch_missing');
                $targetBatch = $this->targetBatch($stock, $sourceBatch, $document, $now);
                $this->increaseTarget($stock, $targetBatch, (int)$allocation['quantity_units'], $now);
                $stock = (array)Db::name('inventory_stock')->where('id', (int)$stock['id'])->lock(true)->find();
                Db::name('inventory_cross_transfer_batch_allocation')->where('id', (int)$allocation['id'])->where('received_at', 0)->update(['to_stock_id' => (int)$stock['id'], 'to_batch_id' => (int)$targetBatch['id'], 'received_at' => $now]);
                $this->appendFact($scope, $stock, $targetBatch, 1, (int)$allocation['quantity_units'], 'cross_transfer_in', (string)$document['transfer_no'], (int)$allocation['id'], (string)$document['business_date'], $now);
            }
            if ((int)$line['request_line_id'] > 0) Db::name('inventory_stock_request_fulfillment')->insert([
                'request_document_id' => (int)$document['request_document_id'], 'request_line_id' => (int)$line['request_line_id'], 'transfer_document_id' => $documentId,
                'transfer_line_id' => (int)$line['id'], 'fulfilled_quantity_units' => (int)$line['requested_quantity_units'], 'received_at' => $now,
            ]);
        }
        $this->refreshRequestStatus((int)$document['request_document_id']);
        Db::name('inventory_cross_transfer_document')->where('id', $documentId)->where('document_status', 'DISPATCHED')->update(['document_status' => 'RECEIVED', 'received_by_operator_id' => $scope['operatorId'], 'received_at' => $now]);
        return ['transfer_id' => $documentId, 'document_status' => 'RECEIVED', 'idempotent' => false];
    }

    private function cancel(array $scope, int $documentId): array
    {
        $document = $this->lockedSubjectDocument($scope, $documentId, true);
        if ((string)$document['document_status'] === 'CANCELLED') return ['transfer_id' => $documentId, 'document_status' => 'CANCELLED', 'idempotent' => true];
        if ((string)$document['document_status'] !== 'DRAFT' || (string)$document['from_party_type'] !== $scope['partyType'] || (int)$document['from_party_id'] !== (int)$scope['partyId']) throw new \RuntimeException('inventory_cross_transfer_cancel_state_invalid');
        Db::name('inventory_cross_transfer_document')->where('id', $documentId)->where('document_status', 'DRAFT')->update(['document_status' => 'CANCELLED', 'cancelled_at' => time()]);
        return ['transfer_id' => $documentId, 'document_status' => 'CANCELLED', 'idempotent' => false];
    }

    /**
     * Reversal is owned by the dispatching subject. It appends exact opposite
     * batch facts and never deletes or rewrites the original movement facts.
     */
    private function reverse(array $scope, int $documentId, array $command): array
    {
        $document = $this->lockedSubjectDocument($scope, $documentId, true);
        if ((string)$document['from_party_type'] !== (string)$scope['partyType'] || (int)$document['from_party_id'] !== (int)$scope['partyId']) throw new \RuntimeException('inventory_cross_transfer_reverse_scope_denied');
        $existing = Db::name('inventory_cross_transfer_reversal_operation')->where('tenant_id', $scope['tenantId'])->where('idempotency_key', $command['idempotencyKey'])->lock(true)->find();
        if ($existing) {
            if ((int)$existing['transfer_document_id'] !== $documentId || (string)$existing['request_fingerprint'] !== $command['fingerprint']) throw new \RuntimeException('inventory_cross_transfer_reverse_idempotency_conflict');
            return ['transfer_id' => $documentId, 'document_status' => 'REVERSED', 'reversal_id' => (int)$existing['id'], 'idempotent' => true];
        }
        $previousStatus = (string)$document['document_status'];
        if (!in_array($previousStatus, ['DISPATCHED', 'RECEIVED'], true)) throw new \RuntimeException('inventory_cross_transfer_reverse_state_invalid');
        $now = time();
        $reversalId = (int)Db::name('inventory_cross_transfer_reversal_operation')->insertGetId([
            'tenant_id' => $scope['tenantId'], 'transfer_document_id' => $documentId,
            'idempotency_key' => $command['idempotencyKey'], 'request_fingerprint' => $command['fingerprint'],
            'previous_status' => $previousStatus, 'reason' => $command['reason'], 'operator_id' => (int)$scope['operatorId'],
            'occurred_at' => $now, 'recorded_at' => $now,
        ]);
        $allocations = Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $documentId)->order('id asc')->lock(true)->select()->toArray();
        if (!$allocations) throw new \RuntimeException('inventory_cross_transfer_allocation_missing');
        foreach ($allocations as $allocation) {
            if ($previousStatus === 'RECEIVED') $this->reverseReceiptAllocation($document, (array)$allocation, $reversalId, $now);
            $this->reverseDispatchAllocation($document, (array)$allocation, $reversalId, $now);
        }
        if ($previousStatus === 'RECEIVED') $this->reverseFulfillment($documentId, (int)$document['request_document_id'], $reversalId, $now);
        $this->refreshRequestStatus((int)$document['request_document_id']);
        if (Db::name('inventory_cross_transfer_document')->where('id', $documentId)->where('document_status', $previousStatus)->update(['document_status' => 'REVERSED']) !== 1) throw new \RuntimeException('inventory_cross_transfer_changed');
        return ['transfer_id' => $documentId, 'document_status' => 'REVERSED', 'reversal_id' => $reversalId, 'idempotent' => false];
    }

    private function reverseReceiptAllocation(array $document, array $allocation, int $reversalId, int $now): void
    {
        if ((int)$allocation['to_stock_id'] <= 0 || (int)$allocation['to_batch_id'] <= 0 || (int)$allocation['received_at'] <= 0) throw new \RuntimeException('inventory_cross_transfer_receipt_reversal_missing');
        $stock = Db::name('inventory_stock')->where('id', (int)$allocation['to_stock_id'])->lock(true)->find();
        $batch = Db::name('inventory_batch')->where('id', (int)$allocation['to_batch_id'])->lock(true)->find();
        $units = (int)$allocation['quantity_units'];
        if (!$stock || !$batch || (int)$batch['stock_id'] !== (int)$stock['id'] || (int)$stock['available_quantity_units'] < $units || (int)$batch['available_quantity_units'] < $units) throw new \RuntimeException('inventory_cross_transfer_target_reversal_stock_insufficient');
        $this->changeExactBatchBalance((array)$stock, (array)$batch, -$units, $now, 'inventory_cross_transfer_target_reversal_stock_changed');
        $original = $this->movementFact((string)$document['tenant_id'], 'cross_transfer_in', (string)$document['transfer_no'], (int)$allocation['id']);
        $this->appendReversalFact((array)$stock, (array)$batch, $original, -1, 'cross_transfer_in_reversal', (string)$document['transfer_no'], (int)$allocation['id'], $reversalId, (string)$document['business_date'], $now);
    }

    private function reverseDispatchAllocation(array $document, array $allocation, int $reversalId, int $now): void
    {
        $stock = Db::name('inventory_stock')->where('id', (int)$allocation['from_stock_id'])->lock(true)->find();
        $batch = Db::name('inventory_batch')->where('id', (int)$allocation['from_batch_id'])->lock(true)->find();
        if (!$stock || !$batch || (int)$batch['stock_id'] !== (int)$stock['id'] || (string)$stock['stock_status'] !== InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD || (string)$batch['batch_status'] !== 'ACTIVE') throw new \RuntimeException('inventory_cross_transfer_source_reversal_invalid');
        $this->changeExactBatchBalance((array)$stock, (array)$batch, (int)$allocation['quantity_units'], $now, 'inventory_cross_transfer_source_reversal_stock_changed');
        $original = $this->movementFact((string)$document['tenant_id'], 'cross_transfer_out', (string)$document['transfer_no'], (int)$allocation['id']);
        $this->appendReversalFact((array)$stock, (array)$batch, $original, 1, 'cross_transfer_out_reversal', (string)$document['transfer_no'], (int)$allocation['id'], $reversalId, (string)$document['business_date'], $now);
    }

    private function changeExactBatchBalance(array $stock, array $batch, int $delta, int $now, string $error): void
    {
        $nextBatch = (int)$batch['available_quantity_units'] + $delta;
        $nextStock = (int)$stock['available_quantity_units'] + $delta;
        if ($nextBatch < 0 || $nextStock < 0) throw new \RuntimeException('inventory_cross_transfer_reversal_stock_insufficient');
        if (Db::name('inventory_batch')->where('id', (int)$batch['id'])->where('version', (int)$batch['version'])->update(['available_quantity_units' => $nextBatch, 'version' => (int)$batch['version'] + 1, 'updated_at' => $now]) !== 1) throw new \RuntimeException($error);
        $batches = Db::name('inventory_batch')->where('stock_id', (int)$stock['id'])->where('batch_status', 'ACTIVE')->lock(true)->select()->toArray();
        $total = 0; $weightedCost = 0;
        foreach ($batches as $candidate) { $quantity = (int)$candidate['available_quantity_units']; $total += $quantity; $weightedCost += $quantity * (int)$candidate['unit_cost_cents']; }
        if ($total !== $nextStock) throw new \RuntimeException('inventory_cross_transfer_reversal_batch_reconciliation_failed');
        $estimatedCost = $total > 0 ? intdiv($weightedCost, $total) : 0;
        if (Db::name('inventory_stock')->where('id', (int)$stock['id'])->where('version', (int)$stock['version'])->update(['available_quantity_units' => $total, 'estimated_unit_cost_cents' => $estimatedCost, 'version' => (int)$stock['version'] + 1, 'updated_at' => $now]) !== 1) throw new \RuntimeException($error);
    }

    private function movementFact(string $tenantId, string $sourceType, string $sourceId, int $detailId): array
    {
        $fact = Db::name('inventory_batch_movement_fact')->where('tenant_id', $tenantId)->where('source_type', $sourceType)->where('source_id', $sourceId)->where('source_detail_id', (string)$detailId)->where('direction', $sourceType === 'cross_transfer_in' ? 1 : -1)->lock(true)->find();
        if (!$fact) throw new \RuntimeException('inventory_cross_transfer_original_fact_missing');
        if (Db::name('inventory_batch_movement_fact')->where('reversal_of', (int)$fact['id'])->lock(true)->find()) throw new \RuntimeException('inventory_cross_transfer_already_reversed');
        return (array)$fact;
    }

    private function appendReversalFact(array $stock, array $batch, array $original, int $direction, string $type, string $sourceId, int $detailId, int $reversalId, string $date, int $now): void
    {
        (new InventoryBatchMovementFactServices())->append([
            'factKey' => $type . ':' . hash('sha256', $sourceId . ':' . $detailId . ':' . $reversalId),
            'tenantId' => (string)$stock['tenant_id'], 'organizationId' => (string)$stock['organization_id'], 'organizationPath' => (string)$stock['organization_path'], 'storeId' => (int)$stock['store_id'],
            'stockId' => (int)$stock['id'], 'batchId' => (int)$batch['id'], 'direction' => $direction,
            'quantityUnits' => (int)$original['quantity_units'], 'unitCostCents' => (int)$original['unit_cost_cents'], 'costAmountCents' => (int)$original['cost_amount_cents'],
            'sourceType' => $type, 'sourceId' => $sourceId, 'sourceDetailId' => (string)$detailId, 'reversalOf' => (int)$original['id'],
            'businessDate' => $date, 'occurredAt' => $now, 'settledAt' => $now, 'recordedAt' => $now,
        ]);
    }

    private function reverseFulfillment(int $transferId, int $requestId, int $reversalId, int $now): void
    {
        if ($requestId <= 0) return;
        $rows = Db::name('inventory_stock_request_fulfillment')->where('transfer_document_id', $transferId)->order('id asc')->lock(true)->select()->toArray();
        foreach ($rows as $row) Db::name('inventory_stock_request_fulfillment_reversal')->insert([
            'request_document_id' => $requestId, 'request_line_id' => (int)$row['request_line_id'], 'reversal_of' => (int)$row['id'],
            'transfer_reversal_operation_id' => $reversalId, 'reversed_quantity_units' => (int)$row['fulfilled_quantity_units'], 'recorded_at' => $now,
        ]);
    }

    private function normalizeReversal(array $input): array
    {
        if (array_keys($input) !== ['idempotency_key', 'reason']) throw new \InvalidArgumentException('inventory_cross_transfer_reverse_input_invalid');
        $key = trim((string)$input['idempotency_key']); $reason = trim((string)$input['reason']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1 || $reason === '' || mb_strlen($reason) > 500) throw new \InvalidArgumentException('inventory_cross_transfer_reverse_input_invalid');
        return ['idempotencyKey' => $key, 'reason' => $reason, 'fingerprint' => hash('sha256', "REVERSE\n" . $reason)];
    }

    private function normalize(array $input): array
    {
        $legacyKeys = ['idempotency_key', 'business_date', 'remark', 'target_store_id', 'request_document_id', 'lines'];
        // 门店控制器只接受调入方字段，调出方始终由登录会话强制确定。
        // 它与旧兼容格式相比多了 target_party_type，不能误走平台来源主体契约。
        $storeKeys = ['idempotency_key', 'business_date', 'remark', 'target_party_type', 'target_store_id', 'request_document_id', 'lines'];
        $keys = ['idempotency_key', 'business_date', 'remark', 'source_party_type', 'source_store_id', 'target_party_type', 'target_store_id', 'request_document_id', 'lines'];
        $keysWithTransferStaff = ['idempotency_key', 'business_date', 'remark', 'source_party_type', 'source_store_id', 'target_party_type', 'target_store_id', 'request_document_id', 'transfer_staff_id', 'lines'];
        if (!in_array(array_keys($input), [$legacyKeys, $storeKeys, $keys, $keysWithTransferStaff], true) || !is_int($input['target_store_id']) || !is_int($input['request_document_id']) || (array_key_exists('transfer_staff_id', $input) && (!is_int($input['transfer_staff_id']) || $input['transfer_staff_id'] < 0)) || !is_array($input['lines']) || !$input['lines'] || count($input['lines']) > 100) throw new \InvalidArgumentException('inventory_cross_transfer_input_invalid');
        $sourcePartyType = strtoupper(trim((string)($input['source_party_type'] ?? 'HQ')));
        $sourceStoreId = (int)($input['source_store_id'] ?? 0);
        if (!in_array($sourcePartyType, ['STORE', 'HQ'], true) || ($sourcePartyType === 'STORE' && $sourceStoreId <= 0) || ($sourcePartyType === 'HQ' && $sourceStoreId !== 0)) throw new \InvalidArgumentException('inventory_cross_transfer_source_invalid');
        $targetPartyType = strtoupper(trim((string)($input['target_party_type'] ?? 'STORE')));
        if (!in_array($targetPartyType, ['STORE', 'HQ'], true)
            || ($targetPartyType === 'STORE' && (int)$input['target_store_id'] <= 0)
            || ($targetPartyType === 'HQ' && (int)$input['target_store_id'] !== 0)) throw new \InvalidArgumentException('inventory_cross_transfer_target_invalid');
        $key = trim((string)$input['idempotency_key']);
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) throw new \InvalidArgumentException('inventory_cross_transfer_idempotency_invalid');
        $lines = [];
        foreach (array_values($input['lines']) as $index => $line) {
            if (!is_array($line) || array_keys($line) !== ['product_id', 'sku_id', 'sku_unique', 'quantity', 'request_line_id'] || !is_int($line['product_id']) || !is_int($line['sku_id']) || !is_int($line['request_line_id']) || $line['product_id'] <= 0 || $line['sku_id'] <= 0 || $line['request_line_id'] < 0) throw new \InvalidArgumentException('inventory_cross_transfer_line_invalid');
            $unique = trim((string)$line['sku_unique']); $quantity = trim((string)$line['quantity']);
            if ($unique === '' || strlen($unique) > 64 || preg_match('/^\d+(?:\.\d{1,4})?$/D', $quantity) !== 1 || (float)$quantity <= 0) throw new \InvalidArgumentException('inventory_cross_transfer_line_invalid');
            $lines[] = ['index' => $index, 'productId' => $line['product_id'], 'skuId' => $line['sku_id'], 'skuUnique' => $unique, 'quantity' => $quantity, 'requestLineId' => $line['request_line_id']];
        }
        $date = $this->date((string)$input['business_date']); $remark = mb_substr(trim((string)$input['remark']), 0, 500);
        $legacyFingerprint = hash('sha256', json_encode([$date, $remark, $input['target_store_id'], $input['request_document_id'], $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['key' => $key, 'date' => $date, 'remark' => $remark, 'sourcePartyType' => $sourcePartyType, 'sourceStoreId' => $sourceStoreId, 'targetPartyType' => $targetPartyType, 'targetStoreId' => $input['target_store_id'], 'requestDocumentId' => $input['request_document_id'], 'transferStaffId' => (int)($input['transfer_staff_id'] ?? 0), 'lines' => $lines, 'fingerprint' => hash('sha256', json_encode([$date, $remark, $sourcePartyType, $sourceStoreId, $targetPartyType, $input['target_store_id'], $input['request_document_id'], $input['transfer_staff_id'] ?? 0, $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'legacyFingerprint' => $legacyFingerprint, 'now' => time()];
    }

    private function platformTransferStaff(array $hqScope, array $sourceScope, array $targetScope, int $staffId): array
    {
        $rows = $this->platformTransferStaffRows($hqScope);
        $currentEmployeeId = (int)($hqScope['employeeId'] ?? 0);
        if ($staffId <= 0 && $currentEmployeeId > 0) {
            foreach ($rows as $row) if ((int)$row['employee_id'] === $currentEmployeeId) { $staffId = (int)$row['id']; break; }
        }
        foreach ($rows as $row) if ((int)$row['id'] === $staffId) return $row;
        if ($staffId === 0) return $this->platformOperatorTransferPerson($hqScope);
        throw new \RuntimeException('inventory_cross_transfer_transfer_staff_invalid');
    }

    private function platformOperatorTransferPerson(array $hqScope): array
    {
        $name = trim((string)($hqScope['operatorName'] ?? ''));
        if ($name === '') $name = '当前登录账号';
        return ['id' => 0, 'employee_id' => 0, 'store_id' => 0, 'staff_name' => $name, 'employee_name' => $name, 'store_name' => '平台账号', 'is_current' => true];
    }

    private function platformTransferStaffRows(array $hqScope): array
    {
        $root = explode('/', trim((string)$hqScope['organizationPath'], '/'))[0] ?? '';
        if ($root === '') throw new \RuntimeException('inventory_cross_transfer_organization_invalid');
        $query = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->field('id');
        if (!$hqScope['isSuperAdmin']) $query->whereIn('id', $hqScope['allowedStoreIds']);
        $storeIds = [];
        foreach ($query->select()->toArray() as $store) {
            $organizationId = (int)Db::name('organization_store')->where('store_id', (int)$store['id'])->value('org_id');
            if ($organizationId > 0 && (explode('/', trim($this->organizationPath($organizationId), '/'))[0] ?? '') === $root) $storeIds[] = (int)$store['id'];
        }
        if (!$storeIds) return [];
        return Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')->join('system_store st', 'st.id=s.store_id')
            ->whereIn('s.store_id', $storeIds)->where('s.status', 1)->where('s.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->where('st.is_del', 0)->where('st.is_show', 1)
            ->field('s.id,s.employee_id,s.store_id,s.staff_name,e.name employee_name,st.name store_name')->order('s.store_id asc,s.id asc')->select()->toArray();
    }

    private function scope(int $storeId, int $operatorId, bool $requireOperator): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)->field('id,name')->lock(true)->find();
        if ($requireOperator && !Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->lock(true)->find()) throw new \RuntimeException('inventory_cross_transfer_scope_denied');
        $organizationId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        if (!$store || $organizationId <= 0) throw new \RuntimeException('inventory_cross_transfer_scope_denied');
        return ['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'organizationId' => (string)$organizationId, 'organizationPath' => $this->organizationPath($organizationId), 'storeId' => $storeId, 'storeName' => mb_substr(trim((string)$store['name']), 0, 100), 'operatorId' => $operatorId, 'partyType' => 'STORE', 'partyId' => $storeId, 'partyName' => mb_substr(trim((string)$store['name']), 0, 100)];
    }

    private function organizationPath(int $organizationId): string
    {
        $ids = []; $seen = []; $current = $organizationId;
        for ($depth = 0; $depth < 64; $depth++) { if (isset($seen[$current])) break; $seen[$current] = true; $node = Db::name('organization')->where('id', $current)->where('is_del', 0)->field('id,pid')->lock(true)->find(); if (!$node) break; $ids[] = (int)$node['id']; if ((int)$node['pid'] === 0) return '/' . implode('/', array_reverse($ids)) . '/'; $current = (int)$node['pid']; }
        throw new \RuntimeException('inventory_cross_transfer_organization_invalid');
    }

    private function defaultLocation(array $scope, int $now): array
    {
        $rows = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('store_id', $scope['storeId'])->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->limit(2)->lock(true)->select()->toArray();
        if (count($rows) === 1) return (array)$rows[0];
        if (count($rows) > 1) throw new \RuntimeException('inventory_cross_transfer_default_location_ambiguous');
        $code = 'STORE-' . $scope['storeId'];
        try { $id = (int)Db::name('inventory_location')->insertGetId(['tenant_id'=>$scope['tenantId'],'organization_id'=>$scope['organizationId'],'organization_path'=>$scope['organizationPath'],'organization_name_snapshot'=>'','location_type'=>'STORE','owner_id'=>$scope['storeId'],'location_code'=>$code,'location_name'=>'默认门店仓','store_id'=>$scope['storeId'],'store_name_snapshot'=>$scope['storeName'],'is_default'=>1,'location_status'=>'ACTIVE','version'=>1,'created_at'=>$now,'updated_at'=>$now]); }
        catch (\Throwable $exception) { $location = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('location_code', $code)->lock(true)->find(); if (!$location) throw $exception; return (array)$location; }
        $location = Db::name('inventory_location')->where('id', $id)->lock(true)->find(); if (!$location) throw new \RuntimeException('inventory_cross_transfer_default_location_create_failed'); return (array)$location;
    }

    private function headquartersScope(array $adminInfo, int $hqLocationId): array
    {
        $resolved = (new InventoryHqLocationServices())->writableLocation($adminInfo, $hqLocationId);
        $scope = (new InventoryHqLocationServices())->scope($resolved['location'], (int)$resolved['access']['admin_id']);
        $scope['partyType'] = 'HQ';
        $scope['partyId'] = 0;
        $scope['partyName'] = '总部仓';
        $scope['location'] = $resolved['location'];
        $scope['allowedStoreIds'] = array_values(array_map('intval', (array)($resolved['access']['store_ids'] ?? [])));
        $scope['isSuperAdmin'] = !empty($resolved['access']['is_super_admin']);
        $scope['employeeId'] = (int)($adminInfo['employee_id'] ?? 0);
        $scope['operatorName'] = mb_substr(trim((string)($adminInfo['real_name'] ?? $adminInfo['name'] ?? $adminInfo['account'] ?? '')), 0, 120);
        return $scope;
    }

    private function headquartersForScope(array $scope): ?array
    {
        $root = (int)(explode('/', trim((string)$scope['organizationPath'], '/'))[0] ?? 0);
        if ($root <= 0) throw new \RuntimeException('inventory_cross_transfer_organization_invalid');
        $location = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('location_type', 'HQ')->where('owner_id', $root)->where('store_id', 0)->where('is_default', 1)->where('location_status', 'ACTIVE')->limit(2)->lock(true)->select()->toArray();
        if (!$location) return null;
        if (count($location) !== 1) throw new \RuntimeException('inventory_cross_transfer_hq_location_ambiguous');
        return ['tenantId' => $scope['tenantId'], 'organizationId' => (string)$location[0]['organization_id'], 'organizationPath' => (string)$location[0]['organization_path'], 'storeId' => 0, 'storeName' => '', 'operatorId' => 0, 'partyType' => 'HQ', 'partyId' => 0, 'partyName' => '总部仓', 'location' => (array)$location[0]];
    }

    private function targetForStore(array $source, array $command): array
    {
        if ($command['targetPartyType'] === 'STORE') return $this->scope((int)$command['targetStoreId'], 0, false);
        $headquarters = $this->headquartersForScope($source);
        if ($headquarters === null) throw new \RuntimeException('inventory_cross_transfer_hq_location_missing');
        return $headquarters;
    }

    private function subjectLocation(array $scope, int $now): array
    {
        if (($scope['partyType'] ?? 'STORE') === 'STORE') return $this->defaultLocation($scope, $now);
        $id = (int)($scope['location']['id'] ?? 0);
        $location = Db::name('inventory_location')->where('id', $id)->lock(true)->find();
        if (!$location || (string)$location['tenant_id'] !== (string)$scope['tenantId'] || (string)$location['location_type'] !== 'HQ'
            || (int)$location['store_id'] !== 0 || (int)$location['is_default'] !== 1 || (string)$location['location_status'] !== 'ACTIVE') throw new \RuntimeException('inventory_cross_transfer_hq_location_invalid');
        return (array)$location;
    }

    private function crossCatalog(array $source, array $target, array $line): array
    {
        $query = Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')
            ->where('p.id', $line['productId'])->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.id', $line['skuId'])->where('a.unique', $line['skuUnique'])->where('a.type', 0)
            ->field('p.id product_id,p.pid source_pid,p.store_name,a.id sku_id,a.unique sku_unique,a.suk sku_name,a.stock_unit,p.salon_stock_enabled')->lock(true);
        if ($source['partyType'] === 'HQ') $query->where('p.type', 0)->where('p.relation_id', 0);
        else $query->where('p.type', 1)->where('p.relation_id', $source['storeId']);
        $from = $query->find();
        if (!$from) throw new \RuntimeException('inventory_cross_transfer_source_sku_not_found');
        $baseProductId = $source['partyType'] === 'HQ' ? (int)$from['product_id'] : (int)$from['source_pid'];
        if ($baseProductId <= 0) throw new \RuntimeException('inventory_cross_transfer_source_sku_not_found');
        $toProductQuery = Db::name('store_product')->where('is_del', 0)->where('is_inventory', 1);
        if ($target['partyType'] === 'HQ') $toProductQuery->where('id', $baseProductId)->where('type', 0)->where('relation_id', 0);
        else $toProductQuery->where('type', 1)->where('relation_id', $target['storeId'])->where('pid', $baseProductId);
        $toProduct = $toProductQuery->lock(true)->find();
        $toSku = $toProduct ? Db::name('store_product_attr_value')->where('product_id', (int)$toProduct['id'])->where('suk', (string)$from['sku_name'])->where('type', 0)->lock(true)->find() : null;
        if (!$toProduct || !$toSku) throw new \RuntimeException('inventory_cross_transfer_target_sku_unavailable');
        $scale = (int)($from['salon_stock_enabled'] ?? 0) === 1 ? 2 : 0;
        return ['fromProductId'=>(int)$from['product_id'],'fromSkuId'=>(int)$from['sku_id'],'fromSkuUnique'=>(string)$from['sku_unique'],'toProductId'=>(int)$toProduct['id'],'toSkuId'=>(int)$toSku['id'],'toSkuUnique'=>(string)$toSku['unique'],'units'=>InventoryEntitlementCompletionContract::decimalToUnits($line['quantity'], $scale),'scale'=>$scale,'productName'=>mb_substr((string)$from['store_name'],0,120),'skuName'=>mb_substr((string)$from['sku_name'],0,120),'stockUnit'=>mb_substr((string)$from['stock_unit'],0,32)];
    }

    /**
     * Request lines are snapshots of the requester's catalog. A transfer form,
     * however, must submit the supplier's catalog identity because draft
     * creation validates the source before mapping it back to the requester.
     */
    private function incomingRequestSourceLines(array $lines, array $requestParty, array $supplyParty, bool $canViewCost): array
    {
        $mapped = [];
        foreach ($lines as $line) {
            $fulfilled = $this->fulfilledUnits((int)$line['id']);
            $remaining = max(0, (int)$line['requested_quantity_units'] - $fulfilled);
            if ($remaining <= 0) continue;
            try {
                $catalog = $this->crossCatalog($requestParty, $supplyParty, [
                    'productId' => (int)$line['product_id'],
                    'skuId' => (int)$line['sku_id'],
                    'skuUnique' => (string)$line['sku_unique'],
                    'quantity' => $this->quantityFromUnits($remaining, (int)$line['quantity_scale']),
                ]);
            } catch (\RuntimeException $exception) {
                if (!in_array($exception->getMessage(), ['inventory_cross_transfer_source_sku_not_found', 'inventory_cross_transfer_target_sku_unavailable'], true)) throw $exception;
                continue;
            }
            $line['product_id'] = $catalog['toProductId'];
            $line['sku_id'] = $catalog['toSkuId'];
            $line['sku_unique'] = $catalog['toSkuUnique'];
            $line['remaining_quantity_units'] = $remaining;
            if (!$canViewCost) $line['reference_unit_cost_cents'] = null;
            $mapped[] = $line;
        }
        return $mapped;
    }

    private function quantityFromUnits(int $units, int $scale): string
    {
        if ($units <= 0 || $scale < 0 || $scale > 4) throw new \RuntimeException('inventory_cross_transfer_request_line_invalid');
        if ($scale === 0) return (string)$units;
        $digits = str_pad((string)$units, $scale + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    private function requestDocument(array $target, int $requestId, array $source): ?array
    {
        if ($requestId === 0) return null;
        $request = Db::name('inventory_stock_request_document')->where('id', $requestId)->where('tenant_id', $target['tenantId'])->lock(true)->find();
        $legacyStoreParty = $request && (int)($request['store_id'] ?? 0) > 0 && (int)($request['request_party_id'] ?? 0) === 0;
        $requestPartyType = $legacyStoreParty ? 'STORE' : strtoupper((string)($request['request_party_type'] ?? ''));
        $requestPartyId = $legacyStoreParty ? (int)$request['store_id'] : (int)($request['request_party_id'] ?? 0);
        if (!$request || !in_array((string)$request['document_status'], ['APPLIED', 'PARTIAL'], true)
            || $requestPartyType !== $target['partyType'] || $requestPartyId !== (int)$target['partyId']
            || strtoupper((string)$request['supply_party_type']) !== $source['partyType']
            || (int)$request['supply_party_id'] !== (int)$source['partyId']) throw new \RuntimeException('inventory_cross_transfer_request_unavailable');
        return (array)$request;
    }

    private function requestLine(?array $request, int $requestLineId, array $catalog, string $quantity): ?array
    {
        if (!$request && $requestLineId === 0) return null;
        if (!$request) throw new \RuntimeException('inventory_cross_transfer_request_line_invalid');
        if ($requestLineId === 0) {
            $candidates = Db::name('inventory_stock_request_line')->where('document_id', (int)$request['id'])->where('product_id', $catalog['toProductId'])->where('sku_id', $catalog['toSkuId'])->where('sku_unique', $catalog['toSkuUnique'])->where('quantity_scale', $catalog['scale'])->lock(true)->select()->toArray();
            if (count($candidates) !== 1) throw new \RuntimeException('inventory_cross_transfer_request_line_invalid');
            $line = $candidates[0];
        } else $line = Db::name('inventory_stock_request_line')->where('id', $requestLineId)->where('document_id', (int)$request['id'])->lock(true)->find();
        if (!$line || (int)$line['product_id'] !== $catalog['toProductId'] || (int)$line['sku_id'] !== $catalog['toSkuId'] || (string)$line['sku_unique'] !== $catalog['toSkuUnique'] || (int)$line['quantity_scale'] !== $catalog['scale'] || (int)$line['requested_quantity_units'] < $catalog['units']) throw new \RuntimeException('inventory_cross_transfer_request_line_invalid');
        return (array)$line;
    }

    private function sourceStock(array $location, array $line): array { $stock=Db::name('inventory_stock')->where('tenant_id',(string)$location['tenant_id'])->where('location_id',(int)$location['id'])->where('consumable_product_id',(int)$line['from_product_id'])->where('sku_id',(int)$line['from_sku_id'])->where('product_unique',(string)$line['from_sku_unique'])->where('stock_status',InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find();if(!$stock||(int)$stock['available_quantity_units']<(int)$line['requested_quantity_units']||(int)$stock['quantity_scale']!==(int)$line['quantity_scale'])throw new \RuntimeException('inventory_cross_transfer_stock_insufficient');return(array)$stock; }
    private function sourceBatches(int $stockId): array { return Db::name('inventory_batch')->where('stock_id',$stockId)->where('batch_status','ACTIVE')->where('available_quantity_units','>',0)->orderRaw('expire_date IS NULL ASC,expire_date ASC,received_business_date IS NULL ASC,received_business_date ASC,id ASC')->lock(true)->select()->toArray(); }
    private function allocate(array $batches,int $units):array{$left=$units;$out=[];foreach($batches as $batch){$take=min($left,(int)$batch['available_quantity_units']);if($take>0)$out[]=['batch'=>(array)$batch,'units'=>$take];$left-=$take;if($left===0)break;}if($left!==0)throw new \RuntimeException('inventory_cross_transfer_stock_insufficient');return$out;}
    private function decreaseSource(array $stock,array $allocations,int $units,int $now):void{if(Db::name('inventory_stock')->where('id',(int)$stock['id'])->where('version',(int)$stock['version'])->where('available_quantity_units','>=',$units)->update(['available_quantity_units'=>(int)$stock['available_quantity_units']-$units,'version'=>(int)$stock['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_cross_transfer_source_stock_changed');foreach($allocations as $allocation){$batch=$allocation['batch'];if(Db::name('inventory_batch')->where('id',(int)$batch['id'])->where('version',(int)$batch['version'])->where('available_quantity_units','>=',$allocation['units'])->update(['available_quantity_units'=>(int)$batch['available_quantity_units']-$allocation['units'],'version'=>(int)$batch['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_cross_transfer_source_batch_changed');}}

    private function targetStock(array $location,array $line,int $now):array{$query=Db::name('inventory_stock')->where('tenant_id',(string)$location['tenant_id'])->where('location_id',(int)$location['id'])->where('consumable_product_id',(int)$line['to_product_id'])->where('sku_id',(int)$line['to_sku_id'])->where('product_unique',(string)$line['to_sku_unique'])->where('stock_status',InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD);$stock=$query->lock(true)->find();if($stock){if((int)$stock['quantity_scale']!==(int)$line['quantity_scale'])throw new \RuntimeException('inventory_cross_transfer_quantity_scale_conflict');return(array)$stock;}try{$id=(int)Db::name('inventory_stock')->insertGetId(['tenant_id'=>$location['tenant_id'],'organization_id'=>$location['organization_id'],'organization_path'=>$location['organization_path'],'location_id'=>(int)$location['id'],'store_id'=>(int)$location['store_id'],'consumable_product_id'=>(int)$line['to_product_id'],'sku_id'=>(int)$line['to_sku_id'],'product_unique'=>(string)$line['to_sku_unique'],'stock_status'=>InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD,'stock_unit'=>$line['stock_unit_snapshot'],'quantity_scale'=>(int)$line['quantity_scale'],'available_quantity_units'=>0,'estimated_unit_cost_cents'=>0,'version'=>1,'created_at'=>$now,'updated_at'=>$now]);}catch(\Throwable $exception){$stock=$query->lock(true)->find();if(!$stock)throw$exception;return(array)$stock;}$stock=Db::name('inventory_stock')->where('id',$id)->lock(true)->find();if(!$stock)throw new \RuntimeException('inventory_cross_transfer_target_stock_create_failed');return(array)$stock;}
    private function targetBatch(array $stock,array $source,array $document,int $now):array{$batch=Db::name('inventory_batch')->where('stock_id',(int)$stock['id'])->where('batch_no',(string)$source['batch_no'])->lock(true)->find();if($batch){if((int)$batch['origin_batch_id']!==(int)$source['origin_batch_id']||(int)$batch['unit_cost_cents']!==(int)$source['unit_cost_cents']||(string)$batch['batch_status']!=='ACTIVE')throw new \RuntimeException('inventory_cross_transfer_target_batch_conflict');return(array)$batch;}$id=(int)Db::name('inventory_batch')->insertGetId(['stock_id'=>(int)$stock['id'],'origin_batch_id'=>(int)$source['origin_batch_id'],'source_batch_id'=>(int)$source['id'],'batch_no'=>$source['batch_no'],'manufactured_date'=>$source['manufactured_date'],'expire_date'=>$source['expire_date'],'received_at'=>$now,'received_business_date'=>$document['business_date'],'available_quantity_units'=>0,'unit_cost_cents'=>(int)$source['unit_cost_cents'],'cost_allocated_quantity_units'=>0,'batch_status'=>'ACTIVE','version'=>1,'product_name_snapshot'=>$source['product_name_snapshot'],'sku_name_snapshot'=>$source['sku_name_snapshot'],'product_code_snapshot'=>$source['product_code_snapshot'],'barcode_snapshot'=>$source['barcode_snapshot'],'brand_name_snapshot'=>$source['brand_name_snapshot'],'category_name_snapshot'=>$source['category_name_snapshot'],'source_order_no_snapshot'=>$document['transfer_no'],'data_quality'=>$source['data_quality'],'created_at'=>$now,'updated_at'=>$now]);$batch=Db::name('inventory_batch')->where('id',$id)->lock(true)->find();if(!$batch)throw new \RuntimeException('inventory_cross_transfer_target_batch_create_failed');return(array)$batch;}
    private function increaseTarget(array $stock,array $batch,int $units,int $now):void{$new=(int)$stock['available_quantity_units']+$units;$estimated=$new===0?(int)$batch['unit_cost_cents']:intdiv((int)$stock['available_quantity_units']*(int)$stock['estimated_unit_cost_cents']+$units*(int)$batch['unit_cost_cents'],$new);if(Db::name('inventory_stock')->where('id',(int)$stock['id'])->where('version',(int)$stock['version'])->update(['available_quantity_units'=>$new,'estimated_unit_cost_cents'=>$estimated,'version'=>(int)$stock['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_cross_transfer_target_stock_changed');if(Db::name('inventory_batch')->where('id',(int)$batch['id'])->where('version',(int)$batch['version'])->update(['available_quantity_units'=>(int)$batch['available_quantity_units']+$units,'version'=>(int)$batch['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_cross_transfer_target_batch_changed');}
    private function appendFact(array $scope,array $stock,array $batch,int $direction,int $units,string $type,string $sourceId,int $detailId,string $date,int $now):void{(new InventoryBatchMovementFactServices())->append(['factKey'=>$type.':'.hash('sha256',$sourceId.':'.$detailId),'tenantId'=>$scope['tenantId'],'organizationId'=>$scope['organizationId'],'organizationPath'=>$scope['organizationPath'],'storeId'=>$scope['storeId'],'stockId'=>(int)$stock['id'],'batchId'=>(int)$batch['id'],'direction'=>$direction,'quantityUnits'=>$units,'unitCostCents'=>(int)$batch['unit_cost_cents'],'costAmountCents'=>intdiv($units*(int)$batch['unit_cost_cents'],10**(int)$stock['quantity_scale']),'sourceType'=>$type,'sourceId'=>$sourceId,'sourceDetailId'=>(string)$detailId,'reversalOf'=>0,'businessDate'=>$date,'occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now]);}

    /** Resolve a platform document to the subject that is allowed to operate its source. */
    private function platformSourceScope(array $hqScope, int $documentId, bool $lock): array
    {
        $document = $this->platformTransferDocument($hqScope, $documentId, $lock);
        if ((string)$document['from_party_type'] === 'HQ') {
            if ((int)$document['from_location_id'] !== (int)$hqScope['location']['id']) throw new \RuntimeException('inventory_cross_transfer_scope_denied');
            return $hqScope;
        }
        if ((string)$document['from_party_type'] !== 'STORE' || (int)$document['from_party_id'] <= 0
            || (!$hqScope['isSuperAdmin'] && !in_array((int)$document['from_party_id'], $hqScope['allowedStoreIds'], true))) throw new \RuntimeException('inventory_cross_transfer_scope_denied');
        $source = $this->scope((int)$document['from_party_id'], (int)$hqScope['operatorId'], false);
        if (!$this->sameOrganizationRoot($hqScope, $source)) throw new \RuntimeException('inventory_cross_transfer_scope_denied');
        return $source;
    }

    /** Resolve either the source or HQ target side for a platform detail view. */
    private function platformDocumentAccessScope(array $hqScope, int $documentId, bool $lock): array
    {
        $document = $this->platformTransferDocument($hqScope, $documentId, $lock);
        if ((string)$document['from_party_type'] === 'HQ' && (int)$document['from_location_id'] === (int)$hqScope['location']['id']) return $hqScope;
        if ((string)$document['from_party_type'] === 'STORE' && (int)$document['from_party_id'] > 0
            && ($hqScope['isSuperAdmin'] || in_array((int)$document['from_party_id'], $hqScope['allowedStoreIds'], true))) {
            $source = $this->scope((int)$document['from_party_id'], (int)$hqScope['operatorId'], false);
            if ($this->sameOrganizationRoot($hqScope, $source)) return $source;
        }
        if ((string)$document['to_party_type'] === 'HQ' && (int)$document['to_location_id'] === (int)$hqScope['location']['id']) return $hqScope;
        throw new \RuntimeException('inventory_cross_transfer_scope_denied');
    }

    private function platformTransferDocument(array $hqScope, int $documentId, bool $lock): array
    {
        $query = Db::name('inventory_cross_transfer_document')->where('tenant_id', $hqScope['tenantId'])->where('id', $documentId);
        if ($lock) $query->lock(true);
        $document = $query->find();
        if (!$document) throw new \RuntimeException('inventory_cross_transfer_not_found');
        return (array)$document;
    }

    private function sameOrganizationRoot(array $left, array $right): bool
    {
        $leftRoot = explode('/', trim((string)$left['organizationPath'], '/'))[0] ?? '';
        $rightRoot = explode('/', trim((string)$right['organizationPath'], '/'))[0] ?? '';
        return $leftRoot !== '' && $leftRoot === $rightRoot;
    }

    private function lockedStoreDocument(array $scope,int $id,bool $lock):array{$query=Db::name('inventory_cross_transfer_document')->where('id',$id)->where('tenant_id',$scope['tenantId'])->where(function($q)use($scope):void{$q->where(function($i)use($scope):void{$i->where('from_party_type','STORE')->where('from_party_id',$scope['storeId']);})->whereOr(function($i)use($scope):void{$i->where('to_party_type','STORE')->where('to_party_id',$scope['storeId']);});});if($lock)$query->lock(true);$row=$query->find();if(!$row)throw new \RuntimeException('inventory_cross_transfer_not_found');return(array)$row;}
    private function lockedSubjectDocument(array $scope,int $id,bool $lock):array{$query=Db::name('inventory_cross_transfer_document')->where('id',$id)->where('tenant_id',$scope['tenantId'])->where(function($q)use($scope):void{$q->where(function($i)use($scope):void{$i->where('from_party_type',$scope['partyType'])->where('from_party_id',(int)$scope['partyId']);if($scope['partyType']==='HQ')$i->where('from_location_id',(int)$scope['location']['id']);})->whereOr(function($i)use($scope):void{$i->where('to_party_type',$scope['partyType'])->where('to_party_id',(int)$scope['partyId']);if($scope['partyType']==='HQ')$i->where('to_location_id',(int)$scope['location']['id']);});});if($lock)$query->lock(true);$row=$query->find();if(!$row)throw new \RuntimeException('inventory_cross_transfer_not_found');return(array)$row;}
    private function assertSameTenant(array $from,array $to):void{if((string)$from['tenantId'] !== (string)$to['tenantId'])throw new \RuntimeException('inventory_cross_transfer_target_scope_denied');}
    private function fulfilledUnits(int $requestLineId):int{$fulfilled=(int)Db::name('inventory_stock_request_fulfillment')->where('request_line_id',$requestLineId)->sum('fulfilled_quantity_units');$reversed=(int)Db::name('inventory_stock_request_fulfillment_reversal')->where('request_line_id',$requestLineId)->sum('reversed_quantity_units');return max(0,$fulfilled-$reversed);}
    private function assertRequestRemaining(int $requestLineId,int $units):void{if($requestLineId<=0)return;$line=Db::name('inventory_stock_request_line')->where('id',$requestLineId)->lock(true)->find();$document=$line?Db::name('inventory_stock_request_document')->where('id',(int)$line['document_id'])->lock(true)->find():null;if(!$line||!$document||!in_array((string)$document['document_status'],['APPLIED','PARTIAL'],true))throw new \RuntimeException('inventory_cross_transfer_request_unavailable');$requested=(int)$line['requested_quantity_units'];$fulfilled=$this->fulfilledUnits($requestLineId);if($requested<$fulfilled+$units)throw new \RuntimeException('inventory_cross_transfer_request_quantity_exceeded');}
    private function refreshRequestStatus(int $requestId):void{if($requestId<=0)return;$document=Db::name('inventory_stock_request_document')->where('id',$requestId)->lock(true)->find();if(!$document||in_array((string)$document['document_status'],['CANCELLED','TERMINATED'],true))return;$lines=Db::name('inventory_stock_request_line')->where('document_id',$requestId)->lock(true)->select()->toArray();if(!$lines)return;$requested=0;$fulfilled=0;foreach($lines as $line){$requested+=(int)$line['requested_quantity_units'];$fulfilled+=$this->fulfilledUnits((int)$line['id']);}$status=$fulfilled>= $requested?'DONE':($fulfilled>0?'PARTIAL':'APPLIED');Db::name('inventory_stock_request_document')->where('id',$requestId)->whereIn('document_status',['APPLIED','PARTIAL','DONE'])->update(['document_status'=>$status]);}
    private function replayDraft(array $document,array $from,array $to,array $command):array{$legacyStoreReplay=$from['partyType']==='STORE'&&$to['partyType']==='STORE'&&(string)$document['request_fingerprint']===(string)($command['legacyFingerprint']??'');if(((string)$document['request_fingerprint']!==$command['fingerprint']&&!$legacyStoreReplay)||(string)$document['from_party_type']!==$from['partyType']||(int)$document['from_party_id']!==(int)$from['partyId']||(string)$document['to_party_type']!==$to['partyType']||(int)$document['to_party_id']!==(int)$to['partyId']||(string)$document['document_status']!=='DRAFT')throw new \RuntimeException('inventory_cross_transfer_idempotency_conflict');return['transfer_id'=>(int)$document['id'],'transfer_no'=>(string)$document['transfer_no'],'document_status'=>'DRAFT','idempotent'=>true];}
    private function statusName(string $status):string{return['DRAFT'=>'草稿','DISPATCHED'=>'在途','RECEIVED'=>'已收货','CANCELLED'=>'已取消','REVERSED'=>'已作废'][$status]??$status;}
    private function date(string $value):string{$date=\DateTimeImmutable::createFromFormat('!Y-m-d',trim($value));$errors=\DateTimeImmutable::getLastErrors();if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d')!==trim($value))throw new \InvalidArgumentException('inventory_cross_transfer_date_invalid');return$date->format('Y-m-d');}
}
