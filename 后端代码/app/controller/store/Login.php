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

namespace app\controller\store;


use app\Request;

use mohe\utils\Captcha;
use mohe\services\CacheService;
use app\services\store\LoginServices;
use think\exception\ValidateException;
use app\validate\api\user\RegisterValidates;
use think\facade\Cache;
use think\facade\Config;

/**
 * 登录
 * Class Login
 * @package app\api\controller
 */
class Login
{
    /**
     * @var LoginServices
     */
    protected $services;

    /**
     * Login constructor.
     * @param LoginServices $services
     */
    public function __construct(LoginServices $services)
    {
        $this->services = $services;
    }

	/**
     * @param Request $request
     * @return mixed
     * @author 等风来
     * @email 136327134@qq.com
     * @date 2022/10/11
     */
    public function getAjCaptcha(Request $request)
    {
        [$account,] = $request->postMore([
            'account',
        ], true);

        return app('json')->success(['is_captcha' => $this->services->isCaptchaRequired((string)$account)]);
    }

	/**
     * @return mixed
     */
    public function ajcaptcha(Request $request)
    {
        $captchaType = $request->get('captchaType');
        return app('json')->success(aj_captcha_create((string)$captchaType));
    }

    /**
     * 一次验证
     * @return mixed
     */
    public function ajcheck(Request $request)
    {
        [$token, $pointJson, $captchaType] = $request->postMore([
            ['token', ''],
            ['pointJson', ''],
            ['captchaType', ''],
        ], true);
		aj_captcha_check_one($captchaType, $token, $pointJson);
		return app('json')->success();
    }

    /**
     * 获取后台登录页轮播图以及LOGO
     * @return mixed
     */
    public function info()
    {
        return app('json')->success($this->services->getLoginInfo());
    }

    /**
     * 验证码
     * @return \app\controller\admin\AgentLogin|\think\Response
     */
    public function captcha()
    {
        return app()->make(Captcha::class)->create();
    }

    /**
     * H5账号登录
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function login(Request $request)
    {
        [$account, $password, $storeId, $captchaType, $captchaVerification] = $request->postMore([
            'account',
            'pwd',
            ['store_id', 0],
            ['captchaType', ''],
            ['captchaVerification', '']
        ], true);

		validate(\app\validate\store\StoreAdminValidate::class)->scene('get')->check(['account' => $account, 'pwd' => $password]);

		try {
			$this->services->assertLoginCaptcha(
				(string)$account,
				(int)$storeId,
				(string)$captchaType,
				(string)$captchaVerification
			);
		} catch (ValidateException $e) {
			return app('json')->fail($e->getMessage());
		}

		$res = $this->services->login($account, $password, 'store', (int)$storeId);
		// 密码已通过（含返回门店列表）：清除失败计数；选店阶段靠短期放行，不再复验一次性验证码
		$this->services->afterPasswordLoginSuccess((string)$account, is_array($res) ? $res : []);
        return app('json')->success($res);
    }

    /**
     * 已登录店员切换授权门店
     */
    public function switchStore(Request $request)
    {
        [$storeId] = $request->postMore([
            ['store_id', 0],
        ], true);
        $staffId = (int)$request->storeStaffId();
        $res = $this->services->switchStore($staffId, (int)$storeId, 'store');
        return app('json')->success($res);
    }

    /**
     * 退出登录
     * @param Request $request
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function logout(Request $request)
    {
        $key = trim(ltrim($request->header(Config::get('cookie.token_name')), 'Bearer'));
        CacheService::redisHandler()->delete(md5($key));
        return app('json')->success();
    }

    /**
     * 密码修改
     * @param Request $request
     * @return mixed
     */
    public function reset(Request $request)
    {
        [$account, $captcha, $password] = $request->postMore([['account', ''], ['captcha', ''], ['password', '']], true);

		validate(RegisterValidates::class)->scene('register')->check(['account' => $account, 'captcha' => $captcha, 'password' => $password]);
		//验证验证码
		try {
			check_sms_code($account, $captcha);
		} catch (\Throwable $e) {
			return app('json')->fail($e->getMessage());
		}
        if (strlen(trim($password)) < 6 || strlen(trim($password)) > 16)
            return app('json')->fail('密码必须是在6到16位之间');
        if ($password == '123456') return app('json')->fail('密码太过简单，请输入较为复杂的密码');
        $resetStatus = $this->services->reset($account, $password);
        if ($resetStatus) {
			CacheService::delete('code_' . $account);
			CacheService::delete('code_error_' . $account);
			return app('json')->success('修改成功');
		}
        return app('json')->fail('修改失败');
    }


}
