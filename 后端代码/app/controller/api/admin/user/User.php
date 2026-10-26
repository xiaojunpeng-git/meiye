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
namespace app\controller\api\admin\user;

use app\jobs\BatchHandleJob;
use app\Request;
use app\services\activity\coupon\StoreCouponIssueServices;
use app\services\activity\coupon\StoreCouponUserServices;
use app\services\other\queue\QueueServices;
use app\services\user\group\UserGroupServices;
use app\services\user\label\UserLabelCateServices;
use app\services\user\label\UserLabelRelationServices;
use app\services\user\level\SystemUserLevelServices;
use app\services\user\level\UserLevelServices;
use app\services\user\UserBatchProcessServices;
use app\services\user\UserServices;
use think\Response;

/**
 * 用户类
 * Class StoreProduct
 * @package app\api\controller\store
 */
class User
{
	protected $services;

	public function __construct(UserServices $services)
	{
		$this->services = $services;
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
		$where['is_filter_del'] = 1;
        return app('json')->success($this->services->index($where));
    }

	/**
	 * 用户详情
	 * @param $id
	 * @return Response
	 */
    public function info($uid)
    {
        if (!$uid) {
            return app('json')->fail('用户uid不能为空');
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

	/**
	 * 修改余额积分
	 * @param Request $request
	 * @param $uid
	 * @return Response
	 */
    public function updateOther(Request $request, $uid)
    {
        $data = $request->postMore([
            ['status', 0],
            ['number', 0],
            ['type', 1]//1余额 2积分
        ]);
        if (!$uid) return app('json')->fail('数据不存在');
        $data['adminId'] = (int)$request->uid();
        $data['is_other'] = true;
        $type = $data['type'];
        unset($data['type']);

        $data['money'] = $data['integration'] = $data['money_status'] = $data['integration_status'] = 0;
        if ($type == 1) {
            $data['money'] = (string)$data['number'];
            $data['money_status'] = $data['status'];
        } else {
            $data['integration'] = (string)$data['number'];
            $data['integration_status'] = $data['status'];
        }
        return app('json')->success($this->services->updateInfo($uid, $data) ? '修改成功' : '修改失败');
    }

	/**
	 * 编辑用户
	 * @param Request $request
	 * @param UserBatchProcessServices $batchProcessServices
	 * @return Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function update(Request $request, UserBatchProcessServices $batchProcessServices)
    {
        [$uid, $type, $level, $days_status, $days, $coupon_id, $group_id, $label_id] = $request->postMore([
            ['uid', 0],
            ['type', 1],//类型
            ['level', 0],//等级
            ['days_status', 0],//付费会员赠加/减少
            ['days', 0],//赠送天数
            ['coupon_id', 0],//优惠券id
            ['group_id', 0],//分组
            ['label_id', []],//标签
        ], true);
        if (!$uid) return app('json')->fail('缺少参数');
        $res = true;
        $updateData = [];
        if (!is_array($uid)) {
            $info = $this->services->get((int)$uid);
            if (!$info) return app('json')->fail('用户不存在');
        }
        switch ($type) {
            case 1://等级
                if ($level == 0) return app('json')->fail('请选择等级');
                if ($level == $info['level']) return app('json')->fail('当前等级无需修改');
                /** @var UserLevelServices $userLevelService */
                $userLevelService = app()->make(UserLevelServices::class);
                $res = $userLevelService->setUserLevel((int)$uid, (int)$level);
                $msg = $res ? '修改成功' : '修改失败';
                break;
            case 2://付费会员
                $res = $this->services->saveGiveLevelTime((int)$uid, (int)$days, (int)$days_status);
                $msg = $res ? '赠送成功' : '赠送失败';
                break;
            case 3://赠送优惠券
                /** @var StoreCouponIssueServices $issueService */
                $issueService = app()->make(StoreCouponIssueServices::class);
                $coupon = $issueService->get($coupon_id);
                if (!$coupon) {
                    return app('json')->fail('数据不存在!');
                } else {
                    $coupon = $coupon->toArray();
                }
                if (is_array($uid)) {
                    /** @var QueueServices $queueService */
                    $queueService = app()->make(QueueServices::class);
                    $queueService->setQueueData([], 'uid', $uid);
                    //加入队列
                    BatchHandleJob::dispatch([$coupon, 1]);
                    $msg = '赠送成功';
                } else {
                    /** @var StoreCouponIssueServices $storeCouponIssueServices */
                    $storeCouponIssueServices = app()->make(StoreCouponIssueServices::class);
                    $res = $storeCouponIssueServices->setCoupon($coupon, [$uid], '', '', true);
                    $msg = $res ? '赠送成功' : '赠送失败';
                }
                break;
            case 4://分组
                if (is_array($uid)) {
                    $batchProcessServices->batchProcess(1, $uid, ['group_id' => $group_id]);
                } else {
                    if ($group_id == $info['group_id']) return app('json')->fail('当前分组无需修改');
                    $updateData['group_id'] = $group_id;
                }
                $msg = '修改成功';
                break;
            case 5://标签
                $label_id = array_filter($label_id);
                if (is_array($uid)) {
                    $batchProcessServices->batchProcess(2, $uid, ['label_id' => $label_id]);
                    $msg = '修改成功';
                } else {
                    /** @var UserLabelRelationServices $userLabel */
                    $userLabel = app()->make(UserLabelRelationServices::class);
                    $res = $userLabel->setUserLable([$uid], $label_id, 0, 0, true);
                    $msg = $res ? '修改成功' : '修改失败';
                }
                break;
            default:
                return app('json')->fail('参数错误');
        }
        if ($res && count($updateData) > 0) {
            $this->services->update((int)$uid, $updateData);
        }
        return app('json')->success($msg);
    }

}
