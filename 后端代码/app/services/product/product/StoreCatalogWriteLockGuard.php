<?php
declare(strict_types=1);

namespace app\services\product\product;

use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use mohe\exceptions\AdminException;
use think\facade\Db;

final class StoreCatalogWritePlanChangedException extends \RuntimeException
{
}

/**
 * Compatibility lock guard shared by legacy product writers and Cashier V3.
 */
final class StoreCatalogWriteLockGuard
{
    private const MAX_PLAN_ATTEMPTS = 3;

    /** @var \stdClass */
    private $issuerToken;

    public function __construct()
    {
        $this->issuerToken = new \stdClass();
    }

    public function acceptsIssuerToken(\stdClass $token): bool
    {
        return $token === $this->issuerToken;
    }

    public function withComponentCatalogMutation(array $productIds, callable $write)
    {
        return $this->withCatalogMutation([], $productIds, [], $write, true);
    }

    public function withCardRelationMutation(array $cardIds, array $targetRefs, callable $write)
    {
        return $this->withCatalogMutation($cardIds, [], $targetRefs, $write, true);
    }

    public function withCatalogMutation(
        array $cardIds,
        array $productIds,
        array $targetRefs,
        callable $write,
        bool $lockFullDefinitions = true
    ) {
        $this->assertNotInTransaction();
        $seed = [
            'cardIds' => $this->positiveIds($cardIds),
            'productIds' => $this->positiveIds($productIds),
            'targetRefs' => $this->normalizeRefs($targetRefs),
            'fullDefinitions' => $lockFullDefinitions,
        ];
        if (!$seed['cardIds'] && !$seed['productIds'] && !$seed['targetRefs']) {
            throw new AdminException('商品目录写锁计划不能为空');
        }

        for ($attempt = 1; $attempt <= self::MAX_PLAN_ATTEMPTS; $attempt++) {
            $plan = $this->discoverPlan($seed);
            try {
                return Db::transaction(function () use ($seed, $plan, $write) {
                    $lockedCards = $this->lockProductRows('catalog_card_definition', $plan['cardIds']);
                    if (count($lockedCards) !== count($plan['cardIds'])) {
                        throw new AdminException('卡项定义商品不存在，目录写入已取消');
                    }

                    $afterCardLock = $this->discoverPlan($seed);
                    if ($afterCardLock['cardIds'] !== $plan['cardIds']) {
                        throw new StoreCatalogWritePlanChangedException('card plan changed');
                    }
                    $plan = $afterCardLock;

                    $lockedProducts = $this->lockProductRows('catalog_product', $plan['productIds']);
                    if (count($lockedProducts) !== count($plan['productIds'])) {
                        throw new AdminException('关联商品不存在，目录写入已取消');
                    }

                    $afterProductLock = $this->discoverPlan($seed);
                    if ($afterProductLock['cardIds'] !== $plan['cardIds']) {
                        throw new StoreCatalogWritePlanChangedException('reverse card plan changed');
                    }
                    if ($afterProductLock['productIds'] !== $plan['productIds']) {
                        throw new StoreCatalogWritePlanChangedException('product plan changed');
                    }

                    $skuRows = $this->readSkuRows(array_merge($plan['productIds'], $plan['cardIds']));
                    $this->assertNoDuplicateSkuIdentity($skuRows);
                    $this->assertTargetRefsResolvable($seed['targetRefs'], $skuRows);
                    $skuIds = array_column($skuRows, 'id');
                    $lockedSkus = $this->lockSkuRows($skuIds);
                    if (count($lockedSkus) !== count($skuIds)) {
                        throw new StoreCatalogWritePlanChangedException('sku plan changed');
                    }
                    $this->assertSameSkuIdentityRows($skuRows, $lockedSkus);

                    $identityKeys = $this->identityKeys($lockedSkus, $seed['targetRefs']);
                    $lease = StoreCatalogWriteLease::issue(
                        $this,
                        $this->issuerToken,
                        $plan['cardIds'],
                        $plan['productIds'],
                        $skuIds,
                        $identityKeys,
                        true
                    );
                    try {
                        $result = $write($lease);
                        $this->assertPostWriteGraphCovered($lease);
                        return $result;
                    } finally {
                        $lease->release();
                    }
                });
            } catch (StoreCatalogWritePlanChangedException $exception) {
                if ($attempt === self::MAX_PLAN_ATTEMPTS) {
                    throw new AdminException('商品目录正在被其他操作修改，请稍后重试');
                }
            }
        }
        throw new AdminException('商品目录写锁计划失败');
    }

    public function withNewProductCreation(callable $write, array $targetRefs = [])
    {
        $this->assertNotInTransaction();
        $targetRefs = $this->normalizeRefs($targetRefs);
        if ($targetRefs) {
            // Existing cards that reference the chosen projects must be locked
            // before a new card relation is written in this transaction.
            return $this->withCatalogMutation([], [], $targetRefs, $write);
        }
        return Db::transaction(function () use ($write) {
            $lease = StoreCatalogWriteLease::issue(
                $this,
                $this->issuerToken,
                [],
                [],
                [],
                [],
                true
            );
            try {
                $result = $write($lease);
                $this->assertPostWriteGraphCovered($lease);
                return $result;
            } finally {
                $lease->release();
            }
        });
    }

    /**
     * Registers a row inserted by the current transaction. Existing referenced
     * products must already be covered when the lease has acquired any SKU lock.
     */
    public function adoptCreatedProduct(
        StoreCatalogWriteLease $lease,
        int $productId,
        int $productType,
        array $targetRefs = []
    ): void {
        $lease->assertActive();
        if (!$lease->allowsCreatedProducts() || $productId <= 0) {
            throw new AdminException('新增商品目录租约无效');
        }
        $row = Db::name('store_product')->where('id', $productId)->find();
        if (!$row || (int)($row['product_type'] ?? -1) !== $productType) {
            throw new AdminException('新增商品写锁快照不一致');
        }

        $refs = $this->normalizeRefs($targetRefs, $productType === 5 ? $productId : 0);
        $additionalProducts = array_column($refs, 'productId');
        $missingProducts = array_values(array_diff($this->positiveIds($additionalProducts), $lease->productIds()));
        if ($missingProducts && $lease->hasLockedSkus()) {
            throw new AdminException('新增卡项引用未在 SKU 加锁前完成规划');
        }
        if ($productType === 5) {
            $locked = Db::name('store_product')->where('id', $productId)->lock(true)->find();
            if (!$locked) {
                throw new AdminException('新增卡项商品锁定失败');
            }
        }
        $this->lockProductRows('catalog_product', $missingProducts);
        $skuRows = $this->readSkuRows($missingProducts);
        $this->assertNoDuplicateSkuIdentity($skuRows);
        $this->assertTargetRefsResolvable($refs, array_merge(
            $skuRows,
            $this->readSkuRows(array_values(array_intersect($additionalProducts, $lease->productIds())))
        ));
        $lockedSkus = $this->lockSkuRows(array_column($skuRows, 'id'));
        $identityKeys = $this->identityKeys($lockedSkus, $refs);
        $lease->registerCreatedProduct(
            $this,
            $this->issuerToken,
            $productId,
            $productType === 5,
            $missingProducts,
            array_column($lockedSkus, 'id'),
            $identityKeys
        );
    }

    /** @return array{cardIds:array,productIds:array,targetRefs:array,fullDefinitions:bool} */
    private function discoverPlan(array $seed): array
    {
        $cardIds = $seed['cardIds'];
        $productIds = $seed['productIds'];
        foreach ($seed['targetRefs'] as $ref) {
            if ($ref['cardProductId'] > 0) {
                $cardIds[] = $ref['cardProductId'];
            }
            $productIds[] = $ref['productId'];
        }

        // A shared project can connect several card definitions. Expand both
        // directions until stable so a relation remap cannot introduce a
        // reference absent from the pre-write lock plan.
        do {
            $previousCardIds = $this->sortedIds('catalog_card_definition', $cardIds);
            $previousProductIds = $this->positiveIds($productIds);
            if ($productIds) {
                $productRows = Db::name('store_product')
                    ->whereIn('id', $this->positiveIds($productIds))
                    ->field('id,product_type')
                    ->select();
                foreach ($this->rows($productRows) as $row) {
                    if ((int)($row['product_type'] ?? 0) === 5) {
                        $cardIds[] = (int)$row['id'];
                    }
                }
                $reverseCards = Db::name('store_card_related')
                    ->whereIn('product_id', $this->positiveIds($productIds))
                    ->column('card_product_id');
                $cardIds = array_merge($cardIds, $reverseCards ?: []);
            }
            $cardIds = $this->sortedIds('catalog_card_definition', $cardIds);

            if ($seed['fullDefinitions'] && $cardIds) {
                $relations = Db::name('store_card_related')
                    ->whereIn('card_product_id', $cardIds)
                    ->field('product_id')
                    ->select();
                foreach ($this->rows($relations) as $relation) {
                    $productIds[] = (int)($relation['product_id'] ?? 0);
                }
            }
            $productIds = $this->positiveIds($productIds);
        } while (
            $previousCardIds !== $cardIds
            || $previousProductIds !== $productIds
        );
        $productIds = array_values(array_diff($productIds, $cardIds));
        $productIds = $this->sortedIds('catalog_product', $productIds);
        return [
            'cardIds' => $cardIds,
            'productIds' => $productIds,
            'targetRefs' => $seed['targetRefs'],
            'fullDefinitions' => $seed['fullDefinitions'],
        ];
    }

    /** @return array<int,array> */
    private function lockProductRows(string $kind, array $ids): array
    {
        $rows = [];
        foreach ($this->sortedIds($kind, $ids) as $id) {
            $row = Db::name('store_product')->where('id', $id)->lock(true)->find();
            if ($row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array<int,array> */
    private function lockSkuRows(array $ids): array
    {
        $rows = [];
        foreach ($this->sortedIds('catalog_sku', $ids) as $id) {
            $row = Db::name('store_product_attr_value')->where('id', $id)->lock(true)->find();
            if ($row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array<int,array> */
    private function readSkuRows(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        return $this->rows(Db::name('store_product_attr_value')
            ->whereIn('product_id', $this->positiveIds($productIds))
            ->where('type', 0)
            ->field('id,product_id,unique,suk,type')
            ->order('id', 'asc')
            ->select());
    }

    private function assertNoDuplicateSkuIdentity(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = StoreCatalogWriteLease::identityKey(
                (int)($row['product_id'] ?? 0),
                (string)($row['unique'] ?? '')
            );
            if (isset($seen[$key])) {
                throw new AdminException('商品存在重复 SKU identity，请先修复数据后重试');
            }
            $seen[$key] = true;
        }
    }

    private function assertTargetRefsResolvable(array $refs, array $skuRows): void
    {
        $counts = [];
        foreach ($skuRows as $row) {
            $key = StoreCatalogWriteLease::identityKey(
                (int)($row['product_id'] ?? 0),
                (string)($row['unique'] ?? '')
            );
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        foreach ($refs as $ref) {
            $key = StoreCatalogWriteLease::identityKey($ref['productId'], $ref['skuUnique']);
            if (($counts[$key] ?? 0) !== 1) {
                throw new AdminException('卡项关联的 SKU 不存在或 identity 重复');
            }
        }
    }

    private function assertSameSkuIdentityRows(array $planned, array $locked): void
    {
        $fingerprint = static function (array $rows): array {
            $result = [];
            foreach ($rows as $row) {
                $result[(int)$row['id']] = [
                    (int)$row['product_id'],
                    (string)$row['unique'],
                    (int)$row['type'],
                ];
            }
            ksort($result, SORT_NUMERIC);
            return $result;
        };
        if ($fingerprint($planned) !== $fingerprint($locked)) {
            throw new StoreCatalogWritePlanChangedException('sku identity changed');
        }
    }

    private function assertPostWriteGraphCovered(StoreCatalogWriteLease $lease): void
    {
        $lease->assertActive();
        $coveredCards = array_fill_keys($lease->cardIds(), true);
        $coveredProducts = array_fill_keys($lease->productIds(), true);
        $products = array_keys($coveredProducts);
        if ($products) {
            $reverse = Db::name('store_card_related')->whereIn('product_id', $products)->column('card_product_id');
            foreach ($reverse ?: [] as $cardId) {
                if (!isset($coveredCards[(int)$cardId])) {
                    throw new AdminException('商品目录引用集合在写入期间发生变化');
                }
            }
        }
        if ($coveredCards) {
            $relations = Db::name('store_card_related')
                ->whereIn('card_product_id', array_keys($coveredCards))
                ->field('product_id,product_attr_unique')
                ->select();
            foreach ($this->rows($relations) as $relation) {
                $productId = (int)($relation['product_id'] ?? 0);
                if (!isset($coveredProducts[$productId])) {
                    throw new AdminException('卡项定义写入了未锁定的关联商品');
                }
                $lease->assertCoversIdentity($productId, (string)($relation['product_attr_unique'] ?? ''));
            }
        }
        $this->assertNoDuplicateSkuIdentity($this->readSkuRows($products));
    }

    /** @return string[] */
    private function identityKeys(array $skuRows, array $refs): array
    {
        $keys = [];
        foreach ($skuRows as $row) {
            $keys[] = StoreCatalogWriteLease::identityKey(
                (int)($row['product_id'] ?? 0),
                (string)($row['unique'] ?? '')
            );
        }
        foreach ($refs as $ref) {
            $keys[] = StoreCatalogWriteLease::identityKey($ref['productId'], $ref['skuUnique']);
        }
        return array_values(array_unique($keys));
    }

    /** @return array<int,array{cardProductId:int,productId:int,skuUnique:string}> */
    private function normalizeRefs(array $refs, int $defaultCardId = 0): array
    {
        $result = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                throw new AdminException('卡项关联数据格式错误');
            }
            $cardId = (int)($ref['cardProductId'] ?? $ref['card_product_id'] ?? $defaultCardId);
            $productId = (int)($ref['productId'] ?? $ref['product_id'] ?? 0);
            $unique = trim((string)($ref['skuUnique'] ?? $ref['product_attr_unique'] ?? $ref['unique'] ?? ''));
            if ($productId <= 0 || $unique === '') {
                throw new AdminException('卡项关联商品或 SKU 参数错误');
            }
            $key = $cardId . "\0" . $productId . "\0" . $unique;
            $result[$key] = [
                'cardProductId' => $cardId,
                'productId' => $productId,
                'skuUnique' => $unique,
            ];
        }
        return array_values($result);
    }

    /** @return int[] */
    private function sortedIds(string $kind, array $ids): array
    {
        $ids = $this->positiveIds($ids);
        usort($ids, static function (int $left, int $right) use ($kind): int {
            return CashierV3ResourceKindCatalog::compareResources(
                $kind,
                (string)$left,
                $kind,
                (string)$right
            );
        });
        return $ids;
    }

    /** @return int[] */
    private function positiveIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if ((int)$id > 0) {
                $result[(int)$id] = (int)$id;
            }
        }
        return array_values($result);
    }

    /** @return array<int,array> */
    private function rows($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $rows = $value->toArray();
            return is_array($rows) ? $rows : [];
        }
        return [];
    }

    private function assertNotInTransaction(): void
    {
        try {
            $connection = Db::connect();
            $pdo = method_exists($connection, 'getPdo') ? $connection->getPdo() : null;
        } catch (\Throwable $exception) {
            $pdo = null;
        }
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            throw new AdminException('商品目录守卫必须拥有最外层事务');
        }
    }
}
