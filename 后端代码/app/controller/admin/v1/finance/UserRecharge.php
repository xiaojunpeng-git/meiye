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
namespace app\controller\admin\v1\finance;


use app\common\controller\Recharge;
use app\controller\admin\AuthController;
use app\services\user\UserRechargeServices;
use think\facade\App;

/**
 * Class UserRecharge
 * @package app\controller\admin\v1\finance
 */
class UserRecharge extends AuthController
{

    use Recharge;

    /**
     * UserRecharge constructor.
     * @param App $app
     * @param UserRechargeServices $services
     */
    public function __construct(App $app, UserRechargeServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

}
