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
