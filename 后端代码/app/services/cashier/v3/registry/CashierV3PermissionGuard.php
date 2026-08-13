<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3\registry;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\permission\CashierV3PermissionPolicyRegistry;

/**
 * 统一权限门禁。
 *
 * V3 全部 action 共用同一 URL，路由中间件无法按 action 判权；
 * 且旧中间件在规则表查不到接口时是放行的。因此 dispatcher 必须先过本 guard。
 *
 * 权限来源：真实 cashierInfo（parseToken 形状）→ DataScopeContext.grantedFeatures
 * 与 permissionPolicyId 策略，禁止依赖永远不会被会话填充的假字段。
 */
class CashierV3PermissionGuard
{
    /** @deprecated 使用 CashierV3FeatureResolver::FEATURE_CODES */
    public const FEATURE_CODES = CashierV3FeatureResolver::FEATURE_CODES;

    /** @var CashierV3PermissionPolicyRegistry */
    protected $policies;

    public function __construct(CashierV3PermissionPolicyRegistry $policies = null)
    {
        $this->policies = $policies ?: new CashierV3PermissionPolicyRegistry();
    }

    public function policies(): CashierV3PermissionPolicyRegistry
    {
        return $this->policies;
    }

    /**
     * @param array $definition manifest requireAction 返回值
     * @param CashierV3DataScopeContext $dataScope
     * @param array $payload 规范化业务参数（选择器 scope 等）
     * @throws CashierV3CommandException
     */
    public function assertAllowed(array $definition, CashierV3DataScopeContext $dataScope, array $payload = []): void
    {
        $action = (string)($definition['action'] ?? $definition['canonical'] ?? '');
        // 组织直属、无 system_store_staff 的数据权限会话只允许浏览投影。
        // 必须在统一权限策略之前按 manifest 类型拦截，避免某个宽松的
        // policy（例如预约操作）或新增 feature 绕过只读边界。
        if ((string)($definition['type'] ?? '') === 'command' && $dataScope->isReadOnlySession()) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前为门店查看模式，不能执行新增、编辑、收银或结账操作。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $action, 'reason' => 'store_read_only_session']
            );
        }
        $policyId = $this->resolvePolicyId($definition);
        if ($policyId === null || $policyId === '') {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_POLICY_MISSING,
                '该操作尚未配置权限，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $action]
            );
        }
        $this->policies->assertAllowed($policyId, $dataScope, $payload, $action);
    }

    /**
     * 兼容旧单测：从 operator_profile 直接判单个 feature（内部仍走 DataScope 语义）。
     *
     * @deprecated 生产路径请使用 assertAllowed + DataScopeContext
     */
    public function assertFeature(string $featureCode, CashierV3DataScopeContext $dataScope, string $action = ''): void
    {
        if (!$dataScope->hasFeature($featureCode)) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号没有该功能的操作权限，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $action, 'feature' => $featureCode]
            );
        }
    }

    /**
     * @return string|null
     */
    protected function resolvePolicyId(array $definition)
    {
        if (isset($definition['permissionPolicyId']) && is_string($definition['permissionPolicyId'])) {
            return $definition['permissionPolicyId'];
        }
        // 兼容旧清单：permission 为 feature code 时自动包成 feature: 策略
        if (array_key_exists('permission', $definition) && $definition['permission'] !== null) {
            $permission = (string)$definition['permission'];
            if ($permission === '') {
                return null;
            }
            if (strpos($permission, 'feature:') === 0 || strpos($permission, 'selector:') === 0) {
                return $permission;
            }
            return 'feature:' . $permission;
        }
        return null;
    }
}
