<?php

use app\services\query\provider\MemberUnifiedQueryPageRegistrar;
use app\services\query\provider\MemberUnifiedQueryProvider;
use app\services\query\provider\MemberUnifiedQueryWorkerContextResolver;
use app\services\query\provider\StaffUnifiedQueryPageRegistrar;
use app\services\query\provider\StaffUnifiedQueryProvider;
use app\services\query\provider\StaffUnifiedQueryWorkerContextResolver;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryRegistrar;
use app\services\cashier\v3\order\CashierV3OrderCenterUnifiedQueryWorkerContextResolver;
use app\services\cashier\v3\order\CashierV3SalesOrderUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3AllOrderUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3RechargeOrderUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3RefundRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3DebtRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3ServiceRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3SupplementRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3GiftRecordUnifiedQueryProvider;
use app\services\cashier\v3\order\CashierV3CardOperationUnifiedQueryProvider;
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
        \app\services\report\query\BasicRegistrationQueryRegistrar::class,
        \app\services\report\query\BasicBusinessQueryRegistrar::class,
        \app\services\report\query\BasicStoreReconciliationQueryRegistrar::class,
        MemberUnifiedQueryPageRegistrar::class,
        \app\services\query\provider\PlatformMemberUnifiedQueryRegistrar::class,
        StaffUnifiedQueryPageRegistrar::class,
        InventoryBatchStockUnifiedQueryRegistrar::class,
        InventoryOperationalUnifiedQueryRegistrar::class,
        InventoryStatisticsUnifiedQueryRegistrar::class,
        CashierV3OrderCenterUnifiedQueryRegistrar::class,
    ],
    // 页面未登记专属 resolver 时，严格使用 requiredFeature/exportFeature。
    'permission_resolvers' => [],
    'providers' => [
        \app\services\report\query\BasicRegistrationQueryProvider::class,
        \app\services\report\query\BasicStoreSummaryQueryProvider::class,
        \app\services\report\query\BasicStoreReconciliationQueryProvider::class,
        \app\services\report\query\BasicCrossStoreSettlementQueryProvider::class,
        \app\services\report\query\BasicStoreItemSalesQueryProvider::class,
        \app\services\report\query\BasicItemConsumptionQueryProvider::class,
        \app\services\report\query\BasicWriteoffDetailQueryProvider::class,
        \app\services\report\query\BasicProductSalesQueryProvider::class,
        \app\services\report\query\BasicSalaryRealtimeQueryProvider::class,
        \app\services\report\query\BasicEmployeeSummaryQueryProvider::class,
        \app\services\report\query\BasicEmployeeItemSalesQueryProvider::class,
        \app\services\report\query\BasicEmployeeServiceHomeStoreQueryProvider::class,
        \app\services\report\query\BasicEmployeeServiceStoreQueryProvider::class,
        \app\services\report\query\BasicEmployeePerformanceDetailQueryProvider::class,
        \app\services\report\query\BasicBalanceOrderDetailQueryProvider::class,
        \app\services\report\query\BasicBalanceOrderSummaryQueryProvider::class,
        \app\services\report\query\BasicDailyReconciliationQueryProvider::class,
        \app\services\report\query\BasicUnconsumedSummaryQueryProvider::class,
        \app\services\report\query\BasicUnconsumedDetailQueryProvider::class,
        \app\services\report\query\BasicFollowupEmployeeDetailQueryProvider::class,
        \app\services\report\query\BasicCustomerEntitlementsQueryProvider::class,
        \app\services\report\query\BasicUnconsumedAsOfDetailQueryProvider::class,
        MemberUnifiedQueryProvider::class,
        \app\services\query\provider\PlatformMemberUnifiedQueryProvider::class,
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
        CashierV3SalesOrderUnifiedQueryProvider::class,
        CashierV3AllOrderUnifiedQueryProvider::class,
        CashierV3RechargeOrderUnifiedQueryProvider::class,
        CashierV3RefundRecordUnifiedQueryProvider::class,
        CashierV3DebtRecordUnifiedQueryProvider::class,
        CashierV3ServiceRecordUnifiedQueryProvider::class,
        CashierV3SupplementRecordUnifiedQueryProvider::class,
        CashierV3GiftRecordUnifiedQueryProvider::class,
        CashierV3CardOperationUnifiedQueryProvider::class,
    ],
    // 后台导出执行时按权威 task.page_code 重建当前账号权限与数据范围。
    'worker_context_resolvers' => [
        \app\services\report\query\BasicRegistrationWorkerContextResolver::class,
        \app\services\report\query\BasicStoreSummaryWorkerContextResolver::class,
        \app\services\report\query\BasicStoreReconciliationWorkerContextResolver::class,
        \app\services\report\query\BasicCrossStoreSettlementWorkerContextResolver::class,
        \app\services\report\query\BasicStoreItemSalesWorkerContextResolver::class,
        \app\services\report\query\BasicItemConsumptionWorkerContextResolver::class,
        \app\services\report\query\BasicWriteoffDetailWorkerContextResolver::class,
        \app\services\report\query\BasicProductSalesWorkerContextResolver::class,
        \app\services\report\query\BasicSalaryRealtimeWorkerContextResolver::class,
        \app\services\report\query\BasicEmployeeSummaryWorkerContextResolver::class,
        \app\services\report\query\BasicEmployeeItemSalesWorkerContextResolver::class,
        \app\services\report\query\BasicEmployeeServiceHomeStoreWorkerContextResolver::class,
        \app\services\report\query\BasicEmployeeServiceStoreWorkerContextResolver::class,
        \app\services\report\query\BasicEmployeePerformanceDetailWorkerContextResolver::class,
        \app\services\report\query\BasicBalanceOrderDetailWorkerContextResolver::class,
        \app\services\report\query\BasicBalanceOrderSummaryWorkerContextResolver::class,
        \app\services\report\query\BasicDailyReconciliationWorkerContextResolver::class,
        \app\services\report\query\BasicUnconsumedSummaryWorkerContextResolver::class,
        \app\services\report\query\BasicUnconsumedDetailWorkerContextResolver::class,
        \app\services\report\query\BasicFollowupEmployeeDetailWorkerContextResolver::class,
        \app\services\report\query\BasicCustomerEntitlementsWorkerContextResolver::class,
        \app\services\report\query\BasicUnconsumedAsOfDetailWorkerContextResolver::class,
        MemberUnifiedQueryWorkerContextResolver::class,
        \app\services\query\provider\PlatformMemberUnifiedQueryWorkerContextResolver::class,
        StaffUnifiedQueryWorkerContextResolver::class,
        InventoryBatchStockUnifiedQueryWorkerContextResolver::class,
        InventoryOperationalUnifiedQueryWorkerContextResolver::class,
        CashierV3OrderCenterUnifiedQueryWorkerContextResolver::class,
    ],
];
