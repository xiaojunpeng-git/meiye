<?php

namespace app\services\report;

use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\fact\CashierV3CheckoutFactPlanV1;
use think\facade\Db;

/**
 * Writes the immutable dimension slice used by the Phase 1 store-operation
 * reports. It is deliberately separate from money facts: sale/payment facts
 * remain the accounting source while this table carries category and
 * experience snapshots needed for reporting.
 */
final class StoreOperationsReportDimensionServices
{
    public const TABLE = 'cashier_v3_report_sale_dimension_fact';

    public function persistInTx(CashierV3CheckoutFactPlanV1 $plan): int
    {
        CashierV3TransactionGuard::assertInTransaction('storeOperationsReportDimensions');
        $context = $plan->context();
        $inserted = 0;
        foreach ($plan->rows()['sale'] ?? [] as $sale) {
            $saleFactId = (string)$sale['fact_id'];
            if (Db::name(self::TABLE)->where('tenant_id', $context['tenant_id'])->where('sale_fact_id', $saleFactId)->find()) {
                continue;
            }
            $line = Db::name('cashier_v3_sales_order_line')
                ->where('tenant_id', $context['tenant_id'])
                ->where('order_id', $context['order_id'])
                ->where('order_line_id', (string)$sale['source_line_id'])
                ->lock(true)->find();
            $categoryId = (int)($sale['category_id_snapshot'] ?? 0);
            // 停用/隐藏分类不参与新事实维度；已经写入的历史快照不回算。
            $category = $categoryId > 0 ? Db::name('store_product_category')->where('id', $categoryId)->where('is_show', 1)->find() : null;
            $path = $this->categoryPath($category);
            $partner = $categoryId > 0
                ? Db::name('cashier_v3_report_category_config')->where('tenant_id', $context['tenant_id'])->where('category_id', $categoryId)->where('enabled', 1)->value('partner_name')
                : '';
            $row = [
                'tenant_id' => (string)$context['tenant_id'], 'store_id' => (int)$context['store_id'],
                'organization_id' => (string)$context['organization_id'], 'member_id' => (int)$context['member_id'],
                'order_id' => (string)$context['order_id'], 'sale_fact_id' => $saleFactId,
                'source_line_id' => (string)$sale['source_line_id'], 'business_date' => (string)$context['business_date'],
                'item_id' => (string)$sale['item_id'], 'item_name_snapshot' => (string)$sale['item_name_snapshot'],
                'product_type_snapshot' => (string)$sale['source_type'], 'category_id_snapshot' => (int)$categoryId,
                'category_name_snapshot' => (string)($category['cate_name'] ?? $sale['category_name_snapshot']),
                'category_parent_id_snapshot' => (int)($category['pid'] ?? 0),
                'category_parent_name_snapshot' => $this->parentName((int)($category['pid'] ?? 0)),
                'category_path_snapshot' => $path !== '' ? $path : (string)$sale['category_name_snapshot'],
                'partner_name_snapshot' => (string)($partner ?? ''),
                'is_experience' => (int)($line['is_experience'] ?? 0),
                'created_at' => time(), 'updated_at' => time(),
            ];
            $row['immutable_fingerprint'] = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            try {
                $inserted += (int)Db::name(self::TABLE)->insert($row);
            } catch (\Throwable $e) {
                if (!$this->duplicate($e)) throw $e;
            }
        }
        return $inserted;
    }

    private function categoryPath(?array $category): string
    {
        if (!$category) return '';
        $parts = [(string)$category['cate_name']];
        $parent = (int)($category['pid'] ?? 0);
        $guard = 0;
        while ($parent > 0 && $guard++ < 8) {
            $row = Db::name('store_product_category')->where('id', $parent)->field('id,pid,cate_name')->find();
            if (!$row) break;
            array_unshift($parts, (string)$row['cate_name']);
            $parent = (int)$row['pid'];
        }
        return implode(' / ', array_values(array_filter($parts)));
    }

    private function parentName(int $parentId): string
    {
        return $parentId > 0 ? (string)(Db::name('store_product_category')->where('id', $parentId)->value('cate_name') ?? '') : '';
    }

    private function duplicate(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return strpos($message, 'duplicate') !== false || strpos($message, '1062') !== false;
    }
}
