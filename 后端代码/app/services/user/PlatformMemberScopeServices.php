<?php
declare(strict_types=1);
namespace app\services\user;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\organization\EmployeeDataScopeServices;
use think\facade\Db;
use think\exception\ValidateException;

/** 平台会员管理对所有有效总后台账号开放；仍只接受真实平台登录态，不消费门店 token。 */
class PlatformMemberScopeServices
{
    public function build(int $adminId, string $operation = 'read', int $storeId = 0): array
    {
        $admin = Db::name('system_admin')->where('id', $adminId)->where('status', 1)->where('is_del', 0)->where('admin_type', 0)->find();
        if (!$admin) throw new ValidateException('平台登录已失效');
        // 产品口径：总后台会员列表不按角色或员工门店范围裁剪。这个全店范围只在
        // member-center 白名单中签发，账号有效性、客户实例和具体命令校验仍由服务端负责。
        $grants = ['read' => true, 'create' => true, 'edit' => true, 'deactivate' => true];
        if (!isset($grants[$operation])) throw new ValidateException('平台会员操作不支持');
        $employeeId = app()->make(EmployeeDataScopeServices::class)->resolveEmployeeIdFromOperator($admin, 'admin');
        $scope = ['mode' => 'all'];
        $visible = null;
        $storeRows = Db::name('system_store')->where('is_del', 0)->field('id,name')->order('id')->select()->toArray();
        $ids = array_map('intval', array_column($storeRows, 'id'));
        if (!$ids) throw new ValidateException('当前实例没有可用门店');
        if ($storeId > 0 && !in_array($storeId, $ids, true)) throw new ValidateException('所选门店已停用或不存在');
        $anchor = $storeId > 0 ? $storeId : $ids[0];
        $org = (string)(Db::name('organization_store')->where('store_id', $anchor)->value('org_id') ?: '');
        $features = ['cashier.v3.member'];
        $features[] = 'cashier.v3.member.create';
        $features[] = 'cashier.v3.member.edit';
        $admin['actor_type'] = 'platform_admin'; $admin['staff_name'] = '平台·' . ($admin['real_name'] ?: $admin['account']);
        $admin['_platform_member'] = true;
        $version = 'platform-member:' . hash('sha256', json_encode([$adminId, $employeeId, $scope, $grants]));
        $operator = new CashierV3OperatorScope($anchor, $adminId, $org, CashierV3ScopeResolver::TENANT_SCOPE_ID);
        $data = new CashierV3DataScopeContext($adminId, $employeeId, $anchor, $operator->tenantId(), $org, $visible,
            CashierV3DataScopeContext::MODE_ALL,
            $scope, true, 'authenticated platform member center', $version, $features, $admin);
        return ['operator' => $operator, 'data' => $data, 'admin' => $admin, 'stores' => $storeRows, 'permissions' => $grants];
    }
}
