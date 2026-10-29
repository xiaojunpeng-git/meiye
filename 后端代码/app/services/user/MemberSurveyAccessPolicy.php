<?php
declare(strict_types=1);
namespace app\services\user;

use think\exception\ValidateException;

/** 调查管理涉及会员隐私和自动赠送：总部会话与有效菜单能力必须同时存在。 */
final class MemberSurveyAccessPolicy
{
    /** grants只接收角色服务解析的有效规则，绝不接收客户端传入的权限名称。 */
    public function assertPlatform(array $admin, array $grants): void
    {
        if ((int)($admin['id'] ?? 0) <= 0 || !array_key_exists('level', $admin)
            || (int)($admin['admin_type'] ?? -1) !== 0) {
            throw new ValidateException('仅总部可管理会员调查');
        }
        if ((int)$admin['level'] === 0) return;
        if (!in_array('admin-user-survey', array_column($grants, 'unique_auth'), true)) {
            throw new ValidateException('没有会员调查管理权限');
        }
    }
}
