<?php
namespace app\services\cashier\v3\registry;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;

/**
 * 单个写命令的 contexts 执行合同（第二层）。
 *
 * 求解结果除 required／allowed／min_counts 外，必须返回精确资源身份合同 identities：
 *   [{role, kind, id, required, allow_multiple?}, ...]
 * ID 从规范化 payload／服务端会话推导，客户端不能靠另传 context 决定「要锁谁」。
 */
class CashierV3ContextPolicy
{
    protected $action;
    protected $staticRequired;
    protected $staticAllowed;
    /** @var callable|null */
    protected $dynamicResolver;
    /** @var string[] */
    protected $requiredTouchedRoles = [];
    /** @var string[] 机器可读：动态 resolver 可能引入的 role */
    protected $declaredDynamicRoles = [];

    /** @var string[] 机器可读：动态 resolver 可能引入的 kind */
    protected $declaredDynamicKinds = [];

    /** @var callable|null */
    protected $serverResourceDiscoverer;

    /** @var bool 少数纯资料保存命令可由领域事务自行锁定，不要求页面资源版本。 */
    protected $allowsEmptyContexts;

    /**
     * @param string[] $staticRequired
     * @param string[] $staticAllowed
     * @param callable|null $dynamicResolver
     * @param string[] $requiredTouchedRoles
     * @param string[] $declaredDynamicRoles
     * @param string[] $declaredDynamicKinds
     */
    public function __construct(
        string $action,
        array $staticRequired,
        array $staticAllowed = [],
        callable $dynamicResolver = null,
        array $requiredTouchedRoles = [],
        array $declaredDynamicRoles = [],
        array $declaredDynamicKinds = [],
        bool $allowsEmptyContexts = false
    ) {
        foreach (array_merge($staticRequired, $staticAllowed, $declaredDynamicKinds) as $kind) {
            CashierV3ResourceKindCatalog::assertKnown($kind);
        }
        if (!$staticRequired && $dynamicResolver === null && !$allowsEmptyContexts) {
            throw new \LogicException(sprintf('写命令 %s 的 context policy 必须至少有一个必需资源', $action));
        }
        $this->action = $action;
        $this->staticRequired = array_values(array_unique($staticRequired));
        $this->staticAllowed = array_values(array_unique(array_merge($staticRequired, $staticAllowed)));
        $this->dynamicResolver = $dynamicResolver;
        $this->requiredTouchedRoles = array_values(array_unique($requiredTouchedRoles));
        $this->declaredDynamicRoles = array_values(array_unique($declaredDynamicRoles));
        $this->declaredDynamicKinds = array_values(array_unique($declaredDynamicKinds));
        $this->allowsEmptyContexts = $allowsEmptyContexts;
    }

    public function action(): string
    {
        return $this->action;
    }

    /** @return string[] 静态必需 kind（激活矩阵用） */
    public function staticRequiredKinds(): array
    {
        return $this->staticRequired;
    }

    public function hasDynamicResolver(): bool
    {
        return $this->dynamicResolver !== null;
    }

    /**
     * 机器可读动态依赖声明（selfCheck 逐个核验 provider）。
     *
     * @return array{roles:string[],kinds:string[]}
     */
    public function declaredDynamicDependencies(): array
    {
        return [
            'roles' => $this->declaredDynamicRoles,
            'kinds' => $this->declaredDynamicKinds,
        ];
    }

    /**
     * Domain installers may attach one server-only resource discoverer before
     * the dispatcher freezes. Client context validation remains unchanged.
     */
    public function configureServerResourceDiscovery(
        callable $discoverer,
        array $declaredRoles,
        array $declaredKinds
    ): void {
        if ($this->serverResourceDiscoverer !== null
            && ($this->action !== 'submit-checkout'
                || is_array($payload['checkoutSnapshot'] ?? null))) {
            throw new \LogicException(sprintf('%s 的服务端资源发现器重复配置', $this->action));
        }
        foreach ($declaredKinds as $kind) {
            CashierV3ResourceKindCatalog::assertKnown((string)$kind);
        }
        $this->serverResourceDiscoverer = $discoverer;
        $this->declaredDynamicRoles = array_values(array_unique(array_merge(
            $this->declaredDynamicRoles,
            array_map('strval', $declaredRoles)
        )));
        $this->declaredDynamicKinds = array_values(array_unique(array_merge(
            $this->declaredDynamicKinds,
            array_map('strval', $declaredKinds)
        )));
    }

    /**
     * @param array $payload
     * @param array $session 含 state_context_id／operator 等，用于 workspace 绑定
     * @return array{
     *   action:string,
     *   required:string[],
     *   allowed:string[],
     *   min_counts:array<string,int>,
     *   identities:array<int,array{role:string,kind:string,id:string,required:bool}>,
     *   required_read_roles:string[],
     *   required_touched_roles:string[]
     * }
     */
    public function resolve(array $payload, array $session = []): array
    {
        $required = $this->staticRequired;
        $allowed = $this->staticAllowed;
        $minCounts = [];
        $identities = [];
        $touchedRoles = $this->requiredTouchedRoles;
        $readRoles = [];
        $extra = null;

        if ($this->dynamicResolver !== null) {
            $extra = call_user_func($this->dynamicResolver, $payload, [
                'required' => $required,
                'allowed' => $allowed,
                'session' => $session,
                'action' => $this->action,
            ]);
            if (!is_array($extra)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的业务分支无法识别，请刷新当前工作台后重试。',
                    ['action' => $this->action, 'reason' => 'dynamic_policy_invalid']
                );
            }
            $required = array_values(array_unique(array_merge($required, (array)($extra['required'] ?? []))));
            $allowed = array_values(array_unique(array_merge($allowed, $required, (array)($extra['allowed'] ?? []))));
            foreach ((array)($extra['forbidden'] ?? []) as $forbidden) {
                $allowed = array_values(array_diff($allowed, [$forbidden]));
                if (in_array($forbidden, $required, true)) {
                    throw new \LogicException(sprintf('%s 的动态策略同时把 %s 列为必需与禁止', $this->action, $forbidden));
                }
            }
            foreach ((array)($extra['min_counts'] ?? []) as $kind => $count) {
                $kind = (string)$kind;
                $count = (int)$count;
                if ($kind === '' || $count < 1) {
                    continue;
                }
                $minCounts[$kind] = max((int)($minCounts[$kind] ?? 0), $count);
            }
            foreach ((array)($extra['identities'] ?? []) as $identity) {
                if (!is_array($identity)) {
                    continue;
                }
                $identities[] = $identity;
            }
            if (isset($extra['required_touched_roles']) && is_array($extra['required_touched_roles'])) {
                $touchedRoles = array_values(array_unique(array_merge($touchedRoles, $extra['required_touched_roles'])));
            }
            if (isset($extra['required_read_roles']) && is_array($extra['required_read_roles'])) {
                $readRoles = array_values(array_unique(array_merge($readRoles, $extra['required_read_roles'])));
            }
        }

        foreach (array_merge($required, $allowed, array_keys($minCounts)) as $kind) {
            CashierV3ResourceKindCatalog::assertKnown($kind);
        }
        foreach ($minCounts as $kind => $count) {
            if (!in_array($kind, $required, true)) {
                $required[] = $kind;
            }
            if (!in_array($kind, $allowed, true)) {
                $allowed[] = $kind;
            }
        }

        // 默认：未显式声明 read 时，所有 identities 为读依赖；touched 仅限声明集合
        if ($readRoles === [] && $identities) {
            $readRoles = array_values(array_unique(array_column($identities, 'role')));
        }
        // touched 不得机械等于全部 discovered；若动态未声明则回退静态 requiredTouchedRoles
        if ($touchedRoles === [] && $this->requiredTouchedRoles) {
            $touchedRoles = $this->requiredTouchedRoles;
        }

        $allowsEmptyContexts = $this->allowsEmptyContexts;
        if (isset($extra['allows_empty_contexts'])) {
            $allowsEmptyContexts = (bool)$extra['allows_empty_contexts'];
        }
        $out = [
            'action' => $this->action,
            'required' => array_values(array_unique($required)),
            'allowed' => array_values(array_unique($allowed)),
            'min_counts' => $minCounts,
            'identities' => $identities,
            'required_read_roles' => array_values(array_unique($readRoles)),
            'required_touched_roles' => array_values(array_unique($touchedRoles)),
            'allows_empty_contexts' => $allowsEmptyContexts,
        ];
        // Browser-owned checkout snapshots do not use catalog display rows as
        // Gateway version contexts. The final transaction resolves the sale
        // identity and performs inventory/entitlement/balance checks directly;
        // guest snapshots may therefore legitimately discover no extra
        // catalog resources.
        if ($this->action === 'submit-checkout'
            && is_array($payload['checkoutSnapshot'] ?? null)) {
            $out['allow_empty_server_resource_discovery'] = true;
            // The final snapshot owns no browser resource version. Server
            // discovery still locks the real resources, but they are domain
            // writes and must not be reported as Gateway projection bumps.
            $out['allows_empty_touched_result'] = true;
        }
        // 透传 Gateway／事务内依赖的动态合同字段（禁止在此丢弃）
        if ($this->dynamicResolver !== null && isset($extra) && is_array($extra)) {
            foreach ([
                'expand_from_checkout_request',
                'expand_from_checkout_resource_plan',
                'expand_from_server_resource_discovery',
                'server_resource_discoverer',
                'checkout_request_id',
                'deferred_identity_kinds',
                'normalized_payload',
                'server_checkout_sources',
                'allow_empty_server_resource_discovery',
                'allows_empty_contexts',
                'allows_empty_touched_result',
                'expand_from_server_resource_discovery',
            ] as $passKey) {
                if (array_key_exists($passKey, $extra)) {
                    $out[$passKey] = $extra[$passKey];
                }
            }
        }
        if ($this->serverResourceDiscoverer !== null
            && ($out['expand_from_server_resource_discovery'] ?? true) !== false) {
            $out['expand_from_server_resource_discovery'] = true;
            $out['server_resource_discoverer'] = $this->serverResourceDiscoverer;
        }
        return $out;
    }
}
