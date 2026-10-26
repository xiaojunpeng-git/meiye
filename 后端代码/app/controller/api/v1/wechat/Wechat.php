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

namespace app\controller\api\v1\wechat;


use app\Request;
use app\services\wechat\RoutineServices;
use app\services\wechat\WechatServices as WechatAuthServices;
use mohe\services\CacheService;
use EasyWeChat\Kernel\Exceptions\BadRequestException;
use EasyWeChat\Kernel\Exceptions\InvalidArgumentException;
use EasyWeChat\Kernel\Exceptions\InvalidConfigException;
use think\Response;

/**
 * 微信公众号
 * Class Wechat
 * @package app\controller\api\wechat
 */
class Wechat
{
    /**
     * @var WechatAuthServices
     */
    protected $services;

    /**
     * Wechat constructor.
     * @param WechatAuthServices $services
     */
    public function __construct(WechatAuthServices $services)
    {
        $this->services = $services;
    }

    /**
     * 微信公众号服务
     * @return Response
     * @throws BadRequestException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws \ReflectionException
     */
    public function serve()
    {
        return $this->services->serve();
    }

	/**
	 * 微信小程序公众号服务
	 * @param RoutineServices $routineServices
	 * @return Response
	 */
	public function miniServe(RoutineServices $routineServices)
	{
		return $routineServices->serve();
	}

    /**
     * 企业微信服务
     * @return Response
     * @throws BadRequestException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws \ReflectionException
     */
    public function work()
    {
        return $this->services->workServe();
    }

    /**
     * 公众号权限配置信息获取
     * @param Request $request
     * @return mixed
     */
    public function config(Request $request)
    {
		$url = $request->get('url', '') ?: sys_config('site_url');
        return app('json')->success($this->services->config($url));
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
            ['login_type', ''],
        ], true);
        $token = $this->services->auth($spread_spid, $login_type);
        if ($token && isset($token['key'])) {
            return app('json')->success('授权成功，请绑定手机号', $token);
        } else if ($token) {
            return app('json')->success('登录成功', ['userInfo' => $token['userInfo']]);
        } else
            return app('json')->fail('登录失败');
    }

    /**
     * App微信登录
     * @param Request $request
     * @return mixed
     */
    public function appAuth(Request $request)
    {
        [$userInfo, $phone, $captcha] = $request->postMore([
            ['userInfo', []],
            ['phone', ''],
            ['code', '']
        ], true);
        if ($phone) {
            if (!$captcha) {
                return app('json')->fail('请输入验证码');
            }
            //验证验证码
			try {
				check_sms_code($phone, $captcha);
			} catch (\Throwable $e) {
				return app('json')->fail($e->getMessage());
			}
        } else {
			if ((!isset($userInfo['unionId']) || !$userInfo['unionId']) && (!isset($userInfo['openid']) || !$userInfo['openid'])) {
				return app('json')->fail('参数错误，请重新登录');
			}
		}
        $token = $this->services->appAuth($userInfo, $phone);
        if ($token) {
			CacheService::delete('code_' . $phone);
            return app('json')->success('登录成功', $token);
        } else if ($token === false) {
            return app('json')->success('登录成功', ['isbind' => true]);
        } else {
            return app('json')->fail('登录失败');
        }
    }

    public function follow()
    {
        $data = $this->services->follow();
        if ($data) {
            return app('json')->success('ok', $data);
        } else {
            return app('json')->fail('获取失败');
        }

    }
}
