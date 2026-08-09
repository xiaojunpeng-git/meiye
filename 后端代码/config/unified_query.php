<?php

use app\services\query\provider\MemberUnifiedQueryPageRegistrar;
use app\services\query\provider\MemberUnifiedQueryProvider;
use app\services\query\provider\MemberUnifiedQueryWorkerContextResolver;
use app\services\query\provider\StaffUnifiedQueryPageRegistrar;
use app\services\query\provider\StaffUnifiedQueryProvider;
use app\services\query\provider\StaffUnifiedQueryWorkerContextResolver;
use app\services\product\inventory\query\InventoryBatchStockUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryBatchStockUnifiedQueryRegistrar;
use app\services\product\inventory\query\InventoryBatchStockUnifiedQueryWorkerContextResolver;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryWorkerContextResolver;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryRegistrar;
use app\services\product\inventory\query\InventoryInboundUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryOutboundUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryCountUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryMovementUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryRequestUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryTransferUnifiedQueryProvider;
use app\services\product\inventory\query\InventorySalonUsageUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryImportUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryStatisticsUnifiedQueryRegistrar;
use app\services\product\inventory\query\InventoryStatisticsInboundUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryStatisticsOutboundUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryStatisticsExpiryUnifiedQueryProvider;
use app\services\product\inventory\query\InventoryStatisticsAgeUnifiedQueryProvider;

return [
    // 所有页面由配置显式登记；Runtime 不持有任何领域默认值。
    'registrars' => [
        MemberUnifiedQueryPageRegistrar::class,
        StaffUnifiedQueryPageRegistrar::class,
        InventoryBatchStockUnifiedQueryRegistrar::class,
        InventoryOperationalUnifiedQueryRegistrar::class,
        InventoryStatisticsUnifiedQueryRegistrar::class,
    ],
    // 页面未登记专属 resolver 时，严格使用 requiredFeature/exportFeature。
    'permission_resolvers' => [],
    'providers' => [
        MemberUnifiedQueryProvider::class,
        StaffUnifiedQueryProvider::class,
        InventoryBatchStockUnifiedQueryProvider::class,
        InventoryInboundUnifiedQueryProvider::class,
        InventoryOutboundUnifiedQueryProvider::class,
        InventoryCountUnifiedQueryProvider::class,
        InventoryMovementUnifiedQueryProvider::class,
        InventoryRequestUnifiedQueryProvider::class,
        InventoryTransferUnifiedQueryProvider::class,
        InventorySalonUsageUnifiedQueryProvider::class,
        InventoryImportUnifiedQueryProvider::class,
        InventoryStatisticsInboundUnifiedQueryProvider::class,
        InventoryStatisticsOutboundUnifiedQueryProvider::class,
        InventoryStatisticsExpiryUnifiedQueryProvider::class,
        InventoryStatisticsAgeUnifiedQueryProvider::class,
    ],
    // 后台导出执行时按权威 task.page_code 重建当前账号权限与数据范围。
    'worker_context_resolvers' => [
        MemberUnifiedQueryWorkerContextResolver::class,
        StaffUnifiedQueryWorkerContextResolver::class,
        InventoryBatchStockUnifiedQueryWorkerContextResolver::class,
        InventoryOperationalUnifiedQueryWorkerContextResolver::class,
    ],
];
