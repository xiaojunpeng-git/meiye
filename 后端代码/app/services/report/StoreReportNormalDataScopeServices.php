<?php

declare(strict_types=1);

namespace app\services\report;

/**
 * Normal business-report visibility is determined by authoritative successful
 * void operations for sales, recharge, repayment and service. Both the original
 * and signed reversal remain immutable for audit but leave operating reports.
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

    /** Recharge facts use RCH:<id>; lifecycle operations retain the numeric recharge id. */
    public function excludeVoidedRechargeOrderFacts($query, string $tenantColumn, string $orderColumn): void
    {
        $query->whereNotExists(function ($operation) use ($tenantColumn, $orderColumn): void {
            $operation->name('cashier_v3_order_lifecycle_operation')->alias('normal_recharge_void')
                ->whereRaw('normal_recharge_void.tenant_id = ' . $tenantColumn)
                ->whereRaw("CONCAT('RCH:', normal_recharge_void.source_order_id) = " . $orderColumn)
                ->where('normal_recharge_void.source_type', 'recharge')
                ->where('normal_recharge_void.operation_type', 'void')
                ->where('normal_recharge_void.status', 'succeeded');
        });
    }

    /**
     * A succeeded supplement void removes both the original repayment and its
     * reversal from normal reports, including when the two facts have different
     * business dates. The immutable payment facts remain available for audit.
     */
    public function excludeVoidedSupplementPaymentFacts($query, string $tenantColumn, string $storeColumn, string $orderColumn): void
    {
        $query->whereNotExists(function ($operation) use ($tenantColumn, $storeColumn, $orderColumn): void {
            $operation->name('cashier_v3_order_center_void_operation')->alias('normal_supplement_void')
                ->whereRaw('normal_supplement_void.tenant_id = ' . $tenantColumn)
                ->whereRaw('normal_supplement_void.store_id = ' . $storeColumn)
                ->whereRaw('normal_supplement_void.source_id = ' . $orderColumn)
                ->whereIn('normal_supplement_void.source_kind', ['sales_supplement', 'recharge_supplement'])
                ->where('normal_supplement_void.status', 'succeeded');
        });
    }

    /** A sales repayment allocation carries its original sale order id, so identify its own payment fact. */
    public function excludeVoidedSupplementAllocations($query, string $allocationAlias): void
    {
        $query->whereNotExists(function ($operation) use ($allocationAlias): void {
            $operation->name('cashier_v3_order_center_void_operation')->alias('normal_allocation_void')
                ->join('cashier_v3_payment_fact normal_allocation_payment',
                    'normal_allocation_payment.tenant_id=normal_allocation_void.tenant_id'
                    . ' AND normal_allocation_payment.order_id=normal_allocation_void.source_id'
                    . " AND normal_allocation_payment.source_document_type='debt_repayment'")
                ->whereRaw('normal_allocation_payment.fact_id=' . $allocationAlias . '.payment_fact_id')
                ->whereRaw('normal_allocation_void.store_id=' . $allocationAlias . '.store_id')
                ->whereRaw('normal_allocation_void.tenant_id=' . $allocationAlias . '.tenant_id')
                ->where('normal_allocation_void.source_kind', 'sales_supplement')
                ->where('normal_allocation_void.status', 'succeeded');
        });
    }

    /**
     * Service and service-performance facts do not reliably carry the sales
     * order ID. Their source line identifies a voided sale only when a sale
     * fact owns that line; unrelated previously purchased services remain.
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
        // A standalone service void keeps the original completed service fact
        // for audit; its stable checkout/line identity controls visibility.
        $this->excludeVoidedServiceRecord($query, $serviceAlias);
    }

    /** Match the stable checkout/line identity on service or service-performance facts. */
    public function excludeVoidedServiceRecord($query, string $serviceAlias): void
    {
        $query->whereNotExists(function ($void) use ($serviceAlias): void {
            $void->name('cashier_v3_service_record_void_operation')->alias('normal_service_record_void')
                ->join('cashier_v3_entitlement_service_fact normal_service_record',
                    'normal_service_record.tenant_id=normal_service_record_void.tenant_id'
                    . ' AND normal_service_record.id=normal_service_record_void.service_fact_id')
                ->whereRaw('normal_service_record.tenant_id=' . $serviceAlias . '.tenant_id')
                ->whereRaw('normal_service_record.store_id=' . $serviceAlias . '.store_id')
                ->whereRaw('normal_service_record.checkout_request_id=' . $serviceAlias . '.checkout_request_id')
                ->whereRaw('normal_service_record.source_line_id=' . $serviceAlias . '.source_line_id')
                ->where('normal_service_record_void.status', 'succeeded');
        });
    }

    /**
     * A successfully voided service is absent from current operating reports on
     * every date. Match its immutable service identity so both the original
     * personnel fact and the later reversal disappear together; neither fact
     * is deleted, and audit queries can still read the complete chain.
     */
    public function excludeVoidedServicePerformanceFacts($query, string $factAlias): void
    {
        $query->whereNotExists(function ($void) use ($factAlias): void {
            $void->name('cashier_v3_service_record_void_operation')->alias('normal_record_void')
                ->join('cashier_v3_entitlement_service_fact normal_record_service',
                    'normal_record_service.tenant_id=normal_record_void.tenant_id'
                    . ' AND normal_record_service.id=normal_record_void.service_fact_id')
                ->whereRaw('normal_record_service.tenant_id=' . $factAlias . '.tenant_id')
                ->whereRaw('normal_record_service.store_id=' . $factAlias . '.store_id')
                ->whereRaw('normal_record_service.checkout_request_id=' . $factAlias . '.checkout_request_id')
                ->whereRaw('normal_record_service.source_line_id=' . $factAlias . '.source_line_id')
                ->where('normal_record_void.status', 'succeeded');
        });
    }
}
