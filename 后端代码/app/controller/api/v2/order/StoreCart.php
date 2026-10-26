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

namespace app\controller\api\v2\order;


use app\Request;
use app\services\order\StoreCartServices;
use app\services\store\SystemStoreServices;

/**
 * 购物车
 * Class StoreCart
 * @package app\controller\api\v2\order
 */
class StoreCart
{
    /**
     * @var StoreCartServices
     */
    protected $services;

    /**
     * StoreCart constructor.
     * @param StoreCartServices $services
     */
    public function __construct(StoreCartServices $services)
    {
        $this->services = $services;
    }

    /**
     * 购物车重选
     * @param Request $request
     * @return mixed
     */
    public function resetCart(Request $request)
    {
        [$id, $unique, $num, $product_id, $store_id] = $request->postMore([
            ['id', 0],
            ['unique', ''],
            ['num', 1],
            ['product_id', 0],
            ['store_id', 0]
        ], true);
        $this->services->resetCart($id, (int)$request->uid(), $product_id, $unique, $num, $store_id);
        return app('json')->successful('操作成功');
    }

    /**
     * 获取用户购物车
     * @param Request $request
     * @param SystemStoreServices $storeServices
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCartList(Request $request, SystemStoreServices $storeServices)
    {
        $uid = (int)$request->uid();
        [$store_id] = $request->postMore([
            ['store_id', 0]
        ], true);
        $where = ['uid' => $uid, 'is_del' => 0, 'is_new' => 0, 'is_pay' => 0, 'type' => 0, 'staff_id' => 0, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0];
        if ($store_id) {
            $where['store_id'] = $store_id;
        } else {
            $where['store_id'] = 0;
        }
        $data = $this->services->getCartList($where, 0, 0, ['productInfo', 'attrInfo']);
        if ($store_id) $this->services->setItem('store_id', (int)$store_id);
        [$data, $valid, $invalid] = $this->services->handleCartList($uid, $data, [], -1);
        $this->services->reset();
        return app('json')->successful($valid);
    }

    /**
     * 购物车 统计 数量 价格
     * @param Request $request
     * @param SystemStoreServices $storeServices
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function count(Request $request, SystemStoreServices $storeServices)
    {
        [$numType, $store_id] = $request->postMore([
            ['numType', true],//购物车编号
            ['store_id', 0]
        ], true);
        if (!$store_id || $store_id == 'undefined') $store_id = 0;
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $result = ['count' => 0, 'ids' => [], 'sum_price' => 0];
        if ($uid) {
            $where = ['status' => 1, 'staff_id' => 0, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0];
            if ($store_id) {
                $where['store_id'] = $store_id;
            } else {
                $where['store_id'] = 0;
            }
            //            if ($store_id) {
//                $where['store_id'] = (int)$store_id;
//            } else {
//                $entryRules = $storeServices->getStoreIdByEntryRules($uid);
//                $storeId = $entryRules['store_id'] ?? 0;
//                $shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
//                switch ($shop_operation_type) {
//                    case 1://展示平台加入购物车商品
//                        $where['store_id'] = 0;
//                        break;
//                    case 2://展示平台+门店加入购物车商品
//                        $where['store_id'] = [0, $storeId];
//                        break;
//                    case 3://展示平台加入购物车商品
//                        $where['store_id'] = $storeId;
//                        break;
//                    default://展示平台加入购物车商品
//                        $where['store_id'] = 0;
//                        break;
//                }
//            }
            $result = $this->services->getUserCartCount($uid, $where, $numType);
        }
        return app('json')->success($result);
    }

    /**
     * 首页加入购物车
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setCartNum(Request $request)
    {
        [$product_id, $num, $unique, $type, $store_id, $new] = $request->postMore([
            ['product_id', 0],
            ['num', 0],
            ['unique', ''],
            ['type', -1],//-1：直接设置购物车数量  1：累加 0:减
            ['store_id', 0],
            ['new', 0],// 1 加入购物车直接购买  0 加入购物车
        ], true);
        /** @var StoreCartServices $cartService */
        $cartService = app()->make(StoreCartServices::class);
        if (!$product_id || !is_numeric($product_id)) return app('json')->fail('参数错误');
        if (!(int)$num) return app('json')->fail('请提交加入购物车商品数量');
        if ($new) {
            $this->services->setItem('store_id', $store_id ?? 0);
            [$cartIds, $cartNum] = $cartService->setCart((int)$request->uid(), (int)$product_id, (int)$num, $unique, 0, $new, 0);
        } else {
            $res = $cartService->setCartNum((int)$request->uid(), (int)$product_id, (int)$num, $unique, (int)$type, (int)$store_id);
            $cartIds = $res;
            $cartNum = $num;
        }
        if ($cartIds) return app('json')->successful('操作成功', ['cartId' => $cartIds, 'cartNum' => $cartNum]);
        return app('json')->fail('操作失败');
    }
}
