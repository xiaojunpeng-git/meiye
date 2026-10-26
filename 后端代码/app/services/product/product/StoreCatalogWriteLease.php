<?php
declare(strict_types=1);

namespace app\services\product\product;

use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * Transaction-scoped proof that the catalog graph was locked in the global order.
 */
final class StoreCatalogWriteLease
{
    /** @var array<string,bool> */
    private static $active = [];

    /** @var string */
    private $token;

    /** @var array<int,bool> */
    private $cardIds = [];

    /** @var array<int,bool> */
    private $productIds = [];

    /** @var array<int,bool> */
    private $skuIds = [];

    /** @var array<string,bool> */
    private $identityKeys = [];

    /** @var array<int,bool> */
    private $createdProductIds = [];

    /** @var bool */
    private $allowsCreatedProducts;

    private function __construct(
        string $token,
        array $cardIds,
        array $productIds,
        array $skuIds,
        array $identityKeys,
        bool $allowsCreatedProducts
    ) {
        $this->token = $token;
        $this->cardIds = self::idSet($cardIds);
        $this->productIds = self::idSet($productIds);
        $this->skuIds = self::idSet($skuIds);
        $this->identityKeys = array_fill_keys(array_values(array_unique($identityKeys)), true);
        $this->allowsCreatedProducts = $allowsCreatedProducts;
    }

    public static function issue(
        StoreCatalogWriteLockGuard $issuer,
        \stdClass $issuerToken,
        array $cardIds,
        array $productIds,
        array $skuIds,
        array $identityKeys,
        bool $allowsCreatedProducts = false
    ): self {
        if (!$issuer->acceptsIssuerToken($issuerToken)) {
            throw new AdminException('商品目录写锁租约签发失败');
        }
        self::assertTransaction();
        $token = bin2hex(random_bytes(16));
        self::$active[$token] = true;
        return new self(
            $token,
            $cardIds,
            $productIds,
            $skuIds,
            $identityKeys,
            $allowsCreatedProducts
        );
    }

    public function assertActive(): void
    {
        self::assertTransaction();
        if (!isset(self::$active[$this->token])) {
            throw new AdminException('商品目录写锁租约已失效');
        }
    }

    public function assertCoversCards(array $cardIds): void
    {
        $this->assertActive();
        foreach (self::positiveIds($cardIds) as $id) {
            if (!isset($this->cardIds[$id]) && !isset($this->createdProductIds[$id])) {
                throw new AdminException('卡项定义未纳入当前写锁计划');
            }
        }
    }

    public function assertCoversProducts(array $productIds): void
    {
        $this->assertActive();
        foreach (self::positiveIds($productIds) as $id) {
            if (!isset($this->productIds[$id])
                && !isset($this->cardIds[$id])
                && !isset($this->createdProductIds[$id])
            ) {
                throw new AdminException('商品未纳入当前写锁计划');
            }
        }
    }

    public function assertCoversSkuIds(array $skuIds): void
    {
        $this->assertActive();
        foreach (self::positiveIds($skuIds) as $id) {
            if (!isset($this->skuIds[$id])) {
                throw new AdminException('SKU 未纳入当前写锁计划');
            }
        }
    }

    public function assertCoversIdentity(int $productId, string $unique): void
    {
        $this->assertCoversProducts([$productId]);
        if (!isset($this->identityKeys[self::identityKey($productId, $unique)])) {
            throw new AdminException('SKU identity 未纳入当前写锁计划');
        }
    }

    public function allowsCreatedProducts(): bool
    {
        return $this->allowsCreatedProducts;
    }

    public function hasLockedSkus(): bool
    {
        return $this->skuIds !== [];
    }

    /** @return int[] */
    public function cardIds(): array
    {
        return array_keys($this->cardIds);
    }

    /** @return int[] */
    public function productIds(): array
    {
        return array_keys($this->productIds + $this->cardIds + $this->createdProductIds);
    }

    public function registerCreatedProduct(
        StoreCatalogWriteLockGuard $issuer,
        \stdClass $issuerToken,
        int $productId,
        bool $isCard,
        array $additionalProductIds,
        array $additionalSkuIds,
        array $identityKeys
    ): void {
        $this->assertActive();
        if (!$this->allowsCreatedProducts || !$issuer->acceptsIssuerToken($issuerToken) || $productId <= 0) {
            throw new AdminException('新增商品未获得目录写锁授权');
        }
        $this->createdProductIds[$productId] = true;
        if ($isCard) {
            $this->cardIds[$productId] = true;
        }
        foreach (self::positiveIds($additionalProductIds) as $id) {
            $this->productIds[$id] = true;
        }
        foreach (self::positiveIds($additionalSkuIds) as $id) {
            $this->skuIds[$id] = true;
        }
        foreach ($identityKeys as $key) {
            $this->identityKeys[(string)$key] = true;
        }
    }

    public function registerWrittenSkuRows(array $rows): void
    {
        $this->assertActive();
        foreach ($rows as $row) {
            if (is_object($row) && method_exists($row, 'toArray')) {
                $row = $row->toArray();
            }
            if (!is_array($row) || (int)($row['type'] ?? 0) !== 0) {
                continue;
            }
            $productId = (int)($row['product_id'] ?? 0);
            $skuId = (int)($row['id'] ?? 0);
            $unique = (string)($row['unique'] ?? '');
            $this->assertCoversProducts([$productId]);
            if ($skuId <= 0 || $unique === '') {
                throw new AdminException('新写入 SKU identity 不完整');
            }
            $this->skuIds[$skuId] = true;
            $this->identityKeys[self::identityKey($productId, $unique)] = true;
        }
    }

    public function release(): void
    {
        unset(self::$active[$this->token]);
    }

    public static function identityKey(int $productId, string $unique): string
    {
        return $productId . "\0" . $unique;
    }

    private static function assertTransaction(): void
    {
        try {
            $connection = Db::connect();
            // Think-Swoole wraps the connection in a dynamic proxy.  Its
            // getPdo() method is exposed through __call(), so method_exists()
            // returns false even while the underlying PDO owns the active
            // transaction.  Ask the proxy directly and fail closed below if it
            // cannot resolve a real PDO.
            $pdo = $connection->getPdo();
        } catch (\Throwable $exception) {
            $pdo = null;
        }
        if (!$pdo instanceof \PDO || !$pdo->inTransaction()) {
            throw new AdminException('商品目录写入必须在守卫事务中执行');
        }
    }

    /** @return array<int,bool> */
    private static function idSet(array $ids): array
    {
        return array_fill_keys(self::positiveIds($ids), true);
    }

    /** @return int[] */
    private static function positiveIds(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if ((int)$id > 0) {
                $result[(int)$id] = (int)$id;
            }
        }
        return array_values($result);
    }
}
