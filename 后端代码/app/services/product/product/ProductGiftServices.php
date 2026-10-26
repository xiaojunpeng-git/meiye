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

namespace app\services\product\product;

use app\services\BaseServices;
use app\services\other\StoreGiftConfigServices;

/**
 * 商品赠送配置
 */
class ProductGiftServices extends BaseServices
{
    /** @var StoreGiftConfigServices */
    protected $giftConfigServices;

    public function __construct(StoreGiftConfigServices $giftConfigServices)
    {
        $this->giftConfigServices = $giftConfigServices;
    }

    public function normalizeSendAll($sendAll): array
    {
        return $this->giftConfigServices->normalizeSendAll($sendAll);
    }

    public function getConfig(int $id): array
    {
        return $this->giftConfigServices->getConfig(StoreGiftConfigServices::GIFT_TYPE_PRODUCT, $id);
    }

    public function saveConfig(int $id, array $sendAll): void
    {
        $this->giftConfigServices->saveConfig(StoreGiftConfigServices::GIFT_TYPE_PRODUCT, $id, $sendAll);
    }

    /**
     * 解析用于赠送配置的平台商品ID
     * pid > 0：门店商品，用 pid 查平台配置
     * pid = 0：平台商品，直接用 id 查
     */
    public function resolvePlatformProductId(array $cart): int
    {
        $productInfo = $cart['productInfo'] ?? [];
        if (!is_array($productInfo)) {
            $productInfo = [];
        }
        $pid = (int)($productInfo['pid'] ?? 0);
        if ($pid > 0) {
            return $pid;
        }
        $id = (int)($productInfo['id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        return (int)($cart['product_id'] ?? 0);
    }

    /**
     * 为购物车条目附加赠送配置
     */
    public function attachToCartList(array $cartList): array
    {
        if (!$cartList) {
            return $cartList;
        }
        $platProductIds = [];
        $platIdByIndex = [];
        foreach ($cartList as $index => $cart) {
            if (!is_array($cart)) {
                continue;
            }
            $platId = $this->resolvePlatformProductId($cart);
            if ($platId > 0) {
                $platProductIds[$platId] = $platId;
                $platIdByIndex[$index] = $platId;
            }
        }
        if (!$platProductIds) {
            return $cartList;
        }
        $configMap = $this->giftConfigServices->getConfigMap(
            StoreGiftConfigServices::GIFT_TYPE_PRODUCT,
            array_values($platProductIds)
        );
        foreach ($platIdByIndex as $index => $platId) {
            $cartList[$index]['send_config'] = $configMap[$platId] ?? ['product' => [], 'coupon' => []];
        }
        return $cartList;
    }

    /**
     * 为分组购物车附加商品赠送配置
     */
    public function attachToCartGroups(array $cartGroups): array
    {
        if (!$cartGroups) {
            return $cartGroups;
        }
        foreach ($cartGroups as &$group) {
            if (!empty($group['cart']) && is_array($group['cart'])) {
                $group['cart'] = $this->attachToCartList($group['cart']);
            }
        }
        unset($group);
        return $cartGroups;
    }
}
