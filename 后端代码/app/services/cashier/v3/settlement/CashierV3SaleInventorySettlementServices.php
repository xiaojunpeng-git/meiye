<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryCompletionContractException;
use app\services\product\inventory\completion\InventoryCompletionDataScope;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use think\facade\Db;

/**
 * Final-transaction stock settlement for ordinary inventory products.
 *
 * The service deliberately does not reuse the entitlement-consumption
 * provider: a retail sale is not a project service completion. It does reuse
 * the inventory-owned stock, batch, scope, lock-order and movement-fact
 * contracts so the two paths share one physical inventory authority.
 */
final class CashierV3SaleInventorySettlementServices
{
    public const CONTRACT_VERSION = 'cashier-v3-sale-inventory-settlement-v1';

    private const RECEIPT_TABLE = 'cashier_v3_sale_inventory_receipt';
    private const MAX_LINES = 200;
    private const MAX_ALLOCATIONS = 5000;

    /**
     * Build the immutable stock plan after the checkout aggregate and catalog
     * rows are already locked by the final Gateway transaction.
     */
    public function planInTx(
        array $lockedRequest,
        array $salesOrder,
        array $salesLines,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        try {
            $this->assertTransaction();
            $context = $this->normalizeContext(
                $lockedRequest,
                $salesOrder,
                $operatorScope,
                $dataScope
            );
            $scope = new InventoryCompletionDataScope(
                $context['tenantId'],
                $context['organizationId'],
                $context['organizationPath'],
                [$context['storeId']],
                $context['operatorId']
            );
            $inventoryLines = $this->lockInventorySaleLines($salesLines, $context);
            if (!$inventoryLines) {
                return $this->emptyPlan($context);
            }

            $location = $this->defaultLocation($context, $scope);
            $stockSet = $this->lockStocks($inventoryLines, $context, (int)$location['id']);
            $inventoryLines = $stockSet['lines'];
            $stocks = $stockSet['stocks'];
            $batches = $this->lockBatches($stocks, $context);
            $plan = $this->allocate($inventoryLines, $stocks, $batches, $context);
            $plan['locationId'] = (int)$location['id'];
            $plan['planFingerprint'] = CashierV3CheckoutSettlementCanonicalizer::fingerprint(
                $this->fingerprintInput($plan)
            );
            return $plan;
        } catch (InventoryCompletionContractException $exception) {
            // 库存领域的拒绝必须作为可绑定的结账失败返回。若让其越过
            // 收银边界，浏览器只能看到“结果未知”，容易诱发重复结账。
            throw $this->failure($exception->reason(), $exception->detail());
        }
    }

    /**
     * Persist a pre-locked plan. The caller remains responsible for the outer
     * checkout transaction, so every failure rolls back sales, payment,
     * inventory, facts and request completion together.
     */
    public function persistInTx(array $plan): array
    {
        $this->assertTransaction();
        $plan = $this->normalizePlan($plan);
        if (!$plan['lineAllocations']) {
            return [
                'contractVersion' => self::CONTRACT_VERSION,
                'receiptId' => '',
                'replayed' => false,
                'inventoryLineCount' => 0,
                'batchAllocationCount' => 0,
                'actualCostCents' => 0,
                'eventAllocations' => [],
            ];
        }

        $existing = $this->lockReceipt(
            $plan['tenantId'],
            $plan['receiptId'],
            $plan['commandIdempotencyKey']
        );
        if ($existing) {
            return $this->replayReceipt($existing, $plan);
        }

        $this->applyStockActions($plan['stockActions'], $plan['recordedAt']);
        $this->applyBatchActions($plan['batchActions'], $plan['recordedAt']);

        $receiptPk = (int)Db::name(self::RECEIPT_TABLE)->insertGetId([
            'receipt_id' => $plan['receiptId'],
            'command_idempotency_key' => $plan['commandIdempotencyKey'],
            'plan_fingerprint' => $plan['planFingerprint'],
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $plan['tenantId'],
            'organization_id' => $plan['organizationId'],
            'organization_path_snapshot' => $plan['organizationPath'],
            'organization_name_snapshot' => $plan['organizationNameSnapshot'],
            'store_id' => $plan['storeId'],
            'store_name_snapshot' => $plan['storeNameSnapshot'],
            'operator_id' => $plan['operatorId'],
            'checkout_request_id' => $plan['checkoutRequestId'],
            'sales_order_id' => $plan['salesOrderId'],
            'actual_cost_cents' => $plan['actualCostCents'],
            'allocation_count' => $plan['allocationCount'],
            'result_snapshot' => '',
            'business_date' => $plan['businessDate'],
            'occurred_at' => $plan['occurredAt'],
            'settled_at' => $plan['settledAt'],
            'recorded_at' => $plan['recordedAt'],
            'add_time' => $plan['recordedAt'],
            'update_time' => $plan['recordedAt'],
        ]);
        if ($receiptPk <= 0) {
            throw $this->failure('sale_inventory_receipt_insert_failed');
        }

        $movementFacts = new InventoryBatchMovementFactServices();
        $eventAllocations = [];
        foreach ($plan['lineAllocations'] as $line) {
            foreach ($line['allocations'] as $index => $allocation) {
                try {
                    $movementFacts->append([
                        'factKey' => 'invsm:' . hash(
                            'sha256',
                            $plan['receiptId'] . ':' . $line['salesOrderLineId']
                                . ':' . $allocation['batchId'] . ':' . $index
                        ),
                        'tenantId' => $plan['tenantId'],
                        'organizationId' => $plan['organizationId'],
                        'organizationPath' => $plan['organizationPath'],
                        'storeId' => $plan['storeId'],
                        'stockId' => $line['stockId'],
                        'batchId' => $allocation['batchId'],
                        'direction' => -1,
                        'quantityUnits' => $allocation['quantityUnits'],
                        'unitCostCents' => $allocation['unitCostCents'],
                        'costAmountCents' => $allocation['actualCostCents'],
                        'sourceType' => 'cashier_sale',
                        'sourceId' => $plan['salesOrderId'],
                        'sourceDetailId' => $line['salesOrderLineId'],
                        'reversalOf' => 0,
                        'businessDate' => $plan['businessDate'],
                        'occurredAt' => $plan['occurredAt'],
                        'settledAt' => $plan['settledAt'],
                        'recordedAt' => $plan['recordedAt'],
                    ]);
                } catch (InventoryCompletionContractException $exception) {
                    throw $this->failure($exception->reason(), $exception->detail());
                }
                $eventAllocations[] = [
                    'salesOrderLineId' => $line['salesOrderLineId'],
                    'itemNameSnapshot' => $line['itemNameSnapshot'],
                    'stockId' => $line['stockId'],
                    'batchId' => $allocation['batchId'],
                    'quantityUnits' => $allocation['quantityUnits'],
                    'actualCostCents' => $allocation['actualCostCents'],
                ];
            }
        }

        $result = [
            'contractVersion' => self::CONTRACT_VERSION,
            'receiptId' => $plan['receiptId'],
            'replayed' => false,
            'inventoryLineCount' => count($plan['lineAllocations']),
            'batchAllocationCount' => count($eventAllocations),
            'actualCostCents' => $plan['actualCostCents'],
            'eventAllocations' => $eventAllocations,
        ];
        $updated = Db::name(self::RECEIPT_TABLE)
            ->where('id', $receiptPk)
            ->update([
                'result_snapshot' => $this->encode($result),
                'update_time' => $plan['recordedAt'],
            ]);
        if ($updated !== 1) {
            throw $this->failure('sale_inventory_receipt_snapshot_update_failed');
        }
        return $result;
    }

    /** @return array<int,array> */
    public function eventInputs(array $result, array $common): array
    {
        if (($result['contractVersion'] ?? null) !== self::CONTRACT_VERSION
            || !is_array($result['eventAllocations'] ?? null)) {
            throw $this->failure('sale_inventory_event_result_invalid');
        }
        $events = [];
        foreach ($result['eventAllocations'] as $allocation) {
            if (!is_array($allocation)) {
                throw $this->failure('sale_inventory_event_allocation_invalid');
            }
            $lineId = $this->token((string)($allocation['salesOrderLineId'] ?? ''), 64, 'sale_inventory_order_line_invalid');
            $stockId = $this->positiveInt($allocation['stockId'] ?? null, 'sale_inventory_event_stock_invalid');
            $batchId = $this->positiveInt($allocation['batchId'] ?? null, 'sale_inventory_event_batch_invalid');
            $events[] = array_merge($common, [
                'event_type' => 'inventory.sale.deducted',
                'aggregate_type' => 'sales_order_line_inventory_batch',
                'aggregate_id' => 'IBS-' . substr(hash('sha256', $lineId . ':' . $stockId . ':' . $batchId), 0, 40),
                'aggregate_version' => 1,
                'event_version' => 1,
                'detail_id' => $lineId,
                'aggregate_name_snapshot' => (string)$allocation['itemNameSnapshot'],
                'payload' => [
                    'inventorySaleReceiptId' => (string)$result['receiptId'],
                    'salesOrderLineId' => $lineId,
                    'stockId' => $stockId,
                    'batchId' => $batchId,
                    'quantityUnits' => $this->positiveInt(
                        $allocation['quantityUnits'] ?? null,
                        'sale_inventory_event_quantity_invalid'
                    ),
                    'actualCostCents' => $this->nonNegativeInt(
                        $allocation['actualCostCents'] ?? null,
                        'sale_inventory_event_cost_invalid'
                    ),
                ],
            ]);
        }
        return $events;
    }

    private function emptyPlan(array $context): array
    {
        $plan = [
            'contractVersion' => self::CONTRACT_VERSION,
            'receiptId' => '',
            'tenantId' => $context['tenantId'],
            'organizationId' => $context['organizationId'],
            'organizationPath' => $context['organizationPath'],
            'organizationNameSnapshot' => $context['organizationNameSnapshot'],
            'storeId' => $context['storeId'],
            'storeNameSnapshot' => $context['storeNameSnapshot'],
            'operatorId' => $context['operatorId'],
            'checkoutRequestId' => $context['checkoutRequestId'],
            'salesOrderId' => $context['salesOrderId'],
            'commandIdempotencyKey' => $context['commandIdempotencyKey'],
            'locationId' => 0,
            'businessDate' => $context['businessDate'],
            'occurredAt' => $context['occurredAt'],
            'settledAt' => $context['settledAt'],
            'recordedAt' => $context['recordedAt'],
            'stockActions' => [],
            'batchActions' => [],
            'lineAllocations' => [],
            'actualCostCents' => 0,
            'allocationCount' => 0,
        ];
        $plan['planFingerprint'] = CashierV3CheckoutSettlementCanonicalizer::fingerprint(
            $this->fingerprintInput($plan)
        );
        return $plan;
    }

    private function normalizeContext(
        array $request,
        array $salesOrder,
        CashierV3OperatorScope $operatorScope,
        CashierV3DataScopeContext $dataScope
    ): array {
        $tenantId = $this->token((string)($request['tenant_id'] ?? ''), 32, 'sale_inventory_tenant_invalid');
        $organizationId = $this->token((string)($request['organization_id'] ?? ''), 32, 'sale_inventory_organization_invalid');
        $organizationPath = $this->pathToken((string)($request['organization_path'] ?? ''));
        $storeId = $this->positiveInt($request['store_id'] ?? null, 'sale_inventory_store_invalid');
        $operatorId = $this->positiveInt($salesOrder['operator_id'] ?? null, 'sale_inventory_operator_invalid');
        $checkoutRequestId = $this->opaqueId($request['request_id'] ?? null, 'CKR', 'sale_inventory_checkout_request_invalid');
        $businessDate = $this->date((string)($request['business_date'] ?? ''), 'sale_inventory_business_date_invalid');
        $occurredAt = $this->positiveInt($salesOrder['occurred_at'] ?? null, 'sale_inventory_occurred_at_invalid');
        $settledAt = $this->positiveInt($salesOrder['settled_at'] ?? null, 'sale_inventory_settled_at_invalid');
        $recordedAt = $this->positiveInt($salesOrder['recorded_at'] ?? null, 'sale_inventory_recorded_at_invalid');
        if ($settledAt < $occurredAt || $recordedAt < $settledAt
            || !hash_equals($tenantId, $dataScope->tenantId())
            || !hash_equals($organizationId, $operatorScope->organizationId())
            || !hash_equals($organizationId, $dataScope->organizationId())
            || $storeId !== $operatorScope->storeId()
            || $storeId !== $dataScope->forcedStoreId()) {
            throw $this->failure('sale_inventory_data_scope_denied');
        }
        if ((string)($salesOrder['checkout_request_id'] ?? '') !== $checkoutRequestId
            || (string)($salesOrder['tenant_id'] ?? '') !== $tenantId
            || (string)($salesOrder['organization_id'] ?? '') !== $organizationId
            || (string)($salesOrder['organization_path_snapshot'] ?? '') !== $organizationPath
            || (int)($salesOrder['store_id'] ?? 0) !== $storeId
            || (string)($salesOrder['business_date'] ?? '') !== $businessDate) {
            throw $this->failure('sale_inventory_sales_order_scope_mismatch');
        }
        return [
            'tenantId' => $tenantId,
            'organizationId' => $organizationId,
            'organizationPath' => $organizationPath,
            'organizationNameSnapshot' => $this->snapshotText(
                (string)($salesOrder['organization_name_snapshot'] ?? ''),
                128,
                'sale_inventory_organization_name_invalid'
            ),
            'storeId' => $storeId,
            'storeNameSnapshot' => $this->snapshotText(
                (string)($salesOrder['store_name_snapshot'] ?? ''),
                128,
                'sale_inventory_store_name_invalid'
            ),
            'operatorId' => $operatorId,
            'checkoutRequestId' => $checkoutRequestId,
            'salesOrderId' => $this->opaqueId(
                $salesOrder['order_id'] ?? null,
                'CSO',
                'sale_inventory_order_id_invalid'
            ),
            'commandIdempotencyKey' => $this->token(
                (string)($salesOrder['command_idempotency_key'] ?? ''),
                128,
                'sale_inventory_command_key_invalid'
            ),
            'businessDate' => $businessDate,
            'occurredAt' => $occurredAt,
            'settledAt' => $settledAt,
            'recordedAt' => $recordedAt,
        ];
    }

    /** @return array<int,array> */
    private function lockInventorySaleLines(array $salesLines, array $context): array
    {
        if (!$salesLines || count($salesLines) > self::MAX_LINES) {
            throw $this->failure('sale_inventory_order_lines_invalid');
        }
        $candidates = [];
        foreach ($salesLines as $line) {
            if (!is_array($line)
                || (string)($line['tenant_id'] ?? '') !== $context['tenantId']
                || (int)($line['store_id'] ?? 0) !== $context['storeId']
                || (string)($line['order_id'] ?? '') !== $context['salesOrderId']) {
                throw $this->failure('sale_inventory_order_line_invalid');
            }
            // Cards issue rights and projects create service entitlements; neither
            // is a physical inventory sale. Their authorities run in the same
            // checkout transaction outside this inventory-owned service.
            if (in_array((string)($line['item_type'] ?? ''), ['card', 'project'], true)) {
                continue;
            }
            if ((string)($line['item_type'] ?? '') !== 'product') {
                throw $this->failure('sale_inventory_order_line_invalid');
            }
            if ((int)($line['inventory_outbound_required'] ?? 1) !== 1) {
                continue;
            }
            $productId = $this->positiveInt($line['item_id'] ?? null, 'sale_inventory_product_invalid');
            $skuId = $this->positiveInt($line['catalog_sku_id'] ?? null, 'sale_inventory_sku_id_invalid');
            $unique = $this->token((string)($line['item_code_snapshot'] ?? ''), 64, 'sale_inventory_sku_unique_invalid');
            $key = $productId . ':' . $skuId;
            $candidates[$key] = [
                'productId' => $productId,
                'skuId' => $skuId,
                'skuUnique' => $unique,
            ];
        }
        usort($candidates, function (array $left, array $right): int {
            $product = InventoryEntitlementCompletionContract::compareResourceIds(
                (string)$left['productId'],
                (string)$right['productId']
            );
            return $product !== 0
                ? $product
                : ((int)$left['skuId'] <=> (int)$right['skuId']);
        });

        $products = [];
        foreach ($candidates as $candidate) {
            $productId = $candidate['productId'];
            if (isset($products[$productId])) {
                continue;
            }
            $row = Db::name('store_product')
                ->where('id', $productId)
                ->where('type', 1)
                ->where('relation_id', $context['storeId'])
                ->field('id,type,relation_id,product_type,is_inventory,store_name')
                ->lock(true)
                ->find();
            // A checkout snapshot owns the sale line that was already shown to
            // the cashier. At final settlement this read establishes only the
            // physical inventory identity; catalogue visibility, review and
            // deletion flags must not turn a valid snapshot into a rejection.
            if (!$row || (int)$row['product_type'] !== 0) {
                throw $this->failure('sale_inventory_product_changed', ['productId' => $productId]);
            }
            $products[$productId] = (array)$row;
        }

        $inventoryProducts = [];
        foreach ($candidates as $candidate) {
            $product = $products[$candidate['productId']];
            if ((int)$product['is_inventory'] === 1) {
                $inventoryProducts[$candidate['productId'] . ':' . $candidate['skuId']] = $candidate;
            }
        }
        if (!$inventoryProducts) {
            return [];
        }

        $skuRows = [];
        foreach ($inventoryProducts as $key => $candidate) {
            $sku = Db::name('store_product_attr_value')
                ->where('id', $candidate['skuId'])
                ->where('type', 0)
                ->field('id,product_id,unique,type')
                ->lock(true)
                ->find();
            if (!$sku || (int)$sku['product_id'] !== $candidate['productId']
                || (string)$sku['unique'] !== $candidate['skuUnique']) {
                throw $this->failure('sale_inventory_sku_changed', [
                    'productId' => $candidate['productId'],
                ]);
            }
            $skuRows[$key] = (array)$sku;
        }

        $lines = [];
        foreach ($salesLines as $line) {
            $productId = (int)$line['item_id'];
            $skuId = (int)($line['catalog_sku_id'] ?? 0);
            $unique = (string)$line['item_code_snapshot'];
            $key = $productId . ':' . $skuId;
            if (!isset($skuRows[$key])) {
                continue;
            }
            $quantity = $this->positiveInt($line['quantity'] ?? null, 'sale_inventory_quantity_invalid');
            $lines[] = [
                'salesOrderLineId' => $this->opaqueId(
                    $line['order_line_id'] ?? null,
                    'CSL',
                    'sale_inventory_order_line_id_invalid'
                ),
                'productId' => $productId,
                'skuId' => $skuId,
                'skuUnique' => $unique,
                'quantity' => $quantity,
                'itemNameSnapshot' => $this->snapshotText(
                    (string)($line['item_name_snapshot'] ?? ''),
                    255,
                    'sale_inventory_item_name_invalid'
                ),
                'skuNameSnapshot' => $unique,
                'lineNo' => $this->positiveInt($line['line_no'] ?? null, 'sale_inventory_line_no_invalid'),
            ];
        }
        usort($lines, static function (array $left, array $right): int {
            $order = $left['lineNo'] <=> $right['lineNo'];
            return $order !== 0 ? $order : strcmp($left['salesOrderLineId'], $right['salesOrderLineId']);
        });
        return $lines;
    }

    private function defaultLocation(array $context, InventoryCompletionDataScope $scope): array
    {
        $scope->assertTenantAndStore($context['tenantId'], $context['storeId']);
        $rows = Db::name('inventory_location')
            ->where('tenant_id', $context['tenantId'])
            ->where('store_id', $context['storeId'])
            ->where('location_type', 'STORE')
            ->where('is_default', 1)
            ->where('location_status', 'ACTIVE')
            ->field('id,tenant_id,organization_id,organization_path,store_id')
            ->order('id asc')
            ->limit(2)
            ->lock(true)
            ->select()
            ->toArray();
        if (count($rows) !== 1 || (int)($rows[0]['id'] ?? 0) <= 0
            || (string)$rows[0]['organization_id'] !== $context['organizationId']
            || (string)$rows[0]['organization_path'] !== $context['organizationPath']) {
            throw $this->failure('sale_inventory_default_location_not_ready');
        }
        return (array)$rows[0];
    }

    /** @return array{stocks:array<string,array>,lines:array<int,array>} */
    private function lockStocks(array $lines, array $context, int $locationId): array
    {
        $candidates = [];
        foreach ($lines as $line) {
            $key = $line['productId'] . ':' . $line['skuId'];
            $candidates[$key] = $line;
        }
        ksort($candidates, SORT_STRING);
        $ids = [];
        foreach ($candidates as $key => $line) {
            $row = Db::name('inventory_stock')
                ->where('tenant_id', $context['tenantId'])
                ->where('store_id', $context['storeId'])
                ->where('location_id', $locationId)
                ->where('consumable_product_id', $line['productId'])
                ->where('sku_id', $line['skuId'])
                ->where('stock_status', InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD)
                ->field('id')
                ->find();
            if (!$row || (int)$row['id'] <= 0) {
                throw $this->failure('sale_inventory_stock_not_ready', [
                    'productId' => $line['productId'],
                    'skuId' => $line['skuId'],
                ]);
            }
            $ids[(string)(int)$row['id']] = (int)$row['id'];
        }
        uasort($ids, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds((string)$left, (string)$right);
        });
        $stocks = [];
        foreach ($ids as $stockId) {
            $row = Db::name('inventory_stock')->where('id', $stockId)->lock(true)->find();
            if (!$row || (string)$row['tenant_id'] !== $context['tenantId']
                || (int)$row['store_id'] !== $context['storeId']
                || (int)$row['location_id'] !== $locationId
                || (string)$row['stock_status'] !== InventoryEntitlementCompletionContract::STOCK_STATUS_GOOD
                || (int)$row['version'] <= 0 || (int)$row['quantity_scale'] < 0
                || (int)$row['quantity_scale'] > 4 || (int)$row['available_quantity_units'] < 0) {
                throw $this->failure('sale_inventory_stock_changed', ['stockId' => $stockId]);
            }
            $stocks[(string)$stockId] = (array)$row;
        }
        foreach ($lines as &$line) {
            $matched = null;
            foreach ($stocks as $stockId => $stock) {
                if ((int)$stock['consumable_product_id'] === $line['productId']
                    && (int)$stock['sku_id'] === $line['skuId']
                    && (string)$stock['product_unique'] === $line['skuUnique']) {
                    $matched = $stockId;
                    break;
                }
            }
            if ($matched === null) {
                throw $this->failure('sale_inventory_stock_mapping_changed', [
                    'productId' => $line['productId'],
                    'skuId' => $line['skuId'],
                ]);
            }
            $line['stockId'] = (int)$matched;
            $line['quantityScale'] = (int)$stocks[$matched]['quantity_scale'];
            $line['quantityUnits'] = $this->quantityUnits($line['quantity'], $line['quantityScale']);
        }
        unset($line);
        return ['stocks' => $stocks, 'lines' => $lines];
    }

    /** @return array<string,array<int,array>> */
    private function lockBatches(array $stocks, array $context): array
    {
        $ids = [];
        foreach ($stocks as $stockId => $stock) {
            $byStock[(string)$stockId] = [];
            $rows = Db::name('inventory_batch')
                ->where('stock_id', (int)$stockId)
                ->where('batch_status', 'ACTIVE')
                ->field('id')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[(string)$id] = $id;
                }
            }
        }
        uasort($ids, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds((string)$left, (string)$right);
        });
        foreach ($ids as $batchId) {
            $batch = Db::name('inventory_batch')->where('id', $batchId)->lock(true)->find();
            if (!$batch) {
                throw $this->failure('sale_inventory_batch_changed', ['batchId' => $batchId]);
            }
            $stockId = (string)(int)($batch['stock_id'] ?? 0);
            if (!isset($stocks[$stockId]) || (string)$batch['batch_status'] !== 'ACTIVE'
                || (int)$batch['version'] <= 0 || (int)$batch['available_quantity_units'] < 0
                || (int)$batch['cost_allocated_quantity_units'] < 0) {
                throw $this->failure('sale_inventory_batch_changed', ['batchId' => $batchId]);
            }
            $byStock[$stockId][] = (array)$batch;
        }
        foreach ($stocks as $stockId => $stock) {
            $batchTotal = 0;
            foreach ($byStock[$stockId] ?? [] as $batch) {
                $batchTotal = $this->safeAdd(
                    $batchTotal,
                    (int)$batch['available_quantity_units'],
                    'sale_inventory_batch_quantity_overflow'
                );
            }
            if ($batchTotal !== (int)$stock['available_quantity_units']) {
                throw $this->failure('sale_inventory_stock_batch_balance_mismatch', ['stockId' => $stockId]);
            }
            usort($byStock[$stockId], static function (array $left, array $right): int {
                $leftExpiry = empty($left['expire_date']) ? '9999-12-31' : (string)$left['expire_date'];
                $rightExpiry = empty($right['expire_date']) ? '9999-12-31' : (string)$right['expire_date'];
                $expiry = strcmp($leftExpiry, $rightExpiry);
                if ($expiry !== 0) {
                    return $expiry;
                }
                $received = (int)$left['received_at'] <=> (int)$right['received_at'];
                return $received !== 0 ? $received : ((int)$left['id'] <=> (int)$right['id']);
            });
        }
        return $byStock;
    }

    private function allocate(array $lines, array $stocks, array $batchesByStock, array $context): array
    {
        $working = [];
        foreach ($batchesByStock as $stockId => $batches) {
            foreach ($batches as $batch) {
                $working[(string)(int)$batch['id']] = [
                    'available' => (int)$batch['available_quantity_units'],
                    'cursor' => (int)$batch['cost_allocated_quantity_units'],
                    'batch' => $batch,
                ];
            }
        }
        $lineAllocations = [];
        $stockActions = [];
        $batchActions = [];
        $actualCost = 0;
        $allocationCount = 0;
        foreach ($lines as $line) {
            $remaining = $line['quantityUnits'];
            $allocations = [];
            foreach ($batchesByStock[(string)$line['stockId']] ?? [] as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $batchId = (string)(int)$batch['id'];
                $available = (int)$working[$batchId]['available'];
                if ($available <= 0) {
                    continue;
                }
                $take = min($remaining, $available);
                $before = (int)$working[$batchId]['cursor'];
                $cost = $this->scaledCostDelta(
                    $before,
                    $take,
                    (int)$batch['unit_cost_cents'],
                    $line['quantityScale']
                );
                $working[$batchId]['available'] -= $take;
                $working[$batchId]['cursor'] += $take;
                $remaining -= $take;
                $allocations[] = [
                    'batchId' => (int)$batch['id'],
                    'batchVersion' => (int)$batch['version'],
                    'quantityUnits' => $take,
                    'unitCostCents' => (int)$batch['unit_cost_cents'],
                    'actualCostCents' => $cost,
                    'costAllocatedQuantityUnitsBefore' => $before,
                    'costAllocatedQuantityUnitsAfter' => $before + $take,
                ];
                $actualCost = $this->safeAdd($actualCost, $cost, 'sale_inventory_cost_overflow');
                $allocationCount++;
                if ($allocationCount > self::MAX_ALLOCATIONS) {
                    throw $this->failure('sale_inventory_allocation_limit_exceeded');
                }
            }
            if ($remaining !== 0) {
                throw $this->failure('sale_inventory_shortage_denied', [
                    'salesOrderLineId' => $line['salesOrderLineId'],
                    'requiredQuantityUnits' => $line['quantityUnits'],
                    'missingQuantityUnits' => $remaining,
                ]);
            }
            $stockId = (string)$line['stockId'];
            if (!isset($stockActions[$stockId])) {
                $stockActions[$stockId] = [
                    'stockId' => (int)$stockId,
                    'expectedVersion' => (int)$stocks[$stockId]['version'],
                    'quantityUnits' => 0,
                ];
            }
            $stockActions[$stockId]['quantityUnits'] = $this->safeAdd(
                $stockActions[$stockId]['quantityUnits'],
                $line['quantityUnits'],
                'sale_inventory_stock_quantity_overflow'
            );
            foreach ($allocations as $allocation) {
                $batchId = (string)$allocation['batchId'];
                if (!isset($batchActions[$batchId])) {
                    $batchActions[$batchId] = [
                        'batchId' => $allocation['batchId'],
                        'stockId' => (int)$stockId,
                        'expectedVersion' => $allocation['batchVersion'],
                        'quantityUnits' => 0,
                        'cursorBefore' => $allocation['costAllocatedQuantityUnitsBefore'],
                        'cursorAfter' => $allocation['costAllocatedQuantityUnitsBefore'],
                    ];
                }
                if ($batchActions[$batchId]['cursorAfter']
                    !== $allocation['costAllocatedQuantityUnitsBefore']) {
                    throw $this->failure('sale_inventory_batch_cursor_plan_inconsistent');
                }
                $batchActions[$batchId]['quantityUnits'] = $this->safeAdd(
                    $batchActions[$batchId]['quantityUnits'],
                    $allocation['quantityUnits'],
                    'sale_inventory_batch_quantity_overflow'
                );
                $batchActions[$batchId]['cursorAfter'] = $allocation['costAllocatedQuantityUnitsAfter'];
            }
            $line['allocations'] = $allocations;
            $lineAllocations[] = $line;
        }
        $receiptId = 'SIR-' . substr(hash(
            'sha256',
            $context['tenantId'] . "\0" . $context['checkoutRequestId']
        ), 0, 40);
        return [
            'contractVersion' => self::CONTRACT_VERSION,
            'receiptId' => $receiptId,
            'tenantId' => $context['tenantId'],
            'organizationId' => $context['organizationId'],
            'organizationPath' => $context['organizationPath'],
            'organizationNameSnapshot' => $context['organizationNameSnapshot'],
            'storeId' => $context['storeId'],
            'storeNameSnapshot' => $context['storeNameSnapshot'],
            'operatorId' => $context['operatorId'],
            'checkoutRequestId' => $context['checkoutRequestId'],
            'salesOrderId' => $context['salesOrderId'],
            'commandIdempotencyKey' => $context['commandIdempotencyKey'],
            'locationId' => 0,
            'businessDate' => $context['businessDate'],
            'occurredAt' => $context['occurredAt'],
            'settledAt' => $context['settledAt'],
            'recordedAt' => $context['recordedAt'],
            'stockActions' => array_values($this->sortResourceMap($stockActions)),
            'batchActions' => array_values($this->sortResourceMap($batchActions)),
            'lineAllocations' => $lineAllocations,
            'actualCostCents' => $actualCost,
            'allocationCount' => $allocationCount,
        ];
    }

    private function applyStockActions(array $actions, int $recordedAt): void
    {
        foreach ($actions as $action) {
            $stockId = $this->positiveInt($action['stockId'] ?? null, 'sale_inventory_stock_action_invalid');
            $expected = $this->positiveInt($action['expectedVersion'] ?? null, 'sale_inventory_stock_version_invalid');
            $quantity = $this->positiveInt($action['quantityUnits'] ?? null, 'sale_inventory_stock_quantity_invalid');
            $row = Db::name('inventory_stock')->where('id', $stockId)->lock(true)->find();
            if (!$row || (int)$row['version'] !== $expected
                || (int)$row['available_quantity_units'] < $quantity) {
                throw $this->failure('sale_inventory_stock_changed', ['stockId' => $stockId]);
            }
            $updated = Db::name('inventory_stock')
                ->where('id', $stockId)
                ->where('version', $expected)
                ->update([
                    'available_quantity_units' => (int)$row['available_quantity_units'] - $quantity,
                    'version' => $expected + 1,
                    'updated_at' => $recordedAt,
                ]);
            if ($updated !== 1) {
                throw $this->failure('sale_inventory_stock_cas_failed', ['stockId' => $stockId]);
            }
        }
    }

    private function applyBatchActions(array $actions, int $recordedAt): void
    {
        foreach ($actions as $action) {
            $batchId = $this->positiveInt($action['batchId'] ?? null, 'sale_inventory_batch_action_invalid');
            $stockId = $this->positiveInt($action['stockId'] ?? null, 'sale_inventory_batch_stock_invalid');
            $expected = $this->positiveInt($action['expectedVersion'] ?? null, 'sale_inventory_batch_version_invalid');
            $quantity = $this->positiveInt($action['quantityUnits'] ?? null, 'sale_inventory_batch_quantity_invalid');
            $before = $this->nonNegativeInt($action['cursorBefore'] ?? null, 'sale_inventory_batch_cursor_invalid');
            $after = $this->positiveInt($action['cursorAfter'] ?? null, 'sale_inventory_batch_cursor_invalid');
            $row = Db::name('inventory_batch')->where('id', $batchId)->lock(true)->find();
            if (!$row || (int)$row['stock_id'] !== $stockId || (int)$row['version'] !== $expected
                || (int)$row['available_quantity_units'] < $quantity
                || (int)$row['cost_allocated_quantity_units'] !== $before
                || $after - $before !== $quantity) {
                throw $this->failure('sale_inventory_batch_changed', ['batchId' => $batchId]);
            }
            $updated = Db::name('inventory_batch')
                ->where('id', $batchId)
                ->where('version', $expected)
                ->update([
                    'available_quantity_units' => (int)$row['available_quantity_units'] - $quantity,
                    'cost_allocated_quantity_units' => $after,
                    'version' => $expected + 1,
                    'updated_at' => $recordedAt,
                ]);
            if ($updated !== 1) {
                throw $this->failure('sale_inventory_batch_cas_failed', ['batchId' => $batchId]);
            }
        }
    }

    private function normalizePlan(array $plan): array
    {
        $expected = [
            'contractVersion', 'receiptId', 'tenantId', 'organizationId', 'organizationPath',
            'organizationNameSnapshot', 'storeId', 'storeNameSnapshot', 'operatorId',
            'checkoutRequestId', 'salesOrderId', 'commandIdempotencyKey', 'locationId',
            'businessDate', 'occurredAt', 'settledAt', 'recordedAt', 'stockActions',
            'batchActions', 'lineAllocations', 'actualCostCents', 'allocationCount',
            'planFingerprint',
        ];
        $actual = array_keys($plan);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected || $plan['contractVersion'] !== self::CONTRACT_VERSION) {
            throw $this->failure('sale_inventory_plan_shape_invalid');
        }
        if ($plan['lineAllocations'] === []) {
            return $plan;
        }
        if ($this->opaqueId($plan['receiptId'], 'SIR', 'sale_inventory_receipt_id_invalid') === ''
            || !hash_equals(
                CashierV3CheckoutSettlementCanonicalizer::fingerprint($this->fingerprintInput($plan)),
                (string)$plan['planFingerprint']
            )) {
            throw $this->failure('sale_inventory_plan_fingerprint_invalid');
        }
        return $plan;
    }

    private function fingerprintInput(array $plan): array
    {
        return [
            'contractVersion' => $plan['contractVersion'],
            'receiptId' => $plan['receiptId'],
            'tenantId' => $plan['tenantId'],
            'organizationId' => $plan['organizationId'],
            'organizationPath' => $plan['organizationPath'],
            'storeId' => $plan['storeId'],
            'operatorId' => $plan['operatorId'],
            'checkoutRequestId' => $plan['checkoutRequestId'],
            'salesOrderId' => $plan['salesOrderId'],
            'commandIdempotencyKey' => $plan['commandIdempotencyKey'],
            'locationId' => $plan['locationId'],
            'businessDate' => $plan['businessDate'],
            'occurredAt' => $plan['occurredAt'],
            'settledAt' => $plan['settledAt'],
            'recordedAt' => $plan['recordedAt'],
            'stockActions' => $plan['stockActions'],
            'batchActions' => $plan['batchActions'],
            'lineAllocations' => $plan['lineAllocations'],
            'actualCostCents' => $plan['actualCostCents'],
            'allocationCount' => $plan['allocationCount'],
        ];
    }

    private function lockReceipt(string $tenantId, string $receiptId, string $idempotencyKey): array
    {
        $receipt = Db::name(self::RECEIPT_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('receipt_id', $receiptId)
            ->lock(true)
            ->find();
        if ($receipt) {
            return (array)$receipt;
        }
        $receipt = Db::name(self::RECEIPT_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('command_idempotency_key', $idempotencyKey)
            ->lock(true)
            ->find();
        return $receipt ? (array)$receipt : [];
    }

    private function replayReceipt(array $receipt, array $plan): array
    {
        if ((string)$receipt['receipt_id'] !== $plan['receiptId']
            || (string)$receipt['command_idempotency_key'] !== $plan['commandIdempotencyKey']
            || (string)$receipt['plan_fingerprint'] !== $plan['planFingerprint']) {
            throw $this->failure('sale_inventory_idempotency_conflict');
        }
        $result = json_decode((string)$receipt['result_snapshot'], true);
        if (!is_array($result) || ($result['contractVersion'] ?? null) !== self::CONTRACT_VERSION
            || ($result['receiptId'] ?? null) !== $plan['receiptId']) {
            throw $this->failure('sale_inventory_receipt_snapshot_invalid');
        }
        $result['replayed'] = true;
        return $result;
    }

    private function quantityUnits(int $quantity, int $scale): int
    {
        if ($quantity <= 0 || $scale < 0 || $scale > 4) {
            throw $this->failure('sale_inventory_quantity_scale_invalid');
        }
        $factor = 1;
        for ($i = 0; $i < $scale; $i++) {
            $factor *= 10;
        }
        if ($quantity > intdiv(PHP_INT_MAX, $factor)) {
            throw $this->failure('sale_inventory_quantity_overflow');
        }
        return $quantity * $factor;
    }

    private function scaledCostDelta(int $before, int $quantity, int $unitCostCents, int $scale): int
    {
        if ($before < 0 || $quantity <= 0 || $unitCostCents < 0 || $scale < 0 || $scale > 4) {
            throw $this->failure('sale_inventory_cost_arguments_invalid');
        }
        $factor = 1;
        for ($i = 0; $i < $scale; $i++) {
            $factor *= 10;
        }
        $cost = static function (int $units) use ($unitCostCents, $factor): int {
            if ($units > 0 && $unitCostCents > intdiv(PHP_INT_MAX, $units)) {
                throw new CashierV3CheckoutSettlementContractException('sale_inventory_cost_overflow');
            }
            $numerator = $units * $unitCostCents;
            return intdiv($numerator, $factor) + ((($numerator % $factor) * 2 >= $factor) ? 1 : 0);
        };
        return $cost($before + $quantity) - $cost($before);
    }

    /** @return array<string,array> */
    private function sortResourceMap(array $map): array
    {
        uksort($map, static function ($left, $right): int {
            return InventoryEntitlementCompletionContract::compareResourceIds((string)$left, (string)$right);
        });
        return $map;
    }

    private function assertTransaction(): void
    {
        CashierV3TransactionGuard::assertInTransaction('saleInventorySettlement');
    }

    private function token(string $value, int $max, string $reason): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('/^[A-Za-z0-9:._-]+$/D', $value) !== 1) {
            throw $this->failure($reason);
        }
        return $value;
    }

    private function pathToken(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 191 || preg_match('/^[A-Za-z0-9:._\/-]+$/D', $value) !== 1) {
            throw $this->failure('sale_inventory_organization_path_invalid');
        }
        return $value;
    }

    private function opaqueId($value, string $prefix, string $reason): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^' . preg_quote($prefix, '/') . '-[a-f0-9]{40}$/D', $value) !== 1) {
            throw $this->failure($reason);
        }
        return $value;
    }

    private function snapshotText(string $value, int $max, string $reason): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw $this->failure($reason);
        }
        return $value;
    }

    private function date(string $value, string $reason): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw $this->failure($reason);
        }
        return $value;
    }

    private function positiveInt($value, string $reason): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            if (strlen($value) > strlen((string)PHP_INT_MAX)
                || (strlen($value) === strlen((string)PHP_INT_MAX)
                    && strcmp($value, (string)PHP_INT_MAX) > 0)) {
                throw $this->failure($reason);
            }
            $value = (int)$value;
        }
        if (!is_int($value) || $value <= 0) {
            throw $this->failure($reason);
        }
        return $value;
    }

    private function nonNegativeInt($value, string $reason): int
    {
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            if (strlen($value) > strlen((string)PHP_INT_MAX)
                || (strlen($value) === strlen((string)PHP_INT_MAX)
                    && strcmp($value, (string)PHP_INT_MAX) > 0)) {
                throw $this->failure($reason);
            }
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0) {
            throw $this->failure($reason);
        }
        return $value;
    }

    private function safeAdd(int $left, int $right, string $reason): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw $this->failure($reason);
        }
        return $left + $right;
    }

    private function encode(array $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw $this->failure('sale_inventory_result_encode_failed');
        }
        return $encoded;
    }

    private function failure(string $reason, array $detail = []): CashierV3CheckoutSettlementContractException
    {
        return new CashierV3CheckoutSettlementContractException($reason, $detail);
    }
}
