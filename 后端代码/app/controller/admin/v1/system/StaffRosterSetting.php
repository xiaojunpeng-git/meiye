<?php
declare(strict_types=1);
namespace app\controller\admin\v1\system;
use app\services\store\roster\StaffRosterSettingServices;
use app\services\store\roster\StaffRosterPlatformScope;
use app\services\store\roster\StaffRosterConflict;
use app\services\system\SystemRoleServices;
use mohe\exceptions\AuthException;
use mohe\utils\ApiErrorCode;
use think\exception\ValidateException;

/** 与相邻系统设置复用既有设置授权；排班管理页面权限不能用来修改全站开关。 */
final class StaffRosterSetting extends \app\controller\admin\AuthController
{
    private function authorize(): void
    {
        if ((int)$this->adminId <= 0 || !$this->adminInfo) throw new AuthException(ApiErrorCode::ERR_AUTH);
        if (empty($this->adminInfo['level']) && (int)$this->adminType !== 3) return;
        foreach (app()->make(SystemRoleServices::class)->getRolesByAuth((array)($this->adminInfo['roles']??[]),1) as $grant)
            if (in_array((string)($grant['unique_auth']??''),['setting-shop-base','setting-system-config'],true)) return;
        throw new AuthException(ApiErrorCode::ERR_AUTH);
    }
    public function read() { $this->authorize(); return $this->success((new StaffRosterSettingServices())->read()); }
    public function update()
    {
        $this->authorize();
        try {
            $allowed=(new StaffRosterPlatformScope())->pickerTree((int)$this->adminType,(int)$this->agentId,(array)$this->adminInfo)['allowed_store_ids'];
            return $this->success('保存成功',(new StaffRosterSettingServices())->save((int)$this->adminId,(array)$this->request->post(),$allowed));
        } catch(StaffRosterConflict $e) { return $this->fail($e->getMessage(),['conflicts'=>$e->conflicts]); }
        catch(ValidateException $e) { return $this->fail($e->getMessage()); }
    }
}
