<?php
namespace app\controller\cashier\v3;

use app\Request;
use app\services\cashier\v3\CashierV3StoreLoginServices;
use app\services\cashier\v3\PlatformStoreEntryBridgeServices;

/** 公开消费入口只接收一次性票据；身份、任职及选店都由服务端重新核实。 */
class PlatformStoreEntry extends \app\controller\cashier\AuthController
{
    public function consume(Request $request, PlatformStoreEntryBridgeServices $bridge, CashierV3StoreLoginServices $login)
    {
        return app('json')->success($bridge->consume(trim((string)$request->post('ticket', '')), $login));
    }
}
