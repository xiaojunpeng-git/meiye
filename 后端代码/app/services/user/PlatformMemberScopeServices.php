<?php
declare(strict_types=1);
namespace app\services\user;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\organization\EmployeeDataScopeServices;
use app\services\system\SystemRoleServices;
use think\facade\Db;
use think\exception\ValidateException;

/** 平台身份独立授权，不消费 cashier token、不伪造门店员工身份。 */
class PlatformMemberScopeServices
{
    public function build(int $adminId, string $operation = 'read', int $storeId = 0): array
    {
        $admin = Db::name('system_admin')->where('id', $adminId)->where('status', 1)->where('is_del', 0)->where('admin_type', 0)->find();
        if (!$admin) throw new ValidateException('平台登录已失效');
        $root = (int)$admin['level'] === 0;
        // 历史角色为逗号串，兼容明确的数组表示；空值不获得任何授权。
        $roles = is_array($admin['roles']) ? $admin['roles'] : json_decode((string)$admin['roles'], true);
        if (!is_array($roles)) $roles = explode(',', (string)$admin['roles']);
        $roles = array_values(array_filter(array_map('intval', $roles)));
        $rules = $root ? [] : app()->make(SystemRoleServices::class)->getRolesByAuth($roles, 2);
        $grants = ['read' => false, 'create' => false, 'edit' => false, 'deactivate' => false];
        foreach ($grants as $key => $_) {
            $method = ['read' => 'get', 'create' => 'post', 'edit' => 'put', 'deactivate' => 'delete'][$key];
            foreach ($rules as $rule) {
                $path = trim(strtolower((string)($rule['api_url'] ?? '')), '/');
                // 仅资源本身授权生效，不能把 user/user_label 或调店等旁路接口当作会员编辑权。
                $paths = $key === 'read' ? ['user/user'] : ($key === 'create' ? ['user/user', 'user/user/save'] : ['user/user/:id']);
                if (in_array($path, $paths, true) && strtolower((string)($rule['methods'] ?? '')) === $method) $grants[$key] = true;
            }
            if ($root) $grants[$key] = true;
        }
        if (empty($grants[$operation]) || empty($grants['read'])) throw new ValidateException('当前账号没有会员管理操作权限');
        $authority = app()->make(EmployeeDataScopeServices::class);
        $employeeId = $authority->resolveEmployeeIdFromOperator($admin, 'admin');
        $where = []; $authority->applyOrderListScope($where, $employeeId, 'admin', 0, $admin);
        $scope = (array)($where['employee_data_scope'] ?? ['mode' => 'none']);
        if (($scope['mode'] ?? '') === 'all') $visible = null;
        elseif (($scope['mode'] ?? '') === 'stores') $visible = array_values(array_filter(array_map('intval', (array)($scope['store_ids'] ?? []))));
        else throw new ValidateException('当前账号暂无可管理的门店范围');
        $stores = Db::name('system_store')->where('is_del', 0)->field('id,name')->order('id');
        if ($visible !== null) $stores->whereIn('id', $visible ?: [-1]);
        $storeRows = $stores->select()->toArray();
        $ids = array_map('intval', array_column($storeRows, 'id'));
        if (!$ids || ($storeId > 0 && !in_array($storeId, $ids, true))) throw new ValidateException('所选门店不在当前权限范围');
        $anchor = $storeId > 0 ? $storeId : $ids[0];
        $org = (string)(Db::name('organization_store')->where('store_id', $anchor)->value('org_id') ?: '');
        $features = ['cashier.v3.member'];
        if ($grants['create']) $features[] = 'cashier.v3.member.create';
        if ($grants['edit']) $features[] = 'cashier.v3.member.edit';
        $admin['actor_type'] = 'platform_admin'; $admin['staff_name'] = '平台·' . ($admin['real_name'] ?: $admin['account']);
        $admin['_platform_member'] = true;
        $version = 'platform-member:' . hash('sha256', json_encode([$adminId, $employeeId, $scope, $grants]));
        $operator = new CashierV3OperatorScope($anchor, $adminId, $org, CashierV3ScopeResolver::TENANT_SCOPE_ID);
        $data = new CashierV3DataScopeContext($adminId, $employeeId, $anchor, $operator->tenantId(), $org, $visible,
            $visible === null ? CashierV3DataScopeContext::MODE_ALL : CashierV3DataScopeContext::MODE_STORES,
            $scope, $root, 'authenticated platform member authority', $version, $features, $admin);
        return ['operator' => $operator, 'data' => $data, 'admin' => $admin, 'stores' => $storeRows, 'permissions' => $grants];
    }
}
