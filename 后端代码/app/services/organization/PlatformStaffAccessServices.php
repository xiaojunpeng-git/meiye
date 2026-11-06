<?php
declare(strict_types=1);
namespace app\services\organization;
use think\facade\Db;
use mohe\exceptions\AdminException;

/** 人员页面、详情、命令和导出的共同资格入口；现有组织范围及业务写入校验继续生效。 */
final class PlatformStaffAccessServices
{
    /** 仅已有跨业务选择器免于人员管理入口校验；新管理动作默认拒绝无授权账号。 */
    public function requiresManagement(string $action): bool
    {
        return !in_array(strtolower($action), ['getstaffselect','store_list','staffrolelist','staffposition','staffpositionlevel'], true);
    }

    /** 始终从当前平台账号读取身份，不接受前端的角色、level 或岗位授权声明。 */
    public function assertAllowed(int $adminId): void
    {
        $admin = Db::name('system_admin')->where('id', $adminId)->where('is_del', 0)->find();
        $decision = (new OrganizationWorkspaceWriteGate())->assertPlatformStaffMaintainPermission($admin ?: []);
        if (empty($decision['ok'])) throw new AdminException($decision['reason_text'] ?: '当前岗位无人员管理权限');
    }
}
