<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\product\product;


use app\dao\product\product\StoreCardRelatedDao;
use app\services\BaseServices;
use app\services\product\sku\StoreProductAttrValueServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * Class StoreCardRelatedServices
 * @package app\services\product\product
 * @mixin StoreCardRelatedDao
 */
class StoreCardRelatedServices extends BaseServices
{
    public function __construct(StoreCardRelatedDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 处理卡项关联商品
     * @param int $card_product_id
     * @param array $related
     * @param int $is_new
     * @return mixed|void
     */
    public function handleCardRelated(int $card_product_id = 0, array $related = [])
    {
        if (!$card_product_id || !$related) throw new AdminException('参数有误');
        $rows = $this->normalizeRelatedRows($card_product_id, $related);
        /** @var StoreCatalogWriteLockGuard $guard */
        $guard = app()->make(StoreCatalogWriteLockGuard::class);
        return $guard->withCardRelationMutation(
            [$card_product_id],
            $rows,
            function (StoreCatalogWriteLease $lease) use ($card_product_id, $rows) {
                return $this->handleCardRelatedInGuard($lease, $card_product_id, $rows);
            }
        );
    }

    public function handleCardRelatedInGuard(
        StoreCatalogWriteLease $lease,
        int $cardProductId,
        array $related
    ) {
        $rows = $this->normalizeRelatedRows($cardProductId, $related);
        $lease->assertCoversCards([$cardProductId]);
        foreach ($rows as $row) {
            $lease->assertCoversIdentity((int)$row['product_id'], (string)$row['product_attr_unique']);
        }
        $this->dao->delete(['card_product_id' => $cardProductId]);
        return $this->dao->saveAll($rows);
    }

    /**
     * 获取卡项关联商品
     * @param int $card_product_id
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCardRelatedProduct(int $card_product_id = 0, bool $is_page = false,$selectedProduct=[])
    {
        $page = 0;
        $limit = 0;
        if ($is_page) {
            [$page, $limit] = $this->getPageValue();
        }
        $where['card_product_id']=$card_product_id;
        if(!empty($selectedProduct)){
            $where['product_id']=$selectedProduct;
        }
        $list = $this->dao->getList($where, $page, $limit, ['productInfo', 'attrInfo']);
        foreach ($list as $key => &$item) {
            if (!$item['productInfo']) unset($list[$key]);
            $item['productInfo']['attrInfo'] = $item['attrInfo'] ?? [];
            unset($item['attrInfo']);
        }
        return array_values($list);
    }

    /**
     * 获取卡项商品全部核销数量
     * @param int $card_product_id
     * @return float
     */
    public function getRelatedProductWrite(int $card_product_id = 0)
    {
        return $this->dao->sum(['card_product_id' => $card_product_id, 'status' => 1], 'write_times');
    }


    /**
     * 检查商品是否存在门店中
     * @param int $product_id
     * @param int $store_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkThereProductsStore(int $product_id = 0, int $store_id = 0)
    {
        if (!$store_id) return true;
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $product = $productServices->getOne(['id' => $product_id, 'type' => 1]);
        if ($product) {
            if ($product['relation_id'] != $store_id) throw new AdminException('商品参数有误！');
        } else {
            $product = $productServices->getOne(['pid' => $product_id, 'type' => 1, 'relation_id' => $store_id]);
            if (!$product) throw new AdminException('商品参数有误！');
            $product_id = $product['id'];
        }
        $list = $this->dao->getList(['card_product_id' => $product_id], 0, 0, []);
        foreach ($list as $key => $item) {
            $storeId = $productServices->value(['id' => $item['product_id'], 'type' => 1], 'relation_id');
            if ($storeId != $store_id) {
                return false;
                break;
            }
        }
        return true;
    }

    /**
     * 卡项关联商品同步门店后处理数据
     * @param int $product_id 平台商品  id
     * @param int $card_product_id 平台卡项商品  id
     * @param int $id 同步后商品在门店里的  id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function updateCardProduct(int $product_id = 0, int $card_product_id = 0, int $id = 0)
    {
        $data = $this->dao->getColumn(['product_id' => $product_id, 'card_product_id' => $card_product_id, 'status' => 1], '*');
        if (!$data) return true;
        /** @var StoreProductAttrValueServices $productAttrValueServices */
        $productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        $targetRefs = [];
        foreach ($data as $datum) {
            $y_attr = $productAttrValueServices->getOne(['product_id' => $product_id, 'unique' => $datum['product_attr_unique']]);
            if (!$y_attr) continue;
            $x_attr = $productAttrValueServices->getOne(['product_id' => $id, 'suk' => $y_attr['suk']]);
            if (!$x_attr) continue;
            $targetRefs[] = [
                'cardProductId' => $card_product_id,
                'productId' => $id,
                'skuUnique' => (string)$x_attr['unique'],
            ];
        }
        if (!$targetRefs) return true;
        // 写入其中一条映射后，整张卡的其余关联仍会参与定义校验；把它们
        // 一并纳入计划，避免锁住父卡却遗漏尚未迁移的其他门店项目引用。
        $cardRelations = $this->dao->getColumn([
            'card_product_id' => $card_product_id,
            'status' => 1,
        ], '*');
        foreach ($cardRelations as $relation) {
            $relationProductId = (int)($relation['product_id'] ?? 0);
            $relationUnique = trim((string)($relation['product_attr_unique'] ?? ''));
            if ($relationProductId <= 0 || $relationUnique === '') {
                continue;
            }
            $targetRefs[] = [
                'cardProductId' => $card_product_id,
                'productId' => $relationProductId,
                'skuUnique' => $relationUnique,
            ];
        }
        /** @var StoreCatalogWriteLockGuard $guard */
        $guard = app()->make(StoreCatalogWriteLockGuard::class);
        return $guard->withCatalogMutation(
            [$card_product_id],
            [$product_id, $id],
            $targetRefs,
            function (StoreCatalogWriteLease $lease) use ($product_id, $card_product_id, $id) {
                return $this->updateCardProductInGuard($lease, $product_id, $card_product_id, $id);
            }
        );
    }

    public function updateCardProductInGuard(
        StoreCatalogWriteLease $lease,
        int $productId,
        int $cardProductId,
        int $targetProductId
    ): bool {
        $lease->assertCoversCards([$cardProductId]);
        $lease->assertCoversProducts([$productId, $targetProductId]);
        $data = $this->dao->getColumn([
            'product_id' => $productId,
            'card_product_id' => $cardProductId,
            'status' => 1,
        ], '*');
        /** @var StoreProductAttrValueServices $productAttrValueServices */
        $productAttrValueServices = app()->make(StoreProductAttrValueServices::class);
        foreach ($data as $datum) {
            $sourceSku = $productAttrValueServices->getOne([
                'product_id' => $productId,
                'unique' => $datum['product_attr_unique'],
                'type' => 0,
            ]);
            if (!$sourceSku) {
                throw new AdminException('卡项来源 SKU 不存在，映射已取消');
            }
            $targetSku = $productAttrValueServices->getOne([
                'product_id' => $targetProductId,
                'suk' => $sourceSku['suk'],
                'type' => 0,
            ]);
            if (!$targetSku) {
                throw new AdminException('门店 SKU 映射不存在，卡项同步已取消');
            }
            $lease->assertCoversIdentity($targetProductId, (string)$targetSku['unique']);
            $this->dao->update(
                ['id' => $datum['id'], 'status' => 1],
                [
                    'product_id' => $targetProductId,
                    'product_attr_unique' => $targetSku['unique'],
                    'add_time' => time(),
                ]
            );
        }
        return true;
    }

    /**
     * 批量设置关联状态
     * @param array $ids
     * @param int $status
     * @return bool
     */
    public function setStatus(array $ids, int $status = 1)
    {
        if (!$ids) return true;
        /** @var StoreCatalogWriteLockGuard $guard */
        $guard = app()->make(StoreCatalogWriteLockGuard::class);
        return $guard->withComponentCatalogMutation($ids, function (StoreCatalogWriteLease $lease) use ($ids, $status) {
            return $this->setStatusInGuard($lease, $ids, $status);
        });
    }

    public function setStatusInGuard(StoreCatalogWriteLease $lease, array $ids, int $status = 1): bool
    {
        $lease->assertCoversProducts($ids);
        $this->dao->setStatus($ids, $status);
        return true;
    }

    public function deleteForProducts(array $productIds): bool
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) return true;
        $cardIds = Db::name('store_product')
            ->whereIn('id', $productIds)
            ->where('product_type', 5)
            ->column('id');
        /** @var StoreCatalogWriteLockGuard $guard */
        $guard = app()->make(StoreCatalogWriteLockGuard::class);
        return $guard->withCatalogMutation(
            $cardIds ?: [],
            $productIds,
            [],
            function (StoreCatalogWriteLease $lease) use ($productIds) {
                return $this->deleteForProductsInGuard($lease, $productIds);
            }
        );
    }

    public function deleteForProductsInGuard(StoreCatalogWriteLease $lease, array $productIds): bool
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $lease->assertCoversProducts($productIds);
        $cardIds = Db::name('store_product')
            ->whereIn('id', $productIds)
            ->where('product_type', 5)
            ->column('id');
        if ($cardIds) {
            $lease->assertCoversCards($cardIds);
        }
        Db::name('store_card_related')->whereIn('product_id', $productIds)->delete();
        Db::name('store_card_related')->whereIn('card_product_id', $productIds)->delete();
        return true;
    }

    public function appendCardRelatedRows(array $relatedRows): bool
    {
        if (!$relatedRows) return true;
        $rowsByCard = [];
        foreach ($relatedRows as $row) {
            $cardId = (int)($row['card_product_id'] ?? $row['cardProductId'] ?? 0);
            $normalized = $this->normalizeRelatedRows($cardId, [$row]);
            $rowsByCard[$cardId][] = $normalized[0];
        }
        $rows = [];
        foreach ($rowsByCard as $cardRows) {
            $rows = array_merge($rows, $cardRows);
        }
        /** @var StoreCatalogWriteLockGuard $guard */
        $guard = app()->make(StoreCatalogWriteLockGuard::class);
        return $guard->withCardRelationMutation(
            array_keys($rowsByCard),
            $rows,
            function (StoreCatalogWriteLease $lease) use ($rows) {
                foreach ($rows as $row) {
                    $cardId = (int)$row['card_product_id'];
                    $productId = (int)$row['product_id'];
                    $unique = (string)$row['product_attr_unique'];
                    $lease->assertCoversCards([$cardId]);
                    $lease->assertCoversIdentity($productId, $unique);
                    $exists = Db::name('store_card_related')->where([
                        'card_product_id' => $cardId,
                        'product_id' => $productId,
                        'product_attr_unique' => $unique,
                    ])->find();
                    if (!$exists) {
                        $this->dao->save($row);
                    }
                }
                return true;
            }
        );
    }

    public function __call($name, $arguments)
    {
        if (in_array($name, ['save', 'saveAll', 'update', 'delete', 'insert', 'insertAll'], true)) {
            throw new AdminException('卡项关联写入必须使用目录写锁守卫');
        }
        return parent::__call($name, $arguments);
    }

    private function normalizeRelatedRows(int $cardProductId, array $related): array
    {
        if ($cardProductId <= 0 || !$related) {
            throw new AdminException('卡项关联参数有误');
        }
        $rows = [];
        foreach ($related as $item) {
            $productId = (int)($item['product_id'] ?? $item['productId'] ?? 0);
            $unique = trim((string)($item['product_attr_unique'] ?? $item['unique'] ?? $item['skuUnique'] ?? ''));
            if ($productId <= 0 || $unique === '') {
                throw new AdminException('卡项关联商品或 SKU 参数有误');
            }
            $rows[] = [
                'card_product_id' => $cardProductId,
                'product_id' => $productId,
                'product_type' => (int)($item['product_type'] ?? $item['productType'] ?? 0),
                'product_attr_unique' => $unique,
                'cost' => $item['cost'] ?? 0,
                'price' => $item['price'] ?? 0,
                'write_times' => $item['write_times'] ?? $item['writeTimes'] ?? 0,
                'writeoff_amount' => $item['writeoff_amount'] ?? $item['writeoffAmount'] ?? 0,
                'status' => (int)($item['status'] ?? 1),
                'add_time' => (int)($item['add_time'] ?? time()),
            ];
        }
        return $rows;
    }
}
