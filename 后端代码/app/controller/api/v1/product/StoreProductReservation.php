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
namespace app\controller\api\v1\product;

use app\Request;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\promotions\StorePromotionsServices;
use app\services\product\product\StoreProductReservationServices;
use app\services\product\product\StoreProductServices;

/**
 * 预约商品
 * Class StoreProductReservation
 * @package app\controller\api\v1\product
 */
class StoreProductReservation
{
	/**
     * @var StoreProductReservationServices
     */
	protected $services;

	 /**
     * StoreProductReservation constructor.
     * @param StoreProductReservationServices $services
     */
	public function __construct(StoreProductReservationServices $services)
	{
		$this->services = $services;
	}

	/**
	 * 获取预约商品、sku详情
	 * @param $id
	 * @return \think\Response
	 */
	public function getReservationProductInfo(Request $request, $id)
	{
		[$store_id, $unique] = $request->postMore([
			['store_id', ''],
			['unique', ''],
		], true);
		if (!(int)$id) return app('json')->fail('缺少参数!');
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		return app('json')->success($this->services->getReservationProductInfo($uid, (int)$id, (int)$store_id, $unique));
	}

	/**
	 * 获取商品可预约展示日期时间
	 * @param Request $request
	 * @param $id
	 * @return \think\Response
	 */
	public function getReservationProductDate(Request $request, $id)
	{
		[$store_id, $month] = $request->postMore([
			['store_id', ''],//门店ID
			['month', ''],//某一月可预约日期
		], true);
		if (!(int)$id) return app('json')->fail('缺少参数!');
		return app('json')->success($this->services->getReservationProductDate((int)$id, (int)$store_id, $month));
	}

	/**
	 * 获取预约商品时段划分库存
	 * @param Request $request
	 * @param $id
	 * @return \think\Response
	 */
	public function getReservationProductTimeStock(Request $request,$id)
	{
		[$unique, $date] = $request->postMore([
			['unique', ''],
			['date', ''],
		], true);
		if (!$id || !is_numeric($id)) {
            return app('json')->fail('商品ID参数错误');
        }
		return app('json')->success($this->services->getReservationProductTimeStock((int)$id, $unique, $date));
	}

	/**
	 * 计算预约商品到手价，优惠明细
	 * @param Request $request
	 * @param StoreProductServices $productServices
	 * @param StoreCouponIssueServices $couponIssueServices
	 * @param StorePromotionsServices $storePromotionsServices
	 * @return \think\Response
	 * @throws \Psr\SimpleCache\InvalidArgumentException
	 * @throws \ReflectionException
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @throws \throwable
	 */
	public function reservationCompute(Request $request, StoreProductServices $productServices, StoreCouponIssueServices $couponIssueServices, StorePromotionsServices $storePromotionsServices)
	{
		[$productId, $unique, $cartNum, $reservationTimeId, $storeId] = $request->postMore([
			[['product_id', 'd'], 0],//商品ID
			['unique', ''],//sku唯一值
			[['cart_num', 'd'], 1],//购买数量
			[['reservation_time_id', 'd'], 0],//时段ID
			[['store_id', 'd'], 0],//门店
		], true);
		$id = (int)$productId;
		$storeInfo = [];
		if ($id) {
			$storeInfo = $productServices->getCacheProductInfo($id);
		}
		$result = ['activity' => [], 'coupons' => [], 'discounts_products' => [], 'promotions' => [], 'activity_background' => [], 'computed' => ['deduction' => []]];
		if ($productId && $storeInfo) {
			$uid = 0;
			if ($request->hasMacro('uid')) $uid = (int)$request->uid();
			$result['activity'] = $productServices->getActivityList($storeInfo, false);
			$result['coupons'] = $couponIssueServices->getProductCouponList($uid, (int)$productId, 'id,coupon_type,coupon_title,is_claimed,quantity_count,top_discount_price,coupon_price,use_min_price,start_time,end_time,applicable_type,applicable_store_id,coupon_issue_type,relation_id', 3);

			[$promotions, $productRelation] = $storePromotionsServices->getProductsPromotions([$productId], [1, 2, 3, 4], '*', ['giveProducts' => function ($query) {
				$query->field('promotions_id,product_id,limit_num,surplus_num')->with(['productInfo' => function ($query) {
					$query->field('id,store_name,image');
				}]);
			}, 'giveCoupon' => function ($query) {
				$query->field('promotions_id,coupon_id,limit_num,surplus_num')->with(['coupon' => function ($query) {
					$query->field('id,type,coupon_type,coupon_title,coupon_price,use_min_price');
				}]);
			}, 'promotions' => function ($query) {
				$query->field('id,pid,promotions_type,promotions_cate,threshold_type,threshold,discount_type,n_piece_n_discount,discount,give_integral,give_coupon_id,give_product_id,give_product_unique')->with(['giveProducts' => function ($query) {
					$query->field('promotions_id, product_id,limit_num,surplus_num')->with(['productInfo' => function ($query) {
						$query->field('id,store_name');
					}]);
				}, 'giveCoupon' => function ($query) {
					$query->field('promotions_id, coupon_id,limit_num,surplus_num')->with(['coupon' => function ($query) {
						$query->field('id,type,coupon_type,coupon_title,coupon_price,use_min_price');
					}]);
				}]);
			}], 'promotions_type', (int)$storeId);
			if ($promotions) {
				foreach ($promotions as $key => $item) {
					$result['promotions'][] = [
						'id' => $item['id'],
						'type' => $item['type'],
						'title' => $item['title'],
						'name' => $item['name'],
						'give_integral' => $item['give_integral'],
						'promotions_type' => $item['promotions_type'],
						'threshold_type' => $item['threshold_type'],
						'threshold' => $item['threshold'],
						'discount_type' => $item['discount_type'],
						'discount' => $item['discount'],
						'desc' => $item['desc'],
						'start_time' => $item['start_time'] ?  date('Y-m-d', $item['start_time']) : '',
						'stop_time' => $item['stop_time'] ?  date('Y-m-d', $item['stop_time']) : '',
						'giveProducts' => $item['giveProducts'] ?? [],
						'giveCoupon' => $item['giveCoupon'] ?? []
					];
				}
			}
			$result['computed'] = $productServices->computedProductPayPrice($uid, (int)$productId, (string)($unique ?? ''), (int)$storeId, (int)$cartNum, (int)$reservationTimeId);
		}
		return app('json')->success($result);
	}


}
