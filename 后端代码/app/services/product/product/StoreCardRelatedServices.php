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
        $this->dao->delete(['card_product_id' => $card_product_id]);
        $data = [];
        foreach ($related as $item) {
            if (isset($item['product_attr_unique']) && !isset($item['unique'])) $item['unique'] = $item['product_attr_unique'];
            $data[] = [
                'card_product_id' => $card_product_id,
                'product_id' => $item['product_id'],
                'product_type' => $item['product_type'],
                'product_attr_unique' => $item['unique'],
                'cost' => $item['cost'],
                'price' => $item['price'],
                'write_times' => $item['write_times'],
                'add_time' => time()
            ];
        }
        if (!$data) throw new AdminException('数据有误');
        return $this->dao->saveAll($data);
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
        foreach ($data as $key => $datum) {
            $y_attr = $productAttrValueServices->getOne(['product_id' => $product_id, 'unique' => $datum['product_attr_unique']]);
            if (!$y_attr) continue;
            $x_attr = $productAttrValueServices->getOne(['product_id' => $id, 'suk' => $y_attr['suk']]);
            if (!$x_attr) continue;
            $this->dao->update(['id' => $datum['id'], 'status' => 1], ['product_id' => $id, 'product_attr_unique' => $x_attr['unique'], 'add_time' => time()]);
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
        $this->dao->setStatus($ids, $status);
        return true;
    }
}
