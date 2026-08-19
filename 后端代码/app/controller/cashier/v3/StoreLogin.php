<?php
namespace app\controller\cashier\v3;

use app\Request;
use app\services\cashier\v3\CashierV3StoreLoginServices;
use app\services\employee\EmployeeInternalAccountServices;
use think\facade\Config;

class StoreLogin extends \app\controller\cashier\AuthController
{
    public function login(Request $request, CashierV3StoreLoginServices $services)
    {
        [$account, $password, $storeId] = $request->postMore([
            ['account', ''], ['pwd', ''], ['store_id', 0],
        ], true);
        return app('json')->success($services->login(
            trim((string)$account), (string)$password, (int)$storeId
        ));
    }

    public function switchStore(Request $request, CashierV3StoreLoginServices $services)
    {
        $storeId = (int)$request->post('store_id', 0);
        return app('json')->success($services->switchStore(
            (int)($this->cashierInfo['employee_id'] ?? 0), $storeId
        ));
    }

    public function logout(Request $request, CashierV3StoreLoginServices $services)
    {
        $token = (string)$request->header(Config::get('cookie.token_name', 'Authori-zation'));
        $services->logout($token);
        return app('json')->success('已退出登录');
    }

    public function changePassword(Request $request, EmployeeInternalAccountServices $accounts, CashierV3StoreLoginServices $services)
    {
        [$currentPassword, $newPassword] = $request->postMore([
            ['current_password', ''], ['new_password', ''],
        ], true);
        $employeeId = (int)($this->cashierInfo['employee_id'] ?? 0);
        $accounts->changeOwnPassword($employeeId, (string)$currentPassword, (string)$newPassword, [
            'operator_id' => (int)$this->cashierId,
            'operator_name' => (string)($this->cashierInfo['staff_name'] ?? $this->cashierInfo['account'] ?? ''),
            'operator_ip' => (string)$request->ip(),
        ]);
        $services->logout((string)$request->header(Config::get('cookie.token_name', 'Authori-zation')));
        return app('json')->success('密码已修改，请重新登录', ['reauth_required' => true]);
    }
}
