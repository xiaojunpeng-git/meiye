<?php

namespace app\services\cashier\v3\cashier;

use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\CashierV3TransactionGuard;
use think\facade\Db;

/** 旧商品主表在收银 V3 的只读/行锁适配器。 */
final class ThinkPhpCashierV3SaleCatalogAuthority implements CashierV3SaleCatalogAuthority
{
    private const LIST_LIMIT_WITH_SENTINEL = 5001;
    private const PRODUCT_FIELDS = [
        'id', 'pid', 'type', 'relation_id', 'product_type', 'store_name', 'cate_id',
        'keyword', 'unit_name', 'is_show', 'is_del', 'is_verify', 'is_inventory',
        'allow_negative_stock', 'card_num', 'card_num_type', 'card_rule_type',
        'card_rule_version', 'card_choice_limit', 'card_shared_times',
    ];

    private const SKU_FIELDS = [
        'id', 'product_id', 'product_type', 'unique', 'suk', 'price', 'ot_price',
        'stock', 'code', 'bar_code', 'is_show', 'type', 'write_times', 'write_valid',
        'write_days', 'write_start', 'write_end',
    ];

    public function listStoreItems(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }
        $rows = Db::name('store_product_attr_value')->alias('sku')
            ->join('store_product p', 'p.id=sku.product_id')
            ->where('p.type', 1)
            ->where('p.relation_id', $storeId)
            ->where('p.is_del', 0)
            ->where('p.is_show', 1)
            ->where('p.is_verify', 1)
            ->whereIn('p.product_type', [0, 4, 5, 6])
            ->where('sku.type', 0)
            ->where('sku.is_show', 1)
            ->field($this->joinedFields())
            ->order('p.sort desc,p.id desc,sku.id asc')
            ->limit(self::LIST_LIMIT_WITH_SENTINEL)
            ->select();
        $rows = $this->rows($rows);
        // 旧系统将定制卡壳保留为隐藏的门店项目副本（pid=8154）。它不能
        // 作为普通项目直接售卖，但 V3 的定制卡配置必须仍能读取并锁定它。
        // 仅追加通过基础审核且 SKU 可用的壳，不放宽常规销售目录的上架条件。
        foreach ($this->customCardShellRows($storeId) as $shell) {
            $skuId = (int)($shell['sku_id'] ?? 0);
            $exists = false;
            foreach ($rows as $row) {
                if ((int)($row['sku_id'] ?? 0) === $skuId) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $rows[] = $shell;
            }
        }
        $rows = $this->hydrateCardComponentsForList($storeId, $rows);
        $categories = $this->categoryMap($storeId, $rows);
        foreach ($rows as &$row) {
            $row['category_names'] = $this->categoryNames(
                (string)($row['product_cate_id'] ?? ''),
                $categories
            );
        }
        unset($row);
        return $rows;
    }

    /** Hidden legacy custom-card shells are configuration hosts, not catalog items. */
    private function customCardShellRows(int $storeId): array
    {
        return $this->rows(Db::name('store_product_attr_value')->alias('sku')
            ->join('store_product p', 'p.id=sku.product_id')
            ->where('p.type', 1)
            ->where('p.relation_id', $storeId)
            ->where('p.pid', 8154)
            ->where('p.is_del', 0)
            ->where('p.is_verify', 1)
            ->where('sku.type', 0)
            ->where('sku.is_show', 1)
            ->field($this->joinedFields())
            ->order('p.id asc,sku.id asc')
            ->limit(2)
            ->select());
    }

    public function readStoreItemBySkuId(int $storeId, int $skuId)
    {
        if ($storeId <= 0 || $skuId <= 0) {
            return null;
        }
        $row = Db::name('store_product_attr_value')->alias('sku')
            ->join('store_product p', 'p.id=sku.product_id')
            ->where('sku.id', $skuId)
            ->where('p.type', 1)
            ->where('p.relation_id', $storeId)
            ->whereIn('p.product_type', [0, 4, 5, 6])
            ->where('sku.type', 0)
            ->field($this->joinedFields())
            ->find();
        if (!$row) {
            return null;
        }
        $row = (array)$row;
        $row['category_names'] = $this->categoryNames(
            (string)($row['product_cate_id'] ?? ''),
            $this->categoryMap($storeId, [$row])
        );
        $productId = (int)($row['product_id'] ?? 0);
        if ((int)($row['product_product_type'] ?? -1) !== 5 || $productId <= 0) {
            $row['card_components'] = [];
            return $row;
        }

        $relations = $this->readActiveCardRelations($productId);
        $productIds = [];
        foreach ($relations as $relation) {
            $componentProductId = (int)($relation['product_id'] ?? 0);
            if ($componentProductId > 0) {
                $productIds[$componentProductId] = $componentProductId;
            }
        }
        $products = $this->readProductsById(array_values($productIds));
        $skus = $this->readComponentSkus($relations);
        $row['card_components'] = $this->lockedCardComponents(
            $storeId,
            $relations,
            $products,
            $skus
        );
        return $row;
    }

    public function lockStoreResourceRow(int $storeId, string $kind, int $resourceId): bool
    {
        CashierV3TransactionGuard::assertInTransaction('cashierSaleCatalogResourceLock:' . $kind);
        if ($storeId <= 0 || $resourceId <= 0
            || !in_array($kind, ['catalog_card_definition', 'catalog_product', 'catalog_sku'], true)) {
            return false;
        }
        if ($kind === 'catalog_sku') {
            $identity = Db::name('store_product_attr_value')
                ->where('id', $resourceId)
                ->where('type', 0)
                ->field('id,product_id')
                ->find();
            $productId = (int)($identity['product_id'] ?? 0);
            $product = $productId > 0
                ? Db::name('store_product')
                    ->where('id', $productId)
                    ->where('type', 1)
                    ->where('relation_id', $storeId)
                    ->field('id')
                    ->find()
                : null;
            if (!$product) {
                return false;
            }
            $row = Db::name('store_product_attr_value')
                ->where('id', $resourceId)
                ->where('product_id', $productId)
                ->where('type', 0)
                ->field('id')
                ->lock(true)
                ->find();
            return !empty($row);
        }
        $row = Db::name('store_product')
            ->where('id', $resourceId)
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->field('id')
            ->lock(true)
            ->find();
        if (!$row) {
            return false;
        }
        if ($kind === 'catalog_card_definition') {
            $type = Db::name('store_product')
                ->where('id', $resourceId)
                ->field('product_type,pid')
                ->find();
            // 定制卡壳是历史项目副本而不是 product_type=5 的普通卡项；
            // 它同样需要作为整张定制卡定义的版本锚点被锁定。
            return (int)($type['product_type'] ?? 0) === 5
                || (int)($type['pid'] ?? 0) === 8154;
        }
        return true;
    }

    public function lockStoreItemBySkuId(int $storeId, int $skuId)
    {
        CashierV3TransactionGuard::assertInTransaction('cashierSaleCatalogAuthorityLock');
        if ($storeId <= 0 || $skuId <= 0) {
            return null;
        }

        // 先无锁发现完整资源集合，再按全部商品 -> 全部 SKU -> 卡项关系稳定加锁。
        $identity = Db::name('store_product_attr_value')
            ->where('id', $skuId)
            ->where('type', 0)
            ->field('id,product_id,product_type,unique')
            ->find();
        $productId = (int)($identity['product_id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }
        $productIdentity = Db::name('store_product')
            ->where('id', $productId)
            ->field('id,product_type')
            ->find();
        if (!$productIdentity) {
            return null;
        }

        $plannedProductType = (int)($productIdentity['product_type'] ?? -1);
        $plannedRelations = $plannedProductType === 5
            ? $this->readActiveCardRelations($productId)
            : [];
        $productIds = [$productId => true];
        $componentUniques = [];
        foreach ($plannedRelations as $relation) {
            $componentProductId = (int)($relation['product_id'] ?? 0);
            $componentUnique = trim((string)($relation['product_attr_unique'] ?? ''));
            if ($componentProductId > 0) {
                $productIds[$componentProductId] = true;
            }
            if ($componentUnique !== '') {
                $componentUniques[$componentUnique] = true;
            }
        }

        $plannedComponentSkus = [];
        if ($componentUniques) {
            $plannedComponentSkus = $this->rows(Db::name('store_product_attr_value')
                ->whereIn('unique', array_keys($componentUniques))
                ->where('type', 0)
                ->field('id,product_id,product_type,unique')
                ->select());
        }
        $skuIds = [$skuId => true];
        foreach ($plannedComponentSkus as $componentSku) {
            $componentProductId = (int)($componentSku['product_id'] ?? 0);
            $componentUnique = trim((string)($componentSku['unique'] ?? ''));
            if ($componentProductId > 0 && $componentUnique !== ''
                && $this->relationContainsComponent($plannedRelations, $componentProductId, $componentUnique)) {
                $componentSkuId = (int)($componentSku['id'] ?? 0);
                if ($componentSkuId > 0) {
                    $skuIds[$componentSkuId] = true;
                }
            }
        }

        $lockedRelations = [];
        if ($plannedProductType === 5) {
            // catalog_card_definition(41) 使用卡商品主行作精确串行点；关系集合只读复核。
            if (!$this->lockCardDefinitionProduct($productId)) {
                return null;
            }
            $lockedRelations = $this->readActiveCardRelations($productId);
            if (!$this->sameRelationPlan($plannedRelations, $lockedRelations)) {
                return null;
            }
        }

        $lockedProducts = $this->lockProductsById(array_keys($productIds));
        $lockedSkus = $this->lockSkusById(array_keys($skuIds));
        $product = $lockedProducts[$productId] ?? null;
        $sku = $lockedSkus[$skuId] ?? null;
        if (!$product || !$sku) {
            return null;
        }
        if ((int)($product['type'] ?? 0) !== 1
            || (int)($product['relation_id'] ?? 0) !== $storeId
            || (int)($product['product_type'] ?? -1) !== $plannedProductType
            || (int)($sku['product_id'] ?? 0) !== $productId
            || (int)($sku['type'] ?? -1) !== 0
            || (int)($identity['product_type'] ?? -1) !== (int)($sku['product_type'] ?? -2)
            || trim((string)($identity['unique'] ?? '')) !== trim((string)($sku['unique'] ?? ''))) {
            return null;
        }

        $row = $this->combineRows($product, $sku);
        $row['category_names'] = $this->categoryNames(
            (string)($row['product_cate_id'] ?? ''),
            $this->categoryMap($storeId, [$row])
        );
        $row['card_components'] = $plannedProductType === 5
            ? $this->lockedCardComponents($storeId, $lockedRelations, $lockedProducts, $lockedSkus)
            : [];
        return $row;
    }

    /** @return array<int,array> */
    private function readActiveCardRelations(int $cardProductId): array
    {
        $rows = $this->rows(Db::name('store_card_related')
            ->where('card_product_id', $cardProductId)
            ->field('id,card_product_id,product_id,product_type,product_attr_unique,cost,price,write_times,writeoff_amount,status')
            ->order('id asc')
            ->select());
        return array_values(array_filter($rows, static function (array $row): bool {
            return (int)($row['status'] ?? 0) === 1;
        }));
    }

    private function lockCardDefinitionProduct(int $cardProductId): ?array
    {
        $row = Db::name('store_product')
            ->where('id', $cardProductId)
            ->field(implode(',', self::PRODUCT_FIELDS))
            ->lock(true)
            ->find();
        return $row ? (array)$row : null;
    }

    /** @return array<int,array> */
    private function lockedCardComponents(
        int $storeId,
        array $relations,
        array $productsById,
        array $skusById
    ): array {
        return $this->composeCardComponents(
            $storeId,
            $relations,
            $productsById,
            $skusById,
            $this->componentCategoryMap($storeId, $productsById, $skusById)
        );
    }

    /**
     * The root catalog must use the same card-component authority as a later
     * locked add-to-cart. Attach component rows in batches so an unavailable
     * card is disabled before the cashier can click it, without an N+1 query.
     *
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    private function hydrateCardComponentsForList(int $storeId, array $rows): array
    {
        $cardIds = [];
        foreach ($rows as $row) {
            if ((int)($row['product_product_type'] ?? -1) === 5) {
                $cardId = (int)($row['product_id'] ?? 0);
                if ($cardId > 0) {
                    $cardIds[$cardId] = $cardId;
                }
            }
        }
        if (!$cardIds) {
            return $rows;
        }

        $relations = $this->readActiveCardRelationsForCards(array_values($cardIds));
        $relationsByCard = [];
        $componentProductIds = [];
        foreach ($relations as $relation) {
            $cardId = (int)($relation['card_product_id'] ?? 0);
            $componentProductId = (int)($relation['product_id'] ?? 0);
            if ($cardId > 0) {
                $relationsByCard[$cardId][] = $relation;
            }
            if ($componentProductId > 0) {
                $componentProductIds[$componentProductId] = $componentProductId;
            }
        }

        $products = $this->readProductsById(array_values($componentProductIds));
        $skus = $this->readComponentSkus($relations);
        $categories = $this->componentCategoryMap($storeId, $products, $skus);
        foreach ($rows as &$row) {
            if ((int)($row['product_product_type'] ?? -1) !== 5) {
                continue;
            }
            $cardId = (int)($row['product_id'] ?? 0);
            $row['card_components'] = $this->composeCardComponents(
                $storeId,
                $relationsByCard[$cardId] ?? [],
                $products,
                $skus,
                $categories
            );
        }
        unset($row);
        return $rows;
    }

    /** @return array<int,array> */
    private function readActiveCardRelationsForCards(array $cardProductIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $cardProductIds))));
        if (!$ids) {
            return [];
        }
        return $this->rows(Db::name('store_card_related')
            ->whereIn('card_product_id', $ids)
            ->where('status', 1)
            ->field('id,card_product_id,product_id,product_type,product_attr_unique,cost,price,write_times,writeoff_amount,status')
            ->order('card_product_id asc,id asc')
            ->select());
    }

    /** @return array<int,string> */
    private function componentCategoryMap(int $storeId, array $productsById, array $skusById): array
    {
        $rows = [];
        foreach ($skusById as $sku) {
            $product = $productsById[(int)($sku['product_id'] ?? 0)] ?? null;
            if ($product) {
                $rows[] = $this->combineRows($product, $sku);
            }
        }
        return $this->categoryMap($storeId, $rows);
    }

    /** @return array<int,array> */
    private function composeCardComponents(
        int $storeId,
        array $relations,
        array $productsById,
        array $skusById,
        array $categories
    ): array {
        $skusByComponent = [];
        $duplicateComponentKeys = [];
        foreach ($skusById as $sku) {
            $key = (int)($sku['product_id'] ?? 0) . ':' . trim((string)($sku['unique'] ?? ''));
            if (array_key_exists($key, $skusByComponent)) {
                $duplicateComponentKeys[$key] = true;
                continue;
            }
            $skusByComponent[$key] = $sku;
        }
        $components = [];
        foreach ($relations as $relation) {
            $productId = (int)($relation['product_id'] ?? 0);
            $unique = trim((string)($relation['product_attr_unique'] ?? ''));
            $product = $productsById[$productId] ?? null;
            if ($product && ((int)($product['type'] ?? 0) !== 1
                || (int)($product['relation_id'] ?? 0) !== $storeId)) {
                $product = null;
            }
            $componentKey = $productId . ':' . $unique;
            $sku = isset($duplicateComponentKeys[$componentKey])
                ? null
                : ($skusByComponent[$componentKey] ?? null);
            $item = ($product && $sku) ? $this->combineRows($product, $sku) : null;
            if (is_array($item)) {
                $item['category_names'] = $this->categoryNames(
                    (string)($item['product_cate_id'] ?? ''),
                    $categories
                );
            }
            $components[] = [
                'relation' => $relation,
                'item' => $item,
            ];
        }
        return $components;
    }

    /** @return array<int,array> */
    private function lockProductsById(array $ids): array
    {
        $rows = [];
        foreach ($this->sortedResourceIds('catalog_product', $ids) as $id) {
            $row = Db::name('store_product')
                ->where('id', (int)$id)
                ->field(implode(',', self::PRODUCT_FIELDS))
                ->lock(true)
                ->find();
            if ($row) {
                $rows[] = $row;
            }
        }
        return $this->rowsById($rows);
    }

    /** @return array<int,array> */
    private function lockSkusById(array $ids): array
    {
        $rows = [];
        foreach ($this->sortedResourceIds('catalog_sku', $ids) as $id) {
            $row = Db::name('store_product_attr_value')
                ->where('id', (int)$id)
                ->field($this->quotedSkuFields())
                ->lock(true)
                ->find();
            if ($row) {
                $rows[] = $row;
            }
        }
        return $this->rowsById($rows);
    }

    /** @return array<int,array> */
    private function readProductsById(array $ids): array
    {
        $ids = $this->sortedResourceIds('catalog_product', $ids);
        if (!$ids) {
            return [];
        }
        $rows = Db::name('store_product')
            ->whereIn('id', array_map('intval', $ids))
            ->field(implode(',', self::PRODUCT_FIELDS))
            ->select();
        return $this->rowsById($this->rows($rows));
    }

    /** @return array<int,array> */
    private function readComponentSkus(array $relations): array
    {
        $productIds = [];
        $uniques = [];
        foreach ($relations as $relation) {
            $productId = (int)($relation['product_id'] ?? 0);
            $unique = trim((string)($relation['product_attr_unique'] ?? ''));
            if ($productId > 0 && $unique !== '') {
                $productIds[$productId] = $productId;
                $uniques[$unique] = $unique;
            }
        }
        if (!$productIds || !$uniques) {
            return [];
        }
        $rows = Db::name('store_product_attr_value')
            ->whereIn('product_id', array_values($productIds))
            ->whereIn('unique', array_values($uniques))
            ->where('type', 0)
            ->field($this->quotedSkuFields())
            ->select();
        return $this->rowsById($this->rows($rows));
    }

    /** @return string[] */
    private function sortedResourceIds(string $kind, array $ids): array
    {
        $canonical = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $canonical[(string)$id] = (string)$id;
            }
        }
        $canonical = array_values($canonical);
        usort($canonical, static function (string $left, string $right) use ($kind): int {
            return CashierV3ResourceKindCatalog::compareResources(
                $kind,
                $left,
                $kind,
                $right
            );
        });
        return $canonical;
    }

    /** @return array<int,array> */
    private function rowsById(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $result[$id] = $row;
            }
        }
        return $result;
    }

    private function relationContainsComponent(array $relations, int $productId, string $unique): bool
    {
        foreach ($relations as $relation) {
            if ((int)($relation['product_id'] ?? 0) === $productId
                && trim((string)($relation['product_attr_unique'] ?? '')) === $unique) {
                return true;
            }
        }
        return false;
    }

    private function sameRelationPlan(array $planned, array $locked): bool
    {
        if (count($planned) !== count($locked)) {
            return false;
        }
        foreach ($planned as $index => $before) {
            $after = $locked[$index] ?? [];
            foreach (['id', 'card_product_id', 'product_id', 'product_type', 'write_times', 'status'] as $field) {
                if ((int)($before[$field] ?? 0) !== (int)($after[$field] ?? 0)) {
                    return false;
                }
            }
            foreach (['product_attr_unique', 'cost', 'price', 'writeoff_amount'] as $field) {
                if (trim((string)($before[$field] ?? '')) !== trim((string)($after[$field] ?? ''))) {
                    return false;
                }
            }
        }
        return true;
    }

    private function joinedFields(): string
    {
        $fields = [];
        foreach (self::PRODUCT_FIELDS as $field) {
            $fields[] = 'p.`' . $field . '` AS `product_' . $field . '`';
        }
        foreach (self::SKU_FIELDS as $field) {
            $fields[] = 'sku.`' . $field . '` AS `sku_' . $field . '`';
        }
        return implode(',', $fields);
    }

    private function quotedSkuFields(): string
    {
        return implode(',', array_map(static function (string $field): string {
            return '`' . $field . '`';
        }, self::SKU_FIELDS));
    }

    private function combineRows(array $product, array $sku): array
    {
        $row = [];
        foreach (self::PRODUCT_FIELDS as $field) {
            $row['product_' . $field] = $product[$field] ?? null;
        }
        foreach (self::SKU_FIELDS as $field) {
            $row['sku_' . $field] = $sku[$field] ?? null;
        }
        return $row;
    }

    /** @return array<int,string> */
    private function categoryMap(int $storeId, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach (explode(',', (string)($row['product_cate_id'] ?? '')) as $raw) {
                $id = (int)trim($raw);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }
        if (!$ids) {
            return [];
        }
        $query = Db::name('store_product_category')
            ->whereIn('id', array_keys($ids))
            ->where('is_show', 1)
            ->field('id,cate_name,type,relation_id');
        $result = [];
        foreach ($this->rows($query->select()) as $row) {
            $type = (int)($row['type'] ?? 0);
            $relationId = (int)($row['relation_id'] ?? 0);
            if (($type === 1 && $relationId !== $storeId) || ($type !== 1 && $relationId !== 0)) {
                continue;
            }
            $name = trim((string)($row['cate_name'] ?? ''));
            if ($name !== '') {
                $result[(int)$row['id']] = $name;
            }
        }
        return $result;
    }

    /** @return string[] */
    private function categoryNames(string $rawIds, array $map): array
    {
        $result = [];
        foreach (explode(',', $rawIds) as $raw) {
            $id = (int)trim($raw);
            if ($id > 0 && isset($map[$id]) && !in_array($map[$id], $result, true)) {
                $result[] = $map[$id];
            }
        }
        return $result;
    }

    /** @return array<int,array> */
    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }
}
