<?php
namespace app\controller\admin\v1\system;

use app\Request;
use app\controller\admin\AuthController;
use app\services\member\MemberCommerceSettingsServices;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AuthException;
use mohe\utils\ApiErrorCode;

/** Uses the same explicit system-settings grant as the adjacent threshold form. */
final class MemberCommerceSettings extends AuthController
{
    private function authorizeSettings(): void
    {
        if ((int)$this->adminId <= 0 || !$this->adminInfo) throw new AuthException(ApiErrorCode::ERR_AUTH);
        if (empty($this->adminInfo['level']) && (int)$this->adminType !== 3) return;
        foreach (app()->make(SystemRoleServices::class)->getRolesByAuth((array)($this->adminInfo['roles'] ?? []), 1) as $grant) {
            if (in_array((string)($grant['unique_auth'] ?? ''), ['setting-shop-base','setting-system-config'], true)) return;
        }
        throw new AuthException(ApiErrorCode::ERR_AUTH);
    }

    public function read()
    {
        $this->authorizeSettings();
        return $this->success((new MemberCommerceSettingsServices())->read());
    }

    public function update(Request $request)
    {
        $this->authorizeSettings();
        $input = $request->postMore([['purchase_primary_id',0], ['purchase_secondary_id',0],
            ['recharge_primary_id',0], ['recharge_secondary_id',0], ['version',0], ['idempotency_key','']]);
        return $this->success('保存成功', (new MemberCommerceSettingsServices())->save($input, (int)$this->adminId));
    }
}
