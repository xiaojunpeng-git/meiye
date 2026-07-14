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
namespace app\controller\erp;

use mohe\services\erp\Erp;
use think\Response;

/**
 * Class AuthController
 * @package app\controller\erp
 */
class AuthController
{

    /*** @var Erp */
    protected $services;

    public function __construct(Erp $services)
    {
        $this->services = $services;
    }

    /**
     * 获取auth测试
     * @return mixed
     */
    public function auth()
    {
        $params = $this->services->getAuthParams();

        $url = $params["url"] . "?";
        unset($params["url"]);
        $url .= http_build_query($params);

        return app('json')->success([$params, 'jump_url' => $url]);
    }

    /**
     * 授权回调测试
     * @return mixed
     */
    public function authCallBack()
    {
        $rep = $this->services->authCallback();
        return Response::create($rep->getData(), "json");
    }

    /**
     * token测试
     * @return void
     */
    public function accessToken()
    {
        $param = $this->services->getAccessToken();
        return app('json')->success($param);
    }
}
