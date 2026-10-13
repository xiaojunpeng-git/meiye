<?php

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 会员消费明细的退款业务日投影。退款现金只认有效负向支付事实，
 * 商品分类只从可验证的原销售商品读取；无原单时不按同名商品猜测归属。
 */
final class StoreMemberConsumptionRefundReadServices
{
    /** Return an SQL-scoped refund population shared by count, rows, totals and export. */
    public function query(array $stores, array $range, array $input, int $participantEmployeeId = 0)
    {
        $query = Db::name('cashier_v3_order_lifecycle_operation')->alias('refund_operation')
            ->leftJoin('cashier_v3_refund_adjustment ra',
                'ra.tenant_id=refund_operation.tenant_id AND ra.store_id=refund_operation.store_id'
                . ' AND ra.operation_id=refund_operation.operation_id')
            ->join('cashier_v3_payment_fact payment',
                "payment.tenant_id=refund_operation.tenant_id AND payment.store_id=refund_operation.store_id"
                . " AND payment.order_id=refund_operation.operation_id AND payment.fact_direction='adjustment'"
                . " AND payment.status='effective'"
                . " AND payment.fact_type IN ('manual_refund_adjustment','payment_refunded')")
            ->leftJoin('user refund_member', 'refund_member.uid=payment.member_id')
            ->leftJoin('cashier_v3_sale_fact sale',
                "sale.tenant_id=refund_operation.tenant_id AND sale.store_id=refund_operation.store_id"
                . " AND sale.order_id=ra.source_sales_order_id AND sale.source_line_id=ra.source_sales_line_id"
                . " AND sale.fact_direction='forward' AND sale.status='effective'")
            ->leftJoin('cashier_v3_report_sale_dimension_fact dimension',
                'dimension.tenant_id=sale.tenant_id AND dimension.store_id=sale.store_id'
                . ' AND dimension.sale_fact_id=sale.fact_id')
            ->leftJoin('user_card_holder refund_holder',
                'refund_holder.id=ra.card_holder_id AND refund_holder.uid=refund_operation.member_id')
            ->leftJoin('store_order_cart_info refund_cart',
                'refund_cart.id=ra.card_component_id AND refund_cart.oid=refund_holder.oid')
            ->where('refund_operation.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('refund_operation.store_id', $stores)
            ->whereBetween('refund_operation.business_date', [$range['start'], $range['end']])
            ->where('refund_operation.operation_type', 'refund')
            ->whereIn('refund_operation.source_type', ['card', 'document', 'catalog', 'imported_card'])
            ->where('refund_operation.status', 'succeeded')
            ->where('payment.amount_cents', '<', 0)
            ->where(function ($active): void {
                $active->where('refund_operation.source_type', 'imported_card')
                    ->whereOr('ra.status', 'effective');
            });
        if ($stores === []) $query->whereRaw('1=0');
        $this->filters($query, $input, $participantEmployeeId);
        return $query;
    }

    private function filters($query, array $input, int $participantEmployeeId): void
    {
        $categoryId = max(0, (int)($input['category_id'] ?? 0));
        $path = trim((string)($input['category_path'] ?? ''));
        $exact = trim((string)($input['category_path_exact'] ?? ''));
        if ($categoryId > 0 || $path !== '' || $exact !== '') {
            $candidates = (clone $query)->fieldRaw('refund_operation.id,refund_operation.store_id,'
                . 'COALESCE(NULLIF(refund_cart.product_id,0),NULLIF(ra.catalog_item_id,0),dimension.item_id,0) product_id')
                ->select()->toArray();
            $links = array_map(static function (array $row): array {
                return ['store_id' => (int)$row['store_id'], 'product_id' => (int)$row['product_id']];
            }, $candidates);
            $resolved = (new StoreReportCurrentProductCategoryServices())->resolve($links);
            $matches = [];
            foreach ($candidates as $index => $candidate) {
                $category = $resolved[$index] ?? [];
                $currentPath = (string)($category['path'] ?? '');
                if ($categoryId > 0 && (int)($category['id'] ?? 0) !== $categoryId) continue;
                if ($path !== '' && strpos($currentPath, $path) !== 0) continue;
                if ($exact !== '' && $currentPath !== $exact) continue;
                $matches[] = (int)$candidate['id'];
            }
            if ($matches === []) $query->whereRaw('1=0');
            else $query->whereIn('refund_operation.id', array_values(array_unique($matches)));
        }
        $type = trim((string)($input['product_type'] ?? ''));
        if ($type !== '') $query->where('dimension.product_type_snapshot', $type);
        if (array_key_exists('is_experience', $input) && $input['is_experience'] !== '' && $input['is_experience'] !== null) {
            $query->where('dimension.is_experience', (int)$input['is_experience']);
        }
        $partner = trim((string)($input['partner_name'] ?? ''));
        if ($partner !== '') $query->where(function ($match) use ($partner): void {
            $match->where(function ($direct) use ($partner): void {
                $direct->where(function ($kind): void {
                    $kind->where('sale.source_type', '<>', 'card')->whereOr('ra.card_component_id', 0);
                })
                    ->where('dimension.partner_name_snapshot', $partner);
            })
                ->whereExists(function ($component) use ($partner): void {
                    $component->name('cashier_v3_card_sale_category_allocation_fact')
                        ->whereRaw('tenant_id=sale.tenant_id AND store_id=sale.store_id AND sale_fact_id=sale.fact_id')
                        ->whereRaw('component_product_id=refund_cart.product_id')
                        ->where('status', 'effective')->where('partner_name_snapshot', $partner);
                }, 'OR');
        });
        $salesperson = max($participantEmployeeId, (int)($input['salesperson_id'] ?? 0));
        if ($participantEmployeeId > 0 && (int)($input['salesperson_id'] ?? 0) > 0
            && (int)$input['salesperson_id'] !== $participantEmployeeId) $query->whereRaw('1=0');
        elseif ($salesperson > 0) $query->whereExists(function ($staff) use ($salesperson): void {
            $staff->name('cashier_v3_performance_fact')->alias('refund_staff')
                ->whereRaw('refund_staff.tenant_id=refund_operation.tenant_id')
                ->whereRaw('refund_staff.store_id=refund_operation.store_id')
                ->whereRaw('refund_staff.order_id=refund_operation.operation_id')
                ->where('refund_staff.performance_type', 'refund_sales_deduction')
                ->where('refund_staff.fact_direction', 'forward')->where('refund_staff.status', 'effective')
                ->where('refund_staff.employee_id', $salesperson)
                ->whereNotExists(function ($reversal): void {
                    $reversal->name('cashier_v3_performance_fact')->alias('refund_staff_reversal')
                        ->whereRaw('refund_staff_reversal.tenant_id=refund_staff.tenant_id')
                        ->whereRaw('refund_staff_reversal.reversal_of=refund_staff.fact_id')
                        ->where('refund_staff_reversal.fact_direction', 'reversal')
                        ->where('refund_staff_reversal.status', 'effective');
                });
        });
        foreach (['guide_id' => ['cashier_v3_customer_guide_round_fact', 'guide_employee_id'],
            'sales_manager_id' => ['cashier_v3_sales_manager_fact', 'sales_manager_employee_id']] as $field => $source) {
            $employeeId = (int)($input[$field] ?? 0);
            if ($employeeId <= 0) continue;
            $query->whereExists(function ($person) use ($source, $employeeId): void {
                $person->name($source[0])->whereRaw('order_id=sale.order_id')
                    ->where($source[1], $employeeId)->where('status', 'effective');
            });
        }
    }

    /** One refund operation produces one visible row; never expand it by salespeople or card components. */
    public function rows($query, array $ids, bool $includeSalespeople = true): array
    {
        if ($ids === []) return [];
        $rows = (clone $query)->whereIn('refund_operation.id', $ids)
            ->fieldRaw('refund_operation.id,refund_operation.operation_id order_id,refund_operation.operation_no order_no_snapshot,'
                . 'refund_operation.business_date,refund_operation.store_id,payment.organization_id,'
                . 'payment.organization_path_snapshot,payment.store_name_snapshot,payment.member_id,'
                . 'payment.member_name_snapshot,refund_member.phone member_phone,payment.business_source_label_snapshot,'
                . 'ra.target_name_snapshot,ra.source_sales_line_id,ra.card_component_id,'
                . 'sale.fact_id original_sale_fact_id,sale.order_no_snapshot original_sale_no,'
                . 'COALESCE(NULLIF(refund_cart.product_id,0),NULLIF(ra.catalog_item_id,0),dimension.item_id,0) current_product_id,'
                . 'dimension.product_type_snapshot,dimension.partner_category_id_snapshot,'
                . 'dimension.partner_default_ratio_snapshot,dimension.partner_name_snapshot,'
                . 'payment.amount_cents,payment.source_document_type')
            ->select()->toArray();
        $saleIds = array_values(array_unique(array_filter(array_column($rows, 'original_sale_fact_id'))));
        $componentProductIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'current_product_id')))));
        $cardPartners = [];
        if ($saleIds !== [] && $componentProductIds !== []) {
            // Match the refunded component, not any other component in its card.
            $allocations = Db::name('cashier_v3_card_sale_category_allocation_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('sale_fact_id', $saleIds)->whereIn('component_product_id', $componentProductIds)
                ->where('status', 'effective')
                ->field('sale_fact_id,component_product_id,partner_category_id_snapshot,'
                    . 'partner_default_ratio_snapshot,partner_name_snapshot')->select()->toArray();
            foreach ($allocations as $allocation) {
                $key = (string)$allocation['sale_fact_id'] . ':' . (int)$allocation['component_product_id'];
                if (isset($cardPartners[$key]) && $cardPartners[$key] != $allocation) {
                    $cardPartners[$key] = null;
                    continue;
                }
                if (!array_key_exists($key, $cardPartners)) $cardPartners[$key] = $allocation;
            }
        }
        $links = array_map(static function (array $row): array {
            return ['store_id' => (int)$row['store_id'], 'product_id' => (int)$row['current_product_id']];
        }, $rows);
        $categories = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $staffNames = [];
        if ($includeSalespeople) {
            $operationIds = array_values(array_unique(array_column($rows, 'order_id')));
            $storeIds = array_values(array_unique(array_map('intval', array_column($rows, 'store_id'))));
            foreach (Db::name('cashier_v3_performance_fact')->alias('refund_staff')
                ->where('refund_staff.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('refund_staff.store_id', $storeIds)
                ->whereIn('refund_staff.order_id', $operationIds)
                ->where('refund_staff.performance_type', 'refund_sales_deduction')
                ->where('refund_staff.fact_direction', 'forward')->where('refund_staff.status', 'effective')
                ->whereNotExists(function ($reversal): void {
                    $reversal->name('cashier_v3_performance_fact')->alias('refund_staff_reversal')
                        ->whereRaw('refund_staff_reversal.tenant_id=refund_staff.tenant_id')
                        ->whereRaw('refund_staff_reversal.reversal_of=refund_staff.fact_id')
                        ->where('refund_staff_reversal.fact_direction', 'reversal')
                        ->where('refund_staff_reversal.status', 'effective');
                })->field('refund_staff.order_id,refund_staff.employee_name_snapshot')->select()->toArray() as $staff) {
                $name = trim((string)$staff['employee_name_snapshot']);
                if ($name !== '') $staffNames[(string)$staff['order_id']][$name] = true;
            }
        }
        foreach ($rows as $index => &$row) {
            $row['current_category_id'] = (int)($categories[$index]['id'] ?? 0);
            $row['category_path_snapshot'] = (string)($categories[$index]['path'] ?? '');
            $row['item_name_snapshot'] = trim((string)($row['target_name_snapshot'] ?? '')) ?: '旧卡退款';
            $row['source_line_id'] = (string)$row['order_id'];
            if ((int)$row['card_component_id'] > 0) {
                $key = (string)$row['original_sale_fact_id'] . ':' . (int)$row['current_product_id'];
                $partner = $cardPartners[$key] ?? null;
                $row['partner_category_id_snapshot'] = (int)($partner['partner_category_id_snapshot'] ?? 0);
                $row['partner_default_ratio_snapshot'] = (int)($partner['partner_default_ratio_snapshot'] ?? 0);
                $row['partner_name_snapshot'] = (string)($partner['partner_name_snapshot'] ?? '');
            }
            $row['partner_share_cents'] = $this->partnerShareCents((int)$row['amount_cents'],
                (int)$row['partner_category_id_snapshot'], (int)$row['partner_default_ratio_snapshot']);
            $row['salesperson_names'] = implode('、', array_keys($staffNames[(string)$row['order_id']] ?? []));
        }
        unset($row);
        return $rows;
    }

    /** Keep the full filtered refund total in cents, independent of the page. */
    public function amountCents($query): int
    {
        $summary = (clone $query)->fieldRaw('COALESCE(SUM(payment.amount_cents),0) amount_cents')->find() ?: [];
        return (int)($summary['amount_cents'] ?? 0);
    }

    /** Original sale ratio applies to the refund magnitude; round half-up once at cent precision. */
    private function partnerShareCents(int $refundCents, int $partnerCategoryId, int $ratio): int
    {
        if ($partnerCategoryId <= 0 || $ratio <= 0 || $refundCents >= 0) return 0;
        return -intdiv(abs($refundCents) * $ratio + 50, 100);
    }
}
