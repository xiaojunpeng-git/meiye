<?php

declare(strict_types=1);

namespace app\services\report;

/**
 * Normal business-report visibility is determined by the authoritative order
 * lifecycle. A succeeded sales-order void hides both its original and signed
 * reversal facts from every operating statistic, while preserving both facts
 * for audit and reconciliation views.
 */
final class StoreReportNormalDataScopeServices
{
    public function excludeVoidedSalesOrderFacts($query, string $tenantColumn, string $orderColumn): void
    {
        $query->whereNotExists(function ($operation) use ($tenantColumn, $orderColumn): void {
            $operation->name('cashier_v3_order_lifecycle_operation')->alias('normal_void_operation')
                ->whereRaw('normal_void_operation.tenant_id = ' . $tenantColumn)
                ->whereRaw('normal_void_operation.source_order_id = ' . $orderColumn)
                ->where('normal_void_operation.source_type', 'sales')
                ->where('normal_void_operation.operation_type', 'void')
                ->where('normal_void_operation.status', 'succeeded');
        });
    }

    /**
     * Service facts do not carry a sales-order ID. A service belongs to a
     * voided sale only when its source line belongs to the successfully
     * voided sales order; services from previously purchased entitlements stay
     * visible because they have no such sales fact.
     */
    public function excludeVoidedSalesOrderServices($query, string $serviceAlias): void
    {
        $query->whereNotExists(function ($operation) use ($serviceAlias): void {
            $operation->name('cashier_v3_order_lifecycle_operation')->alias('normal_service_void_operation')
                ->join(
                    'cashier_v3_sale_fact normal_service_sale',
                    'normal_service_sale.tenant_id=normal_service_void_operation.tenant_id'
                    . ' AND normal_service_sale.order_id=normal_service_void_operation.source_order_id'
                )
                ->whereRaw('normal_service_void_operation.tenant_id = ' . $serviceAlias . '.tenant_id')
                ->whereRaw('normal_service_sale.source_line_id = ' . $serviceAlias . '.source_line_id')
                ->where('normal_service_void_operation.source_type', 'sales')
                ->where('normal_service_void_operation.operation_type', 'void')
                ->where('normal_service_void_operation.status', 'succeeded');
        });
    }
}
