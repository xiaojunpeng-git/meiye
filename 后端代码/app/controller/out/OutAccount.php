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

namespace app\controller\out;


use app\Request;
use app\services\out\OutAccountServices;
use mohe\basic\BaseController;
use app\validate\out\LoginValidate;
use think\facade\App;

/**
 * Class Login
 * @package app\kefu\controller
 */
class OutAccount extends BaseController
{
    /**
     * Login constructor.
     * @param App $app
     * @param OutAccountServices $services
     */
    public function __construct(App $app, OutAccountServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 客服登录
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getToken(Request $request)
    {
        [$appid, $appsecret] = $request->postMore([
            ['appid', ''],
            ['appsecret', ''],
        ], true);
        $this->validate(['appid' => $appid, 'appsecret' => $appsecret], LoginValidate::class);

        $token = $this->services->authLogin($appid, $appsecret);

        return $this->success('获取成功', $token);
    }
    /**
     * 刷新token
     * @return void
     */
    public function refreshToken(Request $request)
    {
        [$token] = $request->postMore([
            ['access_token', ''],
        ], true);
        $token = $this->services->refresh($token);
        return app('json')->success('操作成功', $token);
    }
}
