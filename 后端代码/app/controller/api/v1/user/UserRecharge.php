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
namespace app\controller\api\v1\user;

use app\Request;
use app\services\pay\RechargeServices;
use app\services\user\UserBrokerageServices;
use app\services\user\UserRechargeServices;
use app\services\user\UserServices;
use think\exception\ValidateException;

/**
 * 储值类
 * Class UserRecharge
 * @package app\controller\api\user
 */
class UserRecharge
{
	/**
     * @var UserRechargeServices
     */
    protected $services;

    /**
     * UserRecharge constructor.
     * @param UserRechargeServices $services
     */
    public function __construct(UserRechargeServices $services)
    {
        $this->services = $services;
    }

	/**
	 * 储值额度选择
	 * @param Request $request
	 * @return \think\Response
	 */
	public function index(Request $request)
	{
		$rechargeQuota = sys_data('user_recharge_quota') ?? [];
		$recharge_attention = sys_config('recharge_attention');
		$recharge_attention = explode("\n", $recharge_attention);
		$data = [];
		$data['recharge_quota'] = $rechargeQuota;
		$data['recharge_attention'] = $recharge_attention;
		$data['user_extract_balance_status'] = (int)sys_config('user_extract_balance_status', 1);
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$data['user_now_money'] = $data['broken_commission'] = $data['commissionCount'] = 0;
		if ($uid) {
			/** @var UserServices $userServices */
			$userServices = app()->make(UserServices::class);
			$userInfo = $userServices->get($uid, ['uid', 'now_money', 'brokerage_price']);
			$data['user_now_money'] = $userInfo['now_money'] ?? 0;

			/** @var UserBrokerageServices $userBrokerageServices */
			$userBrokerageServices = app()->make(UserBrokerageServices::class);
			$data['broken_commission'] = max($userBrokerageServices->getUserFrozenPrice($uid), 0);
			$data['commissionCount'] = max(bcsub((string)$userInfo['brokerage_price'], (string)$data['broken_commission'], 2), 0);
		}
		return app('json')->successful($data);
	}

	/**
	 * @param Request $request
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function recharge(Request $request)
    {
        [$price, $recharId, $type, $from] = $request->postMore([
            ['price', 0],
            ['rechar_id', 0],
            ['type', 0],
            ['from', 'weixin']
        ], true);
        if (!$price || $price <= 0) return app('json')->fail('储值金额不能为0元!');
        if (!in_array($type, [0, 1])) return app('json')->fail('储值方式不支持!');
        if (!in_array($from, ['weixin', 'weixinh5', 'routine', 'alipay'])) return app('json')->fail('储值方式不支持');
        $storeMinRecharge = sys_config('store_user_min_recharge');
        if ($price < $storeMinRecharge) return app('json')->fail('储值金额不能低于' . $storeMinRecharge);
        $uid = (int)$request->uid();
        $re = $this->services->recharge($uid, $price, $recharId, $type, $from);
		if ($re) {
			return app('json')->success($type == 1 ? '操作成功' : '订单生成成功',  ['order_id' => $re['data']['order_id'] ?? '']);
		}
		return app('json')->fail('储值订单生产失败');
    }

	/**
	 * 储值订单支付
	 * @param Request $request
	 * @return \think\Response
	 */
	public function pay(Request $request)
	{
		[$uni, $payType, $from, $quitUrl] = $request->postMore([
			['uni', ''],
			['paytype', 'weixin'],
			['from', 'weixin'],
			['quitUrl', '']
		], true);
		$info['order_id'] = $uni;
		/** @var RechargeServices $recharge */
		$recharge = app()->make(RechargeServices::class);
		$info['jsConfig'] = $recharge->recharge($uni, '', $payType);
		if ($from == 'weixinh5') {
			return app('json')->status('wechat_h5_pay', '前往支付', $info);
		} else {
			return app('json')->status('wechat_pay', '前往支付', $info);
		}
	}
}
