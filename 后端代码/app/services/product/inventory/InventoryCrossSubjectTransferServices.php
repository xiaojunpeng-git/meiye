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
    /** Match document text or any active product snapshot without narrowing the joined lines used for detail counts. */
    private function applyListKeyword($query, string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') return;
        $like = '%' . $keyword . '%';
        $query->where(function ($matches) use ($like): void {
            $matches->whereRaw('(CONVERT(d.transfer_no USING utf8mb4) COLLATE utf8mb4_general_ci LIKE :transfer_search_no OR CONVERT(d.remark USING utf8mb4) COLLATE utf8mb4_general_ci LIKE :transfer_search_remark)', [
                'transfer_search_no' => $like,
                'transfer_search_remark' => $like,
            ])->whereExists(function ($line) use ($like): void {
                $line->name('inventory_cross_transfer_line')->alias('search_line')
                    ->whereRaw('search_line.document_id=d.id')
                    ->where('search_line.retired_at', 0)
                    ->whereRaw('CONVERT(search_line.product_name_snapshot USING utf8mb4) COLLATE utf8mb4_general_ci LIKE :transfer_search_product', [
                        'transfer_search_product' => $like,
                    ]);
            }, 'OR');
        });
    }

    public function listForStore(int $storeId, int $operatorId, string $keyword, int $page = 1, int $limit = 20, bool $canViewCost = false, string $dateFrom = '', string $dateTo = ''): array
    {
        $scope = $this->scope($storeId, $operatorId, false);
        $query = Db::name('inventory_cross_transfer_document')->alias('d')
            ->leftJoin('inventory_cross_transfer_line l', 'l.document_id=d.id AND l.retired_at=0')
            ->where('d.tenant_id', $scope['tenantId'])
            ->where(function ($query) use ($scope): void {
                $query->where(function ($inner) use ($scope): void { $inner->where('d.from_party_type', 'STORE')->where('d.from_party_id', $scope['storeId']); })
                    // 草稿只属于调出方；调入方直到发货后才有可读单据。
                    ->whereOr(function ($inner) use ($scope): void { $inner->where('d.to_party_type', 'STORE')->where('d.to_party_id', $scope['storeId'])->whereIn('d.document_status', ['DISPATCHED', 'RECEIVED']); })
                    // 门店从自己可见的请货单生成的草稿仍需可追溯；这只增加只读可见性，
                    // 发货和编辑继续由实际调出方能力控制。
                    ->whereOr(function ($inner) use ($scope): void { $inner->where('d.initiator_store_id', $scope['storeId'])->where('d.document_status', 'DRAFT'); });
            });
        if ($dateFrom !== '' && $dateTo !== '') $query->whereBetween('d.business_date', [$dateFrom, $dateTo]);
        $this->applyListKeyword($query, $keyword);
        $count = (int)(clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.transfer_no order_sn,d.from_party_type,d.from_party_id,d.from_party_name_snapshot from_party_name,d.to_party_type,d.to_party_id,d.to_party_name_snapshot to_party_name,d.document_status,d.document_status status_name,d.withdrawal_count,d.initiator_store_id,d.business_date,d.business_date transfer_date,d.recorded_at add_time,d.recorded_at operation_at,d.created_by_operator_name_snapshot operator_name,COUNT(l.id) detail_count,SUM((l.requested_quantity_units*l.quantity_scale*0)+0) quantity_placeholder')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(100, $limit)))->select()->toArray();
        foreach ($list as &$row) {
            $amount = (int)Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', (int)$row['id'])->where('withdrawn_at', 0)
                ->sum(Db::raw('(quantity_units * unit_cost_cents) / POW(10, quantity_scale)'));
            $row['transfer_amount_cents'] = $canViewCost ? $amount : null;
            $row['status_name'] = $this->statusName((string)$row['status_name']);
            $row['can_dispatch'] = (string)$row['document_status'] === 'DRAFT' && (int)$row['from_party_id'] === $scope['storeId'];
            $row['can_edit'] = $row['can_dispatch'];
            $row['can_delete'] = $row['can_edit'] && (int)$row['withdrawal_count'] === 0;
            $row['can_withdraw'] = (string)$row['document_status'] === 'DISPATCHED' && (int)$row['from_party_id'] === $scope['storeId'];
            $row['can_receive'] = (string)$row['document_status'] === 'DISPATCHED' && (int)$row['to_party_id'] === $scope['storeId'];
            // 已撤回草稿只能记名作废，不能再物理删除曾发生的库存流水。
            $row['can_cancel'] = false;
            $row['can_reverse'] = $row['can_edit'] && (int)$row['withdrawal_count'] > 0;
        }
        unset($row);
        return ['count' => $count, 'list' => $list];
    }

    public function detailForStore(int $storeId, int $operatorId, int $documentId, bool $canViewCost = false): array
    {
        $scope = $this->scope($storeId, $operatorId, false);
        $document = $this->lockedStoreDocument($scope, $documentId, false);
        $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->where('retired_at', 0)->order('line_no asc')->select()->toArray();
        $document['edit_token'] = $this->draftEditToken($document, $lines);
        $document['can_edit'] = (string)$document['document_status'] === 'DRAFT'
            && (string)$document['from_party_type'] === 'STORE' && (int)$document['from_party_id'] === $scope['storeId'];
        $document['can_delete'] = $document['can_edit'] && (int)$document['withdrawal_count'] === 0;
        $document['can_withdraw'] = (string)$document['document_status'] === 'DISPATCHED'
            && (string)$document['from_party_type'] === 'STORE' && (int)$document['from_party_id'] === $scope['storeId'];
        $document['can_reverse'] = $document['can_edit'] && (int)$document['withdrawal_count'] > 0;
        foreach ($lines as &$line) {
            $allocations = Db::name('inventory_cross_transfer_batch_allocation')->where('line_id', (int)$line['id'])->where('withdrawn_at', 0)->order('id asc')->select()->toArray();
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
        foreach ($this->headquartersDirectory($scope) as $location) $allowed[] = [
            'id' => 0, 'name' => (string)$location['location_name'], 'party_type' => 'HQ', 'location_id' => (int)$location['id'],
        ];
        return ['list' => $allowed];
    }

    public function incomingRequestsForStore(int $storeId, int $operatorId, bool $canViewCost = false): array
    {
        $scope = $this->scope($storeId, $operatorId, false);
        $documents = Db::name('inventory_stock_request_document')->alias('d')->leftJoin('system_store s', 's.id=d.store_id')
            ->where('d.tenant_id', $scope['tenantId'])
            ->where(function ($visible) use ($scope): void {
                $visible->where(function ($requester) use ($scope): void {
                    $requester->where('d.store_id', $scope['storeId']);
                })->whereOr(function ($supplier) use ($scope): void {
                    $supplier->where('d.supply_party_type', 'STORE')->where('d.supply_party_id', $scope['storeId']);
                });
            })
            ->whereIn('d.document_status', ['APPLIED', 'PARTIAL'])
            ->field('d.id,d.request_no,d.store_id,d.location_id,d.supply_location_id,d.request_party_type,d.request_party_id,d.request_party_name_snapshot,s.name request_store_name,d.supply_party_type,d.supply_party_id,d.supply_party_name_snapshot,d.business_date,d.document_status')
            ->order('d.id desc')->select()->toArray();
        foreach ($documents as &$document) {
            if ((int)($document['request_party_id'] ?? 0) === 0 && (int)($document['store_id'] ?? 0) > 0) {
                $document['request_party_type'] = 'STORE';
                $document['request_party_id'] = (int)$document['store_id'];
                $document['request_party_name_snapshot'] = (string)($document['request_store_name'] ?? '');
            }
            try {
                [$source, $target] = $this->visibleRequestTransferScopes($scope, $document);
            } catch (\RuntimeException $exception) {
                $document['lines'] = [];
                continue;
            }
            $lines = Db::name('inventory_stock_request_line')->where('document_id', (int)$document['id'])->order('line_no asc')->select()->toArray();
            $document['lines'] = $this->incomingRequestSourceLines($lines, $target, $source, $canViewCost);
        }
        unset($document);
        return ['list' => array_values(array_filter($documents, static fn (array $document): bool => (bool)$document['lines']))];
    }

    public function createDraftForStore(int $storeId, int $operatorId, array $input, bool $allowDispatchedReplay = false): array
    {
        $command = $this->normalize($input);
        return Db::transaction(function () use ($storeId, $operatorId, $command, $allowDispatchedReplay): array {
            $viewerScope = $this->scope($storeId, $operatorId, true);
            if ((int)$command['requestDocumentId'] > 0) {
                $request = Db::name('inventory_stock_request_document')->where('id', (int)$command['requestDocumentId'])
                    ->where('tenant_id', $viewerScope['tenantId'])->lock(true)->find();
                if (!$request) throw new \RuntimeException('inventory_cross_transfer_request_unavailable');
                [$sourceScope, $target] = $this->visibleRequestTransferScopes($viewerScope, (array)$request);
                return $this->createDraft($sourceScope, $target, $command, $allowDispatchedReplay);
            }
            $sourceScope = $viewerScope;
            $target = $this->targetForStore($sourceScope, $command);
            // 目录只展示同组织调入方，但服务端仍需防止直接构造跨组织写请求。
            if (!$this->sameOrganizationRoot($sourceScope, $target)) throw new \RuntimeException('inventory_cross_transfer_target_scope_denied');
            return $this->createDraft($sourceScope, $target, $command, $allowDispatchedReplay);
        });
    }

    /** One worksheet may fan out to many source/target documents, but either all drafts exist or none do. */
    public function createDraftBatchForStore(int $storeId, int $operatorId, array $input): array
    {
        $dispatchAfterCreate = ($input['dispatch_after_create'] ?? false) === true;
        return $this->createDraftBatch(
            $input,
            fn (array $draft): array => $this->createDraftForStore($storeId, $operatorId, $draft, $dispatchAfterCreate),
            fn (int $documentId, array $draft): array => $this->dispatch($this->scope($storeId, $operatorId, true), $documentId)
        );
    }

    /** Replace an unshipped source-store draft under its document lock; no stock, allocation or fact is changed. */
    public function updateDraftForStore(int $storeId, int $operatorId, int $documentId, array $input): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $documentId, $input): array {
            $source = $this->scope($storeId, $operatorId, true);
            $payload = $input;
            unset($payload['expected_edit_token']);
            $target = $this->targetForStore($source, $this->normalize($payload));
            // 门店原有同组织调拨约束保留；平台接收目录的放宽不改变门店业务。
            if (!$this->sameOrganizationRoot($source, $target)) throw new \RuntimeException('inventory_cross_transfer_target_scope_denied');
            return $this->updateDraft($source, $target, $documentId, $input);
        });
    }

    /** 两端共用草稿替换：锁定源方、版本和明细，永不修改库存事实。 */
    private function updateDraft(array $source, array $target, int $documentId, array $input, ?array $hqScope = null): array
    {
        $expectedToken = strtolower(trim((string)($input['expected_edit_token'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedToken) !== 1) throw new \InvalidArgumentException('inventory_cross_transfer_edit_token_invalid');
        unset($input['expected_edit_token']);
        $command = $this->normalize($input);
        return Db::transaction(function () use ($source, $target, $documentId, $command, $expectedToken, $hqScope): array {
            $document = $this->lockedSubjectDocument($source, $documentId, true);
            $this->assertSourceDraft($source, $document, 'edit');
            $previousLines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->where('retired_at', 0)->order('line_no asc')->lock(true)->select()->toArray();
            if (!hash_equals($this->draftEditToken($document, $previousLines), $expectedToken)) throw new \RuntimeException('inventory_cross_transfer_draft_changed');
            $hasDispatchHistory = (bool)Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $documentId)->lock(true)->find();

            $this->assertSameTenant($source, $target);
            $source['location'] = $this->subjectLocation($source, $command['now']);
            $target['location'] = $this->subjectLocation($target, $command['now']);
            if ((int)$source['location']['id'] === (int)$target['location']['id']) throw new \RuntimeException('inventory_cross_transfer_target_scope_denied');
            // A request-backed transfer must still belong to the same request and receiving subject.
            if ((int)$document['request_document_id'] !== (int)$command['requestDocumentId']
                || ((int)$document['request_document_id'] > 0 && ((string)$document['to_party_type'] !== $target['partyType']
                    || (int)$document['to_party_id'] !== (int)$target['partyId'] || (int)$document['to_location_id'] !== (int)$target['location']['id']))) throw new \RuntimeException('inventory_cross_transfer_request_target_locked');
            $request = $this->requestDocument($target, $command['requestDocumentId'], $source);
            $targetLocation = $this->subjectLocation($target, $command['now']);
            // 已发货明细仍被历史分配和流水引用；退役后另起明细代次，不删除审计链。
            $generation = 1;
            if ($hasDispatchHistory) {
                $generation = (int)Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->max('line_generation') + 1;
                Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->where('retired_at', 0)->update(['retired_at' => $command['now']]);
            } else {
                Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->delete();
            }
            $this->persistDraftLines($source, $target, $request, $command, $documentId, $generation);
            // 平台记名人员必须重新验证，不信任浏览器提交的员工 ID 或旧快照。
            $staff = $hqScope === null ? null : $this->platformTransferStaff($hqScope, $source, $target, (int)$command['transferStaffId']);
            $staffSnapshot = $staff === null ? [] : [
                'transfer_staff_id' => (int)$staff['id'], 'transfer_employee_id' => (int)($staff['employee_id'] ?? 0),
                'transfer_staff_name_snapshot' => (string)($staff['employee_name'] ?? $staff['staff_name'] ?? $staff['name'] ?? ''),
            ];
            Db::name('inventory_cross_transfer_document')->where('id', $documentId)->update([
                'to_party_type' => $target['partyType'], 'to_party_id' => (int)$target['partyId'],
                'to_party_name_snapshot' => $target['partyName'], 'to_location_id' => (int)$targetLocation['id'],
                'business_date' => $command['date'], 'remark' => $command['remark'],
            ] + $staffSnapshot);
            // Draft replacement changes line IDs without stock effects; keep the independent note editor in sync.
            (new InventoryDocumentRemarkServices())->rebaseAfterFullEdit('transfer', $documentId, $command['remark'], [
                'type' => (string)($hqScope['operatorType'] ?? $source['operatorType'] ?? 'STORE_STAFF'),
                'id' => (int)($hqScope['operatorId'] ?? $source['operatorId']),
            ]);
            return ['transfer_id' => $documentId, 'transfer_no' => (string)$document['transfer_no'], 'document_status' => 'DRAFT'];
        });
    }

    /** Permanently remove only an unchanged source-store draft with no stock or request fulfillment facts. */
    public function deleteDraftForStore(int $storeId, int $operatorId, int $documentId, string $expectedToken): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $documentId, $expectedToken): array {
            return $this->deleteDraft($this->scope($storeId, $operatorId, true), $documentId, $expectedToken);
        });
    }

    /** 两端共用删除边界：仅未发货、无独立事实且版本未变的源方草稿可删除。 */
    private function deleteDraft(array $source, int $documentId, string $expectedToken): array
    {
        $expectedToken = strtolower(trim($expectedToken));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedToken) !== 1) throw new \InvalidArgumentException('inventory_cross_transfer_edit_token_invalid');
        return Db::transaction(function () use ($source, $documentId, $expectedToken): array {
            $document = $this->lockedSubjectDocument($source, $documentId, true);
            $this->assertSourceDraft($source, $document, 'delete');
            // 撤回后虽回到草稿，已有库存事实仍必须留档，不能借删除接口物理移除。
            if ((int)$document['withdrawal_count'] > 0) throw new \RuntimeException('inventory_cross_transfer_delete_facts_exist');
            $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->where('retired_at', 0)->order('line_no asc')->lock(true)->select()->toArray();
            if (!hash_equals($this->draftEditToken($document, $lines), $expectedToken)) throw new \RuntimeException('inventory_cross_transfer_draft_changed');
            // Draft deletion is safe only before dispatch or receipt has left any independent fact.
            if (Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $documentId)->lock(true)->find()
                || Db::name('inventory_stock_request_fulfillment')->where('transfer_document_id', $documentId)->lock(true)->find()
                || Db::name('inventory_cross_transfer_reversal_operation')->where('transfer_document_id', $documentId)->lock(true)->find()
                || Db::name('inventory_batch_movement_fact')->where('tenant_id', (string)$document['tenant_id'])->where('source_id', (string)$document['transfer_no'])->whereLike('source_type', 'cross_transfer_%')->lock(true)->find()) {
                throw new \RuntimeException('inventory_cross_transfer_delete_facts_exist');
            }
            Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->delete();
            if (Db::name('inventory_cross_transfer_document')->where('id', $documentId)->where('document_status', 'DRAFT')->delete() !== 1) {
                throw new \RuntimeException('inventory_cross_transfer_delete_state_invalid');
            }
            return ['transfer_id' => $documentId, 'deleted' => true];
        });
    }

    /** 平台新建调拨只限制调出库存的操作范围；调入方是接收对象，不继承操作人的门店权限。 */
    public function createDraftForHeadquarters(array $adminInfo, int $hqLocationId, array $input, ?array &$batchStaffRows = null, bool $allowDispatchedReplay = false): array
    {
        $command = $this->normalize($input);
        if (!in_array($command['targetPartyType'], ['STORE', 'HQ'], true)) throw new \InvalidArgumentException('inventory_cross_transfer_target_invalid');
        // 生成入口继承调拨页面，源方仓库、商品和租户仍逐单校验。
        $hqScope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer-generate');
        $sourceScope = $hqScope;
        if ($command['sourcePartyType'] === 'STORE') {
            $sourceStoreId = (int)$command['sourceStoreId'];
            if ($sourceStoreId <= 0 || (!$hqScope['isSuperAdmin'] && !in_array($sourceStoreId, $hqScope['allowedStoreIds'], true))) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
            $sourceScope = $this->scope($sourceStoreId, (int)$hqScope['operatorId'], false);
            $hqRoot = explode('/', trim((string)$hqScope['organizationPath'], '/'))[0] ?? '';
            $sourceRoot = explode('/', trim((string)$sourceScope['organizationPath'], '/'))[0] ?? '';
            if ($hqRoot === '' || $sourceRoot === '' || $hqRoot !== $sourceRoot) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
        }
        return Db::transaction(function () use ($sourceScope, $hqScope, $command, &$batchStaffRows, $allowDispatchedReplay): array {
            $target = $command['targetPartyType'] === 'HQ' ? $this->headquartersForScope($sourceScope, $command['targetLocationId']) : $this->scope($command['targetStoreId'], 0, false);
            if ($target === null) throw new \RuntimeException('inventory_cross_transfer_hq_location_missing');
            // 产品确认：调入方不按人员门店/组织权限裁剪；有效实体和租户隔离仍须服务端验证。
            $this->assertSameTenant($sourceScope, $target);
            $command['transferStaff'] = $this->platformTransferStaff($hqScope, $sourceScope, $target, (int)$command['transferStaffId'], $batchStaffRows);
            return $this->createDraft($sourceScope, $target, $command, $allowDispatchedReplay);
        });
    }

    /** Reuse the single-draft permission and SKU checks inside one outer transaction. */
    public function createDraftBatchForHeadquarters(array $adminInfo, array $input): array
    {
        // Scope this cache to one batch call, including on long-lived application workers.
        $staffRows = [];
        $hqScopes = [];
        $dispatchAfterCreate = ($input['dispatch_after_create'] ?? false) === true;
        return $this->createDraftBatch(
            $input,
            function (array $draft) use ($adminInfo, &$staffRows, $dispatchAfterCreate): array {
                $hqLocationId = (int)($draft['hq_location_id'] ?? 0);
                unset($draft['hq_location_id']);
                return $this->createDraftForHeadquarters($adminInfo, $hqLocationId, $draft, $staffRows, $dispatchAfterCreate);
            },
            function (int $documentId, array $draft) use ($adminInfo, &$hqScopes): array {
                $hqLocationId = (int)($draft['hq_location_id'] ?? 0);
                if (!isset($hqScopes[$hqLocationId])) $hqScopes[$hqLocationId] = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer-generate');
                return $this->dispatch($this->platformSourceScope($hqScopes[$hqLocationId], $documentId, true), $documentId);
            }
        );
    }

    /** Create and optionally dispatch every destination inside one transaction; retrying cannot duplicate stock facts. */
    private function createDraftBatch(array $input, callable $create, ?callable $dispatch = null): array
    {
        $keys = array_keys($input);
        sort($keys);
        $dispatchAfterCreate = ($input['dispatch_after_create'] ?? false) === true;
        if (!in_array($keys, [['drafts', 'idempotency_key'], ['dispatch_after_create', 'drafts', 'idempotency_key']], true)
            || (array_key_exists('dispatch_after_create', $input) && !is_bool($input['dispatch_after_create']))
            || ($dispatchAfterCreate && $dispatch === null) || !is_array($input['drafts'])
            || array_keys($input['drafts']) !== array_keys(array_values($input['drafts']))
            || count($input['drafts']) < 1 || count($input['drafts']) > 200
            || !is_string($input['idempotency_key'])
            || preg_match('/^transfer-batch-([1-9][0-9]{0,2})-[A-Za-z0-9._-]{8,64}$/D', $input['idempotency_key'], $batchKey) !== 1
            || (int)$batchKey[1] !== count($input['drafts'])) {
            throw new \InvalidArgumentException('inventory_cross_transfer_batch_invalid');
        }
        $lineCount = 0;
        foreach ($input['drafts'] as $draft) {
            if (!is_array($draft) || array_key_exists('idempotency_key', $draft) || !isset($draft['lines']) || !is_array($draft['lines'])) {
                throw new \InvalidArgumentException('inventory_cross_transfer_batch_invalid');
            }
            $lineCount += count($draft['lines']);
        }
        if ($lineCount > 500) throw new \InvalidArgumentException('inventory_cross_transfer_batch_invalid');
        return Db::transaction(function () use ($input, $create, $dispatch, $dispatchAfterCreate): array {
            $created = [];
            foreach ($input['drafts'] as $index => $draft) {
                // Dispatch commands cannot replay a draft-only command with the same client key.
                $modeKey = $dispatchAfterCreate ? 'dispatch:' : '';
                $draft['idempotency_key'] = 'transfer-batch-' . substr(hash('sha256', $modeKey . $input['idempotency_key'] . ':' . $index), 0, 48);
                $created[] = $create($draft);
            }
            if (!$dispatchAfterCreate) return ['draft_count' => count($created), 'drafts' => $created];
            $replayed = array_filter($created, static fn (array $item): bool => (bool)($item['idempotent'] ?? false));
            if ($replayed) {
                if (count($replayed) !== count($created) || count(array_filter($created, static fn (array $item): bool => in_array((string)$item['document_status'], ['DISPATCHED', 'RECEIVED'], true))) !== count($created)) {
                    throw new \RuntimeException('inventory_cross_transfer_idempotency_conflict');
                }
                return ['draft_count' => count($created), 'dispatch_count' => count($created), 'drafts' => $created, 'idempotent' => true];
            }
            foreach ($created as $index => &$document) {
                $dispatch((int)$document['transfer_id'], $input['drafts'][$index]);
                $document['document_status'] = 'DISPATCHED';
            }
            unset($document);
            return ['draft_count' => count($created), 'dispatch_count' => count($created), 'drafts' => $created, 'idempotent' => false];
        });
    }

    /** 平台按既有调拨功能授权源方，接收方只校验有效实体与租户隔离。 */
    public function updateDraftForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId, array $input): array
    {
        $hqScope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer');
        return Db::transaction(function () use ($hqScope, $documentId, $input): array {
            $source = $this->platformSourceScope($hqScope, $documentId, true);
            $payload = $input;
            unset($payload['expected_edit_token']);
            $command = $this->normalize($payload);
            $target = $this->targetForStore($source, $command);
            // 调出方从已锁定单据解析，不能用浏览器参数改换库存主体。
            return $this->updateDraft($source, $target, $documentId, $input, $hqScope);
        });
    }

    /** 平台删除复用门店相同的版本、草稿状态与无事实保护。 */
    public function deleteDraftForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId, string $expectedToken): array
    {
        $hqScope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer');
        return Db::transaction(function () use ($hqScope, $documentId, $expectedToken): array {
            return $this->deleteDraft($this->platformSourceScope($hqScope, $documentId, true), $documentId, $expectedToken);
        });
    }

    /** One source and one lifecycle action form an atomic batch; a failed row rolls back every stock fact and document change. */
    public function operateBatchForStore(int $storeId, int $operatorId, array $input): array
    {
        return $this->operateBatch($input, fn (int $documentId): array => $this->scope($storeId, $operatorId, true));
    }

    /** Platform batch authority is resolved against the actual source warehouse, never the receiving store. */
    public function operateBatchForHeadquarters(array $adminInfo, int $hqLocationId, array $input): array
    {
        $hqScope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer');
        return $this->operateBatch($input, fn (int $documentId): array => $this->platformSourceScope($hqScope, $documentId, true));
    }

    private function operateBatch(array $input, callable $sourceResolver): array
    {
        $action = strtoupper(trim((string)($input['action'] ?? '')));
        $entries = $input['documents'] ?? null;
        if (!in_array($action, ['DELETE_DRAFT', 'DISPATCH', 'WITHDRAW'], true)
            || !is_array($entries) || count($entries) < 2 || count($entries) > 200 || array_keys($entries) !== range(0, count($entries) - 1)) {
            throw new \InvalidArgumentException('inventory_cross_transfer_batch_action_invalid');
        }
        $byId = [];
        foreach ($entries as $entry) {
            $rawId = is_array($entry) ? $entry['id'] ?? null : null;
            if ((!is_int($rawId) && !is_string($rawId)) || preg_match('/^[1-9][0-9]*$/D', (string)$rawId) !== 1) {
                throw new \InvalidArgumentException('inventory_cross_transfer_batch_action_invalid');
            }
            $id = (int)$rawId;
            if ($id <= 0 || (string)$id !== (string)$rawId || isset($byId[$id])) throw new \InvalidArgumentException('inventory_cross_transfer_batch_action_invalid');
            $token = strtolower(trim((string)($entry['expected_edit_token'] ?? '')));
            if ($action !== 'WITHDRAW' && preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                throw new \InvalidArgumentException('inventory_cross_transfer_batch_action_invalid');
            }
            $count = $entry['expected_withdrawal_count'] ?? null;
            if ($action === 'WITHDRAW' && ((!is_int($count) && !is_string($count)) || preg_match('/^(0|[1-9][0-9]*)$/D', (string)$count) !== 1)) {
                throw new \InvalidArgumentException('inventory_cross_transfer_batch_action_invalid');
            }
            if ($action === 'WITHDRAW' && (string)(int)$count !== (string)$count) {
                throw new \InvalidArgumentException('inventory_cross_transfer_batch_action_invalid');
            }
            $byId[$id] = ['id' => $id, 'token' => $token, 'withdrawal_count' => (int)$count];
        }
        ksort($byId, SORT_NUMERIC);
        $key = trim((string)($input['idempotency_key'] ?? ''));
        if ($action === 'WITHDRAW' && preg_match('/^[A-Za-z0-9:._-]{8,64}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('inventory_cross_transfer_batch_action_invalid');
        }

        return Db::transaction(function () use ($action, $byId, $key, $sourceResolver): array {
            $firstId = (int)array_key_first($byId);
            $source = $sourceResolver($firstId);
            $first = null;
            $replays = [];
            // Lock the complete set in ID order before the first mutation. No selected row may silently skip an invalid state.
            foreach ($byId as $id => $entry) {
                $document = $this->lockedSubjectDocument($source, (int)$id, true);
                $this->assertSourceParty($source, $document, 'batch');
                $identity = [(string)$document['from_party_type'], (int)$document['from_party_id'], (int)$document['from_location_id']];
                if ($first === null) $first = $identity;
                if ($identity !== $first) throw new \RuntimeException('inventory_cross_transfer_batch_source_mixed');
                if ($action === 'WITHDRAW') {
                    // A lost response replays the entire successful batch, never a second stock restoration.
                    $replay = $this->lifecycleReplay($source, $document, 'WITHDRAW', $this->batchWithdrawKey($key, (int)$id), '');
                    if ($replay !== null) {
                        if ((int)$document['withdrawal_count'] !== $entry['withdrawal_count'] + 1) {
                            throw new \RuntimeException('inventory_cross_transfer_batch_state_changed');
                        }
                        $replays[$id] = $replay;
                        continue;
                    }
                }
                if ((string)$document['document_status'] !== ($action === 'WITHDRAW' ? 'DISPATCHED' : 'DRAFT')) {
                    throw new \RuntimeException('inventory_cross_transfer_batch_state_changed');
                }
                if ($action === 'WITHDRAW') {
                    if ((int)$document['received_at'] > 0 || (int)$document['withdrawal_count'] !== $entry['withdrawal_count']) {
                        throw new \RuntimeException('inventory_cross_transfer_batch_state_changed');
                    }
                } else {
                    $lines = Db::name('inventory_cross_transfer_line')->where('document_id', (int)$id)
                        ->where('retired_at', 0)->order('line_no asc')->lock(true)->select()->toArray();
                    if (!hash_equals($this->draftEditToken($document, $lines), $entry['token'])) {
                        throw new \RuntimeException('inventory_cross_transfer_draft_changed');
                    }
                }
            }
            if ($replays) {
                if (count($replays) !== count($byId)) throw new \RuntimeException('inventory_cross_transfer_batch_state_changed');
                return ['action' => $action, 'count' => count($replays), 'results' => array_values($replays)];
            }
            $results = [];
            foreach ($byId as $id => $entry) {
                if ($action === 'DELETE_DRAFT') $results[] = $this->deleteDraft($source, (int)$id, $entry['token']);
                elseif ($action === 'DISPATCH') $results[] = $this->dispatch($source, (int)$id);
                else $results[] = $this->withdraw($source, (int)$id, [
                    'idempotency_key' => $this->batchWithdrawKey($key, (int)$id),
                ]);
            }
            return ['action' => $action, 'count' => count($results), 'results' => $results];
        });
    }

    private function batchWithdrawKey(string $key, int $documentId): string
    {
        return 'transfer-batch-withdraw-' . substr(hash('sha256', $key . ':' . $documentId), 0, 48);
    }

    /** HQ 的 partyId 固定为零，必须额外比对仓库，防止跨总部仓操作。 */
    private function assertSourceDraft(array $source, array $document, string $action): void
    {
        if ((string)$document['document_status'] !== 'DRAFT'
            || (string)$document['from_party_type'] !== $source['partyType']
            || (int)$document['from_party_id'] !== (int)$source['partyId']
            || ($source['partyType'] === 'HQ' && (int)$document['from_location_id'] !== (int)$source['location']['id'])) {
            throw new \RuntimeException('inventory_cross_transfer_' . $action . '_state_invalid');
        }
    }

    /** 返回当前平台组织范围内可记名的在职员工；平台账号关联员工默认选中。 */
    public function transferStaffCandidatesForHeadquarters(array $adminInfo, int $hqLocationId, string $sourcePartyType, int $sourceStoreId, int $targetStoreId, int $targetLocationId = 0): array
    {
        // 人员候选仍按原授权范围返回；选中的调入方本身不要求在操作者的门店范围内。
        $hqScope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer-generate');
        $sourcePartyType = strtoupper(trim($sourcePartyType));
        if (!in_array($sourcePartyType, ['HQ', 'STORE'], true) || ($sourcePartyType === 'HQ' && $sourceStoreId !== 0) || ($sourcePartyType === 'STORE' && $sourceStoreId <= 0) || $targetStoreId < 0) throw new \InvalidArgumentException('inventory_cross_transfer_transfer_staff_invalid');
        $sourceScope = $hqScope;
        if ($sourcePartyType === 'STORE') {
            if (!$hqScope['isSuperAdmin'] && !in_array($sourceStoreId, $hqScope['allowedStoreIds'], true)) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
            $sourceScope = $this->scope($sourceStoreId, 0, false);
            if (!$this->sameOrganizationRoot($hqScope, $sourceScope)) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
        }
        $targetScope = $targetStoreId > 0 ? $this->scope($targetStoreId, 0, false) : $this->headquartersForScope($sourceScope, $targetLocationId);
        if ($targetScope === null) throw new \RuntimeException('inventory_cross_transfer_hq_location_missing');
        $this->assertSameTenant($sourceScope, $targetScope);
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

    /** 调入目录仅暴露有效接收对象 ID/名称，不授予其库存、商品、人员或单据的查看/操作权限。 */
    public function counterpartiesForHeadquarters(array $adminInfo, int $hqLocationId, string $sourcePartyType = 'HQ', int $sourceStoreId = 0): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId, false);
        $sourcePartyType = strtoupper(trim($sourcePartyType));
        if (!in_array($sourcePartyType, ['HQ', 'STORE'], true) || ($sourcePartyType === 'HQ' && $sourceStoreId !== 0) || ($sourcePartyType === 'STORE' && $sourceStoreId <= 0)) throw new \InvalidArgumentException('inventory_cross_transfer_source_invalid');
        if ($sourcePartyType === 'STORE') {
            // 调入方放开不代表调出方放开，避免通过目录参数借用未授权门店库存。
            if (!$scope['isSuperAdmin'] && !in_array($sourceStoreId, $scope['allowedStoreIds'], true)) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
            if (!$this->sameOrganizationRoot($scope, $this->scope($sourceStoreId, 0, false))) throw new \RuntimeException('inventory_cross_transfer_source_scope_denied');
        }
        // 独立实例内门店共用可信租户；不再按组织根或人员门店权限筛掉接收对象。
        $rows = Db::name('system_store')->alias('s')->join('organization_store os', 'os.store_id=s.id')
            ->join('organization o', 'o.id=os.org_id')->where('s.is_del', 0)->where('s.is_show', 1)
            ->where('o.is_del', 0)->field('s.id,s.name')->distinct(true)->order('s.id asc')->select()->toArray();
        $list = [];
        foreach ($this->headquartersDirectory($scope) as $location) {
            if ($sourcePartyType === 'HQ' && (int)$location['id'] === (int)$scope['location']['id']) continue;
            $list[] = ['id' => 0, 'name' => (string)$location['location_name'], 'party_type' => 'HQ', 'location_id' => (int)$location['id']];
        }
        foreach ($rows as $row) {
            if ($sourcePartyType === 'STORE' && (int)$row['id'] === $sourceStoreId) continue;
            $list[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'party_type' => 'STORE'];
        }
        return ['list' => $list];
    }

    public function incomingRequestsForHeadquarters(array $adminInfo, int $hqLocationId, bool $canViewCost = false, string $sourcePartyType = 'HQ', int $sourceStoreId = 0): array
    {
        // 待履约选择只服务于调拨页生成表单，不提前执行发货。
        $scope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer-generate');
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
            ->where('d.tenant_id', $scope['tenantId'])->where('d.supply_party_type', $sourcePartyType)->where('d.supply_party_id', $sourceStoreId)->where(function ($query) use ($source, $sourcePartyType): void {
                // 历史门店供货单只有唯一默认仓，允许未保存仓ID的旧单；总部多仓绝不推断。
                $query->where('d.supply_location_id', (int)$source['location']['id']);
                if ($sourcePartyType === 'STORE') $query->whereOr('d.supply_location_id', 0);
            })
            ->whereLike('d.organization_path', '/' . $root . '/%')
            ->whereIn('d.document_status', ['APPLIED', 'PARTIAL'])->field('d.id,d.request_no,d.store_id,d.location_id,d.supply_location_id,d.request_party_type,d.request_party_id,d.request_party_name_snapshot,s.name request_store_name,d.business_date,d.document_status')->order('d.id desc');
        if (!$scope['isSuperAdmin']) $query->where(function ($inner) use ($scope): void {
            $inner->whereIn('d.store_id', $scope['allowedStoreIds'])->whereOr(function ($hq) { $hq->where('d.request_party_type', 'HQ')->where('d.request_party_id', 0); });
        });
        $documents = $query->select()->toArray();
        foreach ($documents as &$document) {
            try {
                $target = strtoupper((string)($document['request_party_type'] ?? 'STORE')) === 'HQ'
                    ? $this->headquartersForScope($scope, (int)$document['location_id'])
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

    public function listForHeadquarters(array $adminInfo, int $hqLocationId, string $keyword, int $page = 1, int $limit = 20, string $fromPartyType = '', int $fromPartyId = 0, string $toPartyType = '', int $toPartyId = 0, string $status = '', string $dateFrom = '', string $dateTo = '', int $fromLocationId = 0, int $toLocationId = 0): array
    {
        // 查询只解析可读范围；按钮能力仍取后端写授权，不能由单据状态独自决定。
        $scope = $this->headquartersScope($adminInfo, $hqLocationId, false);
        $query = Db::name('inventory_cross_transfer_document')->alias('d')->leftJoin('inventory_cross_transfer_line l', 'l.document_id=d.id AND l.retired_at=0')
            ->where('d.tenant_id', $scope['tenantId'])->where(function ($inner) use ($scope, $hqLocationId, $fromPartyType, $fromPartyId): void {
                $inner->where(function ($source) use ($scope, $hqLocationId): void {
                    $source->whereIn('d.from_location_id', $scope['hqLocationIds'])
                        ->where(function ($visible) use ($hqLocationId): void {
                            $visible->where('d.document_status', '<>', 'DRAFT')->whereOr('d.from_location_id', $hqLocationId);
                        });
                })->whereOr(function ($target) use ($scope): void { $target->whereIn('d.to_location_id', $scope['hqLocationIds'])->whereIn('d.document_status', ['DISPATCHED', 'RECEIVED']); });
                if ($scope['allowedStoreIds']) {
                    // 门店草稿只在操作者明确切到该调出门店时出现，不混入总部仓接收清单。
                    $inner->whereOr(function ($source) use ($scope, $fromPartyType, $fromPartyId): void {
                        $source->where('d.from_party_type', 'STORE')->whereIn('d.from_party_id', $scope['allowedStoreIds'])
                            ->where(function ($visible) use ($fromPartyType, $fromPartyId): void {
                                $visible->where('d.document_status', '<>', 'DRAFT');
                                if (strtoupper(trim($fromPartyType)) === 'STORE' && $fromPartyId > 0) {
                                    $visible->whereOr('d.from_party_id', $fromPartyId);
                                }
                            });
                    });
                }
            });
        // 在途单是当前待收货清单；即使调用方附带旧日期，也不能漏掉跨日未收货单。
        if ($status !== 'DISPATCHED' && $dateFrom !== '' && $dateTo !== '') $query->whereBetween('d.business_date', [$dateFrom, $dateTo]);
        $this->applyListKeyword($query, $keyword);
        if (in_array(strtoupper(trim($fromPartyType)), ['HQ', 'STORE'], true)) $query->where('d.from_party_type', strtoupper(trim($fromPartyType)))->where('d.from_party_id', $fromPartyId);
        if (in_array(strtoupper(trim($toPartyType)), ['HQ', 'STORE'], true)) $query->where('d.to_party_type', strtoupper(trim($toPartyType)))->where('d.to_party_id', $toPartyId);
        // Platform filters distinguish physical HQ warehouses even though their party ID is always zero.
        if ($fromLocationId > 0) $query->where('d.from_location_id', $fromLocationId);
        if ($toLocationId > 0) $query->where('d.to_location_id', $toLocationId);
        if ($status !== '') $query->where('d.document_status', $status);
        $count = (int)(clone $query)->group('d.id')->count();
        $list = $query->field('d.id,d.transfer_no order_sn,d.from_party_type,d.from_party_id,d.from_location_id,d.from_party_name_snapshot from_party_name,d.to_party_type,d.to_party_id,d.to_location_id,d.to_party_name_snapshot to_party_name,d.document_status,d.document_status status_name,d.withdrawal_count,d.initiator_store_id,d.business_date,d.business_date transfer_date,d.recorded_at add_time,d.recorded_at operation_at,d.created_by_operator_name_snapshot operator_name,COUNT(l.id) detail_count')
            ->group('d.id')->order('d.id desc')->page(max(1, $page), max(1, min(200, $limit)))->select()->toArray();
        foreach ($list as &$row) {
            $row['status_name'] = $this->statusName((string)$row['status_name']);
            $sourceAllowed = in_array((int)$row['from_location_id'], $scope['hqLocationIds'], true)
                ? true
                : ((string)$row['from_party_type'] === 'STORE' && ($scope['isSuperAdmin'] || in_array((int)$row['from_party_id'], $scope['allowedStoreIds'], true)));
            // 已有调拨编辑功能覆盖源方草稿流程；收货仍必须是本总部仓的在途单。
            $row['can_dispatch'] = $scope['canCreate'] && (string)$row['document_status'] === 'DRAFT' && $sourceAllowed;
            $row['can_edit'] = $row['can_dispatch'];
            $row['can_delete'] = $row['can_edit'] && (int)$row['withdrawal_count'] === 0;
            $row['can_withdraw'] = $scope['canCreate'] && (string)$row['document_status'] === 'DISPATCHED' && $sourceAllowed;
            $row['can_receive'] = $scope['canCreate'] && (string)$row['document_status'] === 'DISPATCHED' && in_array((int)$row['to_location_id'], $scope['hqLocationIds'], true);
            $row['can_cancel'] = false;
            $row['can_reverse'] = $row['can_edit'] && (int)$row['withdrawal_count'] > 0;
        }
        unset($row);
        return ['count' => $count, 'list' => $list, 'can_create' => $scope['canGenerate']];
    }

    public function detailForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId): array
    {
        // 查看详情与写命令共用单据归属检查，但不要求仓库管理写授权。
        $scope = $this->headquartersScope($adminInfo, $hqLocationId, false);
        $documentScope = $this->platformDocumentAccessScope($scope, $adminInfo, $documentId, false);
        $document = $this->lockedSubjectDocument($documentScope, $documentId, false);
        $canViewCost = in_array('inventory.cost.view', (array)((new InventoryPlatformAccessPolicy())->resolve($adminInfo)['features'] ?? []), true);
        $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->where('retired_at', 0)->order('line_no asc')->select()->toArray();
        // 版本仅取持久化内容；展示能力、成本脱敏及 allocations 不参与签名。
        $editToken = $this->draftEditToken($document, $lines);
        foreach ($lines as &$line) {
            $line['allocations'] = Db::name('inventory_cross_transfer_batch_allocation')->where('line_id', (int)$line['id'])->where('withdrawn_at', 0)->order('id asc')->select()->toArray();
            if (!$canViewCost) foreach ($line['allocations'] as &$allocation) $allocation['unit_cost_cents'] = null;
            unset($allocation);
        }
        unset($line);
        $document['status_name'] = $this->statusName((string)$document['document_status']);
        $document['lines'] = $lines;
        // 能力由当前授权和源方归属共同决定，收件方查看不能获得编辑权。
        $sourceAllowed = (string)$document['from_party_type'] === 'HQ'
            ? in_array((int)$document['from_location_id'], $scope['hqLocationIds'], true)
            : (($scope['isSuperAdmin'] || in_array((int)$document['from_party_id'], $scope['allowedStoreIds'], true)) && $this->sameOrganizationRoot($scope, $documentScope));
        $document['can_edit'] = $scope['canCreate'] && $sourceAllowed && (string)$document['document_status'] === 'DRAFT';
        $document['can_delete'] = $document['can_edit'] && (int)$document['withdrawal_count'] === 0;
        $document['can_withdraw'] = $scope['canCreate'] && $sourceAllowed && (string)$document['document_status'] === 'DISPATCHED';
        $document['can_reverse'] = $document['can_edit'] && (int)$document['withdrawal_count'] > 0;
        $document['edit_token'] = $editToken;
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
        // 功能权限控制入口，源方权限、库存版本及幂等由共享发货内核最终复核。
        $scope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer');
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
        // 不因调入方目录可见而授予任意门店收货权；这里只操作本总部仓收货。
        $scope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer');
        return Db::transaction(fn (): array => $this->receive($scope, $documentId));
    }

    /** 撤回仅由当前调出方发起；单据锁与收货共用，库存回补和反向流水必须同事务完成。 */
    public function withdrawForStore(int $storeId, int $operatorId, int $documentId, array $input): array
    {
        return Db::transaction(fn (): array => $this->withdraw($this->scope($storeId, $operatorId, true), $documentId, $input));
    }

    public function withdrawForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId, array $input): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer');
        return Db::transaction(fn (): array => $this->withdraw($this->platformSourceScope($scope, $documentId, true), $documentId, $input));
    }

    public function cancelForStore(int $storeId, int $operatorId, int $documentId): array
    {
        // 保留旧调用契约但在领域服务处拒绝写入，防止控制器外调用绕过新禁令。
        throw new \RuntimeException('inventory_cross_transfer_void_disabled');
    }

    public function cancelForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId): array
    {
        throw new \RuntimeException('inventory_cross_transfer_void_disabled');
    }

    public function reverseForStore(int $storeId, int $operatorId, int $documentId, array $input): array
    {
        return Db::transaction(fn (): array => $this->voidWithdrawnDraft($this->scope($storeId, $operatorId, true), $documentId, $input));
    }

    public function reverseForHeadquarters(array $adminInfo, int $hqLocationId, int $documentId, array $input): array
    {
        $scope = $this->headquartersScope($adminInfo, $hqLocationId, true, 'transfer');
        return Db::transaction(fn (): array => $this->voidWithdrawnDraft($this->platformSourceScope($scope, $documentId, true), $documentId, $input));
    }

    private function createDraft(array $source, array $target, array $command, bool $allowDispatchedReplay = false): array
    {
        $this->assertSameTenant($source, $target);
        $source['location'] = $this->subjectLocation($source, $command['now']);
        $target['location'] = $this->subjectLocation($target, $command['now']);
        if ((int)$source['location']['id'] === (int)$target['location']['id']) {
            throw new \RuntimeException('inventory_cross_transfer_target_invalid');
        }
        $sourceLocation = $this->subjectLocation($source, $command['now']);
        $targetLocation = $this->subjectLocation($target, $command['now']);
        $existing = Db::name('inventory_cross_transfer_document')->where('tenant_id', $source['tenantId'])->where('idempotency_key', $command['key'])->lock(true)->find();
        if ($existing) return $this->replayDraft((array)$existing, $source, $target, $command, $allowDispatchedReplay);
        $request = $this->requestDocument($target, $command['requestDocumentId'], $source);
        $transferNo = (new InventoryBusinessDocumentNumberServices())->next($source['tenantId'], InventoryBusinessDocumentNumberServices::TRANSFER, $command['date'], $command['now']);
        $documentId = (int)Db::name('inventory_cross_transfer_document')->insertGetId([
            'transfer_no' => $transferNo, 'idempotency_key' => $command['key'], 'request_fingerprint' => $command['fingerprint'], 'tenant_id' => $source['tenantId'],
            'from_party_type' => $source['partyType'], 'from_party_id' => (int)$source['partyId'], 'from_party_name_snapshot' => $source['partyName'], 'from_location_id' => (int)$sourceLocation['id'],
            'to_party_type' => $target['partyType'], 'to_party_id' => (int)$target['partyId'], 'to_party_name_snapshot' => $target['partyName'], 'to_location_id' => (int)$targetLocation['id'],
            'request_document_id' => $request ? (int)$request['id'] : 0, 'initiator_store_id' => (int)($source['initiatorStoreId'] ?? ($source['partyType'] === 'STORE' ? $source['storeId'] : 0)),
            'transfer_staff_id' => (int)($command['transferStaff']['id'] ?? 0), 'transfer_employee_id' => (int)($command['transferStaff']['employee_id'] ?? 0), 'transfer_staff_name_snapshot' => (string)($command['transferStaff']['employee_name'] ?? $command['transferStaff']['staff_name'] ?? $command['transferStaff']['name'] ?? ''),
            'created_by_operator_id' => (int)$source['operatorId'], 'created_by_operator_name_snapshot' => (string)($source['operatorName'] ?? ''),
            'document_status' => 'DRAFT', 'remark' => $command['remark'], 'business_date' => $command['date'], 'recorded_at' => $command['now'],
        ]);
        $this->persistDraftLines($source, $target, $request, $command, $documentId);
        return ['transfer_id' => $documentId, 'transfer_no' => $transferNo, 'document_status' => 'DRAFT', 'idempotent' => false];
    }

    /** Validate each source/target SKU and any request line before persisting a draft's replaceable lines. */
    private function persistDraftLines(array $source, array $target, ?array $request, array $command, int $documentId, int $generation = 1): void
    {
        foreach ($command['lines'] as $line) {
            $catalog = $this->crossCatalog($source, $target, $line);
            $requestLine = $this->requestLine($request, $line['requestLineId'], $catalog, $line['quantity']);
            Db::name('inventory_cross_transfer_line')->insert([
                'document_id' => $documentId, 'line_no' => $line['index'] + 1, 'line_generation' => $generation, 'request_line_id' => $requestLine ? (int)$requestLine['id'] : 0,
                'from_product_id' => $catalog['fromProductId'], 'from_sku_id' => $catalog['fromSkuId'], 'from_sku_unique' => $catalog['fromSkuUnique'],
                'to_product_id' => $catalog['toProductId'], 'to_sku_id' => $catalog['toSkuId'], 'to_sku_unique' => $catalog['toSkuUnique'],
                'requested_quantity_units' => $catalog['units'], 'quantity_scale' => $catalog['scale'], 'product_name_snapshot' => $catalog['productName'],
                'sku_name_snapshot' => $catalog['skuName'], 'stock_unit_snapshot' => $catalog['stockUnit'], 'detail_remark' => (string)($line['detailRemark'] ?? ''), 'created_at' => $command['now'],
            ]);
        }
    }

    /** The token covers persisted draft content, so stale tabs cannot overwrite another edit or dispatch. */
    private function draftEditToken(array $document, array $lines): string
    {
        return hash('sha256', (string)json_encode([$document, $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function dispatch(array $scope, int $documentId): array
    {
        $document = $this->lockedSubjectDocument($scope, $documentId, true);
        $this->assertSourceParty($scope, $document, 'dispatch');
        if ((string)$document['document_status'] === 'DISPATCHED') return ['transfer_id' => $documentId, 'document_status' => 'DISPATCHED', 'idempotent' => true];
        if ((string)$document['document_status'] !== 'DRAFT' || (string)$document['from_party_type'] !== $scope['partyType'] || (int)$document['from_party_id'] !== (int)$scope['partyId']) throw new \RuntimeException('inventory_cross_transfer_dispatch_state_invalid');
        $sourceLocation = $this->subjectLocation($scope, time());
        if ((int)$sourceLocation['id'] !== (int)$document['from_location_id']) throw new \RuntimeException('inventory_cross_transfer_source_location_changed');
        $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->where('retired_at', 0)->order('from_product_id asc,from_sku_id asc,id asc')->lock(true)->select()->toArray();
        if (!$lines) throw new \RuntimeException('inventory_cross_transfer_line_missing');
        $attempt = (int)Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $documentId)->max('dispatch_attempt') + 1;
        $now = time();
        foreach ($lines as $line) {
            $this->assertRequestRemaining((int)$line['request_line_id'], (int)$line['requested_quantity_units']);
            $stock = $this->sourceStock($sourceLocation, $line);
            $allocations = $this->allocate($this->sourceBatches((int)$stock['id']), (int)$line['requested_quantity_units']);
            $this->decreaseSource($stock, $allocations, (int)$line['requested_quantity_units'], $now);
            foreach ($allocations as $allocation) {
                $batch = $allocation['batch'];
                $allocationId = (int)Db::name('inventory_cross_transfer_batch_allocation')->insertGetId([
                    'document_id' => $documentId, 'line_id' => (int)$line['id'], 'dispatch_attempt' => $attempt, 'from_stock_id' => (int)$stock['id'], 'from_batch_id' => (int)$batch['id'],
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
        if ((string)$document['to_party_type'] !== (string)$scope['partyType']
            || (int)$document['to_party_id'] !== (int)$scope['partyId']) throw new \RuntimeException('inventory_cross_transfer_receive_state_invalid');
        if ((string)$document['document_status'] === 'RECEIVED') return ['transfer_id' => $documentId, 'document_status' => 'RECEIVED', 'idempotent' => true];
        if ((string)$document['document_status'] !== 'DISPATCHED' || (string)$document['to_party_type'] !== $scope['partyType'] || (int)$document['to_party_id'] !== (int)$scope['partyId']) throw new \RuntimeException('inventory_cross_transfer_receive_state_invalid');
        $location = $this->subjectLocation($scope, time());
        if ((int)$location['id'] !== (int)$document['to_location_id']) throw new \RuntimeException('inventory_cross_transfer_target_location_changed');
        $lines = Db::name('inventory_cross_transfer_line')->where('document_id', $documentId)->where('retired_at', 0)->order('to_product_id asc,to_sku_id asc,id asc')->lock(true)->select()->toArray();
        $now = time();
        foreach ($lines as $line) {
            $stock = $this->targetStock($location, $line, $now);
            $allocations = Db::name('inventory_cross_transfer_batch_allocation')->where('line_id', (int)$line['id'])->where('received_at', 0)->where('withdrawn_at', 0)->order('id asc')->lock(true)->select()->toArray();
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

    /** 保留每轮发货分配与原负向事实，以逐批次正向事实撤销本轮扣减。 */
    private function withdraw(array $scope, int $documentId, array $input): array
    {
        $key = $this->lifecycleKey($input);
        $document = $this->lockedSubjectDocument($scope, $documentId, true);
        $this->assertSourceParty($scope, $document, 'withdraw');
        $existing = $this->lifecycleReplay($scope, $document, 'WITHDRAW', $key, '');
        if ($existing) return $existing;
        if ((string)$document['document_status'] !== 'DISPATCHED' || (int)$document['received_at'] > 0) throw new \RuntimeException('inventory_cross_transfer_withdraw_state_invalid');
        $allocations = Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $documentId)
            ->where('withdrawn_at', 0)->order('id asc')->lock(true)->select()->toArray();
        if (!$allocations) throw new \RuntimeException('inventory_cross_transfer_allocation_missing');
        $attempt = (int)$allocations[0]['dispatch_attempt'];
        $now = time();
        foreach ($allocations as $allocation) {
            if ((int)$allocation['dispatch_attempt'] !== $attempt || (int)$allocation['received_at'] > 0) throw new \RuntimeException('inventory_cross_transfer_withdraw_state_invalid');
            $stock = Db::name('inventory_stock')->where('id', (int)$allocation['from_stock_id'])->lock(true)->find();
            $batch = Db::name('inventory_batch')->where('id', (int)$allocation['from_batch_id'])->lock(true)->find();
            if (!$stock || !$batch || (int)$batch['stock_id'] !== (int)$stock['id']
                || (int)$stock['location_id'] !== (int)$document['from_location_id']
                || (int)$stock['quantity_scale'] !== (int)$allocation['quantity_scale']
                || (int)$batch['unit_cost_cents'] !== (int)$allocation['unit_cost_cents']
                || (string)$batch['batch_status'] !== 'ACTIVE') throw new \RuntimeException('inventory_cross_transfer_source_batch_changed');
            $original = Db::name('inventory_batch_movement_fact')->where('tenant_id', (string)$document['tenant_id'])
                ->where('source_type', 'cross_transfer_out')->where('source_id', (string)$document['transfer_no'])
                ->where('source_detail_id', (string)$allocation['id'])->lock(true)->find();
            if (!$original || (int)$original['stock_id'] !== (int)$stock['id'] || (int)$original['batch_id'] !== (int)$batch['id']
                || (int)$original['quantity_units'] !== (int)$allocation['quantity_units']
                || Db::name('inventory_batch_movement_fact')->where('reversal_of', (int)$original['id'])->lock(true)->find()) {
                throw new \RuntimeException('inventory_cross_transfer_withdraw_fact_invalid');
            }
            $this->increaseTarget((array)$stock, (array)$batch, (int)$allocation['quantity_units'], $now);
            (new InventoryBatchMovementFactServices())->append([
                'factKey' => 'cross_transfer_withdraw:' . hash('sha256', (string)$document['transfer_no'] . ':' . $allocation['id']),
                'tenantId' => (string)$scope['tenantId'], 'organizationId' => (string)$scope['organizationId'],
                'organizationPath' => (string)$scope['organizationPath'], 'storeId' => (int)$scope['storeId'],
                'stockId' => (int)$stock['id'], 'batchId' => (int)$batch['id'], 'direction' => 1,
                'quantityUnits' => (int)$original['quantity_units'], 'unitCostCents' => (int)$original['unit_cost_cents'],
                'costAmountCents' => (int)$original['cost_amount_cents'], 'sourceType' => 'cross_transfer_withdraw',
                'sourceId' => (string)$document['transfer_no'], 'sourceDetailId' => (string)$allocation['id'],
                'reversalOf' => (int)$original['id'], 'businessDate' => date('Y-m-d', $now),
                'occurredAt' => $now, 'settledAt' => $now, 'recordedAt' => $now,
                'operatorType' => (string)($scope['operatorType'] ?? ''), 'operatorId' => (int)$scope['operatorId'],
                'operatorName' => (string)($scope['operatorName'] ?? ''),
            ]);
            if (Db::name('inventory_cross_transfer_batch_allocation')->where('id', (int)$allocation['id'])
                ->where('received_at', 0)->where('withdrawn_at', 0)->update(['withdrawn_at' => $now]) !== 1) {
                throw new \RuntimeException('inventory_cross_transfer_withdraw_state_invalid');
            }
        }
        $this->recordLifecycle($scope, $document, 'WITHDRAW', $attempt, $key, '', $now);
        if (Db::name('inventory_cross_transfer_document')->where('id', $documentId)->where('document_status', 'DISPATCHED')
            ->update(['document_status' => 'DRAFT', 'withdrawal_count' => (int)$document['withdrawal_count'] + 1,
                'dispatched_at' => 0, 'dispatched_by_operator_id' => 0]) !== 1) throw new \RuntimeException('inventory_cross_transfer_withdraw_state_invalid');
        return ['transfer_id' => $documentId, 'document_status' => 'DRAFT', 'idempotent' => false];
    }

    /** 已撤回草稿的作废是终态标记，不能删除曾发生的出库与撤回事实。 */
    private function voidWithdrawnDraft(array $scope, int $documentId, array $input): array
    {
        $key = $this->lifecycleKey($input);
        $reason = trim((string)($input['reason'] ?? ''));
        if (mb_strlen($reason) < 2 || mb_strlen($reason) > 500) throw new \InvalidArgumentException('inventory_cross_transfer_void_reason_invalid');
        $document = $this->lockedSubjectDocument($scope, $documentId, true);
        $this->assertSourceParty($scope, $document, 'reverse');
        $existing = $this->lifecycleReplay($scope, $document, 'VOID', $key, $reason);
        if ($existing) return $existing;
        if ((string)$document['document_status'] !== 'DRAFT' || (int)$document['withdrawal_count'] <= 0) throw new \RuntimeException('inventory_cross_transfer_void_state_invalid');
        if (Db::name('inventory_cross_transfer_batch_allocation')->where('document_id', $documentId)
            ->where(function ($query): void { $query->where('withdrawn_at', 0)->whereOr('received_at', '>', 0); })->lock(true)->find()) {
            throw new \RuntimeException('inventory_cross_transfer_void_state_invalid');
        }
        $now = time();
        $this->recordLifecycle($scope, $document, 'VOID', (int)$document['withdrawal_count'], $key, $reason, $now);
        if (Db::name('inventory_cross_transfer_document')->where('id', $documentId)->where('document_status', 'DRAFT')
            ->update(['document_status' => 'REVERSED', 'cancelled_at' => $now]) !== 1) throw new \RuntimeException('inventory_cross_transfer_void_state_invalid');
        return ['transfer_id' => $documentId, 'document_status' => 'REVERSED', 'idempotent' => false];
    }

    private function lifecycleKey(array $input): string
    {
        $key = trim((string)($input['idempotency_key'] ?? ''));
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) throw new \InvalidArgumentException('inventory_cross_transfer_idempotency_invalid');
        return $key;
    }

    /** 重试只能读回同一命令；旧撤回键在再次发货后不得触发第二次库存回补。 */
    private function lifecycleReplay(array $scope, array $document, string $action, string $key, string $reason): ?array
    {
        $operation = Db::name('inventory_cross_transfer_lifecycle_operation')->where('tenant_id', (string)$scope['tenantId'])->where('idempotency_key', $key)->lock(true)->find();
        if (!$operation) return null;
        if ((int)$operation['transfer_document_id'] !== (int)$document['id'] || (string)$operation['action'] !== $action
            || (string)$operation['reason'] !== $reason) throw new \RuntimeException('inventory_cross_transfer_idempotency_conflict');
        // 历史命令只能在其原轮次的结果状态重放；新一轮发货不能复用旧撤回键。
        if ((int)$operation['dispatch_attempt'] !== (int)$document['withdrawal_count']
            || ($action === 'WITHDRAW' && (string)$document['document_status'] !== 'DRAFT')
            || ($action === 'VOID' && (string)$document['document_status'] !== 'REVERSED')) {
            throw new \RuntimeException('inventory_cross_transfer_idempotency_conflict');
        }
        return ['transfer_id' => (int)$document['id'], 'document_status' => (string)$document['document_status'], 'idempotent' => true];
    }

    private function recordLifecycle(array $scope, array $document, string $action, int $attempt, string $key, string $reason, int $now): void
    {
        Db::name('inventory_cross_transfer_lifecycle_operation')->insert([
            'tenant_id' => (string)$scope['tenantId'], 'transfer_document_id' => (int)$document['id'],
            'action' => $action, 'dispatch_attempt' => $attempt, 'idempotency_key' => $key,
            'request_fingerprint' => hash('sha256', implode(':', [(string)$document['id'], $action, (string)$attempt, $reason])),
            'reason' => $reason, 'operator_type' => (string)($scope['operatorType'] ?? ''),
            'operator_id' => (int)$scope['operatorId'], 'operator_name_snapshot' => (string)($scope['operatorName'] ?? ''),
            'occurred_at' => $now, 'recorded_at' => $now,
        ]);
    }

    private function assertSourceParty(array $scope, array $document, string $action): void
    {
        if ((string)$document['from_party_type'] !== (string)$scope['partyType'] || (int)$document['from_party_id'] !== (int)$scope['partyId']
            || ($scope['partyType'] === 'HQ' && (int)$document['from_location_id'] !== (int)$scope['location']['id'])) {
            throw new \RuntimeException('inventory_cross_transfer_' . $action . '_state_invalid');
        }
    }

    private function normalize(array $input): array
    {
        $legacyKeys = ['idempotency_key', 'business_date', 'remark', 'target_store_id', 'request_document_id', 'lines'];
        // 门店控制器只接受调入方字段，调出方始终由登录会话强制确定。
        // 它与旧兼容格式相比多了 target_party_type，不能误走平台来源主体契约。
        $storeKeys = ['idempotency_key', 'business_date', 'remark', 'target_party_type', 'target_store_id', 'request_document_id', 'lines'];
        $keys = ['idempotency_key', 'business_date', 'remark', 'source_party_type', 'source_store_id', 'target_party_type', 'target_store_id', 'request_document_id', 'lines'];
        $keysWithTransferStaff = ['idempotency_key', 'business_date', 'remark', 'source_party_type', 'source_store_id', 'target_party_type', 'target_store_id', 'request_document_id', 'transfer_staff_id', 'lines'];
        // JSON objects have no business-significant key order. Keep an exact
        // field whitelist (including legacy/store contracts), but accept object
        // spread/serializer reordering without weakening required/type checks.
        // 新契约明确调入总部仓；旧契约只保留原默认仓兼容，不把仓库ID塞进门店ID。
        $targetLocationId = (int)($input['target_location_id'] ?? 0);
        if (isset($input['target_location_id']) && (!is_int($input['target_location_id']) || $targetLocationId < 0)) throw new \InvalidArgumentException('inventory_cross_transfer_target_invalid');
        unset($input['target_location_id']);
        $inputKeys = array_keys($input);
        sort($inputKeys);
        $allowedKeySets = [$legacyKeys, $storeKeys, $keys, $keysWithTransferStaff];
        foreach ($allowedKeySets as &$allowedKeys) sort($allowedKeys);
        unset($allowedKeys);
        if (!in_array($inputKeys, $allowedKeySets, true) || !is_int($input['target_store_id']) || !is_int($input['request_document_id']) || (array_key_exists('transfer_staff_id', $input) && (!is_int($input['transfer_staff_id']) || $input['transfer_staff_id'] < 0)) || !is_array($input['lines']) || !$input['lines'] || count($input['lines']) > 100) throw new \InvalidArgumentException('inventory_cross_transfer_input_invalid');
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
            // Apply the same order-independent exact schema to each detail;
            // canonical output below still gives retries a stable fingerprint.
            $lineKeys = is_array($line) ? array_keys($line) : [];
            sort($lineKeys);
            if (!in_array($lineKeys, [
                ['product_id', 'quantity', 'request_line_id', 'sku_id', 'sku_unique'],
                ['detail_remark', 'product_id', 'quantity', 'request_line_id', 'sku_id', 'sku_unique'],
            ], true) || !is_int($line['product_id']) || !is_int($line['sku_id']) || !is_int($line['request_line_id']) || $line['product_id'] <= 0 || $line['sku_id'] <= 0 || $line['request_line_id'] < 0) throw new \InvalidArgumentException('inventory_cross_transfer_line_invalid');
            $unique = trim((string)$line['sku_unique']); $quantity = trim((string)$line['quantity']);
            if ($unique === '' || strlen($unique) > 64 || preg_match('/^\d+(?:\.\d{1,4})?$/D', $quantity) !== 1 || (float)$quantity <= 0) throw new \InvalidArgumentException('inventory_cross_transfer_line_invalid');
            if (array_key_exists('detail_remark', $line) && !is_string($line['detail_remark'])) throw new \InvalidArgumentException('inventory_cross_transfer_line_remark_invalid');
            $detailRemark = trim($line['detail_remark'] ?? '');
            if (mb_strlen($detailRemark) > 500) throw new \InvalidArgumentException('inventory_cross_transfer_line_remark_invalid');
            $normalized = ['index' => $index, 'productId' => $line['product_id'], 'skuId' => $line['sku_id'], 'skuUnique' => $unique, 'quantity' => $quantity, 'requestLineId' => $line['request_line_id']];
            // 空缺字段沿用历史指纹；显式备注参与新单据的重放校验。
            if (array_key_exists('detail_remark', $line)) $normalized['detailRemark'] = $detailRemark;
            $lines[] = $normalized;
        }
        $date = $this->date((string)$input['business_date']); $remark = mb_substr(trim((string)$input['remark']), 0, 500);
        $legacyFingerprint = hash('sha256', json_encode([$date, $remark, $input['target_store_id'], $input['request_document_id'], $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ['key' => $key, 'date' => $date, 'remark' => $remark, 'sourcePartyType' => $sourcePartyType, 'sourceStoreId' => $sourceStoreId, 'targetPartyType' => $targetPartyType, 'targetStoreId' => $input['target_store_id'], 'targetLocationId' => $targetLocationId, 'requestDocumentId' => $input['request_document_id'], 'transferStaffId' => (int)($input['transfer_staff_id'] ?? 0), 'lines' => $lines, 'fingerprint' => hash('sha256', json_encode([$date, $remark, $sourcePartyType, $sourceStoreId, $targetPartyType, $input['target_store_id'], $input['request_document_id'], $input['transfer_staff_id'] ?? 0, $lines, $targetLocationId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'legacyFingerprint' => $legacyFingerprint, 'now' => time()];
    }

    private function platformTransferStaff(array $hqScope, array $sourceScope, array $targetScope, int $staffId, ?array &$batchStaffRows = null): array
    {
        $rows = $this->platformTransferStaffRows($hqScope, $batchStaffRows);
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

    private function platformTransferStaffRows(array $hqScope, ?array &$batchStaffRows = null): array
    {
        $root = explode('/', trim((string)$hqScope['organizationPath'], '/'))[0] ?? '';
        if ($root === '') throw new \RuntimeException('inventory_cross_transfer_organization_invalid');
        $cacheKey = hash('sha256', json_encode([$root, $hqScope['tenantId'], $hqScope['isSuperAdmin'], $hqScope['allowedStoreIds']]));
        if ($batchStaffRows !== null && isset($batchStaffRows[$cacheKey])) return $batchStaffRows[$cacheKey];
        $query = Db::name('system_store')->where('is_del', 0)->where('is_show', 1)->field('id');
        if (!$hqScope['isSuperAdmin']) $query->whereIn('id', $hqScope['allowedStoreIds']);
        $storeIds = [];
        foreach ($query->select()->toArray() as $store) {
            $organizationId = (int)Db::name('organization_store')->where('store_id', (int)$store['id'])->value('org_id');
            if ($organizationId > 0 && (explode('/', trim($this->organizationPath($organizationId), '/'))[0] ?? '') === $root) $storeIds[] = (int)$store['id'];
        }
        if (!$storeIds) {
            if ($batchStaffRows !== null) $batchStaffRows[$cacheKey] = [];
            return [];
        }
        $rows = Db::name('system_store_staff')->alias('s')->join('employee e', 'e.id=s.employee_id')->join('system_store st', 'st.id=s.store_id')
            ->whereIn('s.store_id', $storeIds)->where('s.status', 1)->where('s.is_del', 0)->where('e.status', 1)->where('e.is_del', 0)->where('st.is_del', 0)->where('st.is_show', 1)
            ->field('s.id,s.employee_id,s.store_id,s.staff_name,e.name employee_name,st.name store_name')->order('s.store_id asc,s.id asc')->select()->toArray();
        if ($batchStaffRows !== null) $batchStaffRows[$cacheKey] = $rows;
        return $rows;
    }

    private function scope(int $storeId, int $operatorId, bool $requireOperator): array
    {
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->where('is_show', 1)->field('id,name')->lock(true)->find();
        // 组织员工的选店会话 ID 不是门店员工 ID；调拨专用策略将真实员工
        // 身份写入审计，并仅在令牌选店内执行产品确认的查看模式调拨操作。
        try {
            $actor = $requireOperator
                ? (new InventoryStoreAccessPolicy())->transferActor($storeId, $operatorId)
                : null;
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException('inventory_cross_transfer_scope_denied', 0, $exception);
        }
        $organizationId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        if (!$store || $organizationId <= 0) throw new \RuntimeException('inventory_cross_transfer_scope_denied');
        return ['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'organizationId' => (string)$organizationId, 'organizationPath' => $this->organizationPath($organizationId), 'storeId' => $storeId, 'storeName' => mb_substr(trim((string)$store['name']), 0, 100), 'operatorId' => (int)($actor['id'] ?? $operatorId), 'operatorType' => (string)($actor['type'] ?? 'STORE_STAFF'), 'operatorName' => mb_substr(trim((string)($actor['name'] ?? '')), 0, 128), 'partyType' => 'STORE', 'partyId' => $storeId, 'partyName' => mb_substr(trim((string)$store['name']), 0, 100)];
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

    /** 查询只读；调拨功能与通用管理分离，未指定功能页面的命令维持原授权。 */
    private function headquartersScope(array $adminInfo, int $hqLocationId, bool $writable = true, string $createPage = ''): array
    {
        $resolved = $writable
            ? (new InventoryHqLocationServices())->writableLocation($adminInfo, $hqLocationId, $createPage)
            : (new InventoryHqLocationServices())->readableLocation($adminInfo, $hqLocationId);
        $scope = (new InventoryHqLocationServices())->scope($resolved['location'], (int)$resolved['access']['admin_id']);
        $scope['partyType'] = 'HQ';
        $scope['partyId'] = 0;
        $scope['partyName'] = (string)$resolved['location']['location_name'];
        $scope['location'] = $resolved['location'];
        $scope['hqLocationIds'] = array_map('intval', array_column((new InventoryHqLocationServices())->locationsForAccess($resolved['access'], !$writable), 'id'));
        $scope['allowedStoreIds'] = array_values(array_map('intval', (array)($resolved['access']['store_ids'] ?? [])));
        $scope['isSuperAdmin'] = !empty($resolved['access']['is_super_admin']);
        $scope['canCreate'] = (new InventoryPlatformAccessPolicy())->canCreate($resolved['access'], 'transfer');
        $scope['canGenerate'] = (new InventoryPlatformAccessPolicy())->canGenerateTransfer($resolved['access']);
        $scope['employeeId'] = (int)($adminInfo['employee_id'] ?? 0);
        $scope['operatorType'] = 'PLATFORM_ADMIN';
        $scope['operatorName'] = mb_substr(trim((string)($adminInfo['real_name'] ?? $adminInfo['name'] ?? $adminInfo['account'] ?? '')), 0, 120);
        return $scope;
    }

    /** 接收目录只提供名称和身份；实际收货仍由对应仓库操作权限控制。 */
    private function headquartersDirectory(array $scope): array
    {
        $root = (int)(explode('/', trim((string)$scope['organizationPath'], '/'))[0] ?? 0);
        return Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('location_type', 'HQ')
            ->where('owner_id', $root)->where('location_status', 'ACTIVE')->order('is_default desc,id asc')->select()->toArray();
    }

    private function headquartersForScope(array $scope, int $locationId = 0): ?array
    {
        $root = (int)(explode('/', trim((string)$scope['organizationPath'], '/'))[0] ?? 0);
        if ($root <= 0) throw new \RuntimeException('inventory_cross_transfer_organization_invalid');
        $location = Db::name('inventory_location')->where('tenant_id', $scope['tenantId'])->where('location_type', 'HQ')->where('owner_id', $root)->where('store_id', 0)->where('location_status', 'ACTIVE')
            ->where($locationId > 0 ? 'id' : 'is_default', $locationId > 0 ? $locationId : 1)->limit(2)->lock(true)->select()->toArray();
        if (!$location) return null;
        if (count($location) !== 1) throw new \RuntimeException('inventory_cross_transfer_hq_location_ambiguous');
        return ['tenantId' => $scope['tenantId'], 'organizationId' => (string)$location[0]['organization_id'], 'organizationPath' => (string)$location[0]['organization_path'], 'storeId' => 0, 'storeName' => '', 'operatorId' => 0, 'partyType' => 'HQ', 'partyId' => 0, 'partyName' => (string)$location[0]['location_name'], 'location' => (array)$location[0]];
    }

    private function targetForStore(array $source, array $command): array
    {
        if ($command['targetPartyType'] === 'STORE') return $this->scope((int)$command['targetStoreId'], 0, false);
        $headquarters = $this->headquartersForScope($source, (int)($command['targetLocationId'] ?? 0));
        if ($headquarters === null) throw new \RuntimeException('inventory_cross_transfer_hq_location_missing');
        return $headquarters;
    }

    /**
     * Resolve a visible request into its immutable transfer direction. A store
     * may initiate the shortcut from either side, but cannot replace the
     * supplier, requester, warehouses or organization root carried by the
     * locked request. Actual dispatch still belongs to the resolved source.
     *
     * @return array{0:array,1:array} source scope, target scope
     */
    private function visibleRequestTransferScopes(array $viewer, array $document): array
    {
        if (!in_array((string)($document['document_status'] ?? ''), ['APPLIED', 'PARTIAL'], true)) {
            throw new \RuntimeException('inventory_cross_transfer_request_unavailable');
        }
        $legacyRequester = (int)($document['store_id'] ?? 0) > 0 && (int)($document['request_party_id'] ?? 0) === 0;
        $requesterType = $legacyRequester ? 'STORE' : strtoupper((string)($document['request_party_type'] ?? ''));
        $requesterId = $legacyRequester ? (int)$document['store_id'] : (int)($document['request_party_id'] ?? 0);
        $supplierType = strtoupper((string)($document['supply_party_type'] ?? ''));
        $supplierId = (int)($document['supply_party_id'] ?? 0);
        $visibleAsRequester = $requesterType === 'STORE' && $requesterId === (int)$viewer['storeId']
            && (int)($document['store_id'] ?? 0) === (int)$viewer['storeId'];
        $visibleAsSupplier = $supplierType === 'STORE' && $supplierId === (int)$viewer['storeId'];
        if (!$visibleAsRequester && !$visibleAsSupplier) throw new \RuntimeException('inventory_cross_transfer_request_unavailable');

        if ($requesterType === 'HQ') {
            $target = $this->headquartersForScope($viewer, (int)($document['location_id'] ?? 0));
        } elseif ($requesterType === 'STORE' && $requesterId > 0) {
            $target = $this->scope($requesterId, 0, false);
        } else {
            $target = null;
        }
        if ($supplierType === 'HQ' && $supplierId === 0) {
            $source = $this->headquartersForScope($viewer, (int)($document['supply_location_id'] ?? 0));
        } elseif ($supplierType === 'STORE' && $supplierId > 0) {
            $source = $this->scope($supplierId, 0, false);
        } else {
            $source = null;
        }
        if ($source === null || $target === null || !$this->sameOrganizationRoot($viewer, $source)
            || !$this->sameOrganizationRoot($viewer, $target)) {
            throw new \RuntimeException('inventory_cross_transfer_request_unavailable');
        }
        // The viewing employee initiates the draft, while stock authority and
        // future dispatch remain attached to the original supplier scope.
        $source['operatorId'] = (int)$viewer['operatorId'];
        $source['operatorType'] = (string)$viewer['operatorType'];
        $source['operatorName'] = (string)$viewer['operatorName'];
        $source['initiatorStoreId'] = (int)$viewer['storeId'];
        return [$source, $target];
    }

    private function subjectLocation(array $scope, int $now): array
    {
        if (($scope['partyType'] ?? 'STORE') === 'STORE') return $this->defaultLocation($scope, $now);
        $id = (int)($scope['location']['id'] ?? 0);
        $location = Db::name('inventory_location')->where('id', $id)->lock(true)->find();
        if (!$location || (string)$location['tenant_id'] !== (string)$scope['tenantId'] || (string)$location['location_type'] !== 'HQ'
            || (int)$location['store_id'] !== 0 || (string)$location['location_status'] !== 'ACTIVE') throw new \RuntimeException('inventory_cross_transfer_hq_location_invalid');
        return (array)$location;
    }

    private function crossCatalog(array $source, array $target, array $line): array
    {
        $query = Db::name('store_product_attr_value')->alias('a')->join('store_product p', 'p.id=a.product_id')
            ->where('p.id', $line['productId'])->where('p.is_del', 0)->where('p.is_inventory', 1)->where('a.id', $line['skuId'])->where('a.unique', $line['skuUnique'])->where('a.type', 0)
            // 新调拨单固化当前来源商品单位；历史调拨明细仍保留原快照。
            ->fieldRaw('p.id AS product_id,p.pid AS source_pid,p.store_name,a.id AS sku_id,a.unique AS sku_unique,a.suk AS sku_name,p.salon_stock_enabled,' . InventoryProductUnitPolicy::selectSql())->lock(true);
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
            || (int)$request['supply_party_id'] !== (int)$source['partyId']
            || (int)$request['location_id'] !== (int)$target['location']['id']
            || ((int)($request['supply_location_id'] ?? 0) !== (int)$source['location']['id']
                && !($source['partyType'] === 'STORE' && (int)($request['supply_location_id'] ?? 0) === 0))) throw new \RuntimeException('inventory_cross_transfer_request_unavailable');
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
    /** 同号批次可能来自不同原始入库，按血缘复用；批次号冲突时保留原号前缀并追加来源标识。 */
    private function targetBatch(array $stock, array $source, array $document, int $now): array
    {
        $stockId = (int)$stock['id'];
        $originId = (int)$source['origin_batch_id'];
        if ($originId <= 0) throw new \RuntimeException('inventory_cross_transfer_source_batch_lineage_missing');
        $batch = Db::name('inventory_batch')->where('stock_id', $stockId)->where('origin_batch_id', $originId)
            ->order('id asc')->lock(true)->find();
        if ($batch) {
            if ((int)$batch['unit_cost_cents'] !== (int)$source['unit_cost_cents']
                || (string)$batch['batch_status'] !== 'ACTIVE') {
                throw new \RuntimeException('inventory_cross_transfer_target_batch_conflict');
            }
            return (array)$batch;
        }

        $batchNo = (string)$source['batch_no'];
        // 旧库存仍以仓库+批次号唯一；只为异源同号生成稳定且可读的仓内编号。
        if (Db::name('inventory_batch')->where('stock_id', $stockId)->where('batch_no', $batchNo)->lock(true)->find()) {
            $batchNo = $this->disambiguatedTargetBatchNo($stockId, $batchNo, $originId);
        }
        $id = (int)Db::name('inventory_batch')->insertGetId([
            'stock_id' => $stockId, 'origin_batch_id' => $originId, 'source_batch_id' => (int)$source['id'],
            'batch_no' => $batchNo, 'manufactured_date' => $source['manufactured_date'],
            'expire_date' => $source['expire_date'], 'received_at' => $now,
            'received_business_date' => $document['business_date'], 'available_quantity_units' => 0,
            'unit_cost_cents' => (int)$source['unit_cost_cents'], 'cost_allocated_quantity_units' => 0,
            'batch_status' => 'ACTIVE', 'version' => 1,
            'product_name_snapshot' => $source['product_name_snapshot'],
            'sku_name_snapshot' => $source['sku_name_snapshot'],
            'product_code_snapshot' => $source['product_code_snapshot'],
            'barcode_snapshot' => $source['barcode_snapshot'],
            'brand_name_snapshot' => $source['brand_name_snapshot'],
            'category_name_snapshot' => $source['category_name_snapshot'],
            'source_order_no_snapshot' => $document['transfer_no'], 'data_quality' => $source['data_quality'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $batch = Db::name('inventory_batch')->where('id', $id)->lock(true)->find();
        if (!$batch) throw new \RuntimeException('inventory_cross_transfer_target_batch_create_failed');
        return (array)$batch;
    }

    /** 候选号在目标库存行锁内寻找，避免与人工批次号撞名；长度遵守批次号 64 字节约束。 */
    private function disambiguatedTargetBatchNo(int $stockId, string $sourceNo, int $originId): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $suffix = '~o' . $originId . ($attempt === 0 ? '' : '-' . $attempt);
            $candidate = substr($sourceNo, 0, 64 - strlen($suffix)) . $suffix;
            if (!Db::name('inventory_batch')->where('stock_id', $stockId)->where('batch_no', $candidate)
                ->lock(true)->find()) return $candidate;
        }
        throw new \RuntimeException('inventory_cross_transfer_target_batch_number_exhausted');
    }
    private function increaseTarget(array $stock,array $batch,int $units,int $now):void{$new=(int)$stock['available_quantity_units']+$units;$estimated=$new===0?(int)$batch['unit_cost_cents']:intdiv((int)$stock['available_quantity_units']*(int)$stock['estimated_unit_cost_cents']+$units*(int)$batch['unit_cost_cents'],$new);if(Db::name('inventory_stock')->where('id',(int)$stock['id'])->where('version',(int)$stock['version'])->update(['available_quantity_units'=>$new,'estimated_unit_cost_cents'=>$estimated,'version'=>(int)$stock['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_cross_transfer_target_stock_changed');if(Db::name('inventory_batch')->where('id',(int)$batch['id'])->where('version',(int)$batch['version'])->update(['available_quantity_units'=>(int)$batch['available_quantity_units']+$units,'version'=>(int)$batch['version']+1,'updated_at'=>$now])!==1)throw new \RuntimeException('inventory_cross_transfer_target_batch_changed');}
    private function appendFact(array $scope,array $stock,array $batch,int $direction,int $units,string $type,string $sourceId,int $detailId,string $date,int $now):void{(new InventoryBatchMovementFactServices())->append(['factKey'=>$type.':'.hash('sha256',$sourceId.':'.$detailId),'tenantId'=>$scope['tenantId'],'organizationId'=>$scope['organizationId'],'organizationPath'=>$scope['organizationPath'],'storeId'=>$scope['storeId'],'stockId'=>(int)$stock['id'],'batchId'=>(int)$batch['id'],'direction'=>$direction,'quantityUnits'=>$units,'unitCostCents'=>(int)$batch['unit_cost_cents'],'costAmountCents'=>intdiv($units*(int)$batch['unit_cost_cents'],10**(int)$stock['quantity_scale']),'sourceType'=>$type,'sourceId'=>$sourceId,'sourceDetailId'=>(string)$detailId,'reversalOf'=>0,'businessDate'=>$date,'occurredAt'=>$now,'settledAt'=>$now,'recordedAt'=>$now,'operatorType'=>(string)($scope['operatorType']??''),'operatorId'=>(int)($scope['operatorId']??0),'operatorName'=>(string)($scope['operatorName']??'')]);}

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
    private function platformDocumentAccessScope(array $hqScope, array $adminInfo, int $documentId, bool $lock): array
    {
        $document = $this->platformTransferDocument($hqScope, $documentId, $lock);
        // List access spans all authorized HQ warehouses; detail must resolve the document's actual location.
        if ((string)$document['from_party_type'] === 'HQ' && in_array((int)$document['from_location_id'], $hqScope['hqLocationIds'], true)) {
            return $this->headquartersScope($adminInfo, (int)$document['from_location_id'], false);
        }
        if ((string)$document['from_party_type'] === 'STORE' && (int)$document['from_party_id'] > 0
            && ($hqScope['isSuperAdmin'] || in_array((int)$document['from_party_id'], $hqScope['allowedStoreIds'], true))) {
            $source = $this->scope((int)$document['from_party_id'], (int)$hqScope['operatorId'], false);
            if ($this->sameOrganizationRoot($hqScope, $source)) return $source;
        }
        if (in_array((string)$document['document_status'], ['DISPATCHED', 'RECEIVED'], true) && (string)$document['to_party_type'] === 'HQ' && in_array((int)$document['to_location_id'], $hqScope['hqLocationIds'], true)) {
            return $this->headquartersScope($adminInfo, (int)$document['to_location_id'], false);
        }
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

    /** 同一单据的源方草稿可读，目标方草稿无论列表还是直查都不存在。 */
    private function lockedStoreDocument(array $scope, int $id, bool $lock): array
    {
        $query = Db::name('inventory_cross_transfer_document')->where('id', $id)->where('tenant_id', $scope['tenantId'])
            ->where(function ($q) use ($scope): void {
                $q->where(function ($source) use ($scope): void { $source->where('from_party_type', 'STORE')->where('from_party_id', $scope['storeId']); })
                    ->whereOr(function ($target) use ($scope): void { $target->where('to_party_type', 'STORE')->where('to_party_id', $scope['storeId'])->whereIn('document_status', ['DISPATCHED', 'RECEIVED']); })
                    ->whereOr(function ($initiated) use ($scope): void { $initiated->where('initiator_store_id', $scope['storeId'])->where('document_status', 'DRAFT'); });
            });
        if ($lock) $query->lock(true);
        $row = $query->find();
        if (!$row) throw new \RuntimeException('inventory_cross_transfer_not_found');
        return (array)$row;
    }

    /** 发货和收货在单据锁内使用相同可见性边界，避免目标方预读未发草稿。 */
    private function lockedSubjectDocument(array $scope, int $id, bool $lock): array
    {
        $query = Db::name('inventory_cross_transfer_document')->where('id', $id)->where('tenant_id', $scope['tenantId'])
            ->where(function ($q) use ($scope): void {
                $q->where(function ($source) use ($scope): void {
                    $source->where('from_party_type', $scope['partyType'])->where('from_party_id', (int)$scope['partyId']);
                    if ($scope['partyType'] === 'HQ') $source->where('from_location_id', (int)$scope['location']['id']);
                })->whereOr(function ($target) use ($scope): void {
                    $target->where('to_party_type', $scope['partyType'])->where('to_party_id', (int)$scope['partyId'])->whereIn('document_status', ['DISPATCHED', 'RECEIVED']);
                    if ($scope['partyType'] === 'HQ') $target->where('to_location_id', (int)$scope['location']['id']);
                });
            });
        if ($lock) $query->lock(true);
        $row = $query->find();
        if (!$row) throw new \RuntimeException('inventory_cross_transfer_not_found');
        return (array)$row;
    }
    private function assertSameTenant(array $from,array $to):void{if((string)$from['tenantId'] !== (string)$to['tenantId'])throw new \RuntimeException('inventory_cross_transfer_target_scope_denied');}
    private function fulfilledUnits(int $requestLineId):int{$fulfilled=(int)Db::name('inventory_stock_request_fulfillment')->where('request_line_id',$requestLineId)->sum('fulfilled_quantity_units');$reversed=(int)Db::name('inventory_stock_request_fulfillment_reversal')->where('request_line_id',$requestLineId)->sum('reversed_quantity_units');return max(0,$fulfilled-$reversed);}
    private function assertRequestRemaining(int $requestLineId,int $units):void{if($requestLineId<=0)return;$line=Db::name('inventory_stock_request_line')->where('id',$requestLineId)->lock(true)->find();$document=$line?Db::name('inventory_stock_request_document')->where('id',(int)$line['document_id'])->lock(true)->find():null;if(!$line||!$document||!in_array((string)$document['document_status'],['APPLIED','PARTIAL'],true))throw new \RuntimeException('inventory_cross_transfer_request_unavailable');$requested=(int)$line['requested_quantity_units'];$fulfilled=$this->fulfilledUnits($requestLineId);if($requested<$fulfilled+$units)throw new \RuntimeException('inventory_cross_transfer_request_quantity_exceeded');}
    private function refreshRequestStatus(int $requestId):void{if($requestId<=0)return;$document=Db::name('inventory_stock_request_document')->where('id',$requestId)->lock(true)->find();if(!$document||in_array((string)$document['document_status'],['CANCELLED','TERMINATED'],true))return;$lines=Db::name('inventory_stock_request_line')->where('document_id',$requestId)->lock(true)->select()->toArray();if(!$lines)return;$requested=0;$fulfilled=0;foreach($lines as $line){$requested+=(int)$line['requested_quantity_units'];$fulfilled+=$this->fulfilledUnits((int)$line['id']);}$status=$fulfilled>= $requested?'DONE':($fulfilled>0?'PARTIAL':'APPLIED');Db::name('inventory_stock_request_document')->where('id',$requestId)->whereIn('document_status',['APPLIED','PARTIAL','DONE'])->update(['document_status'=>$status]);}
    /** 幂等重放必须绑定两端实际仓库，避免同一总部内切仓重试读回另一仓草稿。 */
    private function replayDraft(array $document, array $from, array $to, array $command, bool $allowDispatchedReplay = false): array
    {
        $legacyStoreReplay = $from['partyType'] === 'STORE' && $to['partyType'] === 'STORE'
            && (string)$document['request_fingerprint'] === (string)($command['legacyFingerprint'] ?? '');
        if (((string)$document['request_fingerprint'] !== $command['fingerprint'] && !$legacyStoreReplay)
            || (string)$document['from_party_type'] !== $from['partyType'] || (int)$document['from_party_id'] !== (int)$from['partyId']
            || (string)$document['to_party_type'] !== $to['partyType'] || (int)$document['to_party_id'] !== (int)$to['partyId']
            || (int)$document['from_location_id'] !== (int)$from['location']['id']
            || (int)$document['to_location_id'] !== (int)$to['location']['id']
            || !in_array((string)$document['document_status'], $allowDispatchedReplay ? ['DRAFT', 'DISPATCHED', 'RECEIVED'] : ['DRAFT'], true)) throw new \RuntimeException('inventory_cross_transfer_idempotency_conflict');
        return ['transfer_id' => (int)$document['id'], 'transfer_no' => (string)$document['transfer_no'], 'document_status' => (string)$document['document_status'], 'idempotent' => true];
    }
    private function statusName(string $status):string{return['DRAFT'=>'草稿','DISPATCHED'=>'在途','RECEIVED'=>'已收货','CANCELLED'=>'已取消','REVERSED'=>'已作废'][$status]??$status;}
    private function date(string $value):string{$date=\DateTimeImmutable::createFromFormat('!Y-m-d',trim($value));$errors=\DateTimeImmutable::getLastErrors();if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d')!==trim($value))throw new \InvalidArgumentException('inventory_cross_transfer_date_invalid');return$date->format('Y-m-d');}
}
