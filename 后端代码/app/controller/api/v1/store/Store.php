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
namespace app\controller\api\v1\store;

use app\jobs\user\UserBelongStoreJob;
use app\services\order\StoreCartServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\product\product\StoreProductServices;
use app\services\user\UserBelongStoreServices;
use app\Request;

/**
 * Class Store
 * @package app\controller\api\v1\store
 */
class Store
{
    /**
     * @var SystemStoreServices
     */
    protected $services;

    /**
     * Store constructor.
     * @param SystemStoreServices $services
     */
    public function __construct(SystemStoreServices $services)
    {
        $this->services = $services;
    }

    /**
     *    根据进店规则返回与哦那个户进入门店ID
     * @param Request $request
     * @return \think\Response
     */
    public function getUserEntryStore(Request $request)
    {
        $param = $request->getMore([
            ['latitude', ''],//经纬度
            ['longitude', ''],//经纬度
            [['store_id', 'd'], 0],//带参门店ID
            [['select_store_id', 'd'], 0],//用户切换选中门店ID
        ]);
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $param['ip'] = $request->ip();
        return app('json')->success($this->services->getStoreIdByEntryRules($uid, $param, true));
    }

    /**
     * 绑定店员
     * @param Request $request
     * @return \think\Response
     */
    public function  setUserBelongStaff(Request $request, SystemStoreServices $storeServices)
    {
        $param = $request->getMore([
            [['staff_id', 'd'], 0],//带参店员ID
            [['spid', 'd'], 0],//分享 UID
        ]);
        $uid = (int)$request->uid();
        return app('json')->success($storeServices->setUserBelongStaff($uid, $param));
    }

    /**
     * 首页diy获取门店列表
     * @param Request $request
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getHomeStoreList(Request $request)
    {
        [$store_type, $keywords, $latitude, $longitude, $province, $city, $area, $street, $product_num, $store_id] = $request->getMore([
            ['store_type', 1],
            ['keyword', ''],
            ['latitude', ''],
            ['longitude', ''],
            ['province', 0],
            ['city', 0],
            ['area', 0],
            ['street', 0],
            ['product_num', 4],//商品数量
            ['store_id', 0]
        ], true);
        $uid = 0;
        if ($request->hasMacro('uid')) $uid = (int)$request->uid();

        if (!checkCoordinates($longitude, $latitude)) {
            return app('json')->fail('参数错误');
        }
        $storeList = [];
        //开启门店 && 不是单店模式
        if (sys_config('store_func_status', 1)) {
            $where = ['store_type' => $store_type, 'keywords' => $keywords, 'province' => $province, 'city' => $city, 'area' => $area, 'street' => $street];
            $entryRules = $this->services->getStoreIdByEntryRules($uid, ['latitude' => $latitude, 'longitude' => $longitude]);
            $store_id = $entryRules['store_id'] ?? 0;
            if ($store_id) {
                $storeInfo = $this->services->get($store_id, ['id', 'is_alone']);
                if ($storeInfo && $storeInfo['is_alone']) {//进入的是隔离门店
                    $where['id'] = $store_id;
                }
            }
            if (sys_config('shop_operation_type', 1) == 1) {//平台+多店模式
                if (!isset($where['id'])) {//未进入隔离门店 查询当前区域门店
                    $ids = $this->services->getRegionStoreIds((int)$store_id);
                    if ($ids) $where['ids'] = $ids;
                    //去除隔离门店
                    $where['is_alone'] = 0;
                }
            } else {
                if ($store_id) {
                    $where['id'] = $store_id;
                }
            }
            $storeList = $this->services->getHomeStoreList($uid, $where, $latitude, $longitude, '', 0, (int)$product_num);
        }
        return app('json')->success($storeList);
    }

    /**
     * 首页 DIY 员工展示列表。
     */
    public function getHomeStaffList(Request $request, SystemStoreStaffServices $staffServices)
    {
        [$storeId, $limit, $positionIds] = $request->getMore([
            [['store_id', 'd'], 0],
            [['limit', 'd'], 6],
            ['position_ids', ''],
        ], true);
        $positionIds = is_array($positionIds) ? $positionIds : explode(',', (string)$positionIds);
        $positionIds = array_values(array_unique(array_filter(array_map('intval', $positionIds), static function (int $id): bool {
            return $id > 0;
        })));
        return app('json')->success([
            'list' => $staffServices->getHomeDisplayStaffList((int)$storeId, (int)$limit, $positionIds),
        ]);
    }

    /**
     * 附近门店
     * @param Request $request
     * @param SystemStoreServices $services
     * @param StoreCartServices $cartServices
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function nearbyStore(Request $request, SystemStoreServices $services, StoreCartServices $cartServices)
    {
        [$latitude, $longitude, $store_id] = $request->getMore([
            ['latitude', ''],
            ['longitude', ''],
            ['store_id', 0]//选择具体门店
        ], true);
        if (!checkCoordinates($longitude, $latitude)) {
            return app('json')->fail('参数错误');
        }
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $storeStatus = sys_config('store_func_status', 1);
        $storeInfo = $where = [];
        //开启门店
        if ($storeStatus) {
			if ((int)$store_id) {
				$where['id'] = $store_id;
			}
            $storeInfo = $services->getNearbyStore($where, $latitude, $longitude, $request->ip(), 1);
        }
        $data['info'] = $storeInfo;
        $data['tengxun_map_key'] = sys_config('tengxun_map_key');
        $data['store_splicing_switch'] = $storeStatus && sys_config('store_splicing_switch');
        $data['store_self_mention'] = sys_config('store_self_mention', 0);

        //访问某个特定门店
        if ($storeInfo && isset($where['id']) && $where['id']) {
            UserBelongStoreJob::dispatchDo('setVisitStore', [$uid, $storeInfo['id'], ['ip' => $request->ip()]]);
			//用户门店码进入，更新进店ID
			$uid && $this->services->getStoreIdByEntryRules($uid, ['select_store_id' => $store_id, 'latitude' => $latitude, 'longitude' => $longitude], true);
        }
        $data['cart_num'] = 0;
        if ($uid && $data['info']) {
            $cartArr = $cartServices->getUserCartCount($uid, ['status' => 1, 'staff_id' => 0, 'store_id' => $data['info']['id'] ?? 0], 0);
            $data['cart_num'] = $cartArr['count'] ?? 0;
        }
        return app('json')->successful($data);
    }

    /**
     * 获取门店列表
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreList(Request $request)
    {
        [$store_type, $keywords, $latitude, $longitude, $province, $city, $area, $street, $product_id, $store_id, $is_select, $is_sum, $card_product_id, $is_all] = $request->getMore([
            ['store_type', 1],
            ['keyword', ''],
            ['latitude', ''],
            ['longitude', ''],
            ['province', 0],
            ['city', 0],
            ['area', 0],
            ['street', 0],
            ['product_id', 0],//商品ID
            ['store_id', 0],//门店ID
            ['is_select', 0],//首页选择切换门店
            ['is_sum', 0],//是否取数量
            ['card_product_id', 0],//卡项商品id
            ['is_all', 0],//预约等场景：展示全部门店，不依赖定位
        ], true);
        if (!$is_all && !checkCoordinates($longitude, $latitude)) {
            return app('json')->fail('参数错误');
        }
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $intersection = [];
        $product = [];
        if ($product_id) {
            $product = $productServices->get($product_id,['pid','product_type','applicable_type','applicable_store_id']);
            if ($product && $product['pid']) {
                $product = $productServices->get($product['pid'],['pid','product_type','applicable_type','applicable_store_id']);
            }
        }
        if ($card_product_id && $product && $product['product_type'] == 6) {
            $card_product = $productServices->get($card_product_id,['pid','product_type','applicable_type','applicable_store_id']);
            if ($card_product && $card_product['pid']) {
                $card_product = $productServices->get($card_product['pid'],['pid','product_type','applicable_type','applicable_store_id']);
            }
			if(!$card_product || $card_product['product_type'] != 5) return app('json')->fail('参数错误');
            if ($product['applicable_type'] == 1 && $card_product['applicable_type'] == 2) {
                $intersection = $card_product['applicable_store_id'];
            } elseif ($product['applicable_type'] == 2 && $card_product['applicable_type'] == 1) {
                $intersection = $product['applicable_store_id'];
            } elseif ($product['applicable_type'] == 2 && $card_product['applicable_type'] == 2) {
                $card_product_store = $card_product['applicable_store_id'];
                $product_store = $product['applicable_store_id'];
                $intersection = array_intersect($card_product_store,$product_store);
            }
        }

        $uid = (int)$request->uid();
        $where = ['uid' => $uid, 'store_type' => $store_type, 'keywords' => $keywords, 'province' => $province, 'city' => $city, 'area' => $area, 'street' => $street, 'is_all' => (int)$is_all];
        $storeList = [];
        //开启门店
        if (sys_config('store_func_status', 1)) {
			if (!$is_all && !$keywords) {//无搜索门店 进店规则匹配
				$entryRules = $this->services->getStoreIdByEntryRules($uid, ['latitude' => $latitude, 'longitude' => $longitude]);
				$entryStoreId = $entryRules['store_id'] ?? 0;
				$shop_operation_type = $entryRules['shop_operation_type'] ?? 1;
				if ($entryStoreId) {
					$storeInfo = $this->services->get($entryStoreId, ['id', 'is_alone']);
					if ($storeInfo && $storeInfo['is_alone']) {//进入的是隔离门店
						$where['id'] = $entryStoreId;
					}
				}
				if ($is_select || $shop_operation_type == 1) {//切换门店 || 多门店+平台模式
					if (!isset($where['id'])) {//未进入隔离门店 查询当前区域门店
						$ids = $this->services->getRegionStoreIds((int)$entryStoreId);
						if ($ids) $where['ids'] = $ids;
						//去除隔离门店
						$where['is_alone'] = 0;
					}
				} else if (in_array($shop_operation_type, [2, 3])) {
					$store_id = $entryStoreId;
				}
			}
			$storeList = $this->services->getNearbyStore($where, $latitude, $longitude, '', 0, (int)$product_id, (int)$store_id);
        }
        $data = [];
        if($intersection && $storeList) {
            foreach ($storeList as $key => $item) {
                if(in_array($item['id'],$intersection)) {
                    $data[] = $item;
                }
            }
        }
        $sum = count($storeList);
        return app('json')->success($is_sum ? $sum : ($card_product_id ? $data : $storeList));
    }


    /**
     * 获取门店客服列表
     * @param SystemStoreStaffServices $staffServices
     * @param $store_id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCustomerList(SystemStoreStaffServices $staffServices, $store_id)
    {
        $customer = [];
        if ($store_id) {
            $customer = $staffServices->getCustomerList((int)$store_id, 'id,store_id,staff_name,avatar,customer_phone,customer_url');
        }
        return app('json')->success($customer);
    }

    /**
     * 获取客服详情
     * @param SystemStoreStaffServices $staffServices
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCustomerInfo(SystemStoreStaffServices $staffServices, $id)
    {
        $info = [];
        if (!$id) {
            return app('json')->fail('缺少参数');
        }
        $info = $staffServices->getStaffInfo((int)$id, 'id,store_id,staff_name,avatar,customer_phone,customer_url');
        if (!$info) {
            return app('json')->fail('客服不存在');
        }
        $info = $info->toArray();
        $storeInfo = $this->services->getStoreInfo((int)$info['store_id']);
        $info['store_name'] = $storeInfo['name'] ?? '';
        $info['address'] = $storeInfo['address'] ?? '';
        $info['detailed_address'] = $storeInfo['detailed_address'] ?? '';
        $info['latitude'] = $storeInfo['latitude'] ?? '';
        $info['longitude'] = $storeInfo['longitude'] ?? '';
        $info['day_time'] = $storeInfo['day_time'] ?? '';
        $info['day_start'] = $storeInfo['day_start'] ?? '';
        $info['day_end'] = $storeInfo['day_end'] ?? '';
        return app('json')->success($info);
    }

    /**
     * 同城配送设置
     * @return mixed
     */
    public function deliveryStatus()
    {
        return app('json')->success($this->services->getDeliveryStatus());
    }
}
