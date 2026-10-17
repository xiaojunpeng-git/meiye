<?php

namespace app\controller\admin\v1\system;

use app\Request;
use app\controller\admin\AuthController;
use app\services\report\StoreDealPeopleThresholdPolicyServices;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AuthException;
use mohe\utils\ApiErrorCode;
use think\facade\App;

/** 平台系统设置中的成交人数门槛，只管理版本化参数，不修改业务事实。 */
final class DealPeopleThresholdPolicy extends AuthController
{
    private StoreDealPeopleThresholdPolicyServices $services;

    public function __construct(App $app, StoreDealPeopleThresholdPolicyServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function read()
    {
        $this->requireSystemSettingsAccess();
        return $this->success($this->services->current());
    }

    public function update(Request $request)
    {
        $this->requireSystemSettingsAccess();
        [$aYuan, $otherYuan, $version] = $request->postMore([
            ['a_yuan', ''], ['other_yuan', ''], ['version', -1],
        ], true);
        return $this->success('保存成功', $this->services->save($aYuan, $otherYuan, (int)$version, (int)$this->adminId));
    }

    /** 旧路由中间件会放行未登记接口；本配置必须再核实系统设置菜单授权。 */
    private function requireSystemSettingsAccess(): void
    {
        if ((int)$this->adminId <= 0 || !$this->adminInfo) throw new AuthException(ApiErrorCode::ERR_AUTH);
        if (empty($this->adminInfo['level']) && (int)$this->adminType !== 3) return;
        $roles = (array)($this->adminInfo['roles'] ?? []);
        $grants = app()->make(SystemRoleServices::class)->getRolesByAuth($roles, 1);
        foreach ($grants as $grant) {
            if ((string)($grant['unique_auth'] ?? '') === 'setting-system-config') return;
        }
        throw new AuthException(ApiErrorCode::ERR_AUTH);
    }
}
