<?php
declare(strict_types=1);
namespace app\services\user;

use app\services\organization\StaffJobPositionServices;
use app\services\organization\JobPositionPolicyServices;
use think\facade\Db;
use think\exception\ValidateException;

/** 会员入口资格只读取当前岗位；不增设操作权限，不改变已授权账号的数据范围。 */
final class PlatformMemberAccessPolicy
{
    public const AUTH = 'admin-user-user-index';

    /** 每次调用重新读取权威授权，旧登录菜单、旧投影角色不能维持已撤销的岗位资格。 */
    public function assertAllowed(array $admin): void
    {
        if ((int)($admin['admin_type'] ?? -1) !== 0 || (int)($admin['status'] ?? 0) !== 1 || (int)($admin['is_del'] ?? 1) !== 0) {
            throw new ValidateException('平台登录已失效');
        }
        if (isset($admin['level']) && (int)$admin['level'] === 0) return;
        $employeeId = (int)($admin['employee_id'] ?? 0);
        if ($employeeId > 0) {
            $ids = app()->make(StaffJobPositionServices::class)->computeEmployeeChannelRulesUnion($employeeId, JobPositionPolicyServices::CHANNEL_PLATFORM);
        } else {
            // 未关联员工的历史后台账号保留现有角色授权，但不再默认开放会员管理。
            $roles = array_filter(array_map('intval', explode(',', (string)($admin['roles'] ?? ''))));
            $ids = [];
            if ($roles) foreach (Db::name('system_role')->whereIn('id', $roles)->where('status', 1)->column('rules') as $rules) {
                $ids = array_merge($ids, array_filter(array_map('intval', explode(',', (string)$rules))));
            }
        }
        if (!$ids || !Db::name('system_menus')->whereIn('id', array_unique($ids))->where('type', 1)
            ->where('is_del', 0)->where('unique_auth', self::AUTH)->count()) {
            throw new ValidateException('当前岗位无会员管理权限');
        }
    }
}
