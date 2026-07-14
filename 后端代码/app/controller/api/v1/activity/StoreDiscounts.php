<?php


namespace app\controller\api\v1\activity;


use app\Request;
use app\services\activity\discounts\StoreDiscountsServices;

/**
 * 优惠套餐控制器
 * Class StoreDiscounts
 * @package app\controller\api\v1\activity
 */
class StoreDiscounts
{
    protected $services;

    /**
     * StoreDiscounts constructor.
     * @param StoreDiscountsServices $services
     */
    public function __construct(StoreDiscountsServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取优惠商品列表
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        [$product_id] = $request->postMore([
            ['product_id', 0]
        ], true);
        if (!is_numeric($product_id)) {
            return app('json')->fail('商品ID参数错误');
        }
        $uid = (int)$request->uid();
        $list = $this->services->getDiscounts((int)$product_id, $uid);
        return $list ? app('json')->successful($list) : app('json')->fail('活动商品已下架');
    }
}