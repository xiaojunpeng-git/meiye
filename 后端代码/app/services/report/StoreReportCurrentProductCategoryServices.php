<?php

namespace app\services\report;

use think\facade\Db;

/**
 * Resolve report categories only through the product's current platform identity.
 * Inputs are already restricted to the caller's authorized store scope; no names or historical snapshots are used.
 */
final class StoreReportCurrentProductCategoryServices
{
    /** @return array<int|string,array{id:int,root_id:int,path:string}> */
    public function resolve(array $links): array
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
        foreach (Db::name('store_product_category')->where('type', 0)->where('relation_id', 0)
            ->field('id,pid,cate_name')->select()->toArray() as $category) {
            $categories[(int)$category['id']] = $category;
        }

        $result = [];
        foreach ($links as $key => $link) {
            $product = $products[(int)($link['product_id'] ?? 0)] ?? [];
            $storeId = (int)($link['store_id'] ?? 0);
            if ($product === [] || (int)$product['is_del'] !== 0 || !$this->belongsToScope($product, $storeId)) continue;
            $platform = $this->isPlatformProduct($product) ? $product : ($parents[(int)$product['pid']] ?? []);
            if ($platform === [] || !$this->isPlatformProduct($platform) || (int)$platform['is_del'] !== 0) continue;
            $category = $this->categoryForProduct($platform, $categories);
            if ($category !== []) $result[$key] = $category;
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function products(array $ids): array
    {
        $products = [];
        foreach (array_chunk($ids, 300) as $chunk) {
            foreach (Db::name('store_product')->whereIn('id', $chunk)
                ->field('id,pid,type,relation_id,cate_id,is_del')->select()->toArray() as $row) {
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
