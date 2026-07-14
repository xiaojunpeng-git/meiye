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


namespace app\webscoket\handler;

use app\services\supplier\LoginServices;
use app\webscoket\BaseHandler;
use app\webscoket\Response;
use mohe\exceptions\AuthException;

/**
 * Class SupplierHandler
 * @package app\webscoket\handler
 */
class SupplierHandler extends BaseHandler
{
    /**
 	* 供应商登录
	* @param array $data
	* @param Response $response
	* @return bool|mixed|\think\response\Json|null
	* @throws \Psr\SimpleCache\InvalidArgumentException
	 */
    public function login(array $data, Response $response)
    {
        if (!isset($data['token']) || !$token = $data['token']) {
            return $response->fail('授权失败!');
        }

        try {
            /** @var LoginServices $services */
            $services = app()->make(LoginServices::class);
            $authInfo = $services->parseToken($token);
        } catch (AuthException $e) {
            return $response->fail($e->getMessage());
        }

        if (!$authInfo || !isset($authInfo['id'])) {
            return $response->fail('授权失败!');
        }

        return $response->success(['uid' => $authInfo['id']]);
    }

}
