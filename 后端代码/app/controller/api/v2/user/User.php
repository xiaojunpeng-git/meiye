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
namespace app\controller\api\v2\user;

use app\Request;
use app\services\system\CapitalFlowServices;
use app\services\user\UserBrokerageServices;
use app\services\user\UserMoneyServices;
use app\services\user\UserServices;
use app\services\wechat\WechatServices;



/**
 * 用户类
 * Class User
 * @package app\controller\api\v2\user
 */
class User
{
    /**
     * @var UserServices
     */
    protected $services;

    /**
     * User constructor.
     * @param UserServices $services
     */
    public function __construct(UserServices $services)
    {
        $this->services = $services;
    }

    /**
     * 用户记录0：所有余额1：余额消费2：余额储值3：佣金4：提现
     * @param Request $request
     * @param $type
     * @return mixed
     */
    public function userMoneyList(Request $request, $type)
    {
        $data = $request->getMore([
            ['start', 0],
            ['stop', 0],
            ['uid', 0],
            ['keyword', '']
        ]);
        $uid = (int)$request->uid();
        if(!empty($data['uid'])){
            $uid=$data['uid'];
        }
        switch ((int)$type) {
            case 0:
            case 1:
            case 2:
                /** @var UserMoneyServices $services */
                $services = app()->make(UserMoneyServices::class);
                $data = $services->userMoneyList($uid, (int)$type, $data);
                break;
            case 3:
                /** @var UserBrokerageServices $services */
                $services = app()->make(UserBrokerageServices::class);
                $data = $services->userBrokerageList($uid, $data);
                break;
            case 4:
                /** @var UserBrokerageServices $services */
                $services = app()->make(UserBrokerageServices::class);
                $data = $services->userExtractList($uid, $data);
                break;
			case 9://资金记录
				/** @var CapitalFlowServices $services */
                $services = app()->make(CapitalFlowServices::class);
                $data = $services->userCapitalList($uid, $data);
				break;
            default:
                $data = [];
        }
        return app('json')->successful($data);
    }


    /**
     * 更新公众号用户信息
     * @param Request $request
     * @param WechatServices $services
     * @return mixed
     */
    public function updateUserInfo(Request $request, WechatServices $services)
    {
        return app('json')->success($services->updateUserInfo((int)$request->uid()));
    }

}
