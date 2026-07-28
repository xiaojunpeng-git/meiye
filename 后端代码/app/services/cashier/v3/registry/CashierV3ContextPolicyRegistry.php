<?php
namespace app\services\cashier\v3\registry;

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\manifest\CashierV3C3ServiceModule;

/**
 * 写命令 contexts 执行合同注册表（第二层）。
 */
class CashierV3ContextPolicyRegistry
{
    /** @var array<string,CashierV3ContextPolicy> */
    protected $policies = [];

    /** @var bool */
    protected $frozen = false;

    /**
     * @param bool $autoRegisterIncompleteC2C3 生产 C1 Bootstrap 必须传 false：
     *   不自动激活不完整的结账来源猜测策略；C2／C3 接入后再显式 register。
     */
    public function __construct(bool $autoRegisterIncompleteC2C3 = false)
    {
        if ($autoRegisterIncompleteC2C3) {
            // 仅测试兼容；生产禁止
            $this->registerFrozenPolicies();
        }
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function register(CashierV3ContextPolicy $policy): void
    {
        if ($this->frozen) {
            throw new \LogicException('context policy registry 已 freeze，禁止继续注册');
        }
        $action = $policy->action();
        $definition = CashierV3ActionManifest::requireAction($action);
        if ($definition['type'] !== CashierV3ActionManifest::TYPE_COMMAND) {
            throw new \LogicException(sprintf('只读投影 %s 不能登记 context policy', $action));
        }
        if ($definition['canonical'] !== $action) {
            throw new \LogicException(sprintf('context policy 必须登记在规范 action 上，%s 是别名', $action));
        }
        if (isset($this->policies[$action])) {
            throw new \LogicException(sprintf('context policy %s 重复注册', $action));
        }
        $this->policies[$action] = $policy;
    }

    public function has(string $canonicalAction): bool
    {
        return isset($this->policies[$canonicalAction]);
    }

    public function requirePolicy(string $canonicalAction): CashierV3ContextPolicy
    {
        if (!isset($this->policies[$canonicalAction])) {
            throw new CashierV3CommandException(
                CashierV3ResultCode::ACTION_NOT_IMPLEMENTED,
                '该操作尚未开放，请联系管理员。',
                CashierV3ResultCode::STATUS_FAILED,
                ['action' => $canonicalAction, 'missing' => 'context_policy']
            );
        }
        return $this->policies[$canonicalAction];
    }

    /** @return string[] */
    public function registeredActions(): array
    {
        $actions = array_keys($this->policies);
        sort($actions);
        return $actions;
    }

    protected function registerFrozenPolicies(): void
    {
        $this->register(new CashierV3ContextPolicy(
            'prepare-checkout',
            ['cashier_workspace'],
            ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room'],
            [$this, 'resolveCheckoutSourceBranch'],
            ['cashier_workspace'],
            ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room'],
            ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room']
        ));

        $this->register(new CashierV3ContextPolicy(
            'prepare-debt-repayment',
            ['cashier_workspace', 'debt_record'],
            [],
            [$this, 'resolveCheckoutSourceBranch'],
            ['cashier_workspace'],
            ['debt_record'],
            ['debt_record']
        ));

        foreach (CashierV3C3ServiceModule::CHECKOUT_ALIASES as $pageAction => $canonical) {
            $this->register(new CashierV3ContextPolicy(
                $canonical,
                self::checkoutEntryRequired($canonical),
                ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room'],
                [$this, 'resolveCheckoutSourceBranch'],
                array_merge(['cashier_workspace'], array_values(array_diff(self::checkoutEntryRequired($canonical), ['cashier_workspace']))),
                ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room'],
                ['service_order', 'checkout_request', 'hang_order', 'reservation', 'room']
            ));
        }

        foreach ([
            'checkout-step-back',
            'checkout-step-next',
            'toggle-combination-payment',
            'add-payment-method',
            'update-payment-line',
            'remove-payment-line',
            'confirm-debt-warning',
            'confirm-checkout-final-changes',
            'return-to-payment-edit',
            'retry-checkout',
            'continue-partial-payment-recovery',
            'go-to-writeoff-after-checkout',
            'finish-checkout-and-return',
            'submit-debt-repayment',
        ] as $action) {
            // 后续步骤：客户端只提交 checkout_request；真实来源在事务内从持久化记录反推
            $this->register(new CashierV3ContextPolicy(
                $action,
                ['cashier_workspace', 'checkout_request'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
                [$this, 'resolveCheckoutFollowUpBranch'],
                ['cashier_workspace', 'checkout_request'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
                ['service_order', 'hang_order', 'reservation', 'room', 'debt_record']
            ));
        }

        $this->register(new CashierV3ContextPolicy(
            'submit-checkout',
            ['cashier_workspace', 'checkout_request'],
            ['service_order', 'hang_order', 'reservation', 'room'],
            [$this, 'resolveCheckoutFollowUpBranch'],
            ['cashier_workspace', 'checkout_request'],
            ['service_order', 'hang_order', 'reservation', 'room'],
            ['service_order', 'hang_order', 'reservation', 'room']
        ));

        $this->register(new CashierV3ContextPolicy(
            'save-service-room-assignment',
            [],
            [],
            [$this, 'resolveRoomAssignmentBranch'],
            ['cashier_workspace'],
            ['subject', 'current_room', 'target_room', 'current_room_time_slot', 'target_room_time_slot'],
            ['reservation', 'service_order', 'room', 'room_time_slot']
        ));
    }

    /** @return string[] */
    protected static function checkoutEntryRequired(string $canonical): array
    {
        switch ($canonical) {
            case 'prepare-reservation-checkout':
                return ['cashier_workspace', 'reservation'];
            case 'prepare-room-service-checkout':
                return ['cashier_workspace', 'room'];
            case 'prepare-service-checkout':
            default:
                return ['cashier_workspace', 'service_order'];
        }
    }

    /**
     * 结账来源分支。
     *
     * 入口步：来源从 payload 发现（读依赖）；touched 仅 workspace（及入口实际写入的对象）。
     * 后续步：唯一来源必须是已持久化的 checkout_request；锁定后从服务端身份反推，
     * payload 里额外出现的来源 ID 只作为读依赖，不得机械推进其版本。
     */
    public function resolveCheckoutSourceBranch(array $payload, array $base): array
    {
        $action = (string)($base['action'] ?? '');
        $session = is_array($base['session'] ?? null) ? $base['session'] : [];
        $staticAllowed = array_values(array_unique(array_merge($base['allowed'] ?? [], $base['required'] ?? [])));
        $staticRequired = array_values(array_unique($base['required'] ?? []));
        $isFollowUp = in_array('checkout_request', $staticRequired, true);

        $idMap = [
            'service_order' => self::canonicalId($payload, ['serviceOrderId', 'service_order_id']),
            'checkout_request' => self::canonicalId($payload, ['checkoutRequestId', 'checkout_request_id']),
            'hang_order' => self::canonicalId($payload, ['hangOrderId', 'hang_order_id']),
            'reservation' => self::canonicalId($payload, ['reservationId', 'reservation_id']),
            'room' => self::canonicalId($payload, ['roomId', 'room_id']),
            'debt_record' => self::canonicalId($payload, ['debtRecordId', 'debt_record_id', 'debtItemId', 'debt_item_id']),
        ];

        $discovered = [];
        foreach ($idMap as $kind => $id) {
            if ($id !== '') {
                $discovered[$kind] = $id;
            }
        }

        foreach ($discovered as $kind => $id) {
            if (!in_array($kind, $staticAllowed, true)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作携带了不适用的业务对象，请刷新当前工作台后重试。',
                    ['action' => $action, 'kind' => $kind, 'reason' => 'discovered_kind_not_allowed_for_action']
                );
            }
        }

        $identities = [];
        $readRoles = [];
        $touchedRoles = ['cashier_workspace'];

        $workspaceId = self::resolveWorkspaceId($session, $payload);
        $identities[] = [
            'role' => 'cashier_workspace',
            'kind' => 'cashier_workspace',
            'id' => $workspaceId,
            'required' => true,
        ];
        $readRoles[] = 'cashier_workspace';

        $required = ['cashier_workspace'];

        if ($isFollowUp) {
            // 后续步骤：客户端只允许提交 checkout_request；其它来源由 Gateway 事务内从持久化记录反推
            $checkoutId = $idMap['checkout_request'] ?? '';
            if ($checkoutId === '') {
                throw CashierV3CommandException::invalidContext(
                    '本次操作缺少必需的对象标识，请刷新当前工作台后重试。',
                    ['action' => $action, 'kind' => 'checkout_request', 'reason' => 'required_identity_id_missing']
                );
            }
            foreach ($discovered as $kind => $id) {
                if ($kind !== 'checkout_request') {
                    throw CashierV3CommandException::invalidContext(
                        '结账后续步骤不得从客户端 payload 夹带来源对象，请刷新后重试。',
                        ['action' => $action, 'kind' => $kind, 'reason' => 'follow_up_source_must_come_from_checkout_request']
                    );
                }
            }
            $required[] = 'checkout_request';
            $identities[] = [
                'role' => 'checkout_request',
                'kind' => 'checkout_request',
                'id' => $checkoutId,
                'required' => true,
            ];
            $readRoles[] = 'checkout_request';
            $touchedRoles[] = 'checkout_request';
            return [
                'required' => array_values(array_unique($required)),
                'allowed' => ['service_order', 'hang_order', 'reservation', 'room', 'debt_record'],
                'identities' => $identities,
                'required_read_roles' => array_values(array_unique($readRoles)),
                'required_touched_roles' => array_values(array_unique($touchedRoles)),
                'expand_from_checkout_request' => true,
                'checkout_request_id' => $checkoutId,
            ];
        } else {
            // 入口步：discovered 来源进入读依赖；touched 仅 workspace（prepare 试算不推进来源版本）
            // 实际写入来源对象的 C2 命令应在动态策略里显式追加 touched
            foreach ($discovered as $kind => $id) {
                $required[] = $kind;
                $identities[] = [
                    'role' => $kind,
                    'kind' => $kind,
                    'id' => $id,
                    'required' => true,
                ];
                $readRoles[] = $kind;
            }
            foreach ($staticRequired as $kind) {
                if ($kind === 'cashier_workspace') {
                    continue;
                }
                if (!isset($discovered[$kind])) {
                    $id = $idMap[$kind] ?? '';
                    if ($id === '') {
                        throw CashierV3CommandException::invalidContext(
                            '本次操作缺少必需的对象标识，请刷新当前工作台后重试。',
                            ['action' => $action, 'kind' => $kind, 'reason' => 'required_identity_id_missing']
                        );
                    }
                    $required[] = $kind;
                    $identities[] = [
                        'role' => $kind,
                        'kind' => $kind,
                        'id' => $id,
                        'required' => true,
                    ];
                    $readRoles[] = $kind;
                }
            }
        }

        return [
            'required' => array_values(array_unique($required)),
            'allowed' => [],
            'identities' => $identities,
            'required_read_roles' => array_values(array_unique($readRoles)),
            'required_touched_roles' => array_values(array_unique($touchedRoles)),
        ];
    }

    /**
     * 结账后续步骤：仅锁定 checkout_request；真实来源在 Gateway 事务内反推。
     */
    public function resolveCheckoutFollowUpBranch(array $payload, array $base): array
    {
        $base['required'] = ['cashier_workspace', 'checkout_request'];
        $base['allowed'] = ['service_order', 'hang_order', 'reservation', 'room', 'checkout_request'];
        return $this->resolveCheckoutSourceBranch($payload, $base);
    }

    /**
     * 房间安排：assignmentScope = reservation_plan｜active_service；
     * mode 与 current／target 使用穷举合同，禁止静默改写 mode。
     */
    public function resolveRoomAssignmentBranch(array $payload, array $base): array
    {
        $normalized = \app\services\cashier\v3\CashierV3RequestNormalizer::normalize(
            'save-service-room-assignment',
            $payload
        );
        $payload = $normalized['normalized'];
        $scope = \app\services\cashier\v3\CashierV3AliasResolver::resolveEnum(
            $payload,
            ['assignmentScope', 'assignment_scope'],
            true
        );
        $mode = (string)($payload['assignmentMode'] ?? $payload['mode'] ?? '');
        $session = is_array($base['session'] ?? null) ? $base['session'] : [];

        $required = ['cashier_workspace'];
        $forbidden = [];
        $identities = [];
        $touched = ['cashier_workspace'];
        $readRoles = ['cashier_workspace'];

        $workspaceId = self::resolveWorkspaceId($session, $payload);
        $identities[] = [
            'role' => 'cashier_workspace',
            'kind' => 'cashier_workspace',
            'id' => $workspaceId,
            'required' => true,
        ];

        switch ($scope) {
            case 'reservation_plan':
                $reservationId = self::canonicalId($payload, ['reservationId', 'reservation_id']);
                if ($reservationId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '房间安排缺少预约对象，请刷新当前工作台后重试。',
                        ['action' => 'save-service-room-assignment', 'reason' => 'reservation_id_missing']
                    );
                }
                $required[] = 'reservation';
                $forbidden[] = 'service_order';
                $identities[] = [
                    'role' => 'subject',
                    'kind' => 'reservation',
                    'id' => $reservationId,
                    'required' => true,
                ];
                $touched[] = 'subject';
                $readRoles[] = 'subject';
                break;
            case 'active_service':
                $serviceOrderId = self::canonicalId($payload, ['serviceOrderId', 'service_order_id']);
                if ($serviceOrderId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '房间安排缺少服务单对象，请刷新当前工作台后重试。',
                        ['action' => 'save-service-room-assignment', 'reason' => 'service_order_id_missing']
                    );
                }
                $required[] = 'service_order';
                $forbidden[] = 'reservation';
                $identities[] = [
                    'role' => 'subject',
                    'kind' => 'service_order',
                    'id' => $serviceOrderId,
                    'required' => true,
                ];
                $touched[] = 'subject';
                $readRoles[] = 'subject';
                break;
            case 'reservation':
            case 'service':
                throw CashierV3CommandException::invalidContext(
                    '房间安排范围无效，请刷新当前工作台后重试。',
                    ['action' => 'save-service-room-assignment', 'reason' => 'assignment_scope_legacy_rejected', 'scope' => $scope]
                );
            default:
                throw CashierV3CommandException::invalidContext(
                    '房间安排范围未明确，请刷新当前工作台后重试。',
                    ['action' => 'save-service-room-assignment', 'reason' => 'assignment_scope_invalid', 'scope' => $scope]
                );
        }

        $currentRoomId = array_key_exists('currentRoomId', $payload) ? $payload['currentRoomId'] : null;
        $targetRoomId = array_key_exists('targetRoomId', $payload) ? $payload['targetRoomId'] : null;

        $minCounts = [];
        switch ($mode) {
            case 'keep_unassigned':
                break;
            case 'assign':
                $required[] = 'room';
                $required[] = 'room_time_slot';
                $minCounts = ['room' => 1, 'room_time_slot' => 1];
                $targetSlotId = self::canonicalId($payload, ['targetRoomTimeSlotId', 'target_room_time_slot_id', 'roomTimeSlotId', 'room_time_slot_id']);
                if ($targetSlotId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '分配房间时必须指定目标时段。',
                        ['action' => 'save-service-room-assignment', 'reason' => 'target_slot_required']
                    );
                }
                $identities[] = ['role' => 'target_room', 'kind' => 'room', 'id' => (string)$targetRoomId, 'required' => true];
                $identities[] = ['role' => 'target_room_time_slot', 'kind' => 'room_time_slot', 'id' => $targetSlotId, 'required' => true];
                $touched[] = 'target_room';
                $touched[] = 'target_room_time_slot';
                $readRoles = array_merge($readRoles, ['target_room', 'target_room_time_slot']);
                break;
            case 'change':
                $required[] = 'room';
                $required[] = 'room_time_slot';
                $minCounts = ['room' => 2, 'room_time_slot' => 2];
                $currentSlotId = self::canonicalId($payload, ['currentRoomTimeSlotId', 'current_room_time_slot_id']);
                $targetSlotId = self::canonicalId($payload, ['targetRoomTimeSlotId', 'target_room_time_slot_id']);
                if ($currentSlotId === '' || $targetSlotId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '换房必须同时锁定当前与目标时段。',
                        ['action' => 'save-service-room-assignment', 'reason' => 'change_slots_required']
                    );
                }
                $identities[] = ['role' => 'current_room', 'kind' => 'room', 'id' => (string)$currentRoomId, 'required' => true];
                $identities[] = ['role' => 'target_room', 'kind' => 'room', 'id' => (string)$targetRoomId, 'required' => true];
                $identities[] = ['role' => 'current_room_time_slot', 'kind' => 'room_time_slot', 'id' => $currentSlotId, 'required' => true];
                $identities[] = ['role' => 'target_room_time_slot', 'kind' => 'room_time_slot', 'id' => $targetSlotId, 'required' => true];
                $touched = array_merge($touched, ['current_room', 'target_room', 'current_room_time_slot', 'target_room_time_slot']);
                $readRoles = array_merge($readRoles, ['current_room', 'target_room', 'current_room_time_slot', 'target_room_time_slot']);
                break;
            case 'remove':
                $required[] = 'room';
                $required[] = 'room_time_slot';
                $minCounts = ['room' => 1, 'room_time_slot' => 1];
                $currentSlotId = self::canonicalId($payload, ['currentRoomTimeSlotId', 'current_room_time_slot_id', 'roomTimeSlotId', 'room_time_slot_id']);
                if ($currentSlotId === '') {
                    throw CashierV3CommandException::invalidContext(
                        '移除房间占用时必须锁定当前时段。',
                        ['action' => 'save-service-room-assignment', 'reason' => 'remove_slot_required']
                    );
                }
                $identities[] = ['role' => 'current_room', 'kind' => 'room', 'id' => (string)$currentRoomId, 'required' => true];
                $identities[] = ['role' => 'current_room_time_slot', 'kind' => 'room_time_slot', 'id' => $currentSlotId, 'required' => true];
                $touched[] = 'current_room';
                $touched[] = 'current_room_time_slot';
                $readRoles = array_merge($readRoles, ['current_room', 'current_room_time_slot']);
                $forbidden = array_values(array_diff($forbidden, ['room', 'room_time_slot']));
                break;
            default:
                throw CashierV3CommandException::invalidContext(
                    '房间安排方式未明确，请刷新当前工作台后重试。',
                    ['action' => 'save-service-room-assignment', 'reason' => 'assignment_mode_invalid', 'mode' => $mode]
                );
        }

        return [
            'required' => array_values(array_unique($required)),
            'allowed' => [],
            'forbidden' => array_values(array_unique($forbidden)),
            'min_counts' => $minCounts,
            'identities' => $identities,
            'required_read_roles' => array_values(array_unique($readRoles)),
            'required_touched_roles' => array_values(array_unique($touched)),
            'normalized_payload' => $payload,
        ];
    }

    private static function resolveWorkspaceId(array $session, array $payload): string
    {
        $fromSession = trim((string)($session['workspace_id'] ?? ''));
        if ($fromSession !== '') {
            return $fromSession;
        }
        $storeId = (int)($session['store_id'] ?? 0);
        $operatorId = (int)($session['operator_id'] ?? 0);
        $stateContextId = trim((string)($session['state_context_id'] ?? ''));
        if ($storeId > 0 && $operatorId > 0) {
            return $stateContextId !== ''
                ? sprintf('ws:%d:%d:%s', $storeId, $operatorId, $stateContextId)
                : sprintf('ws:%d:%d', $storeId, $operatorId);
        }
        throw CashierV3CommandException::invalidContext(
            '当前工作台会话无效，请刷新页面后重试。',
            ['reason' => 'workspace_identity_unresolved']
        );
    }

    /** @param string[] $keys */
    public static function canonicalId(array $payload, array $keys): string
    {
        return \app\services\cashier\v3\CashierV3AliasResolver::resolveString($payload, $keys, false);
    }
}
