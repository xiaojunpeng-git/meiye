<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\product\inventory\InventoryStoreAccessPolicy;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryException;
use think\facade\Db;

/** Builds an inventory UQ context exclusively from the authenticated store session. */
final class InventoryStoreUnifiedQueryContextFactory
{
    /** 每个统一查询页面只继承自身 V3 库存功能的成本查看能力。 */
    private const PAGE_FEATURES = [
        'inventory_batch_stock' => 'cashier.v3.inventory.stock',
        'inventory_inbound' => 'cashier.v3.inventory.inbound',
        'inventory_outbound' => 'cashier.v3.inventory.outbound',
        'inventory_count' => 'cashier.v3.inventory.count',
        'inventory_movement' => 'cashier.v3.inventory.movement',
        'inventory_request' => 'cashier.v3.inventory.request',
        'inventory_transfer' => 'cashier.v3.inventory.transfer',
        'inventory_salon_usage' => 'cashier.v3.inventory.usage',
        'inventory_import' => 'cashier.v3.inventory.import',
        'inventory_statistics_inbound' => 'cashier.v3.inventory.statistics',
        'inventory_statistics_outbound' => 'cashier.v3.inventory.statistics',
        'inventory_statistics_expiry' => 'cashier.v3.inventory.statistics',
        'inventory_statistics_age' => 'cashier.v3.inventory.statistics',
    ];
    /** @var UnifiedQueryContextFactory */
    private $core;

    public function __construct(UnifiedQueryContextFactory $core)
    {
        $this->core = $core;
    }

    public function make(int $storeId, int $operatorId, array $rules, string $cutoffDate, string $pageCode = InventoryBatchStockQueryContract::PAGE_CODE): array
    {
        if ($storeId <= 0 || $operatorId <= 0) {
            throw new UnifiedQueryException('UNIFIED_QUERY_CONTEXT_INVALID', '库存查询登录身份无效。', []);
        }
        $staff = Db::name('system_store_staff')->where('id', $operatorId)
            ->where('store_id', $storeId)->where('status', 1)->where('is_del', 0)->find();
        $sessionInfo = (array)(request()->storeStaffInfo ?? []);
        $organizationSession = (!empty($sessionInfo['_cashier_v3_delegated']) || !empty($sessionInfo['_cashier_v3_organization']))
            && (int)($sessionInfo['store_id'] ?? 0) === $storeId;
        if (!$staff && !$organizationSession) {
            throw new UnifiedQueryException('UNIFIED_QUERY_CONTEXT_INVALID', '当前员工不属于门店库存范围。', []);
        }
        $locations = Db::name('inventory_location')->where('store_id', $storeId)
            ->where('location_status', 'ACTIVE')->field('id,tenant_id')->select()->toArray();
        $tenantIds = [];
        $locationIds = [];
        foreach ($locations as $location) {
            $tenantId = trim((string)($location['tenant_id'] ?? ''));
            $locationId = (int)($location['id'] ?? 0);
            if ($tenantId === '' || $locationId <= 0) continue;
            $tenantIds[$tenantId] = true;
            $locationIds[(string)$locationId] = true;
        }
        if (count($tenantIds) !== 1 || !$locationIds) {
            throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '当前门店库存仓库范围不完整，查询已停止。', []);
        }
        $organizationId = (string)(Db::name('organization_store')->where('store_id', $storeId)->value('org_id') ?: '');
        if ($organizationId === '') {
            throw new UnifiedQueryException('UNIFIED_QUERY_SCOPE_INVALID', '当前门店缺少组织归属，查询已停止。', []);
        }
        $rules = array_values(array_unique(array_filter(array_map('strval', $rules))));
        if (!isset(self::PAGE_FEATURES[$pageCode])) {
            throw new UnifiedQueryException('UNIFIED_QUERY_CONTEXT_INVALID', '库存查询功能未配置权限映射。', []);
        }
        $features = (new InventoryStoreAccessPolicy())->features($storeId, $operatorId, self::PAGE_FEATURES[$pageCode]);
        return $this->core->make([
            'tenant_id' => (string)array_key_first($tenantIds),
            'account_id' => $operatorId,
            'operator_id' => $operatorId,
            'store_id' => $storeId,
            'organization_id' => $organizationId,
            'visible_store_ids' => [$storeId],
            'ancestor_organization_ids' => [$organizationId],
            'shareable_store_ids' => [$storeId],
            'shareable_organization_ids' => [$organizationId],
            'permission_version' => sha1(implode('|', array_merge($rules, $features))),
            'granted_features' => $features,
            'manage_shared_fields' => true,
            'share_tenant_fields' => true,
            'scope_dimensions' => ['location_id' => array_keys($locationIds)],
            'query_cutoff_date' => InventoryBatchStockQueryContract::assertCutoffDate($cutoffDate),
            'data_as_of' => time(),
        ], ['pageCode' => $pageCode]);
    }
}
