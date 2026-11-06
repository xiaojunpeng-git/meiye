<?php
namespace app\controller\admin\v1\system;

use app\controller\admin\AuthController;
use app\services\organization\StorePermissionCatalogServices;
use app\services\system\SystemRoleServices;
use think\facade\Db;

/** 独立规则维护入口，沿用平台既有“权限规则”管理资格，不能从门店会话访问。 */
class StorePermissions extends AuthController
{
    protected function assertManage(): void
    {
        $info=(array)$this->adminInfo;
        // 门店登录身份即使携带同编号角色，也不能作为平台管理员修改权限目录。
        if ((int)$this->adminType===3) throw new \RuntimeException('请使用平台后台账号管理权限规则');
        if ((int)($info['id'] ?? 0)<=0) throw new \RuntimeException('请先登录平台后台');
        if ((int)($info['level'] ?? -1)===0 && (int)$this->adminType!==3) return;
        $ids=app()->make(SystemRoleServices::class)->getRoleIds((array)($info['roles'] ?? []));
        if (!$ids || !Db::name('system_menus')->whereIn('id',$ids)->where('type',1)->where('is_del',0)->whereIn('unique_auth',$this->managementAuthCodes())->count()) {
            throw new \RuntimeException('当前账号没有权限规则管理权限');
        }
    }

    /** 两个终端复用平台管理资格，具体规则目录由子类提供。 */
    protected function catalog() { return new StorePermissionCatalogServices(); }
    /** 门店规则的管理资格保持原样，不因新增商家规则而扩大。 */
    protected function managementAuthCodes(): array { return ['setting-system-menus','setting-store-permissions']; }

    public function index()
    {
        try { $this->assertManage(); return $this->success($this->catalog()->snapshot()); }
        catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    /** revision 只保护权限配置编辑，不参与收银唯一业务快照或金额落账。 */
    public function save()
    {
        try {
            $this->assertManage();
            return $this->success('保存成功',$this->catalog()->save((array)$this->request->post(),(int)$this->adminInfo['id']));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
}
