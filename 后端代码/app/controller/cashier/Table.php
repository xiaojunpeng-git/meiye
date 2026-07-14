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
namespace app\controller\cashier;

use app\Request;
use app\services\activity\table\TableQrcodeServices;
use app\services\order\StoreOrderCartInfoServices;
use app\services\order\StoreOrderServices;
use app\services\other\CategoryServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\UserServices;
use app\services\activity\collage\UserCollageCodeServices;
use app\services\activity\collage\UserCollagePartakeServices;
use app\services\order\StoreReservationOrderServices;

/**
 * 收银台桌码
 * Class Table
 * @package app\controller\cashier
 */
class Table extends AuthController
{

    /**桌码管理
     * @param CategoryServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTableCode(CategoryServices $services, StoreReservationOrderServices $reservationServices)
    {
        $where = ['type' => 1, 'relation_id' => $this->storeId, 'group' => 6, 'is_show' => 1];
        $list = $services->getTableCodeCateList($where, ['id', 'relation_id', 'name', 'group'], ['tableQrcode']);
        $occupancyMap = $reservationServices->getRoomOccupancyMap((int)$this->storeId);
        foreach ($list as &$cate) {
            if (!empty($cate['tableQrcode'])) {
                foreach ($cate['tableQrcode'] as &$qrcode) {
                    $tableId = (int)($qrcode['id'] ?? 0);
                    $occ = $occupancyMap[$tableId] ?? ['room_occupied' => 0, 'room_people_count' => 0];
                    $qrcode['room_occupied'] = $occ['room_occupied'];
                    $qrcode['room_people_count'] = $occ['room_people_count'];
                }
                unset($qrcode);
            }
        }
        unset($cate);
        return $this->success($list);
    }

    /**
     * 桌码状态设置
     * @param $id
     * @param TableQrcodeServices $qrcodeServices
     * @return mixed
     */
    public function tableQrcodeStatus($id, TableQrcodeServices $qrcodeServices)
    {
        if (!$id) {
            return $this->fail('参数有误!');
        }
        $qrcodeServices->update($id, ['is_use' => 0, 'eat_number' => 0, 'order_time' => 0]);
        return $this->success('设置成功!');
    }


    /**桌码订单列表
     * @param Request $request
     * @param UserCollageCodeServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTableCodeList(Request $request, UserCollageCodeServices $services)
    {
        $where = $request->getMore([
            ['type', 10],
            ['status', ''],
            ['time', ''],
            ['staff_id', 0],
            ['keyword', '', '', 'serial_number']
        ]);
        if ($where['time'] && is_array($where['time']) && count($where['time']) == 2) {
            [$start, $end] = $where['time'];
            if (strtotime($start) > strtotime($end)) {
                return $this->fail('开始时间不能大于结束时间，请重新选择时间');
            }
        }
        if (isset($where['status']) && $where['status'] == '') {
            $where['status'] = [0, 1, 2, 3];
        } else if (isset($where['status']) && $where['status'] == 1) {
            $where['status'] = [0, 1];
        }
        $where['store_id'] = $this->storeId;
        $data = $services->getStoreTableCodeList($where);
        return $this->success($data);
    }

    /**订单购物车信息
     * @param $oid
     * @return mixed
     */
    public function getOrderInfo($oid)
    {
        /** @var StoreOrderServices $orderService */
        $orderService = app()->make(StoreOrderServices::class);
        $orderInfo = $orderService->get($oid, ['id', 'uid', 'order_id', 'status as order_status', 'pay_type', 'paid', 'deduction_price', 'coupon_price', 'total_price', 'pay_price', 'change_price', 'paid', 'refund_status', 'staff_id', 'remark', 'is_del', 'is_system_del']);
        if ($orderInfo) {
            $orderInfo = $orderInfo->toArray();
        } else {
            return $this->success([]);
        }
        if ($orderInfo['paid'] == 1 && $orderInfo['refund_status'] == 4) {
            $orderInfo['status_name'] = '退款中';
        } else if ($orderInfo['paid'] == 1 && $orderInfo['refund_status'] == 3) {
            $orderInfo['status_name'] = '部分退款';
        } else if ($orderInfo['paid'] == 1 && $orderInfo['refund_status'] == 2) {
            $orderInfo['status_name'] = '已退款';
        } else {
            $orderInfo['status_name'] = '';
        }
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
        $orderInfo['userInfo'] = $userService->getUserInfo($orderInfo['uid']);
        $orderInfo['staff'] = [];
        if (isset($orderInfo['staff_id']) && $orderInfo['staff_id']) {
            /** @var SystemStoreStaffServices $staffService */
            $staffService = app()->make(SystemStoreStaffServices::class);
            $staff = $staffService->get((int)$orderInfo['staff_id'], ['uid', 'staff_name']);
            $orderInfo['staff'] = $staff ?? [];
        }
        /** @var StoreOrderCartInfoServices $services */
        $services = app()->make(StoreOrderCartInfoServices::class);
        $_info = $services->getOrderCartInfoCache((int)$oid);
        foreach ($_info as $key => &$item) {
            $item['cart_info']['vip_sum_truePrice'] = bcmul($item['cart_info']['vip_truePrice'], $item['cart_info']['cart_num'] ? $item['cart_info']['cart_num'] : 1, 2);
        }
        $orderInfo['_info'] = $_info;
        //核算优惠金额
        $vipTruePrice = 0;
        foreach ($orderInfo['_info'] ?? [] as $key => $cart) {
            $vipTruePrice = bcadd((string)$vipTruePrice, (string)$cart['cart_info']['vip_sum_truePrice'], 2);
        }
        $orderInfo['vip_true_price'] = $vipTruePrice;
        $orderInfo['is_cashier_yue_pay_verify'] = (int)sys_config('is_cashier_yue_pay_verify'); // 收银台余额支付是否需要验证【是/否】
        return $this->success($orderInfo);
    }

    /**获取全部点餐用户信息
     * @param Request $request
     * @param UserCollagePartakeServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTableCodeUserAll(Request $request, UserCollagePartakeServices $services)
    {
        $where = $request->getMore([
            ['table_id', 0],
        ]);
        if (!$where['table_id']) return $this->fail('参数有误！');
        $store_id = (int)$this->storeId;
        $uids = $services->tableCodeUserAll($where, $store_id);
        return $this->success($uids);
    }

    /**
     * 购物车处理
     * @param Request $request
     * @return mixed
     */
    public function getCartList(Request $request)
    {
        [$table_id, $uid] = $request->getMore([
            ['table_id', 0],
            ['uid', 0],
        ], true);
        if (!$table_id) return app('json')->fail('参数有误!');
        /** @var UserCollagePartakeServices $partakeService */
        $partakeService = app()->make(UserCollagePartakeServices::class);
        $cartIds = $partakeService->allUserSettleAccountsTableCode($table_id, $uid, 10);
        return $this->success($cartIds);
    }

	/**
	 * 桌码订单改价获取订单、商品信息
	 * @param Request $request
	 * @return \think\Response
	 */
	public function getTableUpdateInfo(Request $request)
	{
		[$table_id, $uid] = $request->getMore([
			['table_id', 0],
			['uid', 0],
		], true);
		if (!$table_id) return app('json')->fail('参数有误!');
		$store_id = (int)$this->storeId;
		/** @var UserCollagePartakeServices $partakeService */
		$partakeService = app()->make(UserCollagePartakeServices::class);
		$cartIds = $partakeService->getTableUpdateInfo($table_id, $uid, $store_id);
		return $this->success($cartIds);
	}

	/**
	 * 桌码改价
	 * @param Request $request
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function tableUpdate(Request $request)
	{
		[$table_id, $uid, $changePrice, $cartInfo] = $request->getMore([
			['table_id', 0],
			['uid', 0],
			['change_price', 0],//整体改价金额
			['cart_info', []],//商品改价数组[['id' => 1, 'true_price' => 11.00],['id' => 2, 'true_price' => 11.00]]
		], true);
		if (!$table_id) return app('json')->fail('参数有误!');
		if ($cartInfo) {
			foreach ($cartInfo as $cart) {
				if (!isset($cart['id']) || !$cart['id'] || !isset($cart['true_price'])) {
					return app('json')->fail('请选择需要改价的商品进行操作');
				}
			}
		}
		$store_id = (int)$this->storeId;
		/** @var UserCollagePartakeServices $partakeService */
		$partakeService = app()->make(UserCollagePartakeServices::class);
		$partakeService->tableUpdate($table_id, $uid, $store_id, $cartInfo, $changePrice);
		return $this->success('改价成功');
	}


    /**
	 * 收银台购物车数量操作
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function editCart(Request $request)
    {
        $where = $request->postMore([
            ['productId', 0],//普通商品编号
            [['cartNum', 'd'], 1], //购物车数量
            ['uniqueId', ''],//属性唯一值
            ['tableId', 0],//桌码ID
            ['isAdd', 1],//购物车数量加减 1 加 0 减
        ]);
        $store_id = (int)$this->storeId;
        /** @var UserCollagePartakeServices $partakeService */
        $partakeService = app()->make(UserCollagePartakeServices::class);
        $res = $partakeService->editTableCartProduct($where, $store_id);
        if ($res) {
            return $this->success('ok');
        } else {
            return $this->fail('操作失败');
        }
    }

	/**
	 * 收银台购物车数量操作
	 * @param Request $request
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function delCart(Request $request)
	{
		[$productId, $uniqueId, $tableId] = $request->postMore([
			['productId', 0],//普通商品编号
			['uniqueId', ''],//属性唯一值
			['tableId', 0],//桌码ID
		], true);
		if (!$productId || !$uniqueId || !$tableId) {
			return $this->fail('参数错误');
		}
		$store_id = (int)$this->storeId;
		/** @var UserCollagePartakeServices $partakeService */
		$partakeService = app()->make(UserCollagePartakeServices::class);
		if ($partakeService->delUserCatePartake((int)$tableId, $store_id, (int)$productId, (string)$uniqueId)) {
			return $this->success('ok');
		} else {
			return $this->fail('操作失败');
		}
	}

    /**取消桌码
     * @param Request $request
     * @param UserCollageCodeServices $services
     * @return \think\Response
     */
    public function cancelInitiateTable(Request $request, UserCollageCodeServices $services)
    {
        [$tableId, $qrcodeId, $oid] = $request->getMore([
            ['tableId', 0],
            ['qrcode_id', 0],
            ['oid', 0],
        ], true);
        $where = ['status' => -1];
        if (!$tableId) return app('json')->fail('参数有误!');
        $res = $services->userUpdate((int)$tableId, $where);
        if ($res) {
            /** @var UserCollagePartakeServices $partakeService */
            $partakeService = app()->make(UserCollagePartakeServices::class);
            $partakeService->del(['collate_code_id' => $tableId]);
            /** @var TableQrcodeServices $qrcodeService */
            $qrcodeService = app()->make(TableQrcodeServices::class);
            $qrcodeService->update($qrcodeId, ['is_use' => 0, 'eat_number' => 0, 'order_time' => 0]);
            if ($oid) {
                /** @var StoreOrderServices $orderService */
                $orderService = app()->make(StoreOrderServices::class);
                $orderService->update($oid, ['is_del' => 1, 'is_system_del' => 1]);
            }
            return app('json')->successful('ok');
        } else {
            return app('json')->fail('取消失败');
        }
    }

    /**
     * 手动打单
     * @param Request $request
     * @param UserCollageCodeServices $services
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function staffPlaceOrder(Request $request, UserCollageCodeServices $services)
    {
        [$tableId] = $request->getMore([
            ['tableId', 0],
        ], true);
        $store_id = (int)$this->storeId;
        $res = $services->tablePrint((int)$tableId, $store_id, -1, 1);
        if ($res) {
            return app('json')->successful('打单成功');
        } else {
            return app('json')->fail('打单失败');
        }
    }

}
