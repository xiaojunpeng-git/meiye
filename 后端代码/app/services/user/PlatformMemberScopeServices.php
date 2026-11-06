<?php
declare(strict_types=1);
namespace app\services\user;

use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\organization\EmployeeDataScopeServices;
use think\facade\Db;
use think\exception\ValidateException;

/** 平台会员管理先验证当前岗位入口资格，再签发既有会员范围；不消费门店 token。 */
class PlatformMemberScopeServices
{
    /**
     * 复用已上线的历史归属兼容：主门店失效时选会员真实关联的有效门店作操作上下文。
     * 不修改会员归属，也不代替 build() 的平台身份与组织范围校验。
     * 写操作须按会员→门店/关联顺序使用锁定当前读，不能用旧MVCC快照判定调店后的归属。
     */
    public function activeMemberStoreId(int $memberId, bool $lock = false): int
    {
        if ($memberId <= 0) return 0;
        if ($lock) CashierV3TransactionGuard::assertInTransaction('platformMemberStoreScope');
        $memberQuery = Db::name('user')->where('uid', $memberId)->field('uid,belong_store_id');
        if ($lock) $memberQuery->lock(true);
        $member = $memberQuery->find();
        $primary = (int)($member['belong_store_id'] ?? 0);
        if ($primary > 0) {
            $storeQuery = Db::name('system_store')->where('id', $primary)->where('is_del', 0);
            if ($lock) $storeQuery->lock(true);
            if ($storeQuery->value('id')) return $primary;
        }
        $relationQuery = Db::name('store_user')->alias('su')->join('system_store s', 's.id=su.store_id')
            ->where('su.uid', $memberId)->where('su.status', 1)->where('s.is_del', 0)->order('su.store_id', 'asc');
        if ($lock) $relationQuery->lock(true);
        return (int)$relationQuery->value('su.store_id');
    }

    public function build(int $adminId, string $operation = 'read', int $storeId = 0): array
    {
        $admin = Db::name('system_admin')->where('id', $adminId)->where('status', 1)->where('is_del', 0)->where('admin_type', 0)->find();
        if (!$admin) throw new ValidateException('平台登录已失效');
        (new PlatformMemberAccessPolicy())->assertAllowed($admin);
        // 有会员管理授权的账号保留既有全店范围，不在本轮改变数据范围。这个范围只在
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
