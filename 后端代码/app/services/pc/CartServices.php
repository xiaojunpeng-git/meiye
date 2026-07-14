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
declare (strict_types=1);

namespace app\services\pc;


use app\services\BaseServices;
use app\services\order\StoreCartServices;
use app\services\product\product\StoreProductServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\level\UserLevelServices;
use app\services\user\member\MemberCardServices;
use app\services\user\UserServices;

class CartServices extends BaseServices
{
    /**
     * PC端购物车列表
     * @param int $uid
     * @return array[]
     */
    public function getCartList(int $uid)
    {
        /** @var StoreCartServices $storeCartServices */
        $storeCartServices = app()->make(StoreCartServices::class);
        /** @var StoreProductServices $productServices */
        $productServices = app()->make(StoreProductServices::class);
        $list = $storeCartServices->getCartList(['uid' => $uid, 'is_pay' => 0, 'is_new' => 0, 'is_del' => 0, 'type' => 0, 'store_id' => 0], 0, 0, ['productInfo', 'attrInfo']);
        /** @var MemberCardServices $memberCardService */
        $memberCardService = app()->make(MemberCardServices::class);
        $vipStatus = $memberCardService->isOpenMemberCardCache('vip_price', false);
		/** @var UserLevelServices $userLevelServices */
		$userLevelServices = app()->make(UserLevelServices::class);
		[$userInfo, $discount] = $userLevelServices->getUserInfoAndLevelDiscount($uid);

        $valid = $invalid = [];
        foreach ($list as &$item) {
            $is_valid = $item['attrInfo']['suk'] ?? 0;
            $item['productInfo']['attrInfo'] = $item['attrInfo'] ?? [];
            $item['productInfo']['attrInfo']['image'] = $item['attrInfo']['image'] ?? $item['productInfo']['image'];
            if (isset($item['productInfo']['attrInfo'])) {
                $item['productInfo']['attrInfo'] = get_thumb_water($item['productInfo']['attrInfo']);
            }
            $item['productInfo'] = get_thumb_water($item['productInfo']);
            $productInfo = $item['productInfo'];
			$item['vip_truePrice'] = 0;
			//门店独立商品
			$isBranchProduct = isset($productInfo['type']) && isset($productInfo['pid']) && $productInfo['type'] == 1 && !$productInfo['pid'];

            if (isset($productInfo['attrInfo']['product_id']) && $item['product_attr_unique']) {
                $item['costPrice'] = $productInfo['attrInfo']['cost'] ?? 0;
                $item['trueStock'] = $productInfo['attrInfo']['stock'] ?? 0;
				$item['branch_sales'] = $productInfo['attrInfo']['sales'] ?? 0;
				$item['vip_price'] = $productInfo['attrInfo']['vip_price'] ?? 0;
				$item['truePrice'] = $item['sum_price'] =  (float)($productInfo['attrInfo']['price'] ?? 0);
            } else {
                $item['costPrice'] = $item['productInfo']['cost'] ?? 0;
                $item['trueStock'] = $item['productInfo']['stock'] ?? 0;
				$item['branch_sales'] = $productInfo['sales'] ?? 0;
				$item['vip_price'] = $productInfo['vip_price'] ?? 0;
				$item['truePrice'] = $item['sum_price'] = (float)($productInfo['price'] ?? 0);
			}
			$item['total_price'] = bcmul((string)$item['truePrice'], (string)$item['cart_num'], 2);
			if (!$isBranchProduct) {
				[$truePrice, $vip_truePrice, $type] = $productServices->setLevelPrice($item['truePrice'], $uid, $userInfo, $vipStatus, $discount, $item['vip_price'], $productInfo['is_vip'] ?? 0, true, ['level_type' => $productInfo['level_type'] ?? 1, 'level_price' => $productInfo['attrInfo']['level_price'] ?? '']);
				$item['truePrice'] = $truePrice;
				$item['vip_truePrice'] = $vip_truePrice;
				$item['price_type'] = $type;
			}
			$item['pay_price'] = bcmul((string)$item['truePrice'], (string)$item['cart_num'], 2);


            unset($item['attrInfo']);
            if ($item['status'] == 1 && $is_valid && $item['trueStock'] > 0) {
                $valid[] = $item;
            } else {
                $invalid[] = $item;
            }
        }
        return ['valid' => $valid, 'invalid' => $invalid];
    }
}
