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
namespace app\controller\api\v2\activity;


use app\Request;
use app\services\activity\promotions\StorePromotionsServices;
use app\services\activity\promotions\StorePromotionsAuxiliaryServices;
use app\services\product\product\StoreProductServices;

/**
 * 优惠活动控制器
 * Class StorePromotions
 * @package app\controller\api\v2\activity
 */
class StorePromotions
{
	/**
     * @var StorePromotionsServices
     */
    protected $services;

	/**
     * StorePromotions constructor.
     * @param StorePromotionsServices $services
     */
    public function __construct(StorePromotionsServices $services)
    {
        $this->services = $services;
    }

    /**
     * 某个优惠活动商品列表
     * @param $type
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function productList($type)
    {
        $type = (int)($type ?? 0);
		$product_where = [];
		if ($type) {
			$product_where = $this->services->collectProductByType([$type]);
		}
		$list = [];
		if (!$product_where || (isset($product_where['ids']) && $product_where['ids'])) {
            /** @var StoreProductServices $productServices */
            $productServices = app()->make(StoreProductServices::class);
            $list = $productServices->getGoodsList($product_where, 0, $type);
            if ($list) {
                foreach ($list as $key => &$item) {
                    if (isset($item['promotions']['promotions_type']) && $item['promotions']['promotions_type'] == 1) {
                        $item['price'] = floatval(bcmul((string)$item['price'], (string)bcdiv((string)$item['promotions']['discount'] ?? '100', '100', 2), 2));
                    }
                }
            }
        }
        return app('json')->success(compact('list'));
    }

    /**
     * 获取凑单商品列表
     * @param Request $request
     * @return mixed
     */
    public function collectOrderProduct(Request $request, StoreProductServices $productServices, StorePromotionsAuxiliaryServices $auxiliaryService)
    {
        [$promotions_id] = $request->getMore([
            [['promotions_id', 'd'], 0]
        ], true);
        $promotions = $this->services->get($promotions_id, ['*'], ['promotions']);
        if (!$promotions) {
            return app('json')->fail('活动已失效，请刷新页面');
        }
		$promotions = $promotions->toArray();
		$product_where = $this->services->collectProductById((int)$promotions_id);
		$list = $productServices->getGoodsList($product_where, (int)$request->uid());
		return app('json')->success(compact('promotions','list'));
    }

	/**
 	* 获取优惠活动赠品
	* @param $id
	* @return mixed
	 */
	public function getPromotionsGive($id)
	{
		$result = [];
		if($id) {
			$promotionsInfo= $this->services->getInfo((int)$id);
			if ($promotionsInfo && $promotionsInfo['promotions_type'] == 4) {
				$giveIntegral = $giveCoupon = $giveProducts = [];
				$promotions_cate = $promotionsInfo['promotions_cate'];
				foreach ($promotionsInfo['promotions'] as $p) {
					if ($promotions_cate == 2) {
						$base = '每满' . floatval($p['threshold'] ?? 0);
					} else {
						$base = '满' . floatval($p['threshold'] ?? 0);
					}
					$base .= $p['threshold_type'] == 1 ? '元可领取' : '件可领取';
					if (isset($p['give_integral']) && $p['give_integral']) {
						$giveIntegral[] = ['threshold_title' => $base, 'give_integral' => intval($p['give_integral'])];
					}
					if (isset($p['giveCoupon']) && $p['giveCoupon']) {
						foreach ($p['giveCoupon'] as &$coupon) {
							$coupon['threshold_title'] = $base;
						}
						$giveCoupon = array_merge($giveCoupon, $p['giveCoupon']);
					}
					if (isset($p['giveProducts']) && $p['giveProducts']) {
						foreach ($p['giveProducts'] as &$product) {
							$product['threshold_title'] = $base;
						}
						$giveProducts = array_merge($giveProducts, $p['giveProducts']);
					}
				}
				$result = compact('giveIntegral', 'giveCoupon', 'giveProducts');
			}
		}
		return app('json')->success($result);
	}
}
