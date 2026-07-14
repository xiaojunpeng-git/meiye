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

use app\services\message\service\StoreServiceRecordServices;
use app\services\user\UserAuthServices;
use app\webscoket\BaseHandler;
use app\webscoket\Manager;
use app\webscoket\Response;
use mohe\exceptions\AuthException;

/**
 * Class UserHandler
 * @package app\webscoket\handler
 */
class UserHandler extends BaseHandler
{

    /**
	 * 用户登录
	 * @param array $data
	 * @param Response $response
	 * @return bool|mixed|\think\response\Json|null
	 */
    public function login(array $data, Response $response)
    {
        // 游客登录
        if (isset($data['tourist']) && $data['tourist']) {
            return $response->success();
        }

        if (!isset($data['token']) || !$token = $data['token']) {
            return $response->fail('授权失败!');
        }

        try {
            /** @var UserAuthServices $services */
            $services = app()->make(UserAuthServices::class);
            $authInfo = $services->parseToken($token);
        } catch (AuthException $e) {
            return $response->fail($e->getMessage());
        }

        $user = $authInfo['user'];
        /** @var StoreServiceRecordServices $service */
        $service = app()->make(StoreServiceRecordServices::class);
        $service->updateRecord(['to_uid' => $user->uid], ['online' => 1, 'type' => $data['form_type'] ?? 1]);
        //给所有在线客服人员发送当前用户上线消息
        $this->manager->pushing($this->manager->userFd(Manager::KEFU_TYPE_NUM), $response->message('user_online', [
            'uid' => $user->uid,
            'online' => 1
        ])->getData(), $this->fd);

        return $response->success('login', $user->toArray());
    }

}
