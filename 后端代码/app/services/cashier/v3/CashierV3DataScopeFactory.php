<?php
namespace app\services\cashier\v3;

use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\organization\EmployeeDataScopeServices;

/**
 * 从真实 cashierInfo（LoginServices::parseToken 形状）构建 DataScopeContext。
 *
 * 生产环境必须成功注入 EmployeeDataScopeServices；缺失一律 fail-closed。
 * 禁止 forced_store_fallback。
 */
class CashierV3DataScopeFactory
{
    /** @var CashierV3FeatureResolver */
    protected $featureResolver;

    /** @var EmployeeDataScopeServices */
    protected $employeeDataScope;

    public function __construct(CashierV3FeatureResolver $featureResolver, EmployeeDataScopeServices $employeeDataScope)
    {
        $this->featureResolver = $featureResolver;
        $this->employeeDataScope = $employeeDataScope;
    }

    public function employeeDataScope(): EmployeeDataScopeServices
    {
        return $this->employeeDataScope;
    }

    public function featureResolver(): CashierV3FeatureResolver
    {
        return $this->featureResolver;
    }

    public function build(int $forcedStoreId, int $operatorId, array $operatorProfile, string $tenantId = '0', string $organizationId = ''): CashierV3DataScopeContext
    {
        $employeeId = (int)($operatorProfile['employee_id'] ?? 0);
        $level = array_key_exists('level', $operatorProfile) ? (int)$operatorProfile['level'] : -1;

        $isMenuSuper = ($level === 0);
        $superAdminBasis = $isMenuSuper
            ? 'cashier_level_0_menus_unfiltered(SystemMenusServices::getMenusList type=3)'
            : '';

        $granted = $this->featureResolver->resolveGrantedFeatures($operatorProfile);
        // 数据权限选店会话没有 system_store_staff 任职，只能进入门店查看。
        // 该身份由服务端 delegated token 产生，不能由客户端 profile 注入。
        $readOnlySession = !empty($operatorProfile['_cashier_v3_delegated']);

        $adminInfoForScope = $operatorProfile;
        if (!array_key_exists('admin_type', $adminInfoForScope)) {
            $adminInfoForScope['admin_type'] = 3;
        }

        $isDataSuper = $this->employeeDataScope->isSuperAdmin($adminInfoForScope);
        $visibleStoreIds = [];
        $authorizationMode = CashierV3DataScopeContext::MODE_NONE;
        $employeeScopeMeta = ['mode' => 'none', 'reason' => 'no_employee_binding'];
        $dataSuperBasis = '';

        if ($isDataSuper) {
            $visibleStoreIds = null;
            $authorizationMode = CashierV3DataScopeContext::MODE_ALL;
            $employeeScopeMeta = ['mode' => 'all'];
            $dataSuperBasis = 'EmployeeDataScopeServices::isSuperAdmin(level===0 && admin_type!==3)';
        } elseif ($isMenuSuper) {
            // 门店端 level=0 是当前门店的一级管理员。平台“进入门店”会签发
            // 该门店管理员会话；这类账号可能没有 employee_id，不能因此退化成
            // NONE 并让收银根投影回落成占位页。令牌所属门店仍由 middleware
            // 强制注入，所以这里只放行 forcedStoreId，绝不扩大为跨门店 ALL。
            $visibleStoreIds = [$forcedStoreId];
            $authorizationMode = CashierV3DataScopeContext::MODE_STORES;
            $employeeScopeMeta = [
                'mode' => 'stores',
                'store_ids' => $visibleStoreIds,
                'reason' => 'cashier_level_0_current_store_admin',
            ];
        } elseif ($employeeId > 0) {
            $resolved = $this->employeeDataScope->resolveEffectiveStoreIds($employeeId, $forcedStoreId, $adminInfoForScope);
            if ($resolved === null) {
                $visibleStoreIds = null;
                $authorizationMode = CashierV3DataScopeContext::MODE_ALL;
                $employeeScopeMeta = ['mode' => 'all', 'store_ids' => null];
            } elseif ($resolved === []) {
                // 空门店集合 = 无门店级扩展，仍保留本人参与（不得与「无权限」混用）
                $visibleStoreIds = [];
                $authorizationMode = CashierV3DataScopeContext::MODE_SELF_PARTICIPANT;
                $employeeScopeMeta = [
                    'mode' => CashierV3DataScopeContext::MODE_SELF_PARTICIPANT,
                    'store_ids' => [],
                    'reason' => 'no_store_extension_self_participant_only',
                ];
            } else {
                $visibleStoreIds = array_values($resolved);
                $authorizationMode = CashierV3DataScopeContext::MODE_STORES;
                $employeeScopeMeta = [
                    'mode' => 'stores',
                    'store_ids' => $visibleStoreIds,
                ];
            }
        } else {
            $visibleStoreIds = [];
            $authorizationMode = CashierV3DataScopeContext::MODE_NONE;
            $employeeScopeMeta = ['mode' => 'none', 'reason' => 'no_employee_binding'];
        }

        // 显式拒绝标记（测试／档案拒绝）覆盖为 NONE
        if (!empty($operatorProfile['deny_all']) || (!empty($employeeScopeMeta['mode']) && $employeeScopeMeta['mode'] === 'none' && $employeeId > 0 && !empty($operatorProfile['force_none']))) {
            if (!empty($operatorProfile['deny_all'])) {
                $visibleStoreIds = [];
                $authorizationMode = CashierV3DataScopeContext::MODE_NONE;
                $employeeScopeMeta = ['mode' => 'none', 'reason' => 'denied'];
            }
        }

        $permissionVersion = 'roles:' . md5(json_encode([
            $operatorProfile['roles'] ?? [],
            $level,
            $granted,
            $employeeScopeMeta,
            $authorizationMode,
        ], JSON_UNESCAPED_UNICODE));

        return new CashierV3DataScopeContext(
            $operatorId,
            $employeeId,
            $forcedStoreId,
            $tenantId,
            $organizationId,
            $visibleStoreIds,
            $authorizationMode,
            $employeeScopeMeta,
            $isDataSuper,
            $dataSuperBasis !== '' ? $dataSuperBasis : $superAdminBasis,
            $permissionVersion,
            $granted,
            $operatorProfile,
            $readOnlySession
        );
    }
}
