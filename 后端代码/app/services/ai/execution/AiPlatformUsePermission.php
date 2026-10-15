<?php
namespace app\services\ai\execution;

use app\services\system\SystemRoleServices;
use think\facade\Db;

/** Resolves the platform AI use switch from current role grants, independently of report data scope. */
final class AiPlatformUsePermission
{
    public const AUTH = 'mohe-ai-entry';

    /** Current platform account -> AI function admission; report scope is checked separately. */
    public function allows(array $admin): bool
    {
        // Platform super administrators retain their existing unrestricted function access;
        // the separate root-account check for model configuration remains unchanged.
        if ((int)($admin['level'] ?? 1) === 0 && (int)($admin['admin_type'] ?? 0) !== 3) {
            return true;
        }

        $roles = $admin['roles'] ?? [];
        $roles = is_array($roles) ? $roles : explode(',', (string)$roles);
        $roles = array_values(array_unique(array_filter(array_map('intval', $roles), static function ($id) {
            return $id > 0;
        })));
        if (!$roles) {
            return false;
        }

        // A deleted or hidden rule cannot keep authorizing an old role. Read the
        // current role rules on every admission so revocation affects live runs.
        $permissionId = (int)Db::name('system_menus')
            ->where('type', 1)->where('auth_type', 2)
            ->where('unique_auth', self::AUTH)->where('is_del', 0)->where('is_show', 1)
            ->value('id');
        if ($permissionId <= 0) {
            return false;
        }
        $grants = app()->make(SystemRoleServices::class)->getRoleIds($roles);
        return in_array($permissionId, array_map('intval', $grants), true);
    }
}
