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
namespace app\controller\api\store\user;

use app\model\store\SystemStore;
use app\Request;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\order\StoreOrderServices;
use app\services\store\StoreUserServices;
use app\services\store\SystemStoreStaffServices;
use app\services\user\group\UserGroupServices;
use app\services\user\label\UserLabelCateServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\UserServices;
use think\Response;

/**
 *
 * Class User
 * @package app\controller\api\store\user
 */
class User
{
	protected $services;


	/**
	 * @var int
	 */
	protected $uid;
	/**
	 * 门店店员信息
	 * @var array
	 */
	protected $staffInfo;
	/**
	 * 门店id
	 * @var int|mixed
	 */
	protected $store_id;

	/**
	 * 门店店员ID
	 * @var int|mixed
	 */
	protected $staff_id;

	/**
	 * 构造方法
	 * User constructor.
	 * @param UserServices $services
	 */
	public function __construct(UserServices $services, Request $request)
	{
		$this->services = $services;
		$this->uid = (int)$request->uid();
		$this->getStaffInfo();
	}

	/**
	 * @return void
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	protected function getStaffInfo()
	{
		try {
			/** @var SystemStoreStaffServices $staffServices */
			$staffServices = app()->make(SystemStoreStaffServices::class);
			$staffInfo = $staffServices->getStaffInfoByUid($this->uid)->toArray();
		} catch (\Throwable $e) {
			$staffInfo = [];
		}
		$this->staffInfo = $staffInfo;
		$this->store_id = (int)($this->staffInfo['store_id'] ?? 0);
		$this->staff_id = (int)($this->staffInfo['id'] ?? 0);
	}


    public function list(Request $request): Response
    {
        $where = $request->getMore([
            ['page', 1],
            ['limit', 20],
            ['nickname', ''],
            ['status', ''],
            ['pay_count', ''],
            ['is_promoter', ''],
            ['order', ''],
            ['data', ''],
            ['birthday_type', ''],
            ['user_type', ''],
            ['country', ''],
            ['province', ''],
            ['city', ''],
            ['user_time_type', ''],
            ['user_time', ''],
            ['sex', ''],
            [['level', 0], 0],
            [['group_id', 'd'], 0],
            [['label_id', 'd'], 0],
            ['now_money', 'normal'],
            ['field_key', ''],
            ['isMember', ''],
            ['label_ids', '']
        ]);
        if ($where['label_ids']) {
            $where['label_id'] = stringToIntArray($where['label_ids']);
            unset($where['label_ids']);
        }
        $where['user_time_type'] = $where['user_time_type'] == 'all' ? '' : $where['user_time_type'];

		if ($where['nickname']) {
            $storeId=SystemStore::where("name",$where['nickname'])->value("id");
            if(!empty($storeId)){
                 $where['store_id']=$storeId;
                 $where['nickname']="";
            }
			$where['is_filter_del'] = 1;
			$data = $this->services->index($where);
		} else {
			$storeUserServices = app()->make(StoreUserServices::class);
			$data = $storeUserServices->index($where, $this->store_id);
		}
        return app('json')->success($data);
    }

	/**
	 * 用户详情
	 * @param $id
	 * @return Response
	 */
    public function info($uid)
    {
        if (!$uid) {
            return app('json')->fail('用户id不能为空');
        }
        return app('json')->success($this->services->manageRead((int)$uid));
    }

	/**
	 * 获取用户标签
	 * @param $uid
	 * @param UserLabelCateServices $services
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function userLabel($uid, UserLabelCateServices $services)
    {
        return app('json')->success($services->getUserLabel((int)$uid));
    }

	/**
	 * 分组
	 * @param UserGroupServices $services
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function userGroup(UserGroupServices $services)
    {
        return app('json')->success($services->getGroupList('*'));
    }

	/**
	 * 等级
	 * @param SystemUserLevelServices $services
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function userLevel(SystemUserLevelServices $services)
    {
        return app('json')->success($services->getLevelList(['is_show' => 1, 'is_del' => 0], 'id,name,grade,image,icon'));
    }

	/**
	 * 优惠券列表
	 * @param Request $request
	 * @param StoreCouponIssueServices $services
	 * @param StoreCouponUserServices $storeCouponUserServices
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function couponGrant(Request $request, StoreCouponIssueServices $services, StoreCouponUserServices $storeCouponUserServices)
    {
        [$coupon_title, $uid] = $request->getMore([
            ['coupon_title', ''],
            ['uid', 0],
        ],true);
        if ($uid == 0) {
            $where['receive'] = 'send';
            $where['coupon_title'] = $coupon_title;
            $data = $services->getCouponIssueList($where);
        } else {
            $data = $storeCouponUserServices->getUserCouponList($uid, 0);
        }
        return app('json')->success($data);
    }

    //订单记录
    public function orderList(Request $request){
          $data=$request->getMore([
              ['uid', 0],
              ['show_type', 0],
          ]);
         $where['uid']=$data['uid'];
         $where['is_system_del'] = 0;
         $where['pid'] = -2;
         $where['not_recharge'] = 1;
         $where['plat_type'] = 1;//门店订单
         if($data['show_type'] == 1) {
             //订单记录
             $where['not_auto'] = 1;
         }else{
             $where['link_type']=2;
         }
         $where['is_refund']=0;
        $service = app()->make(StoreOrderServices::class);
        $list = $service->getOrderList($where);
        return app('json')->success($list['data']);
    }

    public function saveUser(Request $request){
        $data=$request->getMore([
            ['uid', 0],
            ['real_name', ''],
            ['birthday', ''],
            ['sex', ''],
            ['addres', ''],
            ['mark', '']
        ]);
        $uid=$data['uid'] ?? 0;
        unset($data['uid']);
        $user=\app\model\user\User::where("uid",$uid)->find();
        if(empty($user)){
            return app('json')->fail('用户不存在');
        }
        if(!empty($data['birthday'])){
             $data['birthday']=strtotime($data['birthday']);
        }else{
             $data['birthday']=0;
        }
        \app\model\user\User::where("uid",$uid)->update($data);
        return app('json')->success("保存成功！");
    }
}
