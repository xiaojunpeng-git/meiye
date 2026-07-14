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

        $key = 'store_login_captcha_' . $account;

        return app('json')->success(['is_captcha' => Cache::get($key) > 2]);
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
        [$account, $password, $captchaType, $captchaVerification] = $request->postMore([
            'account',
            'pwd',
            ['captchaType', ''],
            ['captchaVerification', '']
        ], true);

		validate(\app\validate\store\StoreAdminValidate::class)->scene('get')->check(['account' => $account, 'pwd' => $password]);

        $key = 'store_login_captcha_' . $account;

        if (Cache::has($key) && Cache::get($key) > 2) {
            if (!$captchaType || !$captchaVerification) {
                return app('json')->fail('请拖动滑块验证');
            }
            //二次验证
			aj_captcha_check_two($captchaType, $captchaVerification);
        }
		$res = $this->services->login($account, $password, 'store');
		if ($res) {
			Cache::delete($key);
		}
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
