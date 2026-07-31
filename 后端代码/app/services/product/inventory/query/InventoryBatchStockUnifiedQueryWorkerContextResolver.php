<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\UnifiedQueryWorkerContextResolver;

/**
 * Rebuilds an inventory export context from the authoritative task identity.
 * Worker input never supplies a store, tenant or warehouse scope to the query.
 */
final class InventoryBatchStockUnifiedQueryWorkerContextResolver implements UnifiedQueryWorkerContextResolver
{
    public function pageCode(): string
    {
        return InventoryBatchStockQueryContract::PAGE_CODE;
    }

    public function resolve(array $authoritativeTask): array
    {
        $storeId = (int)($authoritativeTask['origin_store_id'] ?? 0);
        $operatorId = (int)($authoritativeTask['operator_id'] ?? 0);
        $taskTenantId = trim((string)($authoritativeTask['tenant_id'] ?? ''));
        if ($operatorId <= 0 || $taskTenantId === '') {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '库存导出任务缺少可信操作身份，任务已停止。',
                ['page_code' => $this->pageCode()]
            );
        }
        if ($storeId === 0) {
            $factory = new InventoryPlatformUnifiedQueryContextFactory(
                UnifiedQueryRuntime::service('contextFactory')
            );
            $context = $factory->make($operatorId, [], date('Y-m-d'));
            if (!hash_equals($taskTenantId, (string)$context['tenant_id'])) {
                throw new UnifiedQueryException(
                    'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                    '平台库存导出任务商户范围已变化，任务已停止。',
                    ['page_code' => $this->pageCode()]
                );
            }
            return $context;
        }
        if ($storeId < 0) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '库存导出任务门店身份无效，任务已停止。',
                ['page_code' => $this->pageCode()]
            );
        }
        $factory = new InventoryStoreUnifiedQueryContextFactory(
            UnifiedQueryRuntime::service('contextFactory')
        );
        $context = $factory->make($storeId, $operatorId, [], date('Y-m-d'));
        if (!hash_equals($taskTenantId, (string)$context['tenant_id'])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '库存导出任务商户范围已变化，任务已停止。',
                ['page_code' => $this->pageCode()]
            );
        }
        return $context;
    }
}
