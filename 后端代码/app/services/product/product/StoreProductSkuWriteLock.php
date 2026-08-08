<?php
declare(strict_types=1);

namespace app\services\product\product;

use think\exception\ValidateException;
use think\facade\Db;

/**
 * Transaction-local catalog stock lock: catalog_product(42) before catalog_sku(43).
 */
final class StoreProductSkuWriteLock
{
    /**
     * @param array<int,array{product_id:int,unique?:string,platform_pid?:int,require_sku?:bool}> $targets
     * @return array{products:array<int,array>,skus:array<string,array>}
     */
    public function lock(array $targets): array
    {
        $productIds = [];
        $skuTargets = [];
        foreach ($targets as $target) {
            $productId = (int)($target['product_id'] ?? 0);
            $platformProductId = (int)($target['platform_pid'] ?? 0);
            $unique = (string)($target['unique'] ?? '');
            $requireSku = !empty($target['require_sku']);

            if ($productId <= 0) {
                if ($requireSku) {
                    throw new ValidateException('商品规格不存在，禁止漏锁');
                }
                continue;
            }
            $productIds[$productId] = $productId;
            if ($platformProductId > 0) {
                $productIds[$platformProductId] = $platformProductId;
            }
            if ($unique === '') {
                if ($requireSku) {
                    throw new ValidateException('商品规格不存在，禁止漏锁');
                }
                continue;
            }
            $key = StoreCatalogWriteLease::identityKey($productId, $unique);
            if (!isset($skuTargets[$key])) {
                $skuTargets[$key] = [
                    'product_id' => $productId,
                    'unique' => $unique,
                    'require_sku' => $requireSku,
                ];
            } elseif ($requireSku) {
                $skuTargets[$key]['require_sku'] = true;
            }
        }

        $productIds = array_values($productIds);
        sort($productIds, SORT_NUMERIC);
        $lockedProducts = [];
        foreach ($productIds as $productId) {
            $row = Db::name('store_product')
                ->where('id', $productId)
                ->lock(true)
                ->find();
            if (!$row) {
                throw new ValidateException('商品不存在或已删除');
            }
            $lockedProducts[$productId] = is_object($row) && method_exists($row, 'toArray')
                ? $row->toArray()
                : (array)$row;
        }

        if (!$skuTargets) {
            return ['products' => $lockedProducts, 'skus' => []];
        }

        $skuProductIds = [];
        $uniques = [];
        foreach ($skuTargets as $target) {
            $skuProductIds[(int)$target['product_id']] = (int)$target['product_id'];
            $uniques[(string)$target['unique']] = (string)$target['unique'];
        }
        $plannedRows = Db::name('store_product_attr_value')
            ->whereIn('product_id', array_values($skuProductIds))
            ->whereIn('unique', array_values($uniques))
            ->where('type', 0)
            ->field('id,product_id,unique')
            ->select();
        $plannedRows = is_object($plannedRows) && method_exists($plannedRows, 'toArray')
            ? $plannedRows->toArray()
            : (array)$plannedRows;

        $skuIds = [];
        $plannedById = [];
        $seenIdentity = [];
        foreach ($plannedRows as $row) {
            $key = StoreCatalogWriteLease::identityKey(
                (int)($row['product_id'] ?? 0),
                (string)($row['unique'] ?? '')
            );
            if (!isset($skuTargets[$key])) {
                continue;
            }
            if (isset($seenIdentity[$key])) {
                throw new ValidateException('商品存在重复规格标识，请先修复数据');
            }
            $skuId = (int)($row['id'] ?? 0);
            if ($skuId <= 0) {
                continue;
            }
            $seenIdentity[$key] = true;
            $skuIds[$skuId] = $skuId;
            $plannedById[$skuId] = $key;
        }

        foreach ($skuTargets as $key => $target) {
            if (!empty($target['require_sku']) && !isset($seenIdentity[$key])) {
                throw new ValidateException('商品规格不存在，禁止漏锁');
            }
        }

        $skuIds = array_values($skuIds);
        sort($skuIds, SORT_NUMERIC);
        $lockedSkus = [];
        foreach ($skuIds as $skuId) {
            $row = Db::name('store_product_attr_value')
                ->where('id', $skuId)
                ->lock(true)
                ->find();
            $row = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array)$row;
            $key = StoreCatalogWriteLease::identityKey(
                (int)($row['product_id'] ?? 0),
                (string)($row['unique'] ?? '')
            );
            if (!$row || (int)($row['type'] ?? -1) !== 0 || $key !== ($plannedById[$skuId] ?? '')) {
                throw new ValidateException('商品规格锁定计划已变化，请刷新后重试');
            }
            $lockedSkus[$key] = $row;
        }

        return ['products' => $lockedProducts, 'skus' => $lockedSkus];
    }
}
