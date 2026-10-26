<?php
namespace app\services\organization;

use app\services\system\SystemMenusServices;
use think\facade\Db;

/** 组织编辑的功能授权与数据范围交集；不授予新增、删除、授权人员等其他写能力。 */
class OrganizationEditAccessServices
{
    public const AUTH_EDIT = 'admin-store-region_edit';

    /**
     * 仅信任数据库中的有效平台身份和已有编辑功能权限，页面与保存共用本状态。
     * scope 为 null 表示超管不限；空数组必须拒绝，不能回退为全部组织。
     */
    public function status(array $adminInfo): array
    {
        $deny = ['can_edit_organization' => false, 'editable_organization_ids' => [],
            'organization_edit_reason' => '当前账号没有组织编辑权限'];
        $id = (int)($adminInfo['id'] ?? 0);
        $admin = $id > 0 ? Db::name('system_admin')->where('id', $id)->where('is_del', 0)->find() : null;
        if (!$admin || (int)$admin['status'] !== 1 || (int)$admin['admin_type'] === 3) return $deny;
        $gate = app()->make(OrganizationWorkspaceWriteGate::class)->evaluateWriteGate();
        if (!$gate['ok']) return array_replace($deny, ['organization_edit_reason' => $gate['reason_text']]);
        $level = (int)$admin['level'];
        if ($level === 0) return ['can_edit_organization' => true, 'editable_organization_ids' => null, 'organization_edit_reason' => ''];
        $roles = array_values(array_filter(array_map('intval', explode(',', (string)$admin['roles']))));
        if (!$roles) return $deny;
        [, $auth] = app()->make(SystemMenusServices::class)->getMenusList($roles, $level, 1, (int)$admin['admin_type']);
        if (!in_array(self::AUTH_EDIT, (array)$auth, true)) return $deny;
        $scope = app()->make(EmployeeDataScopeServices::class)->resolveOperatorManageableOrgIds($admin);
        if ($scope === null) return $deny; // 普通账号不能借身份或范围缺失获得无限编辑权限。
        $allowed = array_fill_keys(array_map('intval', $scope), true);
        $parents = Db::name('organization')->where('is_del', 0)->column('pid', 'id');
        // 兼容旧范围可能附带祖先导航节点：只有整个下级子树均可管时才能编辑该节点。
        // 调整父组织会影响全部下级门店，不能利用一个可见祖先移动范围外的组织。
        foreach ($parents as $orgId => $pid) {
            if (isset($allowed[(int)$orgId])) continue;
            $seen = [];
            while ((int)$pid > 0 && !isset($seen[(int)$pid])) {
                $seen[(int)$pid] = true;
                unset($allowed[(int)$pid]);
                $pid = $parents[(int)$pid] ?? 0;
            }
        }
        $ids = array_values(array_intersect(array_map('intval', array_keys($parents)), array_keys($allowed)));
        return ['can_edit_organization' => count($ids) > 0, 'editable_organization_ids' => $ids,
            'organization_edit_reason' => $ids ? '' : '超出当前账号的组织管理范围'];
    }

    /** 保存时实时复核目标及变更后的父组织；原父组织在范围外时仍可原位编辑授权子树。 */
    public function assertCanEdit(int $orgId, int $newParentId, array $adminInfo): void
    {
        $status = $this->status($adminInfo);
        if (!$status['can_edit_organization']) throw new \Exception($status['organization_edit_reason']);
        $org = $orgId > 0 ? Db::name('organization')->where('id', $orgId)->where('is_del', 0)->find() : null;
        if (!$org) throw new \Exception('组织不存在或已删除');
        $ids = $status['editable_organization_ids'];
        if ($ids === null) return;
        if (!in_array($orgId, $ids, true)) throw new \Exception('超出当前账号的组织管理范围');
        if ((int)$org['pid'] !== $newParentId && ($newParentId <= 0 || !in_array($newParentId, $ids, true))) {
            throw new \Exception('上级组织超出当前账号的组织管理范围');
        }
    }
}
