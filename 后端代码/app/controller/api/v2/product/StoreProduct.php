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

namespace app\controller\api\v2\product;

use app\Request;
use app\services\product\branch\StoreBranchProductServices;
use app\services\product\product\StoreProductReplyServices;
use app\services\product\product\StoreProductServices;
use app\services\product\product\StoreCardRelatedServices;
use app\services\product\sku\StoreProductAttrServices;
use app\services\system\form\SystemFormServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\UserServices;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

/**
 * 商品
 * Class StoreProduct
 * @package app\controller\api\v2\product
 */
class StoreProduct
{
    /**
     * @var StoreProductServices
     */
    protected $services;

    /**
     * StoreProduct constructor.
     * @param StoreProductServices $services
     */
    public function __construct(StoreProductServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取商品属性
     * @param Request $request
     * @return \think\Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getProductAttr(Request $request)
    {
        [$id, $cartNum] = $request->getMore([
            ['id', 0],
            ['type', 0]
        ], true);
        if (!$id) return app('json')->fail('参数错误');
        $storeInfo = $this->services->getOne(['id' => $id]);
        if (!$storeInfo) {
            return app('json')->fail('商品不存在');
        }
        $storeInfo = $storeInfo->toArray();
        //系统表单
        $storeInfo['custom_form'] = [];
        if ($storeInfo['system_form_id']) {
            /** @var SystemFormServices $systemFormServices */
            $systemFormServices = app()->make(SystemFormServices::class);
            $systemForm = $systemFormServices->value(['id' => $storeInfo['system_form_id']], 'value');
            if ($systemForm) {
                $storeInfo['custom_form'] = is_string($systemForm) ? json_decode($systemForm, true) : $systemForm;
            }
        }
        //有自定义表单或预售或虚拟不展示加入购物车按钮
        $storeInfo['cart_button'] = $storeInfo['custom_form'] || $storeInfo['is_presale_product'] || $storeInfo['product_type'] > 0 ? 0 : 1;
        $data['storeInfo'] = $storeInfo;
        $discount = 100;
        $uid = (int)$request->uid();
        if ($uid) {
            /** @var UserServices $user */
            $user = app()->make(UserServices::class);
            $userInfo = $user->getUserCacheInfo($uid);
            //用户等级是否开启
            /** @var SystemUserLevelServices $systemLevel */
            $systemLevel = app()->make(SystemUserLevelServices::class);
            $levelInfo = $systemLevel->getLevel((int)($userInfo['level'] ?? 0));
            if (sys_config('member_func_status', 1) && $levelInfo) {
                $discount = $levelInfo['discount'] ?? 100;
            }
        }
        $minData = $this->services->getMinPrice($uid, $data['storeInfo'], $discount);
        $data['storeInfo']['price_type'] = $minData['price_type'] ?? '';
        if (!$this->services->vipIsOpen(!!$data['storeInfo']['is_vip'])) $data['storeInfo']['is_vip'] = 0;
        /** @var StoreProductAttrServices $storeProductAttrServices */
        $storeProductAttrServices = app()->make(StoreProductAttrServices::class);
        [$data['productAttr'], $data['productValue']] = $storeProductAttrServices->getProductAttrDetail($id, (int)$request->uid(), $cartNum, 0, 0, $data['storeInfo']);
        return app('json')->successful($data);
    }

    /**
     * 获取商品评论
     * @param Request $request
     * @param StoreProductReplyServices $services
     * @param $id
     * @return \think\Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function reply_list(Request $request, StoreProductReplyServices $services, $id)
    {
        [$type] = $request->getMore([
            [['type', 'd'], 0]
        ], true);
        $list = $services->getNewProductReplyList((int)$id, (int)$type, (int)$request->uid());
        return app('json')->successful(get_thumb_water($list, 'small', ['pics']));
    }

    /**
     * 平台商品ID：获取在门店该商品详情
     * @param Request $request
     * @param StoreBranchProductServices $branchProductServices
     * @param $id
     * @return \think\Response
     */
    public function getStoreProductInfo(Request $request, StoreBranchProductServices $branchProductServices, $id)
    {
        [$store_id] = $request->getMore([
            [['store_id', 'd'], 0]
        ], true);
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        $uid = 0;
        if ($request->hasMacro('uid')) $uid = (int)$request->uid();
        return app('json')->successful($branchProductServices->getStoreProductInfo($uid, (int)$id, (int)$store_id));
    }

    /**
     * 卡项商品关联商品获取
     * @param Request $request
     * @param StoreCardRelatedServices $relatedService
     * @param $id
     * @return \think\Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getCardRelatedProduct(Request $request, StoreCardRelatedServices $relatedService, $id)
    {
        if (!$id) {
            return app('json')->fail('参数错误');
        }
        $related = $relatedService->getCardRelatedProduct($id, true);
        return app('json')->successful($related);
    }
}
