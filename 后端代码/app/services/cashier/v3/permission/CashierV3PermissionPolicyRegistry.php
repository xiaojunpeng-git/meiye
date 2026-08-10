<?php
namespace app\services\cashier\v3\permission;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3ResultCode;

/**
 * 权限策略注册表。
 *
 * 普通单入口：feature:<code>
 * 共用选择器：按业务来源固定的 canonical selectorEntry → feature，
 * 不得仅靠客户端 selectorContext／selectionScope 自由字符串判权，也不得依赖进程内 token。
 */
class CashierV3PermissionPolicyRegistry
{
    /** @var array<string,callable> */
    protected $policies = [];

    /** @var CashierV3SelectorGrantServices|null */
    protected $selectorGrants;

    /** @var bool */
    protected $frozen = false;

    public function __construct(CashierV3SelectorGrantServices $selectorGrants = null)
    {
        $this->selectorGrants = $selectorGrants ?: new CashierV3SelectorGrantServices();
        $this->registerDefaults();
    }

    public function selectorGrants(): CashierV3SelectorGrantServices
    {
        return $this->selectorGrants;
    }

    public function freeze(): void
    {
        $this->frozen = true;
        $this->selectorGrants->freeze();
    }

    public function register(string $policyId, callable $asserter): void
    {
        if ($this->frozen) {
            throw new \LogicException('permission policy registry 已 freeze');
        }
        if (isset($this->policies[$policyId])) {
            throw new \LogicException(sprintf('permission policy %s 重复注册', $policyId));
        }
        $this->policies[$policyId] = $asserter;
    }

    public function has(string $policyId): bool
    {
        return isset($this->policies[$policyId]);
    }

    /**
     * @throws CashierV3CommandException
     */
    public function assertAllowed(string $policyId, CashierV3DataScopeContext $dataScope, array $payload, string $action): void
    {
        if ($policyId === '' || !isset($this->policies[$policyId])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_POLICY_MISSING,
                '该操作尚未配置权限，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $action, 'policy' => $policyId]
            );
        }
        call_user_func($this->policies[$policyId], $dataScope, $payload, $action);
    }

    protected function registerDefaults(): void
    {
        foreach (CashierV3FeatureResolver::FEATURE_CODES as $feature) {
            $policyId = 'feature:' . $feature;
            $this->register($policyId, function (CashierV3DataScopeContext $scope, array $payload, string $action) use ($feature) {
                if (!$scope->hasFeature($feature)) {
                    throw new CashierV3CommandException(
                        CashierV3ResultCode::PERMISSION_DENIED,
                        '当前账号没有该功能的操作权限，请联系管理员。',
                        CashierV3ResultCode::STATUS_FAILED,
                        ['action' => $action, 'feature' => $feature]
                    );
                }
            });
        }

        // 会话 bootstrap 不是收银权限：只要服务端已解析出任一真实的
        // store_v3 功能，即可装载当前门店的根投影。具体页面与业务 action
        // 继续逐项走 feature policy，不能借 bootstrap 越权执行收银操作。
        $this->register('policy:store_v3_session', function (CashierV3DataScopeContext $scope, array $payload, string $action) {
            if ($scope->grantedFeatures()) {
                return;
            }
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号没有可进入的门店端功能权限，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $action]
            );
        });

        $this->register('policy:checkout_entitlement', function (CashierV3DataScopeContext $scope, array $payload, string $action) {
            if ($scope->hasFeature('cashier.v3.cashier') || $scope->hasFeature('cashier.v3.writeoff')) {
                return;
            }
            throw new CashierV3CommandException(
                CashierV3ResultCode::PERMISSION_DENIED,
                '当前账号缺少收银或项目核销权限，不能把会员权益加入本次购物车。',
                CashierV3ResultCode::STATUS_FAILED,
                [
                    'action' => $action,
                    'required_any_of' => ['cashier.v3.cashier', 'cashier.v3.writeoff'],
                ]
            );
        });

        $this->register('policy:unified_query_page', function (CashierV3DataScopeContext $scope, array $payload, string $action) {
            $pageCode = trim((string)($payload['pageCode'] ?? $payload['page_code'] ?? ''));
            $pageFeatures = [
                'member_list' => 'cashier.v3.member',
                'staff_list' => 'cashier.v3.management_center',
                // 订单中心八类记录共用既有“订单”入口权限；页面字段、门店范围
                // 与导出执行范围仍由统一查询注册表和当前数据范围强制控制。
                'order_center_sales' => 'cashier.v3.order_center',
                'order_center_recharge' => 'cashier.v3.order_center',
                'order_center_refund' => 'cashier.v3.order_center',
                'order_center_debt' => 'cashier.v3.order_center',
                'order_center_service' => 'cashier.v3.order_center',
                'order_center_supplement' => 'cashier.v3.order_center',
                'order_center_gift' => 'cashier.v3.order_center',
                'order_center_card_operation' => 'cashier.v3.order_center',
            ];
            $feature = $pageFeatures[$pageCode] ?? '';
            if ($feature === '') {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::PERMISSION_DENIED,
                    '当前页面尚未接入统一查询权限策略。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $action, 'page_code' => $pageCode, 'feature' => $feature]
                );
            }
            if (!$scope->hasFeature($feature)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::PERMISSION_DENIED,
                    '当前账号没有该页面的查询权限，请联系管理员。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $action, 'page_code' => $pageCode, 'feature' => $feature]
                );
            }
        });

        $grants = $this->selectorGrants;

        // 会员选择器：canonical selectorEntry → feature
        $this->register('selector:member', function (CashierV3DataScopeContext $scope, array $payload, string $action) use ($grants) {
            $claims = $grants->assertCanonicalEntry($payload, $scope, 'member');
            $feature = (string)($claims['feature'] ?? '');
            if ($feature === '' || !$scope->hasFeature($feature)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::PERMISSION_DENIED,
                    '当前账号没有该功能的操作权限，请联系管理员。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $action, 'feature' => $feature]
                );
            }
        });

        $this->register('selector:query_entities', function (CashierV3DataScopeContext $scope, array $payload, string $action) use ($grants) {
            $claims = $grants->assertCanonicalEntry($payload, $scope, 'query_entity');
            $feature = (string)($claims['feature'] ?? '');
            if ($feature === '' || !$scope->hasFeature($feature)) {
                throw new CashierV3CommandException(
                    CashierV3ResultCode::PERMISSION_DENIED,
                    '当前账号没有该功能的操作权限，请联系管理员。',
                    CashierV3ResultCode::STATUS_FAILED,
                    ['action' => $action, 'feature' => $feature]
                );
            }
        });
    }
}
