<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\cashier\v3\manifest;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\event\CashierV3BusinessEventContractRegistry;

/**
 * 收银 V3 action 清单（第一层）。
 *
 * 三层职责边界：
 * 1. **本层 manifest**：这个 action 存不存在、规范名是什么、是写命令还是只读投影、
 *    归哪个子任务、需要哪个功能入口权限。登记 ≠ 可执行。
 * 2. **context policy**（第二层）：已激活的写命令需要哪些既有资源版本。
 * 3. **handler registry**（第三层）：真正干活的处理器。
 *
 * 三层都齐了才允许执行；缺任一层一律 fail-closed：
 * - 完全没登记 → UNKNOWN_COMMAND_ACTION
 * - 登记了但没激活 → ACTION_NOT_IMPLEMENTED
 *
 * 前端 manifest（cashierV3ActionManifest.js）必须与本清单逐条一致，
 * 由自动核对测试保证，不靠人工比对。
 */
class CashierV3ActionManifest
{
    public const TYPE_COMMAND = 'command';
    public const TYPE_PROJECTION = 'projection';

    /** @var array<string,array>|null 惰性构建；不是跨请求缓存，进程内构建一次即可 */
    private static $cache = null;

    /**
     * @return CashierV3ActionModule[]
     */
    public static function modules(): array
    {
        return [
            new CashierV3C1WorkbenchModule(),
            new CashierV3C2CashierModule(),
            new CashierV3C3ServiceModule(),
            new CashierV3C4DashboardModule(),
            new CashierV3C5MemberOrderModule(),
        ];
    }

    /**
     * 供各模块组装自己的清单项，统一结构，避免四个模块各写各的。
     *
     * @param array<string,string> $command    规范写命令 => 功能入口权限码
     * @param array<string,string> $projection 只读投影   => 功能入口权限码
     * @param array<string,string> $aliases    页面 action => 规范 action
     * @return array<string,array>
     */
    public static function buildModuleActions(string $owner, array $command, array $projection, array $aliases = []): array
    {
        $actions = [];
        foreach ($command as $action => $permission) {
            $actions[$action] = self::definitionRow($action, self::TYPE_COMMAND, $owner, $permission);
        }
        foreach ($projection as $action => $permission) {
            $actions[$action] = self::definitionRow($action, self::TYPE_PROJECTION, $owner, $permission);
        }
        foreach ($aliases as $pageAction => $canonical) {
            if (!isset($actions[$canonical])) {
                throw new \LogicException(sprintf('别名 %s 指向未登记的规范 action %s', $pageAction, $canonical));
            }
            $row = $actions[$canonical];
            $row['canonical'] = $canonical;
            $row['alias_of'] = $canonical;
            $actions[$pageAction] = $row;
        }
        return $actions;
    }

    /**
     * @return array{canonical:string,type:string,owner:string,permission:string|null,permissionPolicyId:string,recovery:array}
     */
    private static function definitionRow(string $action, string $type, string $owner, string $permission): array
    {
        $policyId = $permission;
        $feature = $permission;
        if (strpos($permission, 'selector:') === 0 || strpos($permission, 'policy:') === 0) {
            $feature = null; // 选择器策略无单一 feature
        } elseif (strpos($permission, 'feature:') === 0) {
            $feature = substr($permission, strlen('feature:'));
            $policyId = $permission;
        } else {
            $policyId = 'feature:' . $permission;
            $feature = $permission;
        }
        $row = [
            'canonical' => $action,
            'type' => $type,
            'owner' => $owner,
            'permission' => $feature,
            'permissionPolicyId' => $policyId,
        ];
        if ($type === self::TYPE_COMMAND) {
            $row['recovery'] = self::recoveryContractFor($action);
            $contracts = self::eventContracts();
            // Keep the row constructible so selfCheck() can report a newly
            // added command that forgot its event decision. Direct lookups
            // still fail immediately in eventContractFor().
            $row['event_contract'] = $contracts[$action] ?? null;
        }
        return $row;
    }

    /**
     * Every command has an explicit event contract. Eventless commands are
     * intentionally documented so a newly added command cannot silently skip
     * the event decision.
     */
    public static function eventContractFor(string $action): array
    {
        $contracts = self::eventContracts();
        if (!array_key_exists($action, $contracts)) {
            throw new \LogicException(sprintf('command %s 未显式登记业务事件合同', $action));
        }
        return $contracts[$action];
    }

    /**
     * Exact canonical command => event contract registry.
     *
     * Every eventless entry is deliberate. Inactive domain commands must
     * replace their eventless contract in the same change that activates the
     * handler; they must never inherit a blanket fallback.
     *
     * @return array<string,array>
     */
    private static function eventContracts(): array
    {
        $workspaceDraft = '仅修改当前收银工作台草稿，未形成正式销售、收款或服务事件。';
        $checkoutPreparation = '仅准备或编辑结账请求，未达到正式结账成功终态。';
        $inactiveCheckout = '当前结账领域 handler 未激活；激活时必须同批登记销售、收款及权益事件。';
        $inactiveService = '当前服务领域 handler 未激活；激活时必须同批登记服务、核销及业绩事件。';
        $inactiveReservation = '当前预约领域 handler 未激活；激活时必须同批登记预约状态事件。';
        $inactiveHang = '当前挂单领域 handler 未激活；激活时必须同批登记挂单状态事件。';
        $inactiveWriteoff = '当前核销领域 handler 未激活；激活时必须同批登记核销、消耗业绩及劳动业绩事件。';
        $selection = '仅改变当前工作台的会员或游客选择，不改变会员权益。';
        $queryPreference = '仅保存当前账号的查询偏好，不是经营业务事件。';
        $queryMetadata = '仅维护统一查询字段、显示名称或导出任务元数据，不改变经营事实。';
        $inactiveOrder = '当前订单变更 handler 未激活；激活时必须同批登记退款、作废、重开或升级事件。';

        $eventless = static function (string $reason): array {
            return [
                'required_event_types' => [],
                'allowed_event_types' => [],
                'event_rules' => [],
                'eventless_reason' => $reason,
                'activation_blocked_until_event_contract' => false,
                'consumers' => [],
            ];
        };
        $deferred = static function (string $reason) use ($eventless): array {
            $contract = $eventless($reason);
            $contract['activation_blocked_until_event_contract'] = true;
            return $contract;
        };

        return [
            // C2 | workspace/cart
            'choose-catalog-item' => $eventless($workspaceDraft),
            'remove-cart-line' => $eventless($workspaceDraft),
            'change-cart-line-quantity' => $eventless($workspaceDraft),
            'select-cashier-member' => $eventless($selection),
            'set-guest-order' => $eventless($selection),
            'change-supplement-date' => $eventless($workspaceDraft),
            'exit-supplement' => $eventless($workspaceDraft),

            // C2 | checkout request preparation and inactive settlement
            'prepare-checkout' => $eventless($checkoutPreparation),
            'prepare-debt-repayment' => $eventless($checkoutPreparation),
            'checkout-step-back' => $eventless($checkoutPreparation),
            'checkout-step-next' => $eventless($checkoutPreparation),
            'toggle-combination-payment' => $eventless($checkoutPreparation),
            'add-payment-method' => $eventless($checkoutPreparation),
            'update-payment-line' => $eventless($checkoutPreparation),
            'remove-payment-line' => $eventless($checkoutPreparation),
            'confirm-debt-warning' => $eventless($checkoutPreparation),
            'confirm-checkout-final-changes' => $eventless($checkoutPreparation),
            'submit-checkout' => $deferred($inactiveCheckout),
            'submit-debt-repayment' => $deferred($inactiveCheckout),
            'return-to-payment-edit' => $eventless($checkoutPreparation),
            'retry-checkout' => $deferred($inactiveCheckout),
            'continue-partial-payment-recovery' => $deferred($inactiveCheckout),
            'go-to-writeoff-after-checkout' => $eventless($checkoutPreparation),
            'finish-checkout-and-return' => $eventless($checkoutPreparation),

            // C3 | service and checkout preparation
            'prepare-reservation-checkout' => $eventless($checkoutPreparation),
            'prepare-room-service-checkout' => $eventless($checkoutPreparation),
            'prepare-service-checkout' => $eventless($checkoutPreparation),
            'confirm-service-completion' => $deferred($inactiveService),
            'save-service-line-completion' => $deferred($inactiveService),
            'save-service-line-craftsmen' => $deferred($inactiveService),
            'finish-service-completion' => $deferred($inactiveService),
            'retry-service-completion' => $deferred($inactiveService),
            'return-to-service-edit' => $deferred($inactiveService),
            'continue-service-checkout' => $deferred($inactiveService),
            'save-service-room-assignment' => $deferred($inactiveService),

            // C3 | reservation
            'confirm-reservation' => $deferred($inactiveReservation),
            'start-reservation-service' => $deferred($inactiveReservation),
            'cancel-reservation' => $deferred($inactiveReservation),
            'reject-reservation' => $deferred($inactiveReservation),
            'mark-reservation-no-show' => $deferred($inactiveReservation),
            'create-reservation' => $deferred($inactiveReservation),
            'update-reservation' => $deferred($inactiveReservation),
            'select-reservation-member' => $eventless($selection),

            // C3 | hang order
            'submit-hang-order' => $deferred($inactiveHang),
            'resume-hang-order' => $deferred($inactiveHang),

            // C3 | writeoff
            'submit-writeoff' => $deferred($inactiveWriteoff),
            'toggle-writeoff-project' => $deferred($inactiveWriteoff),
            'change-writeoff-project-times' => $deferred($inactiveWriteoff),
            'change-writeoff-supplement-date' => $deferred($inactiveWriteoff),
            'exit-writeoff-supplement' => $deferred($inactiveWriteoff),
            'clear-writeoff-selection' => $deferred($inactiveWriteoff),
            'return-to-writeoff-edit' => $deferred($inactiveWriteoff),
            'start-service-from-writeoff' => $deferred($inactiveWriteoff),
            'select-writeoff-member' => $eventless($selection),

            // C5 | member
            'create-member' => [
                'required_event_types' => ['member.created'],
                'allowed_event_types' => ['member.created'],
                'event_rules' => [
                    'member.created' => [
                        'min_count' => 1,
                        'max_count' => 1,
                        'aggregate_type' => 'member',
                        'source_type' => 'create-member',
                        'aggregate_version' => 1,
                    ],
                ],
                'eventless_reason' => '',
                'activation_blocked_until_event_contract' => false,
                'consumers' => ['member.created' => []],
            ],

            // C5 | order mutation and account query preferences
            'refund-sales-order' => $deferred($inactiveOrder),
            'void-sales-order' => $deferred($inactiveOrder),
            'reopen-sales-order' => $deferred($inactiveOrder),
            'upgrade-sales-order' => $deferred($inactiveOrder),
            'print-sales-order-receipt' => $eventless('仅触发已存在订单的打印输出，不改变订单或资金状态。'),
            'save-reservation-query-settings' => $eventless($queryPreference),
            'save-hang-order-query-settings' => $eventless($queryPreference),
            'save-order-center-query-settings' => $eventless($queryPreference),
            'save-member-query-settings' => $eventless($queryPreference),
            'save-unified-query-field-aliases' => $eventless($queryMetadata),
            'save-unified-query-custom-field' => $eventless($queryMetadata),
            'change-unified-query-custom-field-status' => $eventless($queryMetadata),
            'archive-unified-query-custom-field' => $eventless($queryMetadata),
            'upgrade-unified-query-field-reference' => $eventless($queryPreference),
            'create-unified-query-export' => $eventless($queryMetadata),
        ];
    }

    /**
     * 每个 command 必须有确定恢复策略；禁止空恢复。
     * 任何恢复都不得生成新幂等键。
     *
     * @return array{mode:string,queryResultAction?:string}
     */
    public static function recoveryContractFor(string $action): array
    {
        static $queryMap = [
            'submit-checkout' => 'query-checkout-result',
            'retry-checkout' => 'query-checkout-result',
            'confirm-service-completion' => 'query-service-completion-result',
            'retry-service-completion' => 'query-service-completion-result',
            'submit-hang-order' => 'query-hang-order-result',
            'submit-writeoff' => 'query-writeoff-result',
            'create-reservation' => 'query-reservation-result',
            'update-reservation' => 'query-reservation-result',
            'create-unified-query-export' => 'query-unified-query-export-task',
        ];
        if (isset($queryMap[$action])) {
            return [
                'mode' => 'result_query',
                'queryResultAction' => $queryMap[$action],
            ];
        }
        // 默认：同 payload／同 contexts／同幂等键安全重试（不得生成新键）
        return [
            'mode' => 'same_idempotency_retry',
        ];
    }

    /**
     * @return array<string,array{canonical:string,type:string,owner:string,permission:string|null}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $merged = [];
        foreach (self::modules() as $module) {
            foreach ($module->actions() as $action => $definition) {
                if (isset($merged[$action])) {
                    throw new \LogicException(sprintf(
                        'action %s 被 %s 与 %s 重复登记',
                        $action,
                        $merged[$action]['owner'],
                        $definition['owner']
                    ));
                }
                $merged[$action] = $definition;
            }
        }
        ksort($merged);
        self::$cache = $merged;
        return $merged;
    }

    /** 仅供测试在改动模块后重建清单 */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    public static function has(string $action): bool
    {
        $all = self::all();
        return isset($all[$action]);
    }

    /**
     * @return array{action:string,canonical:string,type:string,owner:string,permission:string|null}
     */
    public static function requireAction(string $action): array
    {
        $all = self::all();
        if (!isset($all[$action])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::UNKNOWN_COMMAND_ACTION,
                '该操作尚未开放，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $action]
            );
        }
        return ['action' => $action] + $all[$action];
    }

    /**
     * 页面 action → 规范 action。未登记时按原样返回，由 require() 统一报错。
     */
    public static function canonicalOf(string $action): string
    {
        $all = self::all();
        return isset($all[$action]) ? (string)$all[$action]['canonical'] : $action;
    }

    /**
     * @return array<string,string> 页面 action => 规范 action（只含真正的别名）
     */
    public static function aliases(): array
    {
        $aliases = [];
        foreach (self::all() as $action => $definition) {
            if (isset($definition['alias_of'])) {
                $aliases[$action] = (string)$definition['alias_of'];
            }
        }
        return $aliases;
    }

    /**
     * 契约自检：清单本身是否自洽。
     *
     * @return string[] 违规说明；空数组表示通过
     */
    public static function selfCheck(array $additionalCanonicalCommands = []): array
    {
        $problems = [];
        $all = self::all();
        $canonicalCommands = [];
        foreach ($all as $action => $definition) {
            if (!in_array($definition['type'], [self::TYPE_COMMAND, self::TYPE_PROJECTION], true)) {
                $problems[] = sprintf('action %s 的类型非法：%s', $action, (string)$definition['type']);
            }
            if (!in_array($definition['owner'], ['C1', 'C2', 'C3', 'C4', 'C5'], true)) {
                $problems[] = sprintf('action %s 的 owner 非法：%s', $action, (string)$definition['owner']);
            }
            if (!array_key_exists('permissionPolicyId', $definition) || $definition['permissionPolicyId'] === null || $definition['permissionPolicyId'] === '') {
                if (!array_key_exists('permission', $definition) || $definition['permission'] === null) {
                    $problems[] = sprintf('action %s 没有权限策略', $action);
                }
            }
            if ($definition['type'] === self::TYPE_COMMAND && !isset($definition['alias_of'])) {
                $canonicalCommands[] = $action;
                $recovery = $definition['recovery'] ?? null;
                if (!is_array($recovery) || empty($recovery['mode'])) {
                    $problems[] = sprintf('command %s 缺少恢复策略', $action);
                }
                try {
                    CashierV3BusinessEventContractRegistry::normalize($definition, $action);
                } catch (CashierV3CommandException $e) {
                    $problems[] = sprintf('command %s 事件合同无效：%s', $action, $e->getResultCode());
                }
            }
            $canonical = (string)$definition['canonical'];
            if (!isset($all[$canonical])) {
                $problems[] = sprintf('action %s 的规范名 %s 未登记', $action, $canonical);
                continue;
            }
            if ($all[$canonical]['type'] !== $definition['type']) {
                $problems[] = sprintf('别名 %s 与规范名 %s 的类型不一致', $action, $canonical);
            }
            if (isset($definition['alias_of']) && $all[$canonical]['canonical'] !== $canonical) {
                $problems[] = sprintf('别名 %s 指向的 %s 本身又是别名，不允许链式别名', $action, $canonical);
            }
        }
        $canonicalCommands = array_values(array_unique(array_merge($canonicalCommands, $additionalCanonicalCommands)));
        $contracts = self::eventContracts();
        foreach ($canonicalCommands as $action) {
            $action = trim((string)$action);
            if ($action !== '' && !array_key_exists($action, $contracts)) {
                $problems[] = sprintf('command %s 未显式登记业务事件合同', $action);
            }
        }
        foreach (array_keys($contracts) as $action) {
            if (!in_array($action, $canonicalCommands, true)) {
                $problems[] = sprintf('业务事件合同 %s 没有对应的规范 command', $action);
            }
        }
        return $problems;
    }

    /**
     * @return array<string,array> 仅规范写命令
     */
    public static function commandActions(): array
    {
        $out = [];
        foreach (self::all() as $action => $definition) {
            if ($definition['type'] === self::TYPE_COMMAND && !isset($definition['alias_of'])) {
                $out[$action] = $definition;
            }
        }
        return $out;
    }

    /**
     * @return array<string,array> 仅只读投影
     */
    public static function projectionActions(): array
    {
        $out = [];
        foreach (self::all() as $action => $definition) {
            if ($definition['type'] === self::TYPE_PROJECTION && !isset($definition['alias_of'])) {
                $out[$action] = $definition;
            }
        }
        return $out;
    }
}
