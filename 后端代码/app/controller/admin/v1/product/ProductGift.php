<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\controller\admin\v1\product;

use app\controller\admin\AuthController;
use app\services\product\product\ProductGiftServices;
use app\services\product\product\StoreProductServices;

/**
 * 商品赠送配置
 */
class ProductGift extends AuthController
{
    /**
     * 获取商品赠送配置
     */
    public function read($id, StoreProductServices $productServices, ProductGiftServices $giftServices)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('参数错误');
        }
        if (!$productServices->get($id)) {
            return $this->fail('商品不存在');
        }
        return $this->success($giftServices->getConfig($id));
    }

    /**
     * 保存商品赠送配置
     */
    public function save($id, StoreProductServices $productServices, ProductGiftServices $giftServices)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('参数错误');
        }
        if (!$productServices->get($id)) {
            return $this->fail('商品不存在');
        }
        [$sendAll] = $this->request->postMore([
            ['sendAll', ['product' => [], 'coupon' => []]],
        ], true);
        $giftServices->saveConfig($id, $sendAll);
        return $this->success('保存成功');
    }
}
