<?php

namespace app\services\report;

use think\facade\Db;

/**
 * Resolve report categories only through the product's current platform identity.
 * Inputs are already restricted to the caller's authorized store scope; no names or historical snapshots are used.
 * Deletion hides a catalog item from sale, but must not erase its platform category from historical reports.
 */
final class StoreReportCurrentProductCategoryServices
{
    /**
     * Link each store product to its platform product and current platform category.
     * A caller may pass the category tree it already read for this report request;
     * this only avoids a duplicate lookup and never changes the identity chain.
     * @return array<int|string,array{id:int,root_id:int,path:string,platform_product_id:int,product_type:int}>
     */
    public function resolve(array $links, ?array $categoryRows = null): array
    {
        if ($links === []) return [];
        $ids = [];
        foreach ($links as $link) {
            $id = (int)($link['product_id'] ?? 0);
            if ($id > 0) $ids[$id] = true;
        }
        $products = $this->products(array_keys($ids));
        $parentIds = [];
        foreach ($products as $product) {
            if ((int)$product['pid'] > 0) $parentIds[(int)$product['pid']] = true;
        }
        $parents = $this->products(array_keys($parentIds));
        $categories = [];
        $categoryRows = $categoryRows ?? Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name')->select()->toArray();
        foreach ($categoryRows as $category) {
            $categories[(int)$category['id']] = $category;
        }

        $result = [];
        foreach ($links as $key => $link) {
            $product = $products[(int)($link['product_id'] ?? 0)] ?? [];
            $storeId = (int)($link['store_id'] ?? 0);
            if ($product === [] || !$this->belongsToScope($product, $storeId)) continue;
            $platform = $this->isPlatformProduct($product) ? $product : ($parents[(int)$product['pid']] ?? []);
            if ($platform === [] || !$this->isPlatformProduct($platform)) continue;
            $category = $this->categoryForProduct($platform, $categories);
            if ($category !== []) {
                // Reports may group store copies by their shared platform item.
                // Keep the type beside the current category so a product/project
                // report cannot mistake a card in the same category for an item.
                $category['platform_product_id'] = (int)$platform['id'];
                $category['product_type'] = (int)$platform['product_type'];
                $result[$key] = $category;
            }
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function products(array $ids): array
    {
        $products = [];
        foreach (array_chunk($ids, 300) as $chunk) {
            foreach (Db::name('store_product')->whereIn('id', $chunk)
                ->field('id,pid,type,relation_id,cate_id,product_type')->select()->toArray() as $row) {
                $products[(int)$row['id']] = $row;
            }
        }
        return $products;
    }

    private function isPlatformProduct(array $product): bool
    {
        return (int)$product['type'] === 0 && (int)$product['relation_id'] === 0;
    }

    private function belongsToScope(array $product, int $storeId): bool
    {
        return $this->isPlatformProduct($product)
            || ((int)$product['type'] === 1 && (int)$product['relation_id'] === $storeId);
    }

    /** @return array{id:int,root_id:int,path:string}|array{} */
    private function categoryForProduct(array $product, array $categories): array
    {
        $best = [];
        foreach (explode(',', (string)($product['cate_id'] ?? '')) as $rawId) {
            $rawId = trim($rawId);
            if (preg_match('/^[1-9][0-9]*$/D', $rawId) !== 1) continue;
            $chain = [];
            $seen = [];
            $id = (int)$rawId;
            while ($id > 0 && isset($categories[$id]) && !isset($seen[$id]) && count($chain) < 12) {
                $seen[$id] = true;
                $chain[] = $categories[$id];
                $id = (int)$categories[$id]['pid'];
            }
            if ($id !== 0) continue;
            $chain = array_reverse($chain);
            if (count($chain) > count($best) && !array_filter($chain,
                static fn(array $node): bool => trim((string)$node['cate_name']) === '')) $best = $chain;
        }
        if ($best === []) return [];
        // Keep the full active platform hierarchy: a third-level leaf must not masquerade as its parent.
        $path = implode('/', array_column($best, 'cate_name'));
        return ['id' => (int)$best[count($best) - 1]['id'], 'root_id' => (int)$best[0]['id'], 'path' => $path];
    }
}
