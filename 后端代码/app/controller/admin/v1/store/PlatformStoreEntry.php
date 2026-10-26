<?php
namespace app\controller\admin\v1\store;

use app\controller\admin\AuthController;
use app\Request;
use app\services\cashier\v3\PlatformStoreEntryBridgeServices;
use think\facade\Config;

/** 平台当前登录人签发门店端一次性身份票据，不代入目标门店管理员。 */
class PlatformStoreEntry extends AuthController
{
    public function ticket(Request $request, PlatformStoreEntryBridgeServices $bridge)
    {
        $authorization = (string)$request->header(Config::get('cookie.token_name', 'Authori-zation'));
        $token = preg_replace('/^Bearer\s+/i', '', trim($authorization));
        return $this->success($bridge->issue((int)$this->adminId, (string)$token));
    }
}
