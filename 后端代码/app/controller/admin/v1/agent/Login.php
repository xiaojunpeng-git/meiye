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
namespace app\controller\admin\v1\agent;

use app\Request;
use mohe\services\CacheService;
use mohe\utils\Captcha;
use app\services\system\admin\SystemAdminServices;
use think\facade\Cache;

/**
 * 代理商登录
 * Class Login
 * @package app\controller\admin
 */
class Login
{

    /**
     * Login constructor.
     * @param SystemAdminServices $services
     */
    public function __construct(SystemAdminServices $services)
    {
        $this->services = $services;
    }

    /**
     * 验证码
     * @return $this|\think\Response
     */
    public function captcha()
    {
        return app()->make(Captcha::class)->create();
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

        $key = 'login_captcha_' . $account;

        return app('json')->success(['is_captcha' => Cache::get($key) > 2]);
    }

    /**
     * 登录
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function login(Request $request)
    {
        [$account, $password, $captchaType, $captchaVerification] = $request->postMore([
            'account',
            'pwd',
            ['captchaType', ''],
            ['captchaVerification', ''],
        ], true);

        $key = 'login_captcha_' . $account;

        if (Cache::has($key) && Cache::get($key) > 2) {
            if (!$captchaType || !$captchaVerification) {
                return app('json')->fail('请拖动滑块验证');
            }
            //二次验证
			aj_captcha_check_two($captchaType, $captchaVerification);
        }
        validate(\app\validate\admin\setting\SystemAdminValidate::class)->scene('get')->check(['account' => $account, 'pwd' => $password]);
		$res = $this->services->login($account, $password, 'agent', false, 3);
		if ($res) {
			Cache::delete($key);
		}
        return app('json')->success($res);
    }

    /**
     * 短信验证码登录
     * @param Request $request
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function mobileLogin(Request $request)
    {
        [$phone, $code] = $request->postMore([
            'phone', 'code',
        ], true);
		if (!$phone) {
			return app('json')->fail('请输入手机号');
		}
        if (!$code) {
            return app('json')->fail('请输入验证码');
        }
		//验证验证码
		try {
			check_sms_code($phone, $code);
		} catch (\Throwable $e) {
			return app('json')->fail($e->getMessage());
		}
        return app('json')->success($this->services->login($phone, $code, 'agent', true, 3));
    }

    /**
     * 短信重置密码
     * @param Request $request
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function resetPwd(Request $request)
    {
        [$phone, $code, $newPwd] = $request->postMore([
            'phone', 'code', 'new_pwd'
        ], true);
        if (!$phone) {
            return app('json')->fail('请输入手机号');
        }
        if (!check_phone($phone)) {
            return app('json')->fail('请输入正确的手机号!');
        }
        if (!$code) {
            return app('json')->fail('请输入验证码');
        }
        if (!$newPwd) {
            return app('json')->fail('请输入新密码');
        }
        //验证验证码
		try {
			check_sms_code($phone, $code);
		} catch (\Throwable $e) {
			return app('json')->fail($e->getMessage());
		}
        $this->services->resetPwd((string)$phone, (string)$newPwd, 3);
        return app('json')->success('重置成功，请重新登录');
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
}
