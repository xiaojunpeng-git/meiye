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
namespace app\controller\api\v1\order;

use app\Request;
use app\services\order\StoreCartServices;
use app\services\activity\discounts\StoreDiscountsServices;
use app\services\store\SystemStoreServices;
use mohe\services\CacheService;

/**
 * 购物车类
 * Class StoreCart
 * @package app\controller\api\store
 */
class StoreCart
{
    protected $services;

    public function __construct(StoreCartServices $services)
    {
        $this->services = $services;
    }

    /**
     * 购物车 列表
     * @param Request $request
     * @return mixed
     */
    public function lst(SystemStoreServices $storeServices, Request $request)
    {
        [$status, $latitude, $longitude, $store_id] = $request->postMore([
            ['status', 1],//购物车商品状态
            ['latitude', ''],
            ['longitude', ''],
            ['store_id', 0]
        ], true);
        if (!checkCoordinates($longitude, $latitude)) {
            return app('json')->fail('参数错误');
        }
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $result = [];
        if ($uid) {
            $this->services->setItem('latitude', $latitude)->setItem('longitude', $longitude)->setItem('status', $status);
            $where = ['status' => $status, 'staff_id' => 0, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0, 'store_id' => 0];
            $result = $this->services->getUserCartList($uid, $where);
            $this->services->reset();
            $result['valid'] = $this->services->getReturnCartList($result['valid'], $result['promotions']);
            unset($result['promotions']);
        }
        return app('json')->successful($result);
    }

    /**
     * 全部门店购物车
     * @param SystemStoreServices $storeServices
     * @param Request $request
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCartallLst(SystemStoreServices $storeServices, Request $request)
    {
        [$status] = $request->postMore([
            ['status', 1],//购物车商品状态
        ], true);
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $storeAll = [];
        if ($uid) {
            $where = ['status' => $status, 'staff_id' => 0, 'is_all_store' => 1, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0];
            if ($status) {
                /** @var SystemStoreServices $storeServices */
                $storeServices = app()->make(SystemStoreServices::class);
                $entryRules = $storeServices->getStoreIdByEntryRules($uid);
                $shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
                $storeId = $entryRules['store_id'] ?? 0;
                switch ($shop_operation_type) {
                    case 2://展示平台+门店加入购物车商品
                        $where['store_id'] = [0, $storeId];
                        break;
                    case 3://展示门店加入购物车商品
                        $where['store_id'] = $storeId;
                        break;
                }
            }
            $result = $this->services->getUserCartList($uid, $where);
            $this->services->reset();
            $valid = $result['valid'];

            $store_ids = array_merge(array_unique(array_column($valid, 'store_id')));
            $i = 0;
            foreach ($store_ids as $key => $store_id) {
                $store = $storeServices->getOne(['id' => $store_id, 'is_show' => 1, 'is_del' => 0], 'id,name');
                if (!$store) continue;
                $storeAll[$i]['store_id'] = $store_id;
                $storeAll[$i]['name'] = $store['name'];
                foreach ($valid as $k => $item) {
                    if ($item['store_id'] == $store_id) {
                        $storeAll[$i]['valid'][] = $item;
                    }
                }
                $i++;
            }
            foreach ($storeAll as $k => &$store) {
                $pay_price = 0;
                $count = 0;
                foreach ($store['valid'] as $itm) {
                    $pay_price = bcadd((string)$pay_price, (string)($itm['pay_price'] ?? 0), 2);
                    $count = (int)bcadd($count, 1, 0);
                }
				$store['count'] = $count;
				$store['pay_price'] = $pay_price;
                $store['valid'] = $this->services->getReturnCartList($store['valid'], $result['promotions']);
            }
        }
        return app('json')->successful($storeAll);
    }

    /**
     * 购物车 添加
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function add(Request $request)
    {
        $where = $request->postMore([
            ['productId', 0],//普通商品编号
            ['store_id', 0],//门店 id
            [['cartNum', 'd'], 1], //购物车数量
            ['uniqueId', ''],//属性唯一值
            [['new', 'd'], 0],// 1 加入购物车直接购买  0 加入购物车
            [['is_new', 'd'], 0],// 1 加入购物车直接购买  0 加入购物车
            [['secKillId', 'd'], 0],//秒杀商品编号
            [['bargainId', 'd'], 0],//砍价商品编号
            [['combinationId', 'd'], 0],//拼团商品编号
            [['storeIntegralId', 'd'], 0],//积分商品ID
            [['discountId', 'd'], 0],//优惠套餐编号
            ['discountInfos', []],//优惠套餐商品信息
            [['newcomerId', 'd'], 0],//新人专享商品编号
            [['luckRecordId', 'd'], 0],//抽奖记录编号
            [['key', 's'], ''],//直接购买购物车ID 再次累加1
            [['is_set', 'd'], 0],//1：直接设置购物车数量 0：累加
            [['reservation_type', 'd'], 2],//预约类型
            ['reservation_time', ''],//预约日期
            [['reservation_time_id', 'd'], 0],//预约商品时段ID（旧，可选）
            ['reservation_start', ''],//预约开始时间
            ['reservation_end', ''],//预约结束时间
            [['service_staff_id', 'd'], 0],//服务老师
            [['service_duration_minutes', 'd'], 0],//服务时长
            ['addon_items', []],//加项
            ['sync_all', []],//手艺人
        ]);
        if ($where['is_new'] || $where['new']) $new = true;
        else $new = false;
        if (!$where['productId'] && !$where['discountId']) {
            return app('json')->fail('参数错误');
        }
        $type = 0;
        $uid = (int)$request->uid();
        $activityId = 0;
        $this->services->setItem('store_id', $where['store_id'] ?? 0);
        $this->services->setItem('key', $where['key'] ?? '');
        $this->services->setItem('is_set', $where['is_set'] ?? 0);
        $this->services->setItem('reservation_type', $where['reservation_type'] ?? 2);
        $this->services->setItem('reservation_time', $where['reservation_time'] ?? '');
        $this->services->setItem('reservation_time_id', $where['reservation_time_id'] ?? 0);
        $this->services->setItem('reservation_start', $where['reservation_start'] ?? '');
        $this->services->setItem('reservation_end', $where['reservation_end'] ?? '');
        $this->services->setItem('service_staff_id', $where['service_staff_id'] ?? 0);
        $this->services->setItem('service_duration_minutes', $where['service_duration_minutes'] ?? 0);
        $this->services->setItem('addon_items', $where['addon_items'] ?? []);
        $this->services->setItem('sync_all', $where['sync_all'] ?? []);
        if ($where['discountId']) {
            /** @var StoreDiscountsServices $discountService */
            $discountService = app()->make(StoreDiscountsServices::class);
            $discounts = $discountService->get((int)$where['discountId'], ['id', 'is_limit', 'limit_num']);
            if (!$discounts) {
                return app('json')->fail('套餐商品未找到！');
            }
            //套餐限量
            if ($discounts['is_limit']) {
                if ($discounts['limit_num'] <= 0) {
                    return app('json')->fail('套餐限量不足');
                }
                if (!CacheService::checkStock(md5($discounts['id']), 1, 5)) {
                    return app('json')->fail('套餐限量不足');
                }
            }
            $cartIds = [];
            $cartNum = 0;
            $activityId = (int)$where['discountId'];
            foreach ($where['discountInfos'] as $info) {
                [$cartId, $cartNum] = $this->services->setCart($uid, (int)$info['product_id'], 1, $info['unique'], 5, $new, $activityId, (int)$info['id']);
                $cartIds[] = $cartId;
            }
        } else {
            if ($where['secKillId']) {
                $type = 1;
                $activityId = $where['secKillId'];
            } elseif ($where['bargainId']) {
                $type = 2;
                $activityId = $where['bargainId'];
            } elseif ($where['combinationId']) {
                $type = 3;
                $activityId = $where['combinationId'];
            } elseif ($where['storeIntegralId']) {
                $type = 4;
                $activityId = $where['storeIntegralId'];
            } elseif ($where['newcomerId']) {
                $type = 7;
                $activityId = $where['newcomerId'];
            } elseif ($where['luckRecordId']) {
                $type = 8;
                $activityId = $where['luckRecordId'];
            }
            [$cartIds, $cartNum] = $this->services->setCart($uid, (int)$where['productId'], (int)$where['cartNum'], $where['uniqueId'], $type, $new, (int)$activityId);
        }
        $this->services->reset();
        if (!$cartIds) {
            return app('json')->fail('添加失败');
        } else {
            //更新秒杀详情缓存
            $this->services->cacheTag('Cart_Nums_' . $uid)->clear();
            return app('json')->successful('ok', ['cartId' => $cartIds, 'cartNum' => $cartNum]);
        }
    }

    /**
     * 购物车 删除商品
     * @param Request $request
     * @return mixed
     */
    public function del(Request $request)
    {
        [$ids, $store_id] = $request->postMore([
            ['ids', ''],//购物车编号
            ['store_id', 0]
        ], true);
        $uid = (int)$request->uid();
        if ($store_id != -1) {
            $ids = is_array($ids) ? $ids : stringToIntArray($ids);
            if (!count($ids))
                return app('json')->fail('参数错误!');
            $res = $this->services->removeUserCart($uid, $ids);
        } else {
            $where = ['uid' => $uid, 'staff_id' => 0, 'is_all_store' => 1, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0];
            $res = $this->services->removeStoreCart($where);
        }
        if ($res) {
            $invalid_key = 'invalid_' . $store_id . '_' . $uid;
            CacheService::redisHandler()->delete($invalid_key);
            return app('json')->successful();
        }
        return app('json')->fail('清除失败！');
    }

    /**
     * 购物车 修改商品数量
     * @param Request $request
     * @return mixed
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function num(Request $request)
    {
        $where = $request->postMore([
            ['id', 0],//购物车编号
            ['type', 1],//1购物车 id,2商品 id
            ['number', 0],//购物车编号
        ]);
        if (!$where['id'] || !$where['number'] || !is_numeric($where['id']) || !is_numeric($where['number'])) return app('json')->fail('参数错误!');
        $uid = (int)$request->uid();
        if ($where['type'] == 2) {
            $where['id'] = $this->services->value(['product_id' => $where['id'], 'status' => 1, 'uid' => $uid, 'staff_id' => 0], 'id');
        }
        unset($where['type']);
        $res = $this->services->changeUserCartNum((int)$where['id'], (int)$where['number'], (int)$uid);
        if ($res) return app('json')->successful();
        else return app('json')->fail('修改失败');
    }

    /**
     * 购物车 统计 数量 价格
     * @param Request $request
     * @return mixed
     */
    public function count(Request $request, SystemStoreServices $storeServices)
    {
        [$numType, $store_id] = $request->postMore([
            ['numType', true],//购物车编号
            ['store_id', 0],
        ], true);
        if (!$store_id || $store_id == 'undefined') $store_id = 0;
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $result = $resultAll = ['count' => 0, 'ids' => [], 'sum_price' => 0];
        if ($uid) {
            $where = ['status' => 1, 'staff_id' => 0, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0, 'store_id' => 0];
            $result = $this->services->getUserCartCount($uid, $where, $numType);
            $where = ['status' => 1, 'staff_id' => 0, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0];
            $entryRules = $storeServices->getStoreIdByEntryRules($uid);
            $shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
            $storeId = $entryRules['store_id'] ?? 0;
            switch ($shop_operation_type) {
                case 2://展示平台+门店加入购物车商品
                    $where['store_id'] = [0, $storeId];
                    break;
                case 3://展示门店加入购物车商品
                    $where['store_id'] = $storeId;
                    break;
            }
            $resultAll = $this->services->getUserCartCount($uid, $where, $numType);
        }
        return app('json')->success(['result' => $result, 'resultAll' => $resultAll]);
    }

    /**
     * 购物车重选
     * @param Request $request
     * @return mixed
     */
    public function reChange(Request $request)
    {
        [$cart_id, $product_id, $unique] = $request->postMore([
            ['cart_id', 0],
            ['product_id', 0],
            ['unique', '']
        ], true);
        $this->services->modifyCart($cart_id, $product_id, $unique);
        return app('json')->success('重选成功');
    }

    /**
     * 计算用户购物车商品（优惠活动、最优优惠券）
     * @param Request $request
     * @return mixed
     */
    public function computeCart(Request $request)
    {
        [$cartId, $new, $addressId, $shipping_type, $storeId] = $request->postMore([
            'cartId',
            'new',
            ['addressId', 0],
            ['shipping_type', -1],
            ['store_id', 0],
            ['delivery_type', 1],
        ], true);
        if (!is_string($cartId) || !$cartId) {
            $result = ['promotions' => [], 'coupon' => [], 'deduction' => [], 'svip_status' => false, 'svip_price' => 0.00];
        } else {
            $uid = (int)$request->uid();
            $result = $this->services->computeUserCart($uid, $cartId, !!$new, (int)$addressId, (int)$shipping_type, (int)$storeId);
        }
        return app('json')->success($result);
    }
}
