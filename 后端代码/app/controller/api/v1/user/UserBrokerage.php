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
use app\services\user\UserBrokerageServices;

/**
 * 佣金
 * Class UserBrokerage
 * @package app\controller\api\v1\user
 */
class UserBrokerage
{
    /**
     * @var UserBrokerageServices
     */
    protected $services;

    /**
     * UserBrokerage constructor.
     * @param UserBrokerageServices $services
     */
    public function __construct(UserBrokerageServices $services)
    {
        $this->services = $services;
    }

    /**
     * 推广数据    昨天的佣金   累计提现金额  当前佣金
     * @param Request $request
     * @return mixed
     */
    public function commission(Request $request)
    {
        $uid = (int)$request->uid();
        return app('json')->successful($this->services->commission($uid));
    }

    /**
     * 推广订单
     * @param Request $request
     * @return mixed
     */
    public function spread_order(Request $request)
    {
        $orderInfo = $request->postMore([
            ['page', 1],
            ['limit', 20],
            ['category', 'now_money'],
            ['type', 'brokerage'],
            ['start', 0],
            ['stop', 0],
            ['keyword', '']
        ]);
        $uid = (int)$request->uid();
        return app('json')->successful($this->services->spread_order($uid, $orderInfo));
    }

    /**
     * 推广 佣金/提现 总和
     * @param Request $request
     * @param $type 3 佣金  4 提现
     * @return mixed
     */
    public function spread_count(Request $request, $type)
    {
        $uid = (int)$request->uid();
        return app('json')->successful(['count' => $this->services->spread_count($uid, $type)]);
    }


    /**
     * 佣金排行
     * @param Request $request
     * @return mixed
     */
    public function brokerage_rank(Request $request)
    {
        $data = $request->getMore([
            ['page', ''],
            ['limit'],
            ['type']
        ]);
		$uid = (int)$request->uid();
		$userInfo = $request->user();
		$data = $this->services->brokerage_rank($uid, $data['type']);
		$data['nickname'] = $userInfo['nickname'];
		$data['avatar'] = $userInfo['avatar'];
		return app('json')->success($data);
    }
}
