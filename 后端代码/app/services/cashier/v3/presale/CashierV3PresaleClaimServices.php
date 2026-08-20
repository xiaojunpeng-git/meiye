<?php
declare(strict_types=1);

namespace app\services\cashier\v3\presale;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\order\settlement\CashierV3SalesOrderPlanV1;
use app\services\organization\OrganizationScopeService;
use app\services\product\inventory\InventoryBusinessDocumentNumberServices;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/**
 * Presale claims are independent of a sale's financial facts. A settled
 * presale product line opens exactly one claimable projection; each claim is
 * a separately reversible inventory document with its own idempotency key.
 */
final class CashierV3PresaleClaimServices
{
    public const CLAIMABLE_TABLE = 'cashier_v3_presale_claimable_line';
    public const CLAIM_TABLE = 'cashier_v3_presale_claim';
    public const CLAIM_BATCH_TABLE = 'cashier_v3_presale_claim_batch';

    private const STATUS_AVAILABLE = 'AVAILABLE';
    private const STATUS_FULLY_CLAIMED = 'FULLY_CLAIMED';
    private const STATUS_CLOSED = 'CLOSED_AFTER_SALE_REVERSAL';
    private const CLAIM_SETTLED = 'SETTLED';
    private const CLAIM_VOIDED = 'VOIDED';
    private const SOURCE_PRESALE = 'PRESALE';
    private const SOURCE_GIFT = 'GIFT';

    /** Register current checkout presale product lines inside the outer settlement transaction. */
    public function registerSettledSalesOrderInTx(CashierV3SalesOrderPlanV1 $plan): array
    {
        CashierV3TransactionGuard::assertInTransaction('presaleClaim.registerSettledSalesOrderInTx');
        $header = $plan->header();
        $tenantId = $this->token((string)($header['tenant_id'] ?? ''), 32, 'presale_claim_tenant_invalid');
        $memberId = max(0, (int)($header['member_id'] ?? 0));
        $memberPhone = $memberId > 0
            ? mb_substr(trim((string)Db::name('user')->where('uid', $memberId)->value('phone')), 0, 32)
            : '';
        $registered = [];
        foreach ($plan->lines() as $line) {
            if ((int)($line['is_presale'] ?? 0) !== 1 || (string)($line['item_type'] ?? '') !== 'product') {
                continue;
            }
            if ((int)($line['inventory_outbound_required'] ?? 1) !== 0) {
                throw new \RuntimeException('presale_claim_sale_line_outbound_rule_invalid');
            }
            $sourceLineId = $this->token((string)($line['order_line_id'] ?? ''), 64, 'presale_claim_sale_line_invalid');
            $claimableId = 'PCL-' . strtoupper(substr(hash('sha256', $tenantId . '|' . $sourceLineId), 0, 40));
            $row = [
                'claimable_line_id' => $claimableId,
                'source_kind' => self::SOURCE_PRESALE,
                'gift_id' => '',
                'gift_item_id' => '',
                'tenant_id' => $tenantId,
                'organization_id' => $this->token((string)($header['organization_id'] ?? ''), 32, 'presale_claim_organization_invalid'),
                'organization_path_snapshot' => $this->path((string)($header['organization_path_snapshot'] ?? '')),
                'organization_name_snapshot' => $this->text((string)($header['organization_name_snapshot'] ?? ''), 128),
                'store_id' => $this->positiveInt($header['store_id'] ?? 0, 'presale_claim_store_invalid'),
                'store_name_snapshot' => $this->text((string)($header['store_name_snapshot'] ?? ''), 128),
                'source_order_id' => $this->token((string)($header['order_id'] ?? ''), 64, 'presale_claim_order_invalid'),
                'source_order_no_snapshot' => $this->text((string)($header['order_no'] ?? ''), 64),
                'source_order_line_id' => $sourceLineId,
                'source_checkout_request_id' => $this->token((string)($header['checkout_request_id'] ?? ''), 64, 'presale_claim_checkout_invalid'),
                'member_id' => $memberId,
                'member_name_snapshot' => $this->text((string)($header['member_name_snapshot'] ?? ''), 128),
                'member_phone_snapshot' => $memberPhone,
                'product_id' => $this->positiveInt($line['item_id'] ?? 0, 'presale_claim_product_invalid'),
                'sku_id' => $this->positiveInt($line['catalog_sku_id'] ?? 0, 'presale_claim_sku_invalid'),
                'sku_unique_snapshot' => $this->token((string)($line['item_code_snapshot'] ?? ''), 64, 'presale_claim_sku_unique_invalid'),
                'product_name_snapshot' => $this->text((string)($line['item_name_snapshot'] ?? ''), 255),
                'quantity' => $this->positiveInt($line['quantity'] ?? 0, 'presale_claim_quantity_invalid'),
                'claimed_quantity' => 0,
                'claim_status' => self::STATUS_AVAILABLE,
                'close_operation_id' => '', 'closed_at' => 0, 'version' => 1,
                'business_date' => $this->date((string)($header['business_date'] ?? ''), 'presale_claim_business_date_invalid'),
                'occurred_at' => $this->positiveInt($header['occurred_at'] ?? 0, 'presale_claim_occurred_at_invalid'),
                'settled_at' => $this->positiveInt($header['settled_at'] ?? 0, 'presale_claim_settled_at_invalid'),
                'recorded_at' => $this->positiveInt($header['recorded_at'] ?? 0, 'presale_claim_recorded_at_invalid'),
                'created_at' => $this->positiveInt($header['recorded_at'] ?? 0, 'presale_claim_recorded_at_invalid'),
                'updated_at' => $this->positiveInt($header['recorded_at'] ?? 0, 'presale_claim_recorded_at_invalid'),
            ];
            $existing = Db::name(self::CLAIMABLE_TABLE)->where('tenant_id', $tenantId)
                ->where('source_order_line_id', $sourceLineId)->lock(true)->find();
            if ($existing) {
                foreach (['claimable_line_id', 'source_order_id', 'store_id', 'product_id', 'sku_id', 'sku_unique_snapshot', 'quantity'] as $field) {
                    if ((string)$existing[$field] !== (string)$row[$field]) {
                        throw new \RuntimeException('presale_claim_registration_conflict');
                    }
                }
            } elseif ((int)Db::name(self::CLAIMABLE_TABLE)->insert($row) !== 1) {
                throw new \RuntimeException('presale_claim_registration_failed');
            }
            $registered[] = $claimableId;
        }
        return ['claimableLineIds' => $registered];
    }

    /**
     * Register one independently issued product gift as a claimable inventory
     * obligation. The issuance transaction intentionally does not read or
     * change inventory; only claimInTx does that under inventory locks.
     */
    public function registerIssuedGiftProductInTx(
        string $giftId,
        string $giftNo,
        array $giftItem,
        array $member,
        CashierV3OperatorScope $operatorScope
    ): string {
        CashierV3TransactionGuard::assertInTransaction('presaleClaim.registerIssuedGiftProductInTx');
        $giftId = $this->token($giftId, 64, 'gift_claim_gift_invalid');
        $giftNo = $this->text($giftNo, 64);
        $itemId = $this->token((string)($giftItem['item_id'] ?? ''), 64, 'gift_claim_item_invalid');
        $tenantId = $this->token($operatorScope->tenantId(), 32, 'gift_claim_tenant_invalid');
        $storeId = $this->positiveInt($operatorScope->storeId(), 'gift_claim_store_invalid');
        $itemId = $this->token($itemId, 64, 'gift_claim_item_invalid');
        $productId = $this->positiveInt($giftItem['catalog_product_id'] ?? 0, 'gift_claim_product_invalid');
        $snapshot = json_decode((string)($giftItem['content_snapshot_json'] ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $skuId = $this->positiveInt($snapshot['skuId'] ?? $snapshot['sku_id'] ?? 0, 'gift_claim_sku_invalid');
        $skuUnique = $this->token((string)($snapshot['skuUnique'] ?? $snapshot['sku_unique'] ?? ''), 64, 'gift_claim_sku_unique_invalid');
        $quantity = $this->positiveInt($giftItem['quantity'] ?? 0, 'gift_claim_quantity_invalid');
        $now = $this->positiveInt($giftItem['recorded_at'] ?? time(), 'gift_claim_recorded_at_invalid');
        $location = Db::name('inventory_location')->where('tenant_id', $tenantId)->where('store_id', $storeId)
            ->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')
            ->order('id', 'asc')->limit(2)->lock(true)->select()->toArray();
        if (count($location) !== 1) throw new \RuntimeException('gift_claim_default_location_missing');
        $location = (array)$location[0];
        if ((string)$location['organization_id'] !== $operatorScope->organizationId()) {
            throw new \RuntimeException('gift_claim_organization_changed');
        }
        $storeName = $this->text((string)Db::name('system_store')->where('id', $storeId)->value('name'), 128);
        $memberId = max(0, (int)($member['uid'] ?? $member['id'] ?? 0));
        $memberPhone = $memberId > 0
            ? $this->text((string)Db::name('user')->where('uid', $memberId)->value('phone'), 32)
            : '';
        $claimableId = 'GCL-' . strtoupper(substr(hash('sha256', $tenantId . '|' . $itemId), 0, 40));
        $row = [
            'claimable_line_id' => $claimableId,
            'source_kind' => self::SOURCE_GIFT,
            'gift_id' => $giftId,
            'gift_item_id' => $itemId,
            'tenant_id' => $tenantId,
            'organization_id' => $this->token((string)$location['organization_id'], 32, 'gift_claim_organization_invalid'),
            'organization_path_snapshot' => $this->path((string)$location['organization_path']),
            'organization_name_snapshot' => '',
            'store_id' => $storeId,
            'store_name_snapshot' => $storeName,
            'source_order_id' => $giftId,
            'source_order_no_snapshot' => $giftNo,
            'source_order_line_id' => $itemId,
            'source_checkout_request_id' => $giftId,
            'member_id' => $memberId,
            'member_name_snapshot' => $this->text(
                trim((string)($member['real_name'] ?? ''))
                    ?: (trim((string)($member['nickname'] ?? '')) ?: trim((string)($member['name'] ?? ''))),
                128
            ),
            'member_phone_snapshot' => $memberPhone,
            'product_id' => $productId,
            'sku_id' => $skuId,
            'sku_unique_snapshot' => $skuUnique,
            'product_name_snapshot' => $this->text((string)($giftItem['content_name_snapshot'] ?? ''), 255),
            'quantity' => $quantity,
            'claimed_quantity' => 0,
            'claim_status' => self::STATUS_AVAILABLE,
            'close_operation_id' => '', 'closed_at' => 0, 'version' => 1,
            'business_date' => $this->date(date('Y-m-d', $now), 'gift_claim_business_date_invalid'),
            'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ];
        $existing = Db::name(self::CLAIMABLE_TABLE)->where('tenant_id', $tenantId)
            ->where('source_order_line_id', $itemId)->lock(true)->find();
        if ($existing) {
            foreach (['claimable_line_id', 'source_kind', 'gift_id', 'gift_item_id', 'store_id', 'product_id', 'sku_id', 'sku_unique_snapshot', 'quantity'] as $field) {
                if ((string)$existing[$field] !== (string)$row[$field]) throw new \RuntimeException('gift_claim_registration_conflict');
            }
            return (string)$existing['claimable_line_id'];
        }
        if ((int)Db::name(self::CLAIMABLE_TABLE)->insert($row) !== 1) throw new \RuntimeException('gift_claim_registration_failed');
        return $claimableId;
    }

    /** Financial reversal closes remaining eligibility but never changes completed claims or inventory. */
    public function closeForSalesOrderReversalInTx(string $tenantId, string $orderId, string $operationId, int $now): int
    {
        CashierV3TransactionGuard::assertInTransaction('presaleClaim.closeForSalesOrderReversalInTx');
        $tenantId = $this->token($tenantId, 32, 'presale_claim_tenant_invalid');
        $orderId = $this->token($orderId, 64, 'presale_claim_order_invalid');
        $operationId = $this->token($operationId, 64, 'presale_claim_operation_invalid');
        $rows = Db::name(self::CLAIMABLE_TABLE)->where('tenant_id', $tenantId)->where('source_order_id', $orderId)
            ->whereIn('claim_status', [self::STATUS_AVAILABLE, self::STATUS_FULLY_CLAIMED])->lock(true)->select()->toArray();
        $closed = 0;
        foreach ($rows as $row) {
            $affected = Db::name(self::CLAIMABLE_TABLE)->where('id', (int)$row['id'])->where('version', (int)$row['version'])->update([
                'claim_status' => self::STATUS_CLOSED, 'close_operation_id' => $operationId, 'closed_at' => $now,
                'version' => (int)$row['version'] + 1, 'updated_at' => $now,
            ]);
            if ($affected !== 1) throw new \RuntimeException('presale_claim_close_changed');
            $closed++;
        }
        return $closed;
    }

    public function listForStore(int $storeId, int $operatorId, array $criteria): array
    {
        $scope = $this->storeScope($storeId, $operatorId, false);
        return $this->list($scope, $criteria);
    }

    public function listForPlatform(array $adminInfo, array $criteria): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        $allowed = (array)($access['store_ids'] ?? []);
        /** @var OrganizationScopeService $organizationScope */
        $organizationScope = app()->make(OrganizationScopeService::class);
        $hasStoreIds = trim(is_array($criteria['store_ids'] ?? null) ? implode(',', $criteria['store_ids']) : (string)($criteria['store_ids'] ?? '')) !== '';
        $stores = $hasStoreIds
            ? $organizationScope->resolveScopedStoreIdsFromRequest($allowed, ['store_ids' => $criteria['store_ids']])
            : $organizationScope->resolveDashboardStoreIds(
                max(0, (int)($criteria['organization_id'] ?? 0)),
                max(0, (int)($criteria['store_id'] ?? 0)),
                $allowed
            );
        return $this->list(['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'storeIds' => $stores, 'platform' => true], $criteria);
    }

    /** Read-only inventory permission range for the standard organization/store picker. */
    public function scopeForPlatform(array $adminInfo): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        $allowed = array_values(array_unique(array_filter(array_map('intval', (array)($access['store_ids'] ?? [])))));
        /** @var OrganizationScopeService $organizationScope */
        $organizationScope = app()->make(OrganizationScopeService::class);
        return [
            'tree' => $organizationScope->buildPickerTree($allowed),
            'allowed_store_ids' => $allowed,
            'permission_version' => (string)($access['permission_version'] ?? ''),
        ];
    }

    public function claimForStore(int $storeId, int $operatorId, array $input): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $input): array {
            return $this->claimInTx($this->storeScope($storeId, $operatorId, true), $input);
        });
    }

    public function claimForPlatform(array $adminInfo, array $input): array
    {
        return Db::transaction(function () use ($adminInfo, $input): array {
            $storeId = $this->positiveInt($input['store_id'] ?? 0, 'presale_claim_store_invalid');
            return $this->claimInTx($this->platformScope($adminInfo, $storeId), $input);
        });
    }

    public function detailForStore(int $storeId, int $operatorId, string $claimableId): array
    {
        $scope = $this->storeScope($storeId, $operatorId, false);
        return $this->detail($scope, $claimableId);
    }

    public function detailForPlatform(array $adminInfo, string $claimableId): array
    {
        $access = (new InventoryPlatformAccessPolicy())->resolve($adminInfo);
        return $this->detail(['tenantId' => CashierV3ScopeResolver::TENANT_SCOPE_ID, 'storeIds' => (array)$access['store_ids'], 'platform' => true], $claimableId);
    }

    public function voidForStore(int $storeId, int $operatorId, string $claimId, array $input): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $claimId, $input): array {
            return $this->voidInTx($this->storeScope($storeId, $operatorId, true), $claimId, $input);
        });
    }

    public function voidForPlatform(array $adminInfo, string $claimId, array $input): array
    {
        return Db::transaction(function () use ($adminInfo, $claimId, $input): array {
            $storeId = $this->positiveInt($input['store_id'] ?? 0, 'presale_claim_store_invalid');
            return $this->voidInTx($this->platformScope($adminInfo, $storeId), $claimId, $input);
        });
    }

    private function list(array $scope, array $criteria): array
    {
        $page = max(1, min(100000, (int)($criteria['page'] ?? 1)));
        $limit = max(1, min(100, (int)($criteria['limit'] ?? 20)));
        $keyword = mb_substr(trim((string)($criteria['keyword'] ?? '')), 0, 80);
        $status = trim((string)($criteria['status'] ?? ''));
        $sourceKind = strtoupper(trim((string)($criteria['source_kind'] ?? self::SOURCE_PRESALE)));
        $startDate = $this->optionalDate((string)($criteria['start_date'] ?? ''), 'presale_claim_sales_date_invalid');
        $endDate = $this->optionalDate((string)($criteria['end_date'] ?? ''), 'presale_claim_sales_date_invalid');
        if ($status !== '' && !in_array($status, [self::STATUS_AVAILABLE, self::STATUS_FULLY_CLAIMED, self::STATUS_CLOSED], true)) {
            throw new \InvalidArgumentException('presale_claim_status_invalid');
        }
        if (!in_array($sourceKind, [self::SOURCE_PRESALE, self::SOURCE_GIFT], true)) {
            throw new \InvalidArgumentException('presale_claim_source_kind_invalid');
        }
        if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) throw new \InvalidArgumentException('presale_claim_sales_date_range_invalid');
        $query = Db::name(self::CLAIMABLE_TABLE)->where('tenant_id', (string)$scope['tenantId']);
        $storeIds = array_values(array_unique(array_filter(array_map('intval', (array)($scope['storeIds'] ?? [])))));
        if (!$storeIds) return ['list' => [], 'count' => 0, 'page' => $page, 'limit' => $limit, 'data_as_of' => time()];
        $query->whereIn('store_id', $storeIds);
        $query->where('source_kind', $sourceKind);
        if ($status !== '') $query->where('claim_status', $status);
        if ($startDate !== '') $query->where('business_date', '>=', $startDate);
        if ($endDate !== '') $query->where('business_date', '<=', $endDate);
        if ($keyword !== '') {
            $query->where(function ($inner) use ($keyword): void {
                $inner->whereLike('source_order_no_snapshot', '%' . $keyword . '%')
                    ->whereOr('member_name_snapshot', 'like', '%' . $keyword . '%')
                    ->whereOr('member_phone_snapshot', 'like', '%' . $keyword . '%')
                    ->whereOr('product_name_snapshot', 'like', '%' . $keyword . '%');
            });
        }
        $count = (int)(clone $query)->count();
        $rows = $query->order('business_date', 'desc')->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return ['list' => array_map(function (array $row): array {
            $quantity = (int)$row['quantity']; $claimed = (int)$row['claimed_quantity'];
            return [
                'claimable_line_id' => (string)$row['claimable_line_id'], 'source_kind' => (string)$row['source_kind'],
                'presale_order_no' => (string)$row['source_order_no_snapshot'],
                'sales_date' => (string)$row['business_date'], 'store_id' => (int)$row['store_id'], 'store_name' => (string)$row['store_name_snapshot'],
                'organization_id' => (string)$row['organization_id'], 'organization_name' => (string)$row['organization_name_snapshot'],
                'member_id' => (int)$row['member_id'], 'member_name' => (string)$row['member_name_snapshot'], 'member_phone' => (string)$row['member_phone_snapshot'],
                'product_name' => (string)$row['product_name_snapshot'], 'quantity' => $quantity, 'claimed_quantity' => $claimed,
                'unclaimed_quantity' => max(0, $quantity - $claimed), 'claim_status' => (string)$row['claim_status'],
                'can_claim' => (string)$row['claim_status'] === self::STATUS_AVAILABLE && $claimed < $quantity,
              ];
        }, $rows), 'count' => $count, 'page' => $page, 'limit' => $limit, 'data_as_of' => time()];
    }

    private function detail(array $scope, string $claimableId): array
    {
        $claimable = $this->authorizedClaimable($scope, $claimableId, false);
        $claims = Db::name(self::CLAIM_TABLE)->where('tenant_id', (string)$scope['tenantId'])
            ->where('claimable_line_id', (string)$claimable['claimable_line_id'])->order('id', 'desc')->select()->toArray();
        return ['claimable' => $this->claimableProjection($claimable), 'claims' => array_map(static function (array $claim): array {
            return ['claim_id' => (string)$claim['claim_id'], 'claim_no' => (string)$claim['claim_no'], 'quantity' => (int)$claim['quantity'],
                'source_kind' => (string)$claim['source_kind'], 'status' => (string)$claim['claim_status'], 'operator_name' => (string)$claim['operator_name_snapshot'],
                'occurred_at' => (int)$claim['occurred_at'], 'voided_at' => (int)$claim['voided_at'], 'void_reason' => (string)$claim['void_reason_snapshot']];
        }, $claims)];
    }

    private function claimInTx(array $scope, array $input): array
    {
        $command = $this->claimCommand($input);
        $existing = Db::name(self::CLAIM_TABLE)->where('tenant_id', (string)$scope['tenantId'])
            ->where('idempotency_key', $command['idempotencyKey'])->lock(true)->find();
        if ($existing) return $this->replayClaim((array)$existing, $scope, $command);

        $claimable = $this->authorizedClaimable($scope, $command['claimableLineId'], true);
        if ((string)$claimable['claim_status'] !== self::STATUS_AVAILABLE) {
            throw new \RuntimeException((string)$claimable['claim_status'] === self::STATUS_CLOSED ? 'presale_claim_closed_after_sale_reversal' : 'presale_claim_fully_claimed');
        }
        $remaining = (int)$claimable['quantity'] - (int)$claimable['claimed_quantity'];
        if ($command['quantity'] > $remaining) throw new \RuntimeException('presale_claim_exceeds_remaining');
        $location = $this->defaultLocation($scope);
        $stock = $this->lockStock($scope, $location, $claimable);
        $units = $command['quantity'] * (10 ** (int)$stock['quantity_scale']);
        $allocations = $this->allocateBatches((int)$stock['id'], $units);
        $now = $command['now'];
        $claimId = 'PCC-' . strtoupper(substr(hash('sha256', $scope['tenantId'] . '|' . $command['claimableLineId'] . '|' . $command['idempotencyKey']), 0, 40));
        $sourceKind = (string)$claimable['source_kind'];
        $isGift = $sourceKind === self::SOURCE_GIFT;
        $claimNo = (new InventoryBusinessDocumentNumberServices())->next(
            $scope['tenantId'],
            $isGift ? InventoryBusinessDocumentNumberServices::GIFT_PRODUCT_CLAIM : InventoryBusinessDocumentNumberServices::PRESALE_CLAIM,
            $command['businessDate'],
            $now
        );
        $claimPk = (int)Db::name(self::CLAIM_TABLE)->insertGetId([
            'claim_id' => $claimId, 'tenant_id' => $scope['tenantId'], 'claimable_line_id' => $command['claimableLineId'],
            'store_id' => $scope['storeId'], 'location_id' => (int)$location['id'], 'claim_no' => $claimNo,
            'idempotency_key' => $command['idempotencyKey'], 'request_fingerprint' => $command['fingerprint'], 'quantity' => $command['quantity'],
            'source_kind' => $sourceKind, 'claim_status' => self::CLAIM_SETTLED, 'operator_type' => $scope['operatorType'], 'operator_id' => $scope['operatorId'],
            'operator_name_snapshot' => $scope['operatorName'], 'void_idempotency_key' => '', 'void_reason_snapshot' => '', 'void_operator_id' => 0, 'voided_at' => 0,
            'business_date' => $command['businessDate'], 'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($claimPk <= 0) throw new \RuntimeException('presale_claim_insert_failed');
        $outboundSourceType = $isGift ? 'gift_product_claim_outbound' : 'presale_claim_outbound';
        Db::name('inventory_business_document_no')->insert(['tenant_id' => $scope['tenantId'], 'source_type' => $outboundSourceType, 'source_id' => $claimId, 'business_date' => $command['businessDate'], 'document_no' => $claimNo, 'created_at' => $now]);
        // A claim header and its formal outbound facts are created before the
        // stock balance changes; the surrounding transaction commits all or none.
        foreach ($allocations as $index => $allocation) {
            $batch = $allocation['batch'];
            $cost = intdiv($allocation['units'] * (int)$batch['unit_cost_cents'], 10 ** (int)$stock['quantity_scale']);
            $factId = (new InventoryBatchMovementFactServices())->append([
                'factKey' => 'presale-claim-out:' . hash('sha256', $claimId . ':' . (int)$batch['id'] . ':' . $index),
                'tenantId' => $scope['tenantId'], 'organizationId' => $scope['organizationId'], 'organizationPath' => $scope['organizationPath'], 'storeId' => $scope['storeId'],
                'stockId' => (int)$stock['id'], 'batchId' => (int)$batch['id'], 'direction' => -1, 'quantityUnits' => $allocation['units'],
                'unitCostCents' => (int)$batch['unit_cost_cents'], 'costAmountCents' => $cost,
                'sourceType' => $outboundSourceType, 'sourceId' => $claimId, 'sourceDetailId' => (string)$claimPk . ':' . $index,
                'reversalOf' => 0, 'businessDate' => $command['businessDate'], 'occurredAt' => $now, 'settledAt' => $now, 'recordedAt' => $now,
            ]);
            Db::name(self::CLAIM_BATCH_TABLE)->insert(['tenant_id' => $scope['tenantId'], 'claim_id' => $claimId, 'claim_line_id' => $claimPk,
                'movement_fact_id' => $factId, 'stock_id' => (int)$stock['id'], 'batch_id' => (int)$batch['id'], 'quantity_units' => $allocation['units'],
                'quantity_scale' => (int)$stock['quantity_scale'], 'unit_cost_cents' => (int)$batch['unit_cost_cents'], 'cost_amount_cents' => $cost, 'created_at' => $now]);
        }
        $this->decreaseBalances($stock, $allocations, $units, $now);
        $newClaimed = (int)$claimable['claimed_quantity'] + $command['quantity'];
        $affected = Db::name(self::CLAIMABLE_TABLE)->where('id', (int)$claimable['id'])->where('version', (int)$claimable['version'])->update([
            'claimed_quantity' => $newClaimed, 'claim_status' => $newClaimed === (int)$claimable['quantity'] ? self::STATUS_FULLY_CLAIMED : self::STATUS_AVAILABLE,
            'version' => (int)$claimable['version'] + 1, 'updated_at' => $now,
        ]);
        if ($affected !== 1) throw new \RuntimeException('presale_claimable_line_changed');
        return ['claim_id' => $claimId, 'claim_no' => $claimNo, 'quantity' => $command['quantity'], 'status' => self::CLAIM_SETTLED, 'idempotent' => false];
    }

    private function voidInTx(array $scope, string $claimId, array $input): array
    {
        $claimId = $this->token($claimId, 64, 'presale_claim_id_invalid');
        $command = $this->voidCommand($input);
        $claim = Db::name(self::CLAIM_TABLE)->where('tenant_id', $scope['tenantId'])->where('claim_id', $claimId)->lock(true)->find();
        if (!$claim || (int)$claim['store_id'] !== $scope['storeId']) throw new \RuntimeException('presale_claim_not_found');
        if ((string)$claim['claim_status'] === self::CLAIM_VOIDED) {
            if (hash_equals((string)$claim['void_idempotency_key'], $command['idempotencyKey'])) return ['claim_id' => $claimId, 'claim_no' => (string)$claim['claim_no'], 'status' => self::CLAIM_VOIDED, 'idempotent' => true];
            throw new \RuntimeException('presale_claim_already_voided');
        }
        if ((string)$claim['claim_status'] !== self::CLAIM_SETTLED) throw new \RuntimeException('presale_claim_status_invalid');
        $claimable = $this->authorizedClaimable($scope, (string)$claim['claimable_line_id'], true);
        $batches = Db::name(self::CLAIM_BATCH_TABLE)->where('tenant_id', $scope['tenantId'])->where('claim_id', $claimId)->order('stock_id', 'asc')->order('batch_id', 'asc')->lock(true)->select()->toArray();
        if (!$batches) throw new \RuntimeException('presale_claim_batch_missing');
        $now = $command['now'];
        foreach ($batches as $allocation) {
            $stock = Db::name('inventory_stock')->where('id', (int)$allocation['stock_id'])->lock(true)->find();
            $batch = Db::name('inventory_batch')->where('id', (int)$allocation['batch_id'])->lock(true)->find();
            if (!$stock || !$batch || (int)$batch['stock_id'] !== (int)$stock['id'] || (int)$stock['location_id'] !== (int)$claim['location_id'] || (string)$stock['tenant_id'] !== $scope['tenantId']) {
                throw new \RuntimeException('presale_claim_batch_scope_invalid');
            }
            $units = (int)$allocation['quantity_units'];
            if (Db::name('inventory_stock')->where('id', (int)$stock['id'])->where('version', (int)$stock['version'])->update([
                'available_quantity_units' => (int)$stock['available_quantity_units'] + $units, 'version' => (int)$stock['version'] + 1, 'updated_at' => $now,
            ]) !== 1 || Db::name('inventory_batch')->where('id', (int)$batch['id'])->where('version', (int)$batch['version'])->update([
                'available_quantity_units' => (int)$batch['available_quantity_units'] + $units, 'version' => (int)$batch['version'] + 1, 'updated_at' => $now,
            ]) !== 1) throw new \RuntimeException('presale_claim_void_stock_changed');
            (new InventoryBatchMovementFactServices())->append([
                'factKey' => 'presale-claim-void:' . hash('sha256', $claimId . ':' . (int)$allocation['movement_fact_id']),
                'tenantId' => $scope['tenantId'], 'organizationId' => $scope['organizationId'], 'organizationPath' => $scope['organizationPath'], 'storeId' => $scope['storeId'],
                'stockId' => (int)$stock['id'], 'batchId' => (int)$batch['id'], 'direction' => 1, 'quantityUnits' => $units,
                'unitCostCents' => (int)$allocation['unit_cost_cents'], 'costAmountCents' => (int)$allocation['cost_amount_cents'],
                'sourceType' => (string)$claim['source_kind'] === self::SOURCE_GIFT ? 'gift_product_claim_void' : 'presale_claim_void', 'sourceId' => $claimId, 'sourceDetailId' => (string)$allocation['id'], 'reversalOf' => (int)$allocation['movement_fact_id'],
                'businessDate' => $command['businessDate'], 'occurredAt' => $now, 'settledAt' => $now, 'recordedAt' => $now,
            ]);
        }
        if (Db::name(self::CLAIM_TABLE)->where('id', (int)$claim['id'])->where('claim_status', self::CLAIM_SETTLED)->update([
            'claim_status' => self::CLAIM_VOIDED, 'void_idempotency_key' => $command['idempotencyKey'], 'void_reason_snapshot' => $command['reason'],
            'void_operator_id' => $scope['operatorId'], 'voided_at' => $now, 'updated_at' => $now,
        ]) !== 1) throw new \RuntimeException('presale_claim_void_changed');
        $newClaimed = (int)$claimable['claimed_quantity'] - (int)$claim['quantity'];
        if ($newClaimed < 0) throw new \RuntimeException('presale_claim_void_quantity_invalid');
        $status = (string)$claimable['claim_status'] === self::STATUS_CLOSED
            ? self::STATUS_CLOSED
            : ($newClaimed === (int)$claimable['quantity'] ? self::STATUS_FULLY_CLAIMED : self::STATUS_AVAILABLE);
        if (Db::name(self::CLAIMABLE_TABLE)->where('id', (int)$claimable['id'])->where('version', (int)$claimable['version'])->update([
            'claimed_quantity' => $newClaimed, 'claim_status' => $status, 'version' => (int)$claimable['version'] + 1, 'updated_at' => $now,
        ]) !== 1) throw new \RuntimeException('presale_claim_void_claimable_changed');
        return ['claim_id' => $claimId, 'claim_no' => (string)$claim['claim_no'], 'status' => self::CLAIM_VOIDED, 'idempotent' => false];
    }

    private function storeScope(int $storeId, int $operatorId, bool $lock): array
    {
        if ($storeId <= 0 || $operatorId <= 0) throw new \RuntimeException('presale_claim_scope_invalid');
        $query = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id', $storeId)
            ->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->order('id', 'asc')->limit(2);
        if ($lock) $query->lock(true);
        $locations = $query->select()->toArray();
        $staff = Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)->where('status', 1)->where('is_del', 0);
        if ($lock) $staff->lock(true);
        $staff = $staff->field('id,staff_name')->find();
        if (count($locations) !== 1 || !$staff) throw new \RuntimeException('presale_claim_scope_denied');
        $location = (array)$locations[0];
        return ['tenantId' => (string)$location['tenant_id'], 'organizationId' => (string)$location['organization_id'], 'organizationPath' => (string)$location['organization_path'],
            'storeId' => $storeId, 'storeIds' => [$storeId], 'operatorId' => $operatorId, 'operatorType' => 'STORE_STAFF', 'operatorName' => $this->text((string)$staff['staff_name'], 128), 'location' => $location];
    }

    private function platformScope(array $adminInfo, int $storeId): array
    {
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->resolve($adminInfo);
        $policy->assertFeature($access, 'inventory.location.manage', '当前岗位未配置“平台仓库管理”权限。');
        if ($storeId <= 0 || (!($access['is_super_admin'] ?? false) && !in_array($storeId, (array)$access['store_ids'], true))) throw new \RuntimeException('presale_claim_platform_store_denied');
        $locations = Db::name('inventory_location')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)->where('store_id', $storeId)
            ->where('location_type', 'STORE')->where('is_default', 1)->where('location_status', 'ACTIVE')->order('id', 'asc')->limit(2)->lock(true)->select()->toArray();
        if (count($locations) !== 1) throw new \RuntimeException('presale_claim_default_location_missing');
        $location = (array)$locations[0];
        return ['tenantId' => (string)$location['tenant_id'], 'organizationId' => (string)$location['organization_id'], 'organizationPath' => (string)$location['organization_path'],
            'storeId' => $storeId, 'storeIds' => [$storeId], 'operatorId' => (int)$access['admin_id'], 'operatorType' => 'PLATFORM_ADMIN', 'operatorName' => '平台管理员#' . (int)$access['admin_id'], 'location' => $location];
    }

    private function authorizedClaimable(array $scope, string $claimableId, bool $lock): array
    {
        $query = Db::name(self::CLAIMABLE_TABLE)->where('tenant_id', $scope['tenantId'])->where('claimable_line_id', $this->token($claimableId, 64, 'presale_claimable_line_id_invalid'));
        $storeIds = array_values(array_unique(array_filter(array_map('intval', (array)($scope['storeIds'] ?? [])))));
        if (!$storeIds) throw new \RuntimeException('presale_claim_scope_denied');
        $query->whereIn('store_id', $storeIds); if ($lock) $query->lock(true);
        $row = $query->find(); if (!$row) throw new \RuntimeException('presale_claimable_line_not_found');
        return (array)$row;
    }

    private function defaultLocation(array $scope): array
    {
        $location = (array)($scope['location'] ?? []);
        if (!$location || (string)$location['tenant_id'] !== $scope['tenantId'] || (int)$location['store_id'] !== $scope['storeId']
            || (string)$location['organization_id'] !== $scope['organizationId'] || (string)$location['organization_path'] !== $scope['organizationPath']) throw new \RuntimeException('presale_claim_default_location_invalid');
        return $location;
    }

    private function lockStock(array $scope, array $location, array $claimable): array
    {
        $stock = Db::name('inventory_stock')->where('tenant_id', $scope['tenantId'])->where('location_id', (int)$location['id'])
            ->where('store_id', $scope['storeId'])->where('consumable_product_id', (int)$claimable['product_id'])->where('sku_id', (int)$claimable['sku_id'])
            ->where('product_unique', (string)$claimable['sku_unique_snapshot'])->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)->lock(true)->find();
        if (!$stock || (int)$stock['available_quantity_units'] <= 0 || (int)$stock['quantity_scale'] < 0 || (int)$stock['quantity_scale'] > 4) throw new \RuntimeException('presale_claim_stock_insufficient');
        return (array)$stock;
    }

    private function allocateBatches(int $stockId, int $units): array
    {
        $batches = Db::name('inventory_batch')->where('stock_id', $stockId)->where('batch_status', 'ACTIVE')->where('available_quantity_units', '>', 0)
            ->orderRaw('expire_date IS NULL ASC, expire_date ASC, received_business_date IS NULL ASC, received_business_date ASC, id ASC')->lock(true)->select()->toArray();
        $remaining = $units; $allocations = [];
        foreach ($batches as $batch) {
            $take = min((int)$batch['available_quantity_units'], $remaining);
            if ($take > 0) $allocations[] = ['batch' => (array)$batch, 'units' => $take];
            $remaining -= $take; if ($remaining === 0) break;
        }
        if ($remaining !== 0) throw new \RuntimeException('presale_claim_stock_insufficient');
        return $allocations;
    }

    private function decreaseBalances(array $stock, array $allocations, int $units, int $now): void
    {
        if (Db::name('inventory_stock')->where('id', (int)$stock['id'])->where('version', (int)$stock['version'])->where('available_quantity_units', '>=', $units)->update([
            'available_quantity_units' => (int)$stock['available_quantity_units'] - $units, 'version' => (int)$stock['version'] + 1, 'updated_at' => $now,
        ]) !== 1) throw new \RuntimeException('presale_claim_stock_changed');
        foreach ($allocations as $allocation) {
            $batch = $allocation['batch'];
            if (Db::name('inventory_batch')->where('id', (int)$batch['id'])->where('version', (int)$batch['version'])->where('available_quantity_units', '>=', (int)$allocation['units'])->update([
                'available_quantity_units' => (int)$batch['available_quantity_units'] - (int)$allocation['units'], 'version' => (int)$batch['version'] + 1, 'updated_at' => $now,
            ]) !== 1) throw new \RuntimeException('presale_claim_batch_changed');
        }
    }

    private function claimCommand(array $input): array
    {
        $key = trim((string)($input['idempotency_key'] ?? '')); $claimable = trim((string)($input['claimable_line_id'] ?? ''));
        $quantity = $this->positiveInt($input['quantity'] ?? 0, 'presale_claim_quantity_invalid');
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1) throw new \InvalidArgumentException('presale_claim_idempotency_invalid');
        $now = time(); $date = $this->date((string)($input['business_date'] ?? date('Y-m-d', $now)), 'presale_claim_business_date_invalid');
        return ['idempotencyKey' => $key, 'claimableLineId' => $this->token($claimable, 64, 'presale_claimable_line_id_invalid'), 'quantity' => $quantity,
            'businessDate' => $date, 'now' => $now, 'fingerprint' => hash('sha256', json_encode([$claimable, $quantity, $date], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
    }

    private function voidCommand(array $input): array
    {
        $key = trim((string)($input['idempotency_key'] ?? '')); $reason = trim((string)($input['reason'] ?? ''));
        if (preg_match('/^[A-Za-z0-9:._-]{8,96}$/D', $key) !== 1 || mb_strlen($reason) < 2 || mb_strlen($reason) > 500) throw new \InvalidArgumentException('presale_claim_void_input_invalid');
        $now = time(); return ['idempotencyKey' => $key, 'reason' => $reason, 'businessDate' => date('Y-m-d', $now), 'now' => $now];
    }

    private function replayClaim(array $claim, array $scope, array $command): array
    {
        if ((string)$claim['claimable_line_id'] !== $command['claimableLineId'] || (int)$claim['quantity'] !== $command['quantity']
            || (int)$claim['store_id'] !== $scope['storeId'] || !hash_equals((string)$claim['request_fingerprint'], $command['fingerprint'])) throw new \RuntimeException('presale_claim_idempotency_conflict');
        return ['claim_id' => (string)$claim['claim_id'], 'claim_no' => (string)$claim['claim_no'], 'quantity' => (int)$claim['quantity'], 'status' => (string)$claim['claim_status'], 'idempotent' => true];
    }

    private function claimableProjection(array $row): array
    {
        $quantity = (int)$row['quantity']; $claimed = (int)$row['claimed_quantity'];
        return ['claimable_line_id' => (string)$row['claimable_line_id'], 'source_kind' => (string)$row['source_kind'], 'presale_order_no' => (string)$row['source_order_no_snapshot'], 'sales_date' => (string)$row['business_date'],
            'member_name' => (string)$row['member_name_snapshot'], 'member_phone' => (string)$row['member_phone_snapshot'], 'product_name' => (string)$row['product_name_snapshot'],
            'quantity' => $quantity, 'claimed_quantity' => $claimed, 'unclaimed_quantity' => max(0, $quantity - $claimed), 'claim_status' => (string)$row['claim_status']];
    }

    private function positiveInt($value, string $error): int { if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) throw new \InvalidArgumentException($error); return (int)$value; }
    private function token(string $value, int $max, string $error): string { $value = trim($value); if ($value === '' || strlen($value) > $max || preg_match('/^[A-Za-z0-9_.:-]+$/D', $value) !== 1) throw new \InvalidArgumentException($error); return $value; }
    private function text(string $value, int $max): string { return mb_substr(trim($value), 0, $max); }
    private function path(string $value): string { $value = trim($value); if ($value === '' || strlen($value) > 512 || preg_match('#^/(?:[1-9][0-9]*/)+$#D', $value) !== 1) throw new \InvalidArgumentException('presale_claim_organization_path_invalid'); return $value; }
    private function date(string $value, string $error): string { $value = trim($value); $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException($error); return $value; }
    private function optionalDate(string $value, string $error): string { $value = trim($value); return $value === '' ? '' : $this->date($value, $error); }
}
