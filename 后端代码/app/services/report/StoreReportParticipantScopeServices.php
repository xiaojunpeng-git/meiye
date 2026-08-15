<?php

namespace app\services\report;

use think\facade\Db;

/**
 * 门店报表“个人（本人参与）”的唯一后端参与关系出口。
 *
 * 员工 ID 只能由认证后的 DataScopeContext 注入。本类接收的字段
 * 表达式都是服务端硬编码，不允许从请求参数传入。
 */
final class StoreReportParticipantScopeServices
{
    /** @return array<int,int> */
    public function participatingStoreIds(string $tenantId, int $employeeId): array
    {
        $this->assertEmployee($employeeId);
        $storeIds = [];
        foreach ([
            ['cashier_v3_performance_fact', 'employee_id'],
            ['cashier_v3_customer_guide_round_fact', 'guide_employee_id'],
            ['cashier_v3_sales_manager_fact', 'sales_manager_employee_id'],
        ] as [$table, $employeeField]) {
            $ids = Db::name($table)->where('tenant_id', $tenantId)
                ->where($employeeField, $employeeId)->where('status', 'effective')
                ->where('store_id', '>', 0)->column('store_id');
            $storeIds = array_merge($storeIds, array_map('intval', $ids));
        }
        return array_values(array_unique(array_filter($storeIds)));
    }

    public function applyOrder($query, string $orderField, int $employeeId)
    {
        $this->assertEmployee($employeeId);
        return $query->where(function ($participant) use ($orderField, $employeeId) {
            $participant->whereExists(function ($fact) use ($orderField, $employeeId) {
                $fact->name('cashier_v3_performance_fact')->alias('report_participant_pf')
                    ->whereRaw('report_participant_pf.order_id=' . $orderField)
                    ->where('report_participant_pf.employee_id', $employeeId)
                    ->where('report_participant_pf.status', 'effective');
            })->whereExists(function ($fact) use ($orderField, $employeeId) {
                $fact->name('cashier_v3_customer_guide_round_fact')->alias('report_participant_gf')
                    ->whereRaw('report_participant_gf.order_id=' . $orderField)
                    ->where('report_participant_gf.guide_employee_id', $employeeId)
                    ->where('report_participant_gf.status', 'effective');
            }, 'OR')->whereExists(function ($fact) use ($orderField, $employeeId) {
                $fact->name('cashier_v3_sales_manager_fact')->alias('report_participant_mf')
                    ->whereRaw('report_participant_mf.order_id=' . $orderField)
                    ->where('report_participant_mf.sales_manager_employee_id', $employeeId)
                    ->where('report_participant_mf.status', 'effective');
            }, 'OR');
        });
    }

    public function applyCheckout($query, string $checkoutField, int $employeeId)
    {
        $this->assertEmployee($employeeId);
        return $query->where(function ($participant) use ($checkoutField, $employeeId) {
            $participant->whereExists(function ($fact) use ($checkoutField, $employeeId) {
                $fact->name('cashier_v3_performance_fact')->alias('report_checkout_pf')
                    ->whereRaw('report_checkout_pf.checkout_request_id=' . $checkoutField)
                    ->where('report_checkout_pf.employee_id', $employeeId)
                    ->where('report_checkout_pf.status', 'effective');
            })->whereExists(function ($fact) use ($checkoutField, $employeeId) {
                $fact->name('cashier_v3_customer_guide_round_fact')->alias('report_checkout_gf')
                    ->whereRaw('report_checkout_gf.checkout_request_id=' . $checkoutField)
                    ->where('report_checkout_gf.guide_employee_id', $employeeId)
                    ->where('report_checkout_gf.status', 'effective');
            }, 'OR')->whereExists(function ($fact) use ($checkoutField, $employeeId) {
                $fact->name('cashier_v3_sales_manager_fact')->alias('report_checkout_mf')
                    ->whereRaw('report_checkout_mf.checkout_request_id=' . $checkoutField)
                    ->where('report_checkout_mf.sales_manager_employee_id', $employeeId)
                    ->where('report_checkout_mf.status', 'effective');
            }, 'OR');
        });
    }

    public function applyEmployeeFact($query, string $employeeField, int $employeeId)
    {
        $this->assertEmployee($employeeId);
        return $query->where($employeeField, $employeeId);
    }

    /**
     * @return array{store_id:int,source_order_id:string,source_line_id:string}|null
     */
    public function resolveSubject(string $tenantId, string $subjectType, string $subjectKey, int $employeeId): ?array
    {
        $this->assertEmployee($employeeId);
        if (in_array($subjectType, ['sale_line', 'report_row'], true)) {
            $line = Db::name('cashier_v3_sale_fact')->alias('subject_sale')
                ->where('subject_sale.tenant_id', $tenantId)->where('subject_sale.source_line_id', $subjectKey)
                ->where('subject_sale.status', 'effective');
            $this->applyOrder($line, 'subject_sale.order_id', $employeeId);
            $row = $line->field('subject_sale.store_id,subject_sale.order_id,subject_sale.source_line_id')->find();
            if (is_array($row)) return [
                'store_id' => (int)$row['store_id'],
                'source_order_id' => (string)$row['order_id'],
                'source_line_id' => (string)$row['source_line_id'],
            ];
        }
        if (in_array($subjectType, ['sales_order', 'report_row'], true)) {
            $order = Db::name('cashier_v3_sales_order')->alias('subject_order')
                ->where('subject_order.tenant_id', $tenantId)->where('subject_order.order_id', $subjectKey);
            $this->applyOrder($order, 'subject_order.order_id', $employeeId);
            $row = $order->field('subject_order.store_id,subject_order.order_id')->find();
            if (is_array($row)) return [
                'store_id' => (int)$row['store_id'],
                'source_order_id' => (string)$row['order_id'],
                'source_line_id' => '',
            ];
        }
        return null;
    }

    public function annotationIsVisible(array $annotation, string $tenantId, int $employeeId): bool
    {
        return $this->resolveSubject(
            $tenantId,
            (string)($annotation['subject_type'] ?? ''),
            (string)($annotation['subject_key'] ?? ''),
            $employeeId
        ) !== null;
    }

    private function assertEmployee(int $employeeId): void
    {
        if ($employeeId <= 0) throw new \InvalidArgumentException('个人数据权限缺少有效员工身份');
    }
}
