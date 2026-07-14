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
namespace app\controller\api\admin\order;

use app\Request;
use app\services\order\StoreCartServices;
use app\services\store\SystemStoreServices;

/**
 * 购物车类
 * Class StoreCart
 * @package app\api\controller\store
 */
class StoreCart
{
    protected $services;

    public function __construct(StoreCartServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取购物车数据
     * @param Request $request
     * @param StoreCartServices $services
     * @param $uid
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCartList(Request $request, StoreCartServices $services, $uid)
    {
        $cartIds = $request->get('cart_ids', '');
        $touristUid = $request->get('tourist_uid', '');
        $new = $request->get('new', false);
        $cartIds = $cartIds ? explode(',', $cartIds) : [];
        if (!$touristUid && !$uid) {
            return app('json')->fail('缺少用户信息');
        }
        $services->setItem('tourist_uid', $touristUid);

        $where = ['staff_id' => $request->uid(),'store_id' => 0, 'tourist_uid' => $touristUid, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0];
        $result = $services->getUserCartList((int)$uid, $where, $cartIds, -1, 0, !!$new);
        $services->reset();

        return app('json')->success($result['valid'] ?? []);
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
    public function getCartallLst(SystemStoreServices $storeServices, Request $request,StoreCartServices $services, $uid)
    {
        $cartIds = $request->get('cart_ids', '');
        $touristUid = $request->get('tourist_uid', '');
        $new = $request->get('new', false);
        $cartIds = $cartIds ? explode(',', $cartIds) : [];
        if (!$touristUid && !$uid) {
            return app('json')->fail('缺少用户信息');
        }
        $services->setItem('tourist_uid', $touristUid);
        $where = ['staff_id' => $request->uid(), 'is_all_store' => 1, 'tourist_uid' => $touristUid, 'status' => 1, 'cart_type' => 0, 'collate_code_id' => 0, 'activity_id' => 0];
        $result = $services->getUserCartList((int)$uid, $where, $cartIds, -1, 0, !!$new);
        $valid = $result['valid'];
        $storeAll = [];
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
        return app('json')->successful($storeAll);
    }

    /**
     * 加入购物车
     * @param Request $request
     * @param StoreCartServices $services
     * @param $uid
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function addCart(Request $request, StoreCartServices $services, $uid)
    {
        $where = $request->postMore([
            ['productId', 0],//普通商品编号
            [['cartNum', 'd'], 1], //购物车数量
            ['uniqueId', ''],//属性唯一值
            ['new', 1],//1直接购买,0=加入购物车
            ['tourist_uid', ''],//虚拟用户uid
            ['store_id', 0],
            ['is_set', 0],//1：直接设置购物车数量 0：累加 -1:减
        ]);

        $new = !!$where['new'];

        if (!$where['productId']) {
            return app('json')->fail('参数错误');
        }
        //真实用户存在，虚拟用户uid为空
        if ($uid) {
            $where['tourist_uid'] = '';
        }
        if (!$uid && !$where['tourist_uid']) {
            return app('json')->fail('缺少用户UID');
        }
        $services->setItem('is_set', $where['is_set'] ?? 0);
        $services->setItem('store_id', $where['store_id'] ?? '');
        $services->setItem('tourist_uid', $where['tourist_uid'])
            ->setItem('staff_id', $request->uid());

        $activityId = $type = 0;

        [$cartId, $cartNum] = $services->setCart((int)$uid, (int)$where['productId'], (int)$where['cartNum'], $where['uniqueId'], $type, $new, (int)$activityId);

        $services->reset();
        return app('json')->success('操作成功',['cartId' => $cartId]);
    }

    /**
     * 收银台更改购物车数量
     * @param Request $request
     * @param StoreCartServices $services
     * @param $uid
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function numCart(Request $request, StoreCartServices $services, $uid)
    {
        $where = $request->postMore([
            ['id', 0],//购物车编号
            ['number', 0],//购物数量
        ]);
        if (!$where['id'] || !$where['number'] || !is_numeric($where['id']) || !is_numeric($where['number'])) {
            return app('json')->fail('参数错误!');
        }
        if ($services->changeCashierCartNum((int)$where['id'], (int)$where['number'], $uid)) {

            return app('json')->success('修改成功');
        } else {
            return app('json')->fail('修改失败');
        }
    }

    /**
     * 删除购物车
     * @param Request $request
     * @param StoreCartServices $services
     * @param $uid
     * @return \think\Response
     */
    public function delCart(Request $request, StoreCartServices $services, $uid)
    {
        [$ids] = $request->postMore([
            ['ids', ''],//购物车编号
        ], true);
        if (!$ids) {
            return app('json')->fail('参数错误!');
        }
        $ids = is_array($ids) ? $ids : stringToIntArray($ids);
        if ($services->removeUserCart((int)$uid, $ids)) {
            return app('json')->success('删除成功');
        } else {
            return app('json')->fail('清除失败！');
        }
    }

}
