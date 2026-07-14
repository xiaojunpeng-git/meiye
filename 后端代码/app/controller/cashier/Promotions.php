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
namespace app\controller\cashier;

use app\Request;
use app\services\activity\seckill\StoreSeckillTimeServices;
use app\services\user\UserServices;
use app\services\activity\discounts\StoreDiscountsServices;
use app\services\activity\promotions\StorePromotionsServices;
use app\services\activity\promotions\StorePromotionsAuxiliaryServices;
use app\services\activity\seckill\StoreSeckillServices;
use app\services\product\product\StoreProductServices;
use app\services\product\branch\StoreBranchProductServices;
use think\facade\App;

/**
 * 促销活动
 * Class Promotions
 * @package app\controller\cashier
 */
class Promotions extends AuthController
{
    /**
     * @var StorePromotionsServices
     */
    protected $services;

    /**
     * StoreOrder constructor.
     * @param App $app
     * @param StorePromotionsServices $service
     */
    public function __construct(App $app, StorePromotionsServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

    /** 获取门店适用的活动
     * @param $type
     * @return mixed
     */
    public function getPromotionInfo($type)
    {
        $where['promotions_type'] = $type;
        $where['type'] = 1;
        $where['status'] = 1;
        $where['applicable_type'] = '1,2';
        $where['store_id'] = 0;
        $where['pid'] = 0;
        $where['is_del'] = 0;
        return $this->success($this->services->getgetPromotionListInfo($where,$this->storeId));
    }

    /**
     * 获取活动商品数量信息
     * @return mixed
     */
    public function promotionsCount(StoreSeckillTimeServices $seckillTimeServices, StoreSeckillServices $seckillServices, StoreDiscountsServices $discountsServices, $uid)
    {
        $typeArr = [1 => 'time_discount', 2 => 'n_piece_n_discount', 3 => 'full_discount', 4 => 'full_give'];
        $where[] = [];
        $where['type'] = 1;
        $where['store_id'] = 0;
        $where['pid'] = 0;
        $where['is_del'] = 0;
        $where['status'] = 1;
        $where['promotionsTime'] = true;
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        /** @var StoreBranchProductServices $branchProductServices */
        $branchProductServices = app()->make(StoreBranchProductServices::class);
        $storeProducts = $branchProductServices->getSearchList(['type' => 1, 'relation_id' => $this->storeId, 'status' => 7, 'pid' => -1], 0, 0, ['pid']);
        $not_ids = [];
        if ($storeProducts) {
            $not_ids = array_column($storeProducts, 'pid');
        }
        $storeProducts = $branchProductServices->getSearchList(['type' => 1, 'relation_id' => $this->storeId, 'status' => 1, 'pid' => -1], 0, 0, ['pid']);
                $ids = [];
        if ($storeProducts) {
            $ids = array_column($storeProducts, 'pid');
        }
        $result = [];
        foreach ($typeArr as $type => $key) {
            $where['promotions_type'] = $type;
            $product_where = [];
			$product_where['type'] = 1;
			$product_where['relation_id'] = $this->storeId;
			$product_where['is_verify'] = 1;
            $product_where['pids'] = $ids;
            $product_where['not_pids'] = $not_ids;
            //门店不展示卡密商品
            $product_where['product_type'] = [0, 2, 4, 5, 6];
            $product_where['is_show'] = 1;
            $product_where['is_del'] = 0;
            //存在一个全部商品折扣优惠活动 直接返回商品
            if ($ids && !$this->services->count($where + ['product_partake_type' => 1])) {
                //正选并集
                $mergeIds = function ($promotions) {
                    $data = [];
                    foreach ($promotions as $item) {
                        $productIds = is_string($item['product_id']) ? explode(',', $item['product_id']) : $item['product_id'];
                        $data = array_merge($data, $productIds);
                    }
                    return $data;
                };
                $promotions = $this->services->getList($where + ['product_partake_type' => 2], 'id,product_id');
                $product_where['pids'] = $promotions ? $mergeIds($promotions) : [];
                $notPromotions = $this->services->getList($where + ['product_partake_type' => 3], 'id,product_id');
                //反选交集
                /** @var StorePromotionsAuxiliaryServices $auxiliaryService */
                $auxiliaryService = app()->make(StorePromotionsAuxiliaryServices::class);
                $intersectIds = function ($promotions) use ($auxiliaryService) {
                    $data = [];
                    foreach ($promotions as $item) {
                        $productIds = is_string($item['product_id']) ? explode(',', $item['product_id']) : $item['product_id'];
                        $productIds = $auxiliaryService->getColumn(['promotions_id' => $item['id'], 'type' => 1, 'is_all' => 1, 'product_id' => $productIds], 'product_id', '', true);
                    if(!$productIds) {
                        continue;
                    }
					if ($data) {
						$data = array_intersect($data, $productIds);
					} else {
						$data = $productIds;
					}
                    }
                    return $data;
                };
                $product_where['not_pids'] = array_merge($product_where['not_ids'] ?? [], $notPromotions ? $intersectIds($notPromotions) : []);
            }
            $count = 0;
            if ($product_where['pids'] && $this->services->count($where)) {
				$product_where['pids'] = array_merge(array_diff($product_where['pids'], $product_where['not_pids']));
				unset($product_where['not_pids']);
                $product_where['is_vip_product'] = 0;
                $product_where['is_presale_product'] = 0;
				//门店不展示卡密商品
				$product_where['product_type'] = [0, 2, 4, 5, 6];
                if ($uid) {
                    /** @var UserServices $user */
                    $user = app()->make(UserServices::class);
                    $userInfo = $user->getUserCacheInfo((int)$uid);
                    $is_vip = $userInfo['is_money_level'] ?? 0;
                    $product_where['is_vip_product'] = $is_vip ? -1 : 0;
                }
                $count = $productServices->getCount($product_where);
            }
            $result[$key] = ['type' => $type, 'count' => $count];
        }
        $result['seckill'] = ['type' => '5', 'count' => $seckillServices->getCountByTime($seckillTimeServices->getSeckillTime(), $ids, $not_ids)];
        $result['discount'] = ['type' => '6', 'count' => $discountsServices->getDiscountsCount()];
        return $this->success($result);
    }

    /**
     * 收银台获取活动商品列表
     * @param $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function activityList(Request $request, $type, $uid)
    {
        $data = [];
        /** @var StoreBranchProductServices $branchProductServices */
        $branchProductServices = app()->make(StoreBranchProductServices::class);
        $storeProducts = $branchProductServices->getSearchList(['type' => 1, 'relation_id' =>  $this->storeId, 'status' => 7, 'pid' => -1], 0, 0, ['pid']);
        $not_ids = [];
        if ($storeProducts) {
            $not_ids = array_column($storeProducts, 'pid');
        }
        $storeProducts = $branchProductServices->getSearchList(['type' => 1, 'relation_id' =>  $this->storeId, 'status' => 1, 'pid' => -1], 0, 0, ['pid']);
        $ids = [];
        if ($storeProducts) {
            $ids = array_column($storeProducts, 'pid');
        }
        $data['list'] = [];
        $data['count'] = 0;
        switch ($type) {
            case 1:
            case 2:
            case 3:
            case 4:
                [$staff_id, $promotions_id, $tourist_uid, $store_name] = $request->getMore([
                    ['staff_id', ''],
                    ['promotions_id', 0],
                    ['tourist_uid', ''],//虚拟用户uid
                    ['store_name', '']
                ], true);
                $this->services->setItem('store_id', $this->storeId)
                            ->setItem('tourist_uid', $tourist_uid)
                            ->setItem('staff_id', $staff_id)
                            ->setItem('ids', $ids)
                            ->setItem('not_ids', $not_ids)
                            ->setItem('store_name', $store_name);
                $data = $this->services->getTypeList((int)$type, (int)$uid, (int)$promotions_id);
                $this->services->reset();
                break;
            case 5:
				/** @var StoreSeckillTimeServices $seckillTimeServices */
				$seckillTimeServices = app()->make(StoreSeckillTimeServices::class);
                /** @var StoreSeckillServices $seckillServices */
                $seckillServices = app()->make(StoreSeckillServices::class);
                $timeId = (int)$seckillTimeServices->getSeckillTime();
                $data['list'] = $seckillServices->getListByTime($timeId, ['ids' => $ids], true);
                $data['count'] = $seckillServices->getCountByTime($timeId, $ids);
                break;
            case 6:
                $where['is_del'] = 0;
                $where['status'] = 1;
                $where['is_time'] = 1;
                /** @var StoreDiscountsServices $discountsServices */
                $discountsServices = app()->make(StoreDiscountsServices::class);
                $data = $discountsServices->getList($where);
                break;
        }
        return $this->success($data);
    }
}
