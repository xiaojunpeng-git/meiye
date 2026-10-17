<?php

declare(strict_types=1);

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * 独立服务的手工费投影。服务行没有同源销售事实，不能借同日销售或补交单承载手工费；
 * 金额只取仍有效的员工分配事实，分类只按服务项目 ID 关联当前平台商品分类。
 */
final class StorePartnerIndependentServiceFacts
{
    /** @return array<int,array<string,mixed>> 与合作方销售、收款行共用的报表行形状 */
    public function read(array $stores, array $range, array $input, int $participantEmployeeId = 0): array
    {
        if ($stores === []) return [];
        // 销售和服务共用 source_line_id 时，原销售行已经承载对应手工费；
        // 这里只补没有销售行的完成服务。日期以服务事实为准，人员在以后月份调整时仍归到原服务日。
        $query = Db::name('cashier_v3_entitlement_service_fact')->alias('sv')
            ->join('cashier_v3_performance_fact pf',
                "pf.tenant_id=sv.tenant_id AND pf.store_id=sv.store_id AND pf.checkout_request_id=sv.checkout_request_id AND pf.source_line_id=sv.source_line_id AND pf.performance_type='labor_performance_allocated' AND pf.status='effective'")
            ->leftJoin('user member', 'member.uid=sv.member_id')
            ->where('sv.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('sv.store_id', $stores)
            ->whereBetween('sv.business_date', [$range['start'], $range['end']])
            ->where('sv.service_status', 'completed')
            ->whereNotExists(function ($sale): void {
                $sale->name('cashier_v3_sale_fact')->alias('independent_service_sale')
                    ->whereRaw('independent_service_sale.tenant_id=sv.tenant_id')
                    ->whereRaw('independent_service_sale.store_id=sv.store_id')
                    ->whereRaw('independent_service_sale.checkout_request_id=sv.checkout_request_id')
                    ->whereRaw('independent_service_sale.source_line_id=sv.source_line_id')
                    ->where('independent_service_sale.fact_direction', 'forward')
                    ->where('independent_service_sale.status', 'effective');
            })
            ->field('pf.fact_id,pf.reversal_of,pf.fact_direction,pf.labor_fee_amount_cents,pf.employee_id,'
                . 'sv.id service_source_fact_id,sv.service_fact_id,sv.store_id,sv.business_date,'
                . 'sv.organization_id,sv.organization_path_snapshot,sv.store_name_snapshot,'
                . 'sv.member_id,sv.member_name_snapshot,member.phone member_phone,'
                . 'sv.project_name_snapshot item_name_snapshot,sv.project_id current_product_id,'
                . 'sv.source_line_id,sv.checkout_request_id,sv.service_record_no,sv.document_no_snapshot,'
                . 'sv.is_experience');
        (new StoreReportNormalDataScopeServices())->excludeVoidedSalesOrderServices($query, 'pf');
        // 个人范围只允许看到本人实际取得手工费的服务行，不能按同一结账请求放大到其他项目。
        if ($participantEmployeeId > 0) $query->where('pf.employee_id', $participantEmployeeId);
        $facts = $query->select()->toArray();
        if ($facts === []) return [];

        // 撤销可能发生在查询月份之后；跨日期按原 fact_id 排除，和薪资表保持当前有效口径。
        $forwardIds = [];
        foreach ($facts as $fact) {
            if ((string)$fact['fact_direction'] === 'forward') $forwardIds[(string)$fact['fact_id']] = true;
        }
        $reversed = [];
        foreach (array_chunk(array_keys($forwardIds), 300) as $ids) {
            foreach (Db::name('cashier_v3_performance_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('fact_direction', 'reversal')->where('status', 'effective')
                ->whereIn('reversal_of', $ids)->field('reversal_of')->select()->toArray() as $reversal) {
                $reversed[(string)$reversal['reversal_of']] = true;
            }
        }
        $services = [];
        foreach ($facts as $fact) {
            if ((string)$fact['fact_direction'] !== 'forward' || isset($reversed[(string)$fact['fact_id']])) continue;
            $key = (string)$fact['service_fact_id'];
            if (!isset($services[$key])) {
                $services[$key] = $fact;
                $services[$key]['labor_amount_cents'] = 0;
            }
            $services[$key]['labor_amount_cents'] += (int)$fact['labor_fee_amount_cents'];
        }
        if ($services === []) return [];

        $links = [];
        foreach ($services as $key => $service) {
            $links[$key] = ['store_id'=>(int)$service['store_id'],
                'product_id'=>(int)$service['current_product_id']];
        }
        $categories = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $rows = [];
        foreach ($services as $key => $service) {
            $labor = (int)$service['labor_amount_cents'];
            if ($labor === 0) continue;
            $category = $categories[$key] ?? [];
            if (!$this->matchesFilters($service, $category, $input)) continue;
            $serviceNo = trim((string)($service['service_record_no'] ?? ''))
                ?: (trim((string)($service['document_no_snapshot'] ?? '')) ?: $key);
            $rows[] = [
                'fact_id'=>$key, 'source_fact_id'=>(int)$service['service_source_fact_id'],
                'store_id'=>(int)$service['store_id'], 'business_date'=>(string)$service['business_date'],
                'organization_id'=>(string)$service['organization_id'],
                'organization_path_snapshot'=>(string)$service['organization_path_snapshot'],
                'store_name_snapshot'=>(string)$service['store_name_snapshot'],
                'store_name'=>(string)$service['store_name_snapshot'],
                'order_id'=>'', 'order_no_snapshot'=>$serviceNo, 'original_sale_no'=>'',
                'member_id'=>(int)$service['member_id'],
                'member_name_snapshot'=>(string)$service['member_name_snapshot'],
                'member_phone'=>(string)($service['member_phone'] ?? ''),
                'item_name_snapshot'=>(string)$service['item_name_snapshot'],
                'source_type'=>'service', 'source_line_id'=>(string)$service['source_line_id'],
                'current_product_id'=>(int)$service['current_product_id'],
                'category_id_snapshot'=>(int)($category['id'] ?? 0),
                'category_path_snapshot'=>(string)($category['path'] ?? ''),
                'product_type_snapshot'=>'project', 'partner_name_snapshot'=>'',
                'is_experience'=>(int)$service['is_experience'],
                'quantity'=>0, 'sale_amount_cents'=>0, 'consumption_amount_cents'=>0,
                'labor_amount_cents'=>$labor, '_sale_activity'=>false,
                // 人工补充字段绑定真实服务事实；前端展示键与保存键都不能借其他销售行。
                'annotation_subject_type'=>'business_event_line',
                'annotation_subject_key'=>'service:' . $key,
            ];
        }
        return $rows;
    }

    /** 服务行没有合作方和销售角色归属；筛选这些维度时不得错误纳入。 */
    private function matchesFilters(array $service, array $category, array $input): bool
    {
        if (trim((string)($input['partner_name'] ?? '')) !== '') return false;
        $productType = trim((string)($input['product_type'] ?? ''));
        if ($productType !== '' && $productType !== 'project') return false;
        foreach (['salesperson_id','guide_id','sales_manager_id'] as $key) {
            if ((int)($input[$key] ?? 0) > 0) return false;
        }
        if (array_key_exists('is_experience', $input) && $input['is_experience'] !== ''
            && $input['is_experience'] !== null
            && (int)$input['is_experience'] !== (int)$service['is_experience']) return false;
        $hasCategoryFilter = (int)($input['category_id'] ?? 0) > 0
            || trim((string)($input['category_path'] ?? '')) !== ''
            || trim((string)($input['category_path_exact'] ?? '')) !== '';
        return !$hasCategoryFilter || StoreReportSaleCategoryServices::matches([$category], $input);
    }
}
