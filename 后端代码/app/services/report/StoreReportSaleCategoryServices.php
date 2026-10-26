<?php

namespace app\services\report;

use app\services\cashier\v3\CashierV3ScopeResolver;
use think\facade\Db;

/**
 * Sales and allocated repayments share the same immutable item identity.
 * Cards contribute their purchased projects, never the outer card category.
 * This read projection retains frozen partner amounts; it cannot recalculate ratios.
 */
final class StoreReportSaleCategoryServices
{
    /** @return array<int|string,array<int,array>> input key => resolved components (including unknowns) */
    public function resolve(array $rows): array
    {
        $ids = $stores = [];
        foreach ($rows as $row) {
            if (($row['source_type'] ?? '') === 'card') {
                $id = (string)($row['sale_fact_id'] ?? '');
                if ($id !== '') $ids[$id] = true;
                $stores[(int)$row['store_id']] = true;
            }
        }
        $components = $groups = $shares = [];
        foreach (array_chunk(array_keys($ids), 300) as $chunk) {
            $facts = Db::name('cashier_v3_card_sale_item_allocation_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('store_id', array_keys($stores))->whereIn('sale_fact_id', $chunk)
                ->where('status', 'effective')->order('id')
                ->field('store_id,sale_fact_id,card_receipt_id,category_id_snapshot,component_product_id product_id,sale_amount_cents,configured_amount_cents,cash_performance_amount_cents')->select()->toArray();
            foreach ($facts as $fact) $groups[$this->groupKey($fact)][] = $fact;
            foreach (Db::name('cashier_v3_card_sale_category_allocation_fact')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('store_id', array_keys($stores))->whereIn('sale_fact_id', $chunk)->where('status', 'effective')
                ->field('store_id,sale_fact_id,card_receipt_id,category_id_snapshot,partner_share_amount_cents')->select()->toArray() as $fact) {
                $key = $this->groupKey($fact);
                $shares[$key]['row'] = $fact;
                $shares[$key]['cents'] = ($shares[$key]['cents'] ?? 0) + (int)$fact['partner_share_amount_cents'];
            }
        }
        // Category facts can group several projects. Read every purchased item and distribute only
        // the frozen share cents within that original group; current ratios never enter the calculation.
        foreach ($groups as $groupKey => $items) {
            $share = (int)($shares[$groupKey]['cents'] ?? 0);
            $weights = array_map(static fn(array $item): int => max(0, (int)$item['sale_amount_cents']), $items);
            if (array_sum($weights) === 0) $weights = array_map(static fn(array $item): int => max(0, (int)$item['configured_amount_cents']), $items);
            $total = array_sum($weights); $remaining = abs($share); $last = count($items) - 1;
            foreach ($items as $index => $item) {
                $part = count($items) === 1 ? $remaining : ($total > 0
                    ? ($index === $last ? $remaining : (int)bcdiv(bcmul((string)abs($share), (string)$weights[$index], 0), (string)$total, 0)) : 0);
                $remaining -= $part;
                $item['partner_share_amount_cents'] = $share < 0 ? -$part : $part;
                $components[$item['store_id'] . ':' . $item['sale_fact_id']][] = $item;
            }
            if ($remaining > 0) {
                $item = $items[0]; $item['product_id'] = 0;
                foreach (['sale_amount_cents','configured_amount_cents','cash_performance_amount_cents'] as $field) $item[$field] = 0;
                $item['partner_share_amount_cents'] = $share < 0 ? -$remaining : $remaining;
                $components[$item['store_id'] . ':' . $item['sale_fact_id']][] = $item;
            }
            unset($shares[$groupKey]);
        }
        foreach ($shares as $share) {
            $fact = $share['row'];
            $components[$fact['store_id'] . ':' . $fact['sale_fact_id']][] = [
                'product_id' => 0, 'partner_share_amount_cents' => $share['cents']];
        }
        $links = $owners = [];
        foreach ($rows as $key => $row) {
            $store = (int)$row['store_id'];
            $items = ($row['source_type'] ?? '') === 'card'
                ? ($components[$store . ':' . ($row['sale_fact_id'] ?? '')] ?? [])
                : [['product_id' => (int)($row['product_id'] ?? 0),
                    'partner_share_amount_cents' => (int)($row['partner_share_amount_cents'] ?? 0)]];
            // Missing component identity must retain any known amount in unclassified.
            if ($items === []) $items = [['product_id' => 0,
                'partner_share_amount_cents' => (int)($row['partner_share_amount_cents'] ?? 0)]];
            foreach ($items as $item) {
                $links[] = ['store_id' => $store, 'product_id' => (int)$item['product_id']];
                $owners[] = [$key, $item];
            }
        }
        $resolved = (new StoreReportCurrentProductCategoryServices())->resolve($links);
        $result = [];
        foreach ($links as $index => $link) {
            $category = $resolved[$index] ?? [];
            // A corrupt component pointing at another card is not a project identity.
            if (($rows[$owners[$index][0]]['source_type'] ?? '') === 'card'
                && in_array((int)($category['product_type'] ?? -1), [4, 5], true)) $category = [];
            $result[$owners[$index][0]][] = array_merge($owners[$index][1], ['id' => 0, 'path' => ''], $category,
                ['product_id' => $link['product_id']]);
        }
        return $result;
    }

    /** Frozen category and receipt IDs locate the original amount pool, not the displayed category. */
    private function groupKey(array $row): string
    {
        return implode(':', [(int)$row['store_id'], (string)$row['sale_fact_id'],
            (string)$row['card_receipt_id'], (int)$row['category_id_snapshot']]);
    }

    /** A single business row may contain several verified categories; do not duplicate its amount. */
    public static function label(array $categories): string
    {
        $paths = [];
        foreach ($categories as $category) $paths[(string)($category['path'] ?? '') ?: '未分类'] = true;
        return implode('、', array_keys($paths));
    }

    /** The same resolved IDs drive filtering and display; unknowns never match a named category. */
    public static function matches(array $categories, array $input): bool
    {
        foreach ($categories as $category) {
            if ((int)($category['id'] ?? 0) <= 0) continue;
            $path = (string)$category['path'];
            if ((int)($input['category_id'] ?? 0) > 0 && (int)$input['category_id'] !== (int)$category['id']) continue;
            $prefix = trim((string)($input['category_path'] ?? ''));
            if ($prefix !== '' && $path !== $prefix && strpos($path, $prefix . '/') !== 0) continue;
            $exact = trim((string)($input['category_path_exact'] ?? ''));
            if ($exact !== '' && $path !== $exact) continue;
            return true;
        }
        return false;
    }
}
