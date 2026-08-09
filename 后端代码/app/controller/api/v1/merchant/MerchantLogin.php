<?php

declare(strict_types=1);

namespace app\controller\api\v1\merchant;

use app\Request;
use app\services\merchant\MerchantEmployeeLoginServices;

final class MerchantLogin
{
    public function login(Request $request, MerchantEmployeeLoginServices $services)
    {
        [$account, $password, $storeId, $ticket] = $request->postMore([
            ['account', ''], ['pwd', ''], ['store_id', 0], ['login_ticket', ''],
        ], true);
        return app('json')->success($services->login(
            trim((string)$account), (string)$password, (int)$storeId, (string)$ticket
        ));
    }
}
