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
namespace app\services\store;

use app\dao\store\SystemStoreStaffDao;
use app\jobs\user\UserBelongStoreJob;
use app\model\position\Position;
use app\model\position\PositionLevel;
use app\services\BaseServices;
use app\services\order\OtherOrderServices;
use app\services\order\store\BranchOrderServices;
use app\services\order\StoreOrderRefundServices;
use app\services\product\product\StoreProductServices;
use app\services\user\UserBelongStoreServices;
use app\services\store\finance\StaffFlowingWaterServices;
use app\services\store\finance\StoreFinanceFlowServices;
use app\services\user\UserServices;
use app\services\work\WorkMemberServices;
use app\services\work\WorkClientServices;
use app\services\system\SystemRoleServices;
use app\services\user\UserCardServices;
use app\services\user\UserRechargeServices;
use app\services\user\UserSpreadServices;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;

/**
 * 门店店员
 * Class SystemStoreStaffServices
 * @package app\services\system\store
 * @mixin SystemStoreStaffDao
 */
class SystemStoreStaffServices extends BaseServices
{

    /**
     * 构造方法
     * SystemStoreStaffServices constructor.
     * @param SystemStoreStaffDao $dao
     */
    public function __construct(SystemStoreStaffDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取低于等级的店员名称和id
     * @param string $field
     * @param int $level
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrdAdmin(string $field = 'real_name,id', int $storeId = 0, int $level = 0)
    {
        return $this->dao->getWhere()->where('store_id', $storeId)->where('level', '>=', $level)->field($field)->select()->toArray();
    }

    /**
     * 获取门店客服列表
     * @param int $store_id
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCustomerList(int $store_id, string $field = '*')
    {
        return $this->dao->getWhere()->where('store_id', $store_id)->where('status', 1)->where('is_del', 0)->where('is_customer', 1)->field($field)->select()->toArray();
    }

    /**
     * 获取店员详情
     * @param int $id
     * @param string $field
     * @return array|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStaffInfo(int $id, string $field = '*')
    {
        $info = $this->dao->getOne(['id' => $id, 'is_del' => 0], $field);
        if (!$info) {
            throw new ValidateException('店员不存在');
        }
        return $info;
    }

    /**
     * 根据uid获取门店店员信息
     * @param int $uid
     * @param int $store_id
     * @param string $field
     * @return array|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStaffInfoByUid(int $uid, int $store_id = 0, string $field = '*')
    {
        $where = ['uid' => $uid, 'is_del' => 0, 'status' => 1];
        if ($store_id) $where['store_id'] = $store_id;
        $info = $this->dao->getOne($where, $field);
//        if (!$info) {
//            throw new ValidateException('店员不存在');
//        }
        return $info;
    }

    /**
     * 检查用户是否是店员
     * @param int $uid
     * @return array|\think\Model
     */
    public function isStaff(int $uid, string $field = '*')
    {
        $where = ['uid' => $uid, 'is_del' => 0, 'status' => 1];
        $info = $this->dao->getOne($where, $field);
        if (!$info) {
            return null;
        }
        return $info;
    }

    /**
     * 校验门店店员查看客户卡包权限（含普通员工）
     * @param array $staffInfo
     */
    public function assertViewCustomerCardPermission(array $staffInfo): void
    {
        if (!$staffInfo || empty($staffInfo['id'])) {
            throw new ValidateException('无操作权限');
        }
    }

    /**
     * 临期解绑店员
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function systemOperateUnbind()
    {
        $period_of_validity = sys_config('period_of_validity', 1);
        if (in_array($period_of_validity, [1, 3])) return true;
        $period_validity_day = sys_config('period_validity_day', 1);
        /** @var UserServices $userServices */
        $userServices = app()->make(UserServices::class);
        $expire_time = time() - $period_validity_day * 86400;
        $where['salesmanTime'] = $expire_time;
        $userList = $userServices->getUserInfoList($where);
        if (!count($userList)) return true;
        foreach ($userList as $key => $item) {
            if (!$item['salesman_id']) continue;
            $info = $this->dao->get($item['salesman_id']);
            if (!$info) continue;
            /** @var UserBelongStoreServices $belongServices */
            $belongServices = app()->make(UserBelongStoreServices::class);
            $belongServices->setUserBelongStoreStaff((int)$item['uid'], (int)$item['salesman_id'], (int)$info['store_id'], 6);
        }
        return true;
    }

    /**
     * 企业微信绑定
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function systemOperateWeComBelong()
    {
        $where['is_work_member'] = 1;
        $where['status'] = 1;
        $where['is_del'] = 0;
        $where['is_customer'] = 1;
        $list = $this->dao->getStoreStaffList($where);
        if (!count($list)) return true;
        foreach ($list as $item) {
            /** @var WorkMemberServices $workMemberService */
            $workMemberService = app()->make(WorkMemberServices::class);
            $userid = $workMemberService->value(['id' => $item['work_member_id']], 'userid');
            if (!$userid) continue;
            /** @var WorkClientServices $clientService */
            $clientService = app()->make(WorkClientServices::class);
            $where['external_userid'] = $userid;
            $where['is_uid'] = 1;
            $clientUsers = $clientService->getClientUserList($where);
            if (!count($clientUsers)) continue;
            foreach ($clientUsers as $client) {
                UserBelongStoreJob::dispatchDo('belongStoreStaff', [$client['uid'], 0, 3, $item['id'], $item['store_id']]);
            }
        }
        return true;
    }

    /**
     * 工作台
     * @param int $uid
     * @param array $countWhere
     * @param int $plat_type
     * @return array
     * @throws \think\db\exception\DbException
     */
    public function getStagingData(int $store_id, int $staff_id = 0, string $time = 'today')
    {
        $where = ['store_id' => $store_id, 'time' => $time];
		$todayWhere = [];
        if ($staff_id) {
			$where['staff_id'] = $staff_id;
        }
        $data = [];
        $order_where = ['pid' => 0, 'paid' => 1, 'refund_status' => [0, 3], 'is_system_del' => 0];
        /** @var BranchOrderServices $orderServices */
        $orderServices = app()->make(BranchOrderServices::class);
        $cashier_price = $orderServices->sum($where + $order_where + ['type' => 106], 'pay_price', true);
        $writeoff_price = $orderServices->sum($where + $order_where + ['type' => 105], 'pay_price', true);
        $send_price = $orderServices->sum($where + $order_where + ['type' => 107], 'pay_price', true);
		$reservation_price = $orderServices->sum($where + $order_where + ['type' => 12], 'pay_price', true);
		$card_price = $orderServices->sum($where + $order_where + ['type' => 11], 'pay_price', true);
        $refund_price = $orderServices->sum($where + ['status' => -3], 'pay_price', true);
        /** @var OtherOrderServices $otherOrder */
        $otherOrder = app()->make(OtherOrderServices::class);
        $svip_price = $otherOrder->sum($where + ['paid' => 1, 'type' => [0, 1, 2, 4]], 'pay_price', true);
        /** @var UserRechargeServices $userRecharge */
        $userRecharge = app()->make(UserRechargeServices::class);
        $recharge_price = $userRecharge->getWhereSumField($where + ['paid' => 1], 'price');

        $todayPrice = $orderServices->sum($where + $order_where + $todayWhere, 'pay_price', true);

        $pid_where = ['pid' => 0, 'paid' => 1, 'is_del' => 0, 'is_system_del' => 0];

        unset($where['time']);
        //待发货
        $staging['unshipped_count'] = (string)$orderServices->count($where + ['status' => 1] + $pid_where);

        /** @var StoreOrderRefundServices $storeOrderRefundServices */
        $storeOrderRefundServices = app()->make(StoreOrderRefundServices::class);
        $refund_where = ['is_cancel' => 0];
        $staging['refunding_count'] = (string)$storeOrderRefundServices->count($where + $refund_where + ['refund_type' => [0, 1, 2, 4, 5]]);
        $staging['refunded_count'] = (string)$storeOrderRefundServices->count($where + $refund_where + ['refund_type' => [3, 6]]);
        $staging['refund_count'] = (string)bcadd($staging['refunding_count'], $staging['refunded_count'], 0);
		$staging['recharge_price'] = $recharge_price;
		$staging['svip_price'] = $svip_price;
        /** @var StoreFinanceFlowServices $flowServices */
        $flowServices = app()->make(StoreFinanceFlowServices::class);
        $staging['rate_price'] = $flowServices->sum(['store_id' => $store_id, 'pm'=>1, 'type' => [5, 6]],'number');
        /** @var StoreProductServices $StoreProductServices */
        $StoreProductServices = app()->make(StoreProductServices::class);
        //已经售馨商品
        $staging['outofstock'] = $StoreProductServices->getCount(['status' => 4, 'type' => 1, 'relation_id' => $store_id]);
        //警戒库存商品
        $store_stock = sys_config('store_stock', 0);
        $staging['policeforce'] = $StoreProductServices->getCount(['status' => 5, 'type' => 1, 'relation_id' => $store_id, 'store_stock' => $store_stock > 0 ? $store_stock : 2]);
        $result['statistics'] = [
            ['title' => '收银订单额', 'value' => $cashier_price, 'key' => '元',],
            ['title' => '核销订单额', 'value' => $writeoff_price, 'key' => '元',],
            ['title' => '配送订单额', 'value' => $send_price, 'key' => '元',],
            ['title' => '退款订单额', 'value' => $refund_price, 'key' => '元',],
			['title' => '预约订单额', 'value' => $reservation_price, 'key' => '元',],
			['title' => '卡项订单额', 'value' => $card_price, 'key' => '元',],
        ];
        $result['staging'] = $staging;
        $result['todayPrice'] = $todayPrice;
        return $result;
    }

    /**
     * 获取门店｜店员统计
     * @param int $store_id
     * @param int $staff_id
     * @param string $time
     * @return array
     */
    public function getStoreData(int $uid, int $store_id, int $staff_id = 0, string $time = 'today')
    {
        $where = ['store_id' => $store_id, 'time' => $time];
        if ($staff_id) {
            $where['staff_id'] = $staff_id;
        }
        $data = [];
        $order_where = ['pid' => 0, 'paid' => 1, 'refund_status' => [0, 3], 'is_system_del' => 0];
        /** @var BranchOrderServices $orderServices */
        $orderServices = app()->make(BranchOrderServices::class);
        $data['price'] = $orderServices->sum($where + $order_where, 'pay_price', true);
        $data['send_price'] = $orderServices->sum($where + $order_where + ['type' => 107], 'pay_price', true);
        $data['send_count'] = $orderServices->count($where + $order_where + ['type' => 107]);
        $data['refund_price'] = $orderServices->sum($where + ['pid' => 0, 'status' => -3], 'pay_price', true);
        $data['cashier_price'] = $orderServices->sum($where + $order_where + ['type' => 106], 'pay_price', true);
        $data['writeoff_price'] = $orderServices->sum($where + $order_where + ['type' => 105], 'pay_price', true);
        /** @var OtherOrderServices $otherOrder */
        $otherOrder = app()->make(OtherOrderServices::class);
        $data['svip_price'] = $otherOrder->sum($where + ['paid' => 1, 'type' => [0, 1, 2, 4]], 'pay_price', true);
        /** @var UserRechargeServices $userRecharge */
        $userRecharge = app()->make(UserRechargeServices::class);
        $data['recharge_price'] = $userRecharge->getWhereSumField($where + ['paid' => 1], 'price');
        /** @var UserSpreadServices $userSpread */
        $userSpread = app()->make(UserSpreadServices::class);
        $data['spread_count'] = $userSpread->count($where + ['timeKey' => 'spread_time']);
        /** @var UserCardServices $userCard */
        $userCard = app()->make(UserCardServices::class);
        $data['card_count'] = $userCard->count($where + ['is_submit' => 1]);
        return $data;
    }

    /**
     * 判断是否是有权限核销的店员
     * @param $uid
     * @return bool
     */
    public function verifyStatus($uid)
    {
        return (bool)$this->dao->getOne(['uid' => $uid, 'status' => 1, 'is_del' => 0, 'verify_status' => 1]);
    }

    /**
     * 获取店员列表
     * @param array $where
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreStaffList(array $where, array $with = [], bool $hideFencheng = false)
    {
        $with = array_merge($with, [
            'workMember' => function ($query) {
                $query->field(['id', 'uid', 'name', 'position', 'qr_code', 'external_position']);
            }
        ]);
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getStoreStaffList($where, '*', $page, $limit, $with);
        if ($list) {
            $allRole = $this->loadRoleMapForStaffList($list);
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            foreach ($list as &$item) {
                $this->enrichStaffListItem($item, $allRole, $userService, $hideFencheng);
            }
            unset($item);
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

    /**
     * 店员列表
     * @param array $where
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreStaffListData(array $where, array $with = [], bool $hideFencheng = false)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getStoreStaffList($where, '*', $page, $limit, $with);
        if ($list) {
            $allRole = $this->loadRoleMapForStaffList($list);
            /** @var StaffFlowingWaterServices $waterService */
            $waterService = app()->make(StaffFlowingWaterServices::class);
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
            foreach ($list as &$item) {
                $this->enrichStaffListItem($item, $allRole, $userService, $hideFencheng);
                $orderData = $waterService->getStaffData($item['id']);
                $item['order_num'] = $orderData['num'];
                $item['order_price'] = $orderData['sum'];
                $item['performance_price'] = $orderData['sum'];
            }
            unset($item);
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }


    /**
     * 不查询总数
     * @param array $where
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreStaff(array $where, array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getStoreStaffList($where, '*', $page, $limit, $with);
        foreach ($list as $key => $item) {
            unset($list[$key]['pwd']);
        }
        return $list;
    }

    /**
     * 店员详情
     * @param int $id
     * @return array|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function read(int $id, bool $hideFencheng = false)
    {
        $staffInfo = $this->getStaffInfo($id);
        if (is_object($staffInfo)) {
            $staffInfo = $staffInfo->toArray();
        }
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
        $staffInfo['nickname'] = $userService->value(['uid' => $staffInfo['uid']], 'nickname');
        $staffInfo = $this->formatStaffRead($staffInfo, $hideFencheng);
        $info = [
            'id' => $id,
            'headerList' => $this->getHeaderList($id, $staffInfo),
            'ps_info' => $staffInfo,
        ];
        return $info;
    }

    /**
     * 获取单个店员统计信息
     * @param $id 用户id
     * @return mixed
     */
    public function staffDetail(int $id, string $type)
    {
        $staffInfo = $this->getStaffInfo($id);
        if (!$staffInfo) {
            throw new AdminException('店员不存在');
        }
        $where = ['store_id' => $staffInfo['store_id'], 'staff_id' => $staffInfo['id']];
        $data = [];
        switch ($type) {
            case 'cashier_order':
                /** @var BranchOrderServices $orderServices */
                $orderServices = app()->make(BranchOrderServices::class);
                $where = array_merge($where, ['pid' => 0, 'type' => 106, 'paid' => 1, 'refund_status' => [0, 3], 'is_del' => 0, 'is_system_del' => 0]);
                $field = ['uid', 'order_id', 'real_name', 'total_num', 'total_price', 'pay_price', 'FROM_UNIXTIME(pay_time,"%Y-%m-%d") as pay_time', 'paid', 'pay_type', 'type', 'activity_id', 'pink_id'];
                $data = $orderServices->getStoreOrderList($where, $field, [], true);
                break;
            case 'self_order':
                /** @var BranchOrderServices $orderServices */
                $orderServices = app()->make(BranchOrderServices::class);
                $where = array_merge($where, ['pid' => 0, 'type' => 107, 'paid' => 1, 'refund_status' => [0, 3], 'is_del' => 0, 'is_system_del' => 0]);
                $field = ['uid', 'order_id', 'real_name', 'total_num', 'total_price', 'pay_price', 'FROM_UNIXTIME(pay_time,"%Y-%m-%d") as pay_time', 'paid', 'pay_type', 'type', 'activity_id', 'pink_id'];
                $data = $orderServices->getStoreOrderList($where, $field, [], true);
                break;
            case 'writeoff_order':
                /** @var BranchOrderServices $orderServices */
                $orderServices = app()->make(BranchOrderServices::class);
                $where = array_merge($where, ['pid' => 0, 'type' => 105, 'paid' => 1, 'refund_status' => [0, 3], 'is_del' => 0, 'is_system_del' => 0]);
                $field = ['uid', 'order_id', 'real_name', 'total_num', 'total_price', 'pay_price', 'FROM_UNIXTIME(pay_time,"%Y-%m-%d") as pay_time', 'paid', 'pay_type', 'type', 'activity_id', 'pink_id'];
                $data = $orderServices->getStoreOrderList($where, $field, [], true);
                break;
            case 'recharge':
                /** @var UserRechargeServices $userRechargeServices */
                $userRechargeServices = app()->make(UserRechargeServices::class);
                $data = $userRechargeServices->getRechargeList($where + ['paid' => 1]);
                break;
            case 'spread':
                /** @var UserSpreadServices $userSpreadServices */
                $userSpreadServices = app()->make(UserSpreadServices::class);
                $data = $userSpreadServices->getSpreadList($where);
                break;
            case 'card':
                /** @var UserCardServices $userCardServices */
                $userCardServices = app()->make(UserCardServices::class);
                $data = $userCardServices->getCardList($where + ['is_submit' => 1]);
                break;
            case 'svip':
                /** @var OtherOrderServices $otherOrderServices */
                $otherOrderServices = app()->make(OtherOrderServices::class);
                $data = $otherOrderServices->getMemberRecord($where);
                break;
            case 'customer':
                /** @var UserServices $userServices */
                $userServices = app()->make(UserServices::class);
                $data = $userServices->getStaffCustomerList(['salesman_id' => $id]);
                break;
            default:
                throw new AdminException('type参数错误');
        }
        return $data;
    }

    /**
     * 店员详情头部信息
     * @param int $id
     * @param array $staffInfo
     * @return array[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getHeaderList(int $id, $staffInfo = [])
    {
        if (!$staffInfo) {
            $staffInfo = $this->dao->get($id);
        }
        $where = ['store_id' => $staffInfo['store_id'], 'staff_id' => $staffInfo['id']];
        /** @var BranchOrderServices $orderServices */
        $orderServices = app()->make(BranchOrderServices::class);
        $cashier_order = $orderServices->sum($where + ['pid' => 0, 'type' => 106, 'paid' => 1, 'refund_status' => 0, 'is_del' => 0, 'is_system_del' => 0], 'pay_price', true);
        $writeoff_order = $orderServices->sum($where + ['pid' => 0, 'type' => 105, 'paid' => 1, 'refund_status' => 0, 'is_del' => 0, 'is_system_del' => 0], 'pay_price', true);
        $self_order = $orderServices->sum($where + ['pid' => 0, 'type' => 107, 'paid' => 1, 'refund_status' => 0, 'is_del' => 0, 'is_system_del' => 0], 'pay_price', true);
        /** @var UserRechargeServices $userRechargeServices */
        $userRechargeServices = app()->make(UserRechargeServices::class);
        $recharge = $userRechargeServices->sum($where + ['paid' => 1], 'price', true);
        /** @var UserSpreadServices $userSpreadServices */
        $userSpreadServices = app()->make(UserSpreadServices::class);
        $spread = $userSpreadServices->count($where);
        /** @var UserCardServices $userCardServices */
        $userCardServices = app()->make(UserCardServices::class);
        $card = $userCardServices->count($where + ['is_submit' => 1]);
        /** @var OtherOrderServices $otherOrderServices */
        $otherOrderServices = app()->make(OtherOrderServices::class);
        $svip = $otherOrderServices->sum($where, 'pay_price', true);
        return [
            [
                'title' => '收银订单',
                'value' => $cashier_order,
                'key' => '元',
            ],
            [
                'title' => '核销订单',
                'value' => $writeoff_order,
                'key' => '元',
            ],
            [
                'title' => '配送订单',
                'value' => $self_order,
                'key' => '元',
            ],
            [
                'title' => '储值订单',
                'value' => $recharge,
                'key' => '元',
            ],
            [
                'title' => '付费会员',
                'value' => $svip,
                'key' => '元',
            ],
            [
                'title' => '推广用户数',
                'value' => $spread,
                'key' => '人',
            ],
            [
                'title' => '激活会员卡',
                'value' => $card,
                'key' => '张',
            ]
        ];
    }

    /**
     * 获取select选择框中的门店列表
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreSelectFormData()
    {
        /** @var SystemStoreServices $service */
        $service = app()->make(SystemStoreServices::class);
        $menus = [];
        foreach ($service->getStore(['status' => 1]) as $menu) {
            $menus[] = ['value' => $menu['id'], 'label' => $menu['name']];
        }
        return $menus;
    }

	/**
	 * 获取核销员表单
	 * @param array $formData
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function createStaffForm(array $formData = [])
    {
        if ($formData) {
            $field[] = Form::frameImage('image', '更换头像', $this->url(config('admin.admin_prefix') . '/widget.images/index', array('fodder' => 'image'), true), $formData['avatar'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]);
        } else {
            $field[] = Form::frameImage('image', '商城用户', $this->url(config('admin.admin_prefix') . '/system.User/list', ['fodder' => 'image'], true))->icon('ios-add')->width('960px')->height('550px')->modal(['footer-hide' => true])->Props(['srcKey' => 'image']);
        }
        $field[] = Form::hidden('uid', $formData['uid'] ?? 0);
        $field[] = Form::hidden('avatar', $formData['avatar'] ?? '');
        $field[] = Form::select('store_id', '所属门店：', ($formData['store_id'] ?? 0))->setOptions($this->getStoreSelectFormData())->filterable(true);
        $field[] = Form::input('staff_name', '核销员名称：', $formData['staff_name'] ?? '')->col(24)->required();
        $field[] = Form::input('phone', '手机号码：', $formData['phone'] ?? '')->col(24)->required();
        $field[] = Form::radio('verify_status', '核销开关：', $formData['verify_status'] ?? 1)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('status', '状态：', $formData['status'] ?? 1)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        return $field;
    }

	/**
	 * 添加核销员表单
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function createForm()
    {
        return create_form('添加核销员', $this->createStaffForm(), $this->url('/merchant/store_staff/save/0'));
    }

	/**
	 * 编辑核销员form表单
	 * @param int $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function updateForm(int $id)
    {
        $storeStaff = $this->dao->get($id);
        if (!$storeStaff) {
            throw new AdminException('没有查到信息,无法修改');
        }
        return create_form('修改核销员', $this->createStaffForm($storeStaff->toArray()), $this->url('/merchant/store_staff/save/' . $id));
    }

    /**
     * 获取门店店员
     * @param $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getStoreAdminList($where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getStoreAdminList($where, $page, $limit);
        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $allRole = $service->getRoleArray(['type' => 1, 'store_id' => $where['store_id']]);
        foreach ($list as &$item) {
            if ($item['roles']) {
                $roles = [];
                foreach ($item['roles'] as $id) {
                    if (isset($allRole[$id])) $roles[] = $allRole[$id];
                }
                if ($roles) {
                    $item['roles'] = implode(',', $roles);
                } else {
                    $item['roles'] = '';
                }
            }
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

	/**
	 * 添加门店管理员
	 * @param int $store_id
	 * @param $level
	 * @return mixed
	 */
    public function createStoreAdminForm(int $store_id, $level)
    {
        $field[] = Form::input('staff_name', '管理员名称：')->col(24)->required();
        $field[] = Form::frameImage('avatar', '管理员头像：', $this->url(config('admin.store_prefix') . '/widget.images/index', ['fodder' => 'avatar'], true))->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]);
        $field[] = Form::input('account', '管理员账号：')->maxlength(35)->required('请填写管理员账号');
        $field[] = Form::input('phone', '手机号码：')->col(24)->required();
        $field[] = Form::input('pwd', '管理员密码：')->type('password')->required('请填写管理员密码');
        $field[] = Form::input('conf_pwd', '确认密码：')->type('password')->required('请输入确认密码');
        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $options = $service->getRoleFormSelect($level, 1, $store_id);
        $roles = [];
        $field[] = Form::select('roles', '管理员身份：', $roles)->setOptions(Form::setOptions($options))->multiple(true)->required('请选择管理员身份');
        $field[] = Form::radio('status', '状态：', 1)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        return create_form('添加门店管理员', $field, $this->url('/system/admin'));
    }

	/**
	 * 修改门店管理员
	 * @param $id
	 * @param $level
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function updateStoreAdminForm($id, $level)
    {
        $adminInfo = $this->dao->get($id);
        if (!$adminInfo) {
            throw new AdminException('门店管理员不存在!');
        }
        if ($adminInfo->is_del) {
            throw new AdminException('门店管理员已经删除');
        }
        $adminInfo = $adminInfo->toArray();
        $field[] = Form::input('staff_name', '门店管理员名称：', $adminInfo['staff_name'])->col(24)->required('请填写门店管理员名称');
        $field[] = Form::frameImage('avatar', '管理员头像：', $this->url(config('admin.store_prefix') . '/widget.images/index', ['fodder' => 'avatar'], true), $adminInfo['avatar'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]);
        $field[] = Form::input('account', '门店管理员账号：', $adminInfo['account'])->maxlength(35)->required('请填写门店管理员账号');
        $field[] = Form::input('phone', '手机号码：', $adminInfo['phone'])->col(24)->required();
        $field[] = Form::input('pwd', '门店管理员密码：')->placeholder('不更改密码请留空')->type('password');
        $field[] = Form::input('conf_pwd', '确认密码：')->placeholder('不更改密码请留空')->type('password');
        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $options = $service->getRoleFormSelect($level, 1, (int)$adminInfo['store_id']);
        $roles = [];
        if ($adminInfo && isset($adminInfo['roles']) && $adminInfo['roles']) {
            foreach ($adminInfo['roles'] as $role) {
                $roles[] = (int)$role;
            }
        }
        $field[] = Form::select('roles', '管理员身份：', $roles)->setOptions(Form::setOptions($options))->multiple(true)->required('请选择门店管理员身份');
        $field[] = Form::radio('status', '状态：', (int)$adminInfo['status'])->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        return create_form('修改门店管理员', $field, $this->url('/system/admin/' . $id), 'put');
    }

	/**
	 * 添加门店店员
	 * @param int $store_id
	 * @param $level
	 * @return mixed
	 */
    public function createStoreStaffForm(int $store_id, $level)
    {
        $field[] = Form::input('staff_name', '店员名称：')->col(24)->required('请输入门店店员名称');
        $field[] = Form::frameImage('image', '商城用户：', $this->url(config('admin.store_prefix') . '/system.User/list', ['fodder' => 'image'], true))->icon('ios-add')->width('960px')->height('450px')->modal(['footer-hide' => true])->Props(['srcKey' => 'image']);
        $field[] = Form::hidden('uid', 0);
        $field[] = Form::hidden('avatar', '');
        $field[] = Form::input('account', '店员账号：')->maxlength(35)->required('请填写门店店员账号');
        $field[] = Form::input('pwd', '店员密码：')->type('password')->required('请填写门店店员密码');
        $field[] = Form::input('conf_pwd', '确认密码：')->type('password')->required('请输入确认密码');
        $field[] = Form::input('phone', '手机号码：')->col(24)->required('请输入手机号');
        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $options = $service->getRoleFormSelect($level, 1, $store_id);
        $roles = [];
        $field[] = Form::select('roles', '店员身份：', $roles)->setOptions(Form::setOptions($options))->multiple(true)->required('请选择店员身份');
        $field[] = Form::radio('is_manager', '是否是店长：', 0)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('order_status', '订单管理：', 1)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('verify_status', '核销开关：', 1)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('is_cashier', '是否是收银员：', 1)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        /** @var WorkMemberServices $workMemberService */
        $workMemberService = app()->make(WorkMemberServices::class);
        $work = 0;
        $workList = $workMemberService->getMemberList(['status' => 1, 'enable' => 1], ['id', 'name', 'qr_code']);
        $field[] = Form::radio('is_customer', '是否是客服：', 1)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']])->appendControl(1, [
            Form::input('customer_phone', '客服手机号码：')->col(24),
            Form::frameImage('customer_url', '客服二维码：', $this->url(config('admin.store_prefix') . '/widget.images/index', ['fodder' => 'customer_url'], true))->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]),
            Form::select('work_member_id', '企微员工：', $work)->setOptions(Form::setOptions($workList))->multiple(false),
        ]);
        $field[] = Form::radio('notify', '通知开关：', 0)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('status', '状态：', 1)->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        return create_form('添加门店店员', $field, $this->url('/staff/staff'));
    }

	/**
	 * 编辑门店店员
	 * @param $id
	 * @param $level
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function updateStoreStaffForm($id, $level)
    {
        $staffInfo = $this->dao->get($id);
        if (!$staffInfo) {
            throw new AdminException('门店店员不存在!');
        }
        if ($staffInfo->is_del) {
            throw new AdminException('门店店员已经删除');
        }
        $field[] = Form::input('staff_name', '店员名称：', $staffInfo['staff_name'])->col(24)->required('请填写门店店员名称');
        if ($staffInfo['uid']) {
            $field[] = Form::frameImage('avatar', '店员头像：', $this->url(config('admin.store_prefix') . '/widget.images/index', ['fodder' => 'avatar'], true), $staffInfo['avatar'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]);
        } else {//没绑定过商城用户
            $field[] = Form::frameImage('image', '商城用户：', $this->url(config('admin.store_prefix') . '/system.User/list', ['fodder' => 'image'], true))->icon('ios-add')->width('960px')->height('450px')->modal(['footer-hide' => true])->Props(['srcKey' => 'image']);
            $field[] = Form::hidden('uid', 0);
            $field[] = Form::hidden('avatar', '');
        }
        $field[] = Form::input('account', '店员账号：', $staffInfo['account'])->maxlength(35)->required('请填写门店店员账号');
        $field[] = Form::input('pwd', '店员密码：')->placeholder('不更改密码请留空')->type('password');
        $field[] = Form::input('conf_pwd', '确认密码：')->placeholder('不更改密码请留空')->type('password');
        $field[] = Form::input('phone', '手机号码：', $staffInfo['phone'])->col(24)->required('请输入手机号');
        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $options = $service->getRoleFormSelect($level, 1, (int)$staffInfo['store_id']);
        $roles = [];
        if ($staffInfo && isset($staffInfo['roles']) && $staffInfo['roles']) {
            foreach ($staffInfo['roles'] as $role) {
                $roles[] = (int)$role;
            }
        }
        $field[] = Form::select('roles', '店员身份：', $roles)->setOptions(Form::setOptions($options))->multiple(true)->required('请选择店员身份');
        $field[] = Form::radio('is_manager', '是否是店长：', (int)$staffInfo['is_manager'])->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('order_status', '订单管理：', (int)$staffInfo['order_status'])->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('verify_status', '核销开关：', (int)$staffInfo['verify_status'])->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('is_cashier', '是否是收银员：', (int)$staffInfo['is_cashier'])->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        /** @var WorkMemberServices $workMemberService */
        $workMemberService = app()->make(WorkMemberServices::class);
        $work = (int)$staffInfo['work_member_id'];
        $workList = $workMemberService->getMemberList(['status' => 1, 'enable' => 1], ['id', 'name', 'qr_code']);
        $field[] = Form::radio('is_customer', '是否是客服：',  (int)$staffInfo['is_customer'])->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']])->appendControl(1, [
            Form::input('customer_phone', '客服手机号码：',$staffInfo['customer_phone'])->col(24),
            Form::frameImage('customer_url', '客服二维码：', $this->url(config('admin.store_prefix') . '/widget.images/index', ['fodder' => 'customer_url'], true), $staffInfo['customer_url'] ?? '')->icon('ios-add')->width('960px')->height('505px')->modal(['footer-hide' => true]),
            Form::select('work_member_id', '企微员工：', $work)->setOptions(Form::setOptions($workList))->multiple(false),
        ]);
        $field[] = Form::radio('notify', '通知开关：', (int)$staffInfo['notify'])->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        $field[] = Form::radio('status', '状态：', (int)$staffInfo['status'])->options([['value' => 1, 'label' => '开启'], ['value' => 0, 'label' => '关闭']]);
        return create_form('编辑门店店员', $field, $this->url('/staff/staff/' . $id), 'put');
    }

    /**
     * 获取店员select
     * @param array $where
     * @return mixed
     */
    public function getSelectList($where = [])
    {
        $list = $this->dao->getSelectList($where);
        $menus = [];
        foreach ($list as $menu) {
            $menus[] = ['value' => $menu['id'], 'label' => $menu['staff_name'] ?? ''];
        }
        return $menus;
    }

    public function geAllList($where = [])
    {
        $list = $this->dao->getChooseList($where,"*");
        return $list;
    }
    /**
     * 首页店员统计
     * @param int $store_id
     * @param array $time
     * @return array
     */
    public function staffChart(int $store_id, array $time)
    {
        $list = $this->dao->getStoreStaffList(['store_id' => $store_id, 'is_del' => 0], 'id,uid,avatar,staff_name');
        if ($list) {
            /** @var UserSpreadServices $userSpreadServices */
            $userSpreadServices = app()->make(UserSpreadServices::class);
            /** @var BranchOrderServices $orderServices */
            $orderServices = app()->make(BranchOrderServices::class);
            /** @var OtherOrderServices $otherOrderServices */
            $otherOrderServices = app()->make(OtherOrderServices::class);
            $where = ['store_id' => $store_id, 'time' => $time];
            $order_where = ['paid' => 1, 'pid' => 0, 'is_del' => 0, 'is_system_del' => 0, 'refund_status' => [0, 3]];
            $staffIds = array_unique(array_column($list, 'id'));
            $otherStaff = $otherOrderServices->preStaffTotal($where + ['staff_id' => $staffIds, 'paid' => 1, 'type' => [0, 1, 2, 4]], 'distinct(`uid`)');
            $otherStaff = array_combine(array_column($otherStaff, 'staff_id'), $otherStaff);
            foreach ($list as &$item) {
                $staff_where = ['staff_id' => $item['id']];
                $spread_uid = $userSpreadServices->getColumn($where + ['timeKey' => 'spread_time'] + $staff_where, 'uid', '', true);
                $item['spread_count'] = count($spread_uid);
                $item['speread_order_price'] = 0;
                if ($spread_uid) {
                    $item['speread_order_price'] = $orderServices->sum($where + $order_where + ['uid' => $spread_uid], 'pay_price', true);
                }
                $item['vip_count'] = $otherStaff[$item['id']]['count'] ?? 0;
                $item['vip_price'] = $otherStaff[$item['id']]['price'] ?? 0;
                unset($spread);
            }
        }
        return $list;
    }

    /**
     * 修改当前店员信息
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function updateStaffPwd(int $id, array $data)
    {
        $staffInfo = $this->dao->get($id);
        if (!$staffInfo)
            throw new AdminException('店员信息未查到');
        if ($staffInfo->is_del) {
            throw new AdminException('店员已经删除');
        }
        if ($data['real_name']) {
            $staffInfo->staff_name = $data['real_name'];
        }
        if ($data['avatar']) {
            $staffInfo->avatar = $data['avatar'];
        }
        if ($data['pwd']) {
            if (!password_verify($data['pwd'], $staffInfo['pwd']))
                throw new AdminException('原始密码错误');
            if (!$data['new_pwd'])
                throw new AdminException('请输入新密码');
            if (!$data['conf_pwd'])
                throw new AdminException('请输入确认密码');
            if ($data['new_pwd'] != $data['conf_pwd'])
                throw new AdminException('两次输入的密码不一致');
            $staffInfo->pwd = $this->passwordHash($data['new_pwd']);
        }
        if ($staffInfo->save())
            return true;
        else
            return false;
    }

    /**
     * 获取门店接收通知店员
     * @param int $store_id
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getNotifyStoreStaffList(int $store_id, string $field = '*')
    {
        $where = [
            'store_id' => $store_id,
            'status' => 1,
            'is_del' => 0,
            'notify' => 1
        ];
        $list = $this->dao->getStoreStaffList($where, $field);
        return $list;
    }

    /**
     * 预约选人：按门店加载可被预约的员工（不做距离筛选）
     * @param int $storeId
     * @param array $params keyword、service_date
     * @return array
     */
    public function getReservationStaffList(int $storeId, array $params = []): array
    {
        if (!$storeId) {
            throw new ValidateException('请选择门店');
        }
        $keyword = trim((string)($params['keyword'] ?? ''));
        $serviceDate = trim((string)($params['service_date'] ?? ''));
        $serviceTime = trim((string)($params['service_time'] ?? ''));
        if (!$serviceTime) {
            $serviceTime = trim((string)($params['reservation_start'] ?? ''));
        }
        $serviceDuration = (int)($params['service_duration'] ?? 0);
        $excludeReservationId = (int)($params['exclude_reservation_id'] ?? 0);
        if (!$serviceDate) {
            $serviceDate = date('Y-m-d');
        }
        /** @var StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(StoreStaffScheduleServices::class);
        /** @var StoreReservationStaffServices $reservationStaffServices */
        $reservationStaffServices = app()->make(StoreReservationStaffServices::class);
        if ($scheduleServices->isScheduleManageEnabled()) {
            $boardStaff = $scheduleServices->getReservationBoardStaff($storeId, $serviceDate);
            if (!$boardStaff) {
                return [];
            }
            $staffIds = array_column($boardStaff, 'id');
            $where = ['store_id' => $storeId, 'status' => 1, 'is_del' => 0];
            if ($keyword) {
                $where['keyword'] = $keyword;
            }
            $staffIdSet = array_flip($staffIds);
            $list = array_values(array_filter(
                $this->dao->getStoreStaffList($where, 'id,uid,staff_name,phone,avatar,is_reservable,position,position_level,age,birthday_date', 0, 0, []),
                function ($item) use ($staffIdSet) {
                    return isset($staffIdSet[(int)($item['id'] ?? 0)]) && (int)($item['is_reservable'] ?? 1) === 1;
                }
            ));
            $orderMap = array_flip($staffIds);
            usort($list, function ($a, $b) use ($orderMap) {
                return ($orderMap[$a['id']] ?? 999) <=> ($orderMap[$b['id']] ?? 999);
            });
            foreach ($list as &$item) {
                $item['staff_id'] = (int)$item['id'];
            }
            unset($item);
        } else {
        $where = ['store_id' => $storeId, 'status' => 1, 'is_del' => 0, 'is_reservable' => 1];
        if ($keyword) {
            $where['keyword'] = $keyword;
        }
        $list = $this->dao->getStoreStaffList($where, 'id,uid,staff_name,phone,avatar,is_reservable,position,position_level,age,birthday_date', 0, 0, []);
        foreach ($list as &$item) {
            $item['staff_id'] = (int)$item['id'];
        }
        unset($item);
        }
        if ($serviceTime && $list) {
            $timeStr = strlen($serviceTime) <= 5 ? $serviceTime . ':00' : $serviceTime;
            $appointmentTs = strtotime($serviceDate . ' ' . $timeStr);
            if ($appointmentTs) {
                if ($serviceDuration <= 0) {
                    $serviceDuration = 120;
                }
                $list = array_values(array_filter($list, function ($item) use (
                    $reservationStaffServices,
                    $storeId,
                    $serviceDate,
                    $serviceTime,
                    $serviceDuration,
                    $excludeReservationId
                ) {
                    $staffId = (int)($item['id'] ?? 0);
                    if (!$staffId || trim((string)($item['staff_name'] ?? '')) === '') {
                        return false;
                    }
                    return $reservationStaffServices->isStaffSelectableForReservation(
                        $staffId,
                        $storeId,
                        $serviceDate,
                        $serviceTime,
                        $serviceDuration,
                        $excludeReservationId
                    );
                }));
            }
        } else {
            $list = array_values(array_filter($list, function ($item) use ($reservationStaffServices) {
                $staffId = (int)($item['id'] ?? 0);
                if (!$staffId || trim((string)($item['staff_name'] ?? '')) === '') {
                    return false;
                }
                return $reservationStaffServices->isStaffOnDuty($staffId);
            }));
        }
        $this->enrichReservationStaffListItems($list, $storeId, [
            'service_date' => $serviceDate,
            'service_time' => $serviceTime,
            'service_duration' => $serviceDuration,
            'exclude_reservation_id' => $excludeReservationId,
        ]);
        return $list;
    }

    /**
     * 预约老师列表：补充展示字段
     */
    protected function enrichReservationStaffListItems(array &$list, int $storeId, array $params = []): void
    {
        if (!$list) {
            return;
        }
        $serviceDate = trim((string)($params['service_date'] ?? '')) ?: date('Y-m-d');
        $serviceTime = trim((string)($params['service_time'] ?? ''));
        if (!$serviceTime) {
            $serviceTime = trim((string)($params['reservation_start'] ?? ''));
        }
        $serviceDuration = (int)($params['service_duration'] ?? 0);
        $excludeReservationId = (int)($params['exclude_reservation_id'] ?? 0);
        if ($serviceDuration <= 0) {
            $serviceDuration = 120;
        }
        if ($serviceTime) {
            $timeStr = strlen($serviceTime) <= 5 ? $serviceTime . ':00' : $serviceTime;
            $appointmentTs = strtotime($serviceDate . ' ' . $timeStr) ?: time();
        } else {
            $appointmentTs = time();
        }
        /** @var StoreReservationStaffServices $reservationStaffServices */
        $reservationStaffServices = app()->make(StoreReservationStaffServices::class);
        $positionMap = Position::column('name', 'id');
        $levelMap = PositionLevel::column('name', 'id');
        $staffIds = array_map('intval', array_column($list, 'id'));
        $orderCountMap = [];
        if ($staffIds) {
            $rows = \app\model\order\StoreReservationOrder::whereIn('service_staff_id', $staffIds)
                ->where('store_id', $storeId)
                ->where('status', 2)
                ->where('is_del', 0)
                ->field('service_staff_id, count(*) as cnt')
                ->group('service_staff_id')
                ->select();
            foreach ($rows as $row) {
                $row = is_object($row) ? $row->toArray() : (array)$row;
                $orderCountMap[(int)($row['service_staff_id'] ?? 0)] = (int)($row['cnt'] ?? 0);
            }
        }
        $uids = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'uid')))));
        $sexMap = $uids ? \app\model\user\User::whereIn('uid', $uids)->column('sex', 'uid') : [];
        /** @var UserServices $userService */
        $userService = app()->make(UserServices::class);
        foreach ($list as &$item) {
            $staffId = (int)($item['id'] ?? 0);
            $item['position_label'] = $positionMap[(int)($item['position'] ?? 0)] ?? '';
            $item['position_level_label'] = $levelMap[(int)($item['position_level'] ?? 0)] ?? '初级';
            $item['order_count'] = $orderCountMap[$staffId] ?? 0;
            $item['follow_count'] = (int)$userService->getCount(['salesman_id' => $staffId]);
            $item['star_level'] = 4;
            $item['sex'] = (int)($sexMap[(int)($item['uid'] ?? 0)] ?? 0);
            $item['age'] = (int)($item['age'] ?? 0);
            $available = $reservationStaffServices->isStaffSelectableForReservation(
                $staffId,
                $storeId,
                $serviceDate,
                $serviceTime,
                $serviceDuration,
                $excludeReservationId
            );
            $item['service_available'] = $available ? 1 : 0;
        }
        unset($item);
    }

    /**
     * 老师中心信息
     */
    public function getTeacherCenterInfo(array $staffInfo): array
    {
        if (!$staffInfo) {
            throw new ValidateException('非门店员工');
        }
        $storeId = (int)($staffInfo['store_id'] ?? 0);
        $staffId = (int)($staffInfo['id'] ?? 0);
        $storeName = '';
        if ($storeId) {
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $storeName = (string)$storeServices->value(['id' => $storeId], 'name');
        }
        return [
            'id' => $staffId,
            'staff_name' => (string)($staffInfo['staff_name'] ?? ''),
            'phone' => (string)($staffInfo['phone'] ?? ''),
            'avatar' => (string)($staffInfo['avatar'] ?? ''),
            'store_id' => $storeId,
            'store_name' => $storeName,
            'is_reservable' => (int)($staffInfo['is_reservable'] ?? 1),
            'off_work_time' => (string)($staffInfo['off_work_time'] ?? ''),
            'staff_intro' => (string)($staffInfo['staff_intro'] ?? ''),
            'is_manager' => (int)($staffInfo['is_manager'] ?? 0),
            'is_butler' => (int)($staffInfo['is_butler'] ?? 0),
        ];
    }

    /**
     * 更新老师中心资料
     */
    public function updateTeacherProfile(int $staffId, int $storeId, array $data): bool
    {
        $staff = $this->dao->getOne(['id' => $staffId, 'store_id' => $storeId, 'is_del' => 0]);
        if (!$staff) {
            throw new ValidateException('员工不存在');
        }
        $update = [];
        if (array_key_exists('is_reservable', $data)) {
            $update['is_reservable'] = (int)$data['is_reservable'] ? 1 : 0;
        }
        if (array_key_exists('off_work_time', $data)) {
            $time = trim((string)$data['off_work_time']);
            if ($time && !preg_match('/^\d{2}:\d{2}$/', $time)) {
                throw new ValidateException('下班时间格式错误');
            }
            $update['off_work_time'] = $time;
        }
        if (array_key_exists('staff_intro', $data)) {
            $update['staff_intro'] = mb_substr(trim((string)$data['staff_intro']), 0, 500);
        }
        if (!$update) {
            return true;
        }
        return (bool)$this->dao->update($staffId, $update);
    }

    /**
     * 老师休息可选班次（门店班次 + 固定「整天」）
     */
    public function getTeacherRestShiftOptions(int $storeId): array
    {
        /** @var StoreStaffShiftServices $shiftServices */
        $shiftServices = app()->make(StoreStaffShiftServices::class);
        $options = [];
        foreach ($shiftServices->getList($storeId) as $shift) {
            $start = trim((string)($shift['start_time'] ?? ''));
            $end = trim((string)($shift['end_time'] ?? ''));
            $label = trim((string)($shift['name'] ?? ''));
            if ($start && $end) {
                $label = $label !== '' ? ($label . '（' . $start . '-' . $end . '）') : ($start . '-' . $end);
            }
            $options[] = [
                'shift_id' => (int)($shift['id'] ?? 0),
                'name' => (string)($shift['name'] ?? ''),
                'label' => $label ?: ('班次' . (int)($shift['id'] ?? 0)),
                'start_time' => $start,
                'end_time' => $end,
                'is_full_day' => 0,
            ];
        }
        $options[] = [
            'shift_id' => 0,
            'name' => '整天',
            'label' => '整天',
            'start_time' => '',
            'end_time' => '',
            'is_full_day' => 1,
        ];
        return $options;
    }

    /**
     * 老师休息列表
     */
    public function getTeacherRestList(int $storeId, int $staffId, int $page, int $limit): array
    {
        /** @var StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(StoreStaffScheduleServices::class);
        /** @var \app\dao\store\StoreStaffScheduleDao $scheduleDao */
        $scheduleDao = app()->make(\app\dao\store\StoreStaffScheduleDao::class);
        $query = \think\facade\Db::name('store_staff_schedule')
            ->where('store_id', $storeId)
            ->where('staff_id', $staffId)
            ->where('schedule_type', 0)
            ->order('schedule_date desc,id desc');
        $count = (int)(clone $query)->count();
        $list = $query->page($page, $limit)->select()->toArray();
        $shiftIds = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'shift_id')))));
        $shiftNameMap = $shiftIds
            ? \think\facade\Db::name('store_staff_shift')->whereIn('id', $shiftIds)->where('is_del', 0)->column('name', 'id')
            : [];
        $legacyShiftLabels = ['', '早班', '晚班', '整天', '当前至晚间'];
        foreach ($list as &$row) {
            $row['date'] = (string)($row['schedule_date'] ?? '');
            $shiftId = (int)($row['shift_id'] ?? 0);
            $startTime = trim((string)($row['start_time'] ?? ''));
            $endTime = trim((string)($row['end_time'] ?? ''));
            if (!$startTime && !$endTime) {
                $row['type'] = 0;
                $row['type_txt'] = '整天';
            } elseif ($shiftId && isset($shiftNameMap[$shiftId])) {
                $row['type'] = $shiftId;
                $label = (string)$shiftNameMap[$shiftId];
                if ($startTime && $endTime) {
                    $label .= '（' . $startTime . '-' . $endTime . '）';
                }
                $row['type_txt'] = $label;
            } else {
                $type = $shiftId;
                if (!$type) {
                    if ($startTime && $endTime && $startTime <= '12:00' && $endTime <= '14:30') {
                        $type = 1;
                    } elseif ($endTime === '22:00' && $startTime > '12:00') {
                        $type = in_array($startTime, ['14:00', '15:00'], true) ? 2 : 4;
                    } elseif ($startTime || $endTime) {
                        $type = 2;
                    } else {
                        $type = 3;
                    }
                }
                $row['type'] = $type;
                $row['type_txt'] = $legacyShiftLabels[$type] ?? '休息';
                if ($startTime && $endTime && $row['type_txt'] !== '整天') {
                    $row['type_txt'] .= '（' . $startTime . '-' . $endTime . '）';
                }
            }
            $row['remark'] = (string)($row['mark'] ?? '');
        }
        unset($row);
        return compact('list', 'count');
    }

    /**
     * 添加老师休息
     */
    public function saveTeacherRest(int $storeId, int $staffId, array $data): bool
    {
        $date = trim((string)($data['date'] ?? $data['schedule_date'] ?? ''));
        $shiftId = (int)($data['shift_id'] ?? 0);
        $isFullDay = (int)($data['is_full_day'] ?? 0);
        $type = (int)($data['type'] ?? 0);
        $remark = trim((string)($data['remark'] ?? $data['mark'] ?? ''));
        if (!$date) {
            throw new ValidateException('请选择休息日期');
        }
        $startTime = '';
        $endTime = '';
        $saveShiftId = 0;
        if ($isFullDay === 1 || $type === 3) {
            $saveShiftId = 0;
        } elseif ($shiftId > 0) {
            /** @var StoreStaffShiftServices $shiftServices */
            $shiftServices = app()->make(StoreStaffShiftServices::class);
            $shiftMap = $shiftServices->getShiftMap($storeId);
            if (!isset($shiftMap[$shiftId])) {
                throw new ValidateException('休息班次不存在，请重新选择');
            }
            $shift = $shiftMap[$shiftId];
            $startTime = trim((string)($shift['start_time'] ?? ''));
            $endTime = trim((string)($shift['end_time'] ?? ''));
            if ($startTime === '' || $endTime === '') {
                throw new ValidateException('班次时间配置不完整');
            }
            $saveShiftId = $shiftId;
        } elseif (in_array($type, [1, 2, 4], true)) {
            if ($type === 1) {
                $startTime = '09:00';
                $endTime = '14:00';
            } elseif ($type === 2) {
                $startTime = '14:00';
                $endTime = '22:00';
            } else {
                $startTime = date('H:i');
                $endTime = '22:00';
            }
            $saveShiftId = $type;
        } else {
            throw new ValidateException('请选择休息班次');
        }
        /** @var StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(StoreStaffScheduleServices::class);
        return $scheduleServices->saveSchedule($storeId, [
            'staff_id' => $staffId,
            'schedule_date' => $date,
            'schedule_type' => 0,
            'shift_id' => $saveShiftId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'mark' => $remark,
        ]);
    }

    /**
     * 删除老师休息
     */
    public function deleteTeacherRest(int $storeId, int $staffId, int $id): bool
    {
        /** @var StoreStaffScheduleServices $scheduleServices */
        $scheduleServices = app()->make(StoreStaffScheduleServices::class);
        $row = \think\facade\Db::name('store_staff_schedule')->where('id', $id)->find();
        if (!$row || (int)$row['store_id'] !== $storeId || (int)$row['staff_id'] !== $staffId) {
            throw new ValidateException('记录不存在');
        }
        return $scheduleServices->deleteSchedule($storeId, $id);
    }

    /**
     * 规范化店员默认头像（写入前）
     * 默认男/女头像统一存相对路径，避免 request()->domain() 缺端口导致列表裂图
     */
    public function normalizeStaffAvatar(array &$data): void
    {
        $avatar = trim((string)($data['avatar'] ?? ''));
        if ($avatar === '') {
            $data['avatar'] = '/static/images/staff/avatar_male.png';
            return;
        }
        if (preg_match('#/static/images/staff/avatar_(male|female)\.(svg|png)(?:\?|$)#i', $avatar, $m)) {
            $data['avatar'] = '/static/images/staff/avatar_' . strtolower($m[1]) . '.png';
        }
    }

    /**
     * 规范化店员日期字段（写入前）
     */
    public function normalizeStaffDates(array &$data): void
    {
        foreach (['join_date', 'birthday_date', 'contract_begin', 'contract_end'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                $data[$field] = null;
                continue;
            }
            if (is_numeric($value)) {
                $year = (int)date('Y', (int)$value);
                if ($year <= 1899) {
                    $data[$field] = null;
                }
                continue;
            }
            $str = trim((string)$value);
            if ($str === '') {
                $data[$field] = null;
                continue;
            }
            $year = (int)substr($str, 0, 4);
            if ($year <= 1899) {
                $data[$field] = null;
            }
        }
    }

    /**
     * 无效日期返回空字符串（读取展示）
     */
    public function formatInvalidDate($value): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return '';
        }
        if (is_numeric($value)) {
            $year = (int)date('Y', (int)$value);
            return $year <= 1899 ? '' : date('Y-m-d', (int)$value);
        }
        $str = trim((string)$value);
        if ($str === '') {
            return '';
        }
        $year = (int)substr($str, 0, 4);
        return $year <= 1899 ? '' : $str;
    }

    /**
     * 手机号全局唯一校验
     */
    public function assertPhoneUnique(string $phone, int $excludeId = 0): void
    {
        $phone = trim($phone);
        if ($phone === '') {
            return;
        }
        $staff = $this->dao->getOne(['phone' => $phone, 'is_del' => 0]);
        if (!$staff) {
            return;
        }
        $staffId = is_object($staff) ? (int)$staff->id : (int)($staff['id'] ?? 0);
        if ($excludeId > 0 && $staffId === $excludeId) {
            return;
        }
        $row = is_object($staff) ? $staff->toArray() : (array)$staff;
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $storeName = (string)$storeServices->value(['id' => (int)($row['store_id'] ?? 0)], 'name');
        $staffName = (string)($row['staff_name'] ?? '');
        $addTime = !empty($row['add_time']) ? date('Y-m-d H:i:s', is_numeric($row['add_time']) ? (int)$row['add_time'] : strtotime((string)$row['add_time'])) : '';
        throw new AdminException('该手机号已存在于【' . $storeName . '】，店员名称【' . $staffName . '】，创建时间【' . $addTime . '】。');
    }

    /**
     * 账号全局唯一校验（非空账号）
     */
    public function assertAccountUnique(string $account, int $excludeId = 0): void
    {
        $account = trim($account);
        if ($account === '') {
            return;
        }
        $query = $this->dao->getWhere()->where('account', $account)->where('is_del', 0);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }
        $staff = $query->find();
        if (!$staff) {
            return;
        }
        $staffId = is_object($staff) ? (int)$staff->id : (int)($staff['id'] ?? 0);
        $row = is_object($staff) ? $staff->toArray() : (array)$staff;
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $storeName = (string)$storeServices->value(['id' => (int)($row['store_id'] ?? 0)], 'name');
        $staffName = (string)($row['staff_name'] ?? '');
        throw new AdminException('该员工账号已经存在于【' . $storeName . '】，店员名称【' . $staffName . '】');
    }

    /**
     * 列表项字段增强
     */
    public function enrichStaffListItem(array &$item, array $allRole = [], $userService = null, bool $hideFencheng = false): void
    {
        $item['has_pwd'] = !empty($item['pwd']) ? 1 : 0;
        unset($item['pwd']);
        $item['position_label'] = Position::where('id', $item['position'] ?? 0)->value('name') ?: '-';
        $item['position_level_label'] = PositionLevel::where('id', $item['position_level'] ?? 0)->value('name') ?: '-';
        if ($item['level']) {
            if (!empty($item['roles'])) {
                $roles = [];
                foreach ($item['roles'] as $roleId) {
                    if (isset($allRole[$roleId])) {
                        $roles[] = $allRole[$roleId];
                    }
                }
                $item['roles'] = $roles ? implode(',', $roles) : '-';
            } else {
                $item['roles'] = '-';
            }
        } else {
            $item['roles'] = '超级管理员';
        }
        if (!$userService) {
            /** @var UserServices $userService */
            $userService = app()->make(UserServices::class);
        }
        if (empty($item['nickname']) && !empty($item['uid'])) {
            $item['nickname'] = $userService->value(['uid' => $item['uid']], 'nickname') ?: '';
        }
        $item['customer_num'] = $userService->getCount(['salesman_id' => $item['id']]);
        if (empty($item['name']) && !empty($item['store_id'])) {
            /** @var SystemStoreServices $storeServices */
            $storeServices = app()->make(SystemStoreServices::class);
            $item['name'] = (string)$storeServices->value(['id' => (int)$item['store_id']], 'name');
        }
        $item['store_name'] = $item['name'] ?? '-';
        $item['is_fencheng'] = (int)($item['is_fencheng'] ?? 0);
        if ($hideFencheng) {
            unset($item['is_fencheng']);
        }
        foreach (['join_date', 'birthday_date', 'contract_begin', 'contract_end'] as $dateField) {
            if (array_key_exists($dateField, $item)) {
                $formatted = $this->formatInvalidDate($item[$dateField]);
                $item[$dateField] = $formatted !== '' ? $formatted : '-';
            }
        }
    }

    /**
     * 详情读取格式化
     */
    public function formatStaffRead(array $staffInfo, bool $hideFencheng = false): array
    {
        $staffInfo['has_pwd'] = !empty($staffInfo['pwd']) ? 1 : 0;
        unset($staffInfo['pwd']);
        $staffInfo['is_fencheng'] = (int)($staffInfo['is_fencheng'] ?? 0);
        if ($hideFencheng) {
            unset($staffInfo['is_fencheng']);
        }
        foreach (['join_date', 'birthday_date', 'contract_begin', 'contract_end'] as $dateField) {
            if (array_key_exists($dateField, $staffInfo)) {
                $staffInfo[$dateField] = $this->formatInvalidDate($staffInfo[$dateField]);
            }
        }
        return $staffInfo;
    }

    /**
     * 根据角色计算 order_status / is_cashier
     */
    public function applyRolesFlags(array &$data): void
    {
        $data['order_status'] = 0;
        $data['is_cashier'] = 0;
        if (empty($data['roles']) || !is_array($data['roles'])) {
            return;
        }
        /** @var SystemRoleServices $systemRoleService */
        $systemRoleService = app()->make(SystemRoleServices::class);
        $roles = $systemRoleService->getColumn(['id' => $data['roles']], '*');
        if (!$roles) {
            return;
        }
        foreach ($roles as $role) {
            if (!empty($role['mall_rules'])) {
                $data['order_status'] = 1;
                break;
            }
            if (!empty($role['cashier_rules'])) {
                $data['is_cashier'] = 1;
            }
        }
    }

    /**
     * 加载列表涉及门店的角色映射
     */
    protected function loadRoleMapForStaffList(array $list): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'store_id')))));
        if (!$storeIds) {
            return [];
        }
        /** @var SystemRoleServices $service */
        $service = app()->make(SystemRoleServices::class);
        $allRole = [];
        foreach ($storeIds as $storeId) {
            $roles = $service->getRoleArray(['type' => 1, 'store_id' => $storeId, 'status' => 1]);
            if ($roles) {
                $allRole = array_merge($allRole, $roles);
            }
        }
        return $allRole;
    }

}
