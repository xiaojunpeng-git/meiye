<?php
declare(strict_types=1);

namespace app\services\product\inventory\query;

use app\services\query\UnifiedQueryException;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\UnifiedQueryWorkerContextResolver;

/** Rebuilds task-authorized scope for all inventory document and statistics exports. */
final class InventoryOperationalUnifiedQueryWorkerContextResolver implements UnifiedQueryWorkerContextResolver
{
    public function pageCode(): string
    {
        $pageCodes = $this->pageCodes();
        return $pageCodes[0];
    }

    /** @return string[] */
    public function pageCodes(): array
    {
        return array_merge(
            InventoryOperationalUnifiedQueryContract::PAGE_CODES,
            InventoryStatisticsUnifiedQueryContract::PAGE_CODES
        );
    }

    public function resolve(array $authoritativeTask): array
    {
        $pageCode = trim((string)($authoritativeTask['page_code'] ?? ''));
        if (!in_array($pageCode, $this->pageCodes(), true)) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '库存导出任务页面身份无效，任务已停止。',
                ['page_code' => $pageCode]
            );
        }

        $storeId = (int)($authoritativeTask['origin_store_id'] ?? 0);
        $operatorId = (int)($authoritativeTask['operator_id'] ?? 0);
        $taskTenantId = trim((string)($authoritativeTask['tenant_id'] ?? ''));
        if ($operatorId <= 0 || $taskTenantId === '') {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '库存导出任务缺少可信操作身份，任务已停止。',
                ['page_code' => $pageCode]
            );
        }

        if ($storeId === 0) {
            $factory = new InventoryPlatformUnifiedQueryContextFactory(
                UnifiedQueryRuntime::service('contextFactory')
            );
            $context = $factory->make($operatorId, [], date('Y-m-d'), 0, $pageCode);
        } elseif ($storeId > 0) {
            $factory = new InventoryStoreUnifiedQueryContextFactory(
                UnifiedQueryRuntime::service('contextFactory')
            );
            $context = $factory->make($storeId, $operatorId, [], date('Y-m-d'), $pageCode);
        } else {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '库存导出任务门店身份无效，任务已停止。',
                ['page_code' => $pageCode]
            );
        }

        if (!hash_equals($taskTenantId, (string)$context['tenant_id'])) {
            throw new UnifiedQueryException(
                'UNIFIED_QUERY_EXPORT_PERMISSION_REVOKED',
                '库存导出任务商户范围已变化，任务已停止。',
                ['page_code' => $pageCode]
            );
        }
        return $context;
    }
}
