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
namespace app\controller\api\v2\wechat;

use app\Request;
use app\services\wechat\WechatServices;
use mohe\services\CacheService;

/**
 * Class Wechat
 * @package app\controller\api\v2\wechat
 */
class Wechat
{
    protected $services;

    /**
     * Wechat constructor.
     * @param WechatServices $services
     */
    public function __construct(WechatServices $services)
    {
        $this->services = $services;
    }

	/**
	 * @param $spread_spid
	 * @return \think\Response
	 */
	public function authLogin($spread_spid = '')
	{
		$data = $this->services->authLogin((int)$spread_spid);
		return app('json')->success($data);
	}

    /**
     * 公众号授权登录
     * @param Request $request
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function auth(Request $request)
    {
        [$spread_spid, $login_type] = $request->getMore([
            [['spread_spid', 'd'], 0],
            ['login_type', 'wechat'],
        ], true);
        $token = $this->services->newAuth($spread_spid, $login_type);
        if ($token && isset($token['key'])) {
            return app('json')->success('授权成功，请绑定手机号', $token);
        } else if ($token) {
            return app('json')->success('登录成功', ['token' => $token['token'], 'userInfo' => $token['userInfo'], 'expires_time' => $token['params']['exp']]);
        } else
            return app('json')->fail('登录失败');
    }

    /**
     * 微信公众号静默授权
     * @param $code
     * @param $spread_spid
     * @return mixed
     */
    public function silenceAuth($spread_spid = '')
    {
        $token = $this->services->silenceAuth($spread_spid);
        if ($token && isset($token['key'])) {
            return app('json')->success('授权成功，请绑定手机号', $token);
        } else if ($token) {
            return app('json')->success('登录成功', ['token' => $token['token'], 'expires_time' => $token['params']['exp']]);
        } else
            return app('json')->fail('登录失败');
    }

	/**
	 * 微信公众号静默授权
	 * @param $spread_spid
	 * @param $snsapi
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function silenceAuthNoLogin($spread_spid = '', $snsapi = '')
    {
        $token = $this->services->silenceAuth($spread_spid, true, $snsapi);
        if ($token && isset($token['auth_login'])) {
            return app('json')->success('授权成功', $token);
        } else if ($token) {
            return app('json')->success('登录成功', ['token' => $token['token'], 'userInfo' => $token['userInfo'], 'expires_time' => $token['params']['exp']]);
        } else
            return app('json')->fail('登录失败');
    }

    /**
     * 静默授权 手机号直接注册登录
     * @param string $key
     * @param string $phone
     * @param string $captcha
     * @return mixed
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function silenceAuthBindingPhone($key = '', $phone = '', $captcha = '')
    {
        //验证验证码
		try {
			check_sms_code($phone, $captcha);
		} catch (\Throwable $e) {
			return app('json')->fail($e->getMessage());
		}
        $token = $this->services->silenceAuthBindingPhone($key, $phone);
        if ($token) {
			CacheService::delete('code_' . $phone);
			CacheService::delete('code_error_' . $phone);
            return app('json')->success('登录成功', $token);
        } else
            return app('json')->fail('登录失败');
    }
}
