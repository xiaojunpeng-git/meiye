<?php
namespace app\controller\admin\v1\system;

use app\controller\admin\AuthController;
use app\services\organization\StorePermissionCatalogServices;
use app\services\system\SystemRoleServices;
use think\facade\Db;

/** 独立规则维护入口，沿用平台既有“权限规则”管理资格，不能从门店会话访问。 */
final class StorePermissions extends AuthController
{
    private function assertManage(): void
    {
        $info=(array)$this->adminInfo;
        if ((int)($info['id'] ?? 0)<=0) throw new \RuntimeException('请先登录平台后台');
        if ((int)($info['level'] ?? -1)===0 && (int)$this->adminType!==3) return;
        $ids=app()->make(SystemRoleServices::class)->getRoleIds((array)($info['roles'] ?? []));
        if (!$ids || !Db::name('system_menus')->whereIn('id',$ids)->where('type',1)->where('is_del',0)->whereIn('unique_auth',['setting-system-menus','setting-store-permissions'])->count()) {
            throw new \RuntimeException('当前账号没有权限规则管理权限');
        }
    }

    public function index()
    {
        try { $this->assertManage(); return $this->success((new StorePermissionCatalogServices())->snapshot()); }
        catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    /** revision 只保护权限配置编辑，不参与收银唯一业务快照或金额落账。 */
    public function save()
    {
        try {
            $this->assertManage();
            return $this->success('保存成功',(new StorePermissionCatalogServices())->save((array)$this->request->post(),(int)$this->adminInfo['id']));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
}
