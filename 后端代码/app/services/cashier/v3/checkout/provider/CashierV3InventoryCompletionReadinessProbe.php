<?php

namespace app\services\cashier\v3\checkout\provider;

use app\services\cashier\v3\checkout\CashierV3EntitlementCompletionKernel;
use app\services\product\inventory\completion\InventoryBatchMovementFactServices;
use app\services\product\inventory\completion\InventoryCompletionFactServices;
use app\services\product\inventory\completion\InventoryEntitlementCompletionContract;
use app\services\product\inventory\completion\InventoryEntitlementCompletionDiscovery;
use app\services\product\inventory\completion\InventoryEntitlementCompletionProvider;

final class CashierV3InventoryCompletionReadinessProbe
{
    public function readinessStatus(): array
    {
        $codeReady = class_exists(InventoryEntitlementCompletionProvider::class)
            && class_exists(InventoryEntitlementCompletionDiscovery::class)
            && class_exists(InventoryEntitlementCompletionContract::class)
            && class_exists(InventoryCompletionFactServices::class)
            && class_exists(InventoryBatchMovementFactServices::class)
            && class_exists(CashierV3InventoryResourceVersionProvider::class)
            && class_exists(CashierV3InventoryCompletionGatewayAdapter::class)
            && InventoryEntitlementCompletionContract::CONTRACT_VERSION
                === CashierV3EntitlementCompletionKernel::INVENTORY_PROVIDER_CONTRACT_VERSION
            && InventoryEntitlementCompletionContract::CONTRACT_VERSION
                === CashierV3EntitlementProviderContracts::INVENTORY_COMPLETION
            && InventoryEntitlementCompletionContract::LOCK_ORDER_SHORTAGE_CURSOR === 56
            && CashierV3EntitlementCompletionKernel::INVENTORY_SHORTAGE_CURSOR_GATE
                === InventoryEntitlementCompletionContract::SHORTAGE_CURSOR_GATE
            && CashierV3EntitlementCompletionKernel::INVENTORY_SHORTAGE_CURSOR_GATE
                === CashierV3EntitlementProviderContracts::INVENTORY_SHORTAGE_CURSOR_GATE;
        $schema = CashierV3EntitlementProviderSchemaProbe::tablesStatus([
            'eb_inventory_shortage_policy' => [
                'tenant_id', 'policy_scope', 'project_id', 'policy_value', 'version',
            ],
            'eb_inventory_stock' => [
                'id', 'tenant_id', 'organization_id', 'organization_path', 'location_id',
                'store_id', 'consumable_product_id', 'sku_id', 'stock_status',
                'quantity_scale', 'available_quantity_units', 'estimated_unit_cost_cents', 'version',
            ],
            'eb_inventory_batch' => [
                'id', 'stock_id', 'available_quantity_units', 'unit_cost_cents',
                'cost_allocated_quantity_units', 'version',
            ],
            'eb_inventory_shortage_cost_cursor' => [
                'id', 'tenant_id', 'store_id', 'recipe_id', 'stock_id',
                'estimated_unit_cost_cents', 'allocated_quantity_units', 'version',
            ],
            'eb_inventory_location' => [
                'id', 'tenant_id', 'store_id', 'location_type', 'is_default',
                'location_status', 'version',
            ],
            'eb_inventory_consumption_receipt' => [
                'id', 'receipt_key', 'idempotency_key', 'request_fingerprint',
                'contract_version', 'tenant_id', 'store_id', 'result_snapshot',
            ],
            'eb_inventory_batch_consumption_fact' => [
                'id', 'fact_key', 'receipt_id', 'tenant_id', 'store_id', 'stock_id', 'batch_id',
            ],
            'eb_inventory_shortage_fact' => [
                'id', 'fact_key', 'receipt_id', 'tenant_id', 'store_id', 'stock_id',
            ],
            'eb_inventory_shortage_cost_adjustment' => [
                'id', 'adjustment_key', 'shortage_fact_id', 'tenant_id', 'store_id',
            ],
            'eb_inventory_batch_movement_fact' => [
                'id', 'fact_key', 'tenant_id', 'organization_id', 'organization_path',
                'location_id', 'store_id', 'stock_id', 'batch_id', 'reversal_of',
            ],
            'eb_store_project_consumable_recipe' => [
                'id', 'type', 'relation_id', 'project_product_id', 'project_unique',
                'status', 'version',
            ],
            'eb_store_project_consumable_recipe_detail' => [
                'id', 'recipe_id', 'consumable_product_id', 'consumable_unique',
                'qty_per_writeoff',
            ],
        ]);
        $reasons = [];
        if (!$codeReady) {
            $reasons[] = 'inventory_contract_code_mismatch';
        }
        if (!$schema['ready']) {
            $reasons[] = 'inventory_provider_schema_not_ready';
        }
        return [
            'dependency' => 'inventory',
            'consumerContractVersion' => CashierV3EntitlementCompletionKernel::CONTRACT_VERSION,
            'providerContractVersion' => class_exists(InventoryEntitlementCompletionContract::class)
                ? InventoryEntitlementCompletionContract::CONTRACT_VERSION
                : '',
            'shortageCursorGate' => CashierV3EntitlementCompletionKernel::INVENTORY_SHORTAGE_CURSOR_GATE,
            'shortageCursorLockOrder' => InventoryEntitlementCompletionContract::LOCK_ORDER_SHORTAGE_CURSOR,
            'shortageCursorStableResourceId' => 'stockId:recipeId:estimatedUnitCostCents',
            'ready' => $codeReady && $schema['ready'],
            'reasons' => $reasons,
            'schema' => $schema,
        ];
    }
}
