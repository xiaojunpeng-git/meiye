<?php
/**
 * C1-A 第四轮永久单元契约。
 */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';

function section(string $title): void
{
    echo "== {$title} ==\n";
}

use app\services\cashier\v3\CashierV3AliasResolver;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeFactory;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\CashierV3ResourceLockServices;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\event\CashierV3ActionActivationGate;
use app\services\cashier\v3\event\CashierV3BusinessEventContractRegistry;
use app\services\cashier\v3\event\CashierV3EventConsumerRegistry;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use app\services\cashier\v3\permission\CashierV3PermissionPolicyRegistry;
use app\services\cashier\v3\permission\CashierV3SelectorGrantServices;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\projection\CashierV3RootStateContract;
use app\services\cashier\v3\registry\CashierV3ContextPolicyRegistry;
use app\services\cashier\v3\registry\CashierV3HandlerRegistry;
use app\services\cashier\v3\registry\CashierV3PermissionGuard;
use app\services\organization\EmployeeDataScopeServices;

CashierV3ActionManifest::flushCache();
CashierV3Bootstrap::resetForTests();

section('manifest / kind / recovery');
$problems = array_merge(CashierV3ActionManifest::selfCheck(), CashierV3ResourceKindCatalog::selfCheck());
ok('manifest+kind selfCheck empty', $problems === [], implode('; ', $problems));
ok('query_preference account scope', CashierV3ResourceKindCatalog::scopeTypeOf('query_preference') === CashierV3ResourceScope::TYPE_ACCOUNT);

$futureEventProblems = CashierV3ActionManifest::selfCheck(['future-command-without-event-contract']);
ok(
    '新 command 未显式登记事件合同时 selfCheck 失败',
    count(array_filter($futureEventProblems, function (string $problem): bool {
        return strpos($problem, 'future-command-without-event-contract 未显式登记业务事件合同') !== false;
    })) === 1,
    implode('; ', $futureEventProblems),
    'EO-15-01'
);
$unknownEventContractRejected = false;
try {
    CashierV3ActionManifest::eventContractFor('future-command-without-event-contract');
} catch (\LogicException $exception) {
    $unknownEventContractRejected = strpos($exception->getMessage(), '未显式登记') !== false;
}
ok('未知 command 直接查事件合同时 fail-closed', $unknownEventContractRejected, '', 'EO-15-01');
$memberEventContract = CashierV3ActionManifest::eventContractFor('create-member');
ok(
    'create-member 事件类型、基数、聚合与来源精确',
    ($memberEventContract['event_rules']['member.created']['min_count'] ?? 0) === 1
        && ($memberEventContract['event_rules']['member.created']['max_count'] ?? 0) === 1
        && ($memberEventContract['event_rules']['member.created']['aggregate_type'] ?? '') === 'member'
        && ($memberEventContract['event_rules']['member.created']['source_type'] ?? '') === 'create-member'
        && ($memberEventContract['event_rules']['member.created']['aggregate_version'] ?? 0) === 1,
    json_encode($memberEventContract, JSON_UNESCAPED_UNICODE),
    'EO-15-02'
);
$deferredCheckoutContract = CashierV3BusinessEventContractRegistry::normalize(
    CashierV3ActionManifest::requireAction('submit-checkout'),
    'submit-checkout'
);
$workspaceDraftContract = CashierV3BusinessEventContractRegistry::normalize(
    CashierV3ActionManifest::requireAction('choose-catalog-item'),
    'choose-catalog-item'
);
ok(
    '延后业务 command 注册 handler 后仍因缺 production event contract 禁止激活',
    CashierV3BusinessEventContractRegistry::activationProblems($deferredCheckoutContract, true)
        === ['production_event_contract']
        && CashierV3BusinessEventContractRegistry::activationProblems($deferredCheckoutContract, false) === []
        && CashierV3BusinessEventContractRegistry::activationProblems($workspaceDraftContract, true) === [],
    json_encode([
        'deferred' => $deferredCheckoutContract,
        'draft' => $workspaceDraftContract,
    ], JSON_UNESCAPED_UNICODE),
    'EO-15-02'
);

$emptyConsumerRegistry = new CashierV3EventConsumerRegistry();
$emptyConsumerRegistry->freeze();
$runtimeActivationGate = new CashierV3ActionActivationGate($emptyConsumerRegistry);
$blockedAtRuntime = false;
$blockedMissing = [];
try {
    $runtimeActivationGate->assertExecutable(
        CashierV3ActionManifest::requireAction('submit-checkout'),
        'submit-checkout'
    );
} catch (CashierV3CommandException $exception) {
    $blockedAtRuntime = $exception->getResultCode() === CashierV3ResultCode::ACTION_NOT_IMPLEMENTED;
    $blockedMissing = (array)($exception->getDetail()['missing'] ?? []);
}
ok(
    '延后业务合同被真实运行时门禁拒绝',
    $blockedAtRuntime && in_array('production_event_contract', $blockedMissing, true),
    json_encode($blockedMissing, JSON_UNESCAPED_UNICODE),
    'EO-15-02'
);

$consumerDefinition = [
    'canonical' => 'test-consumer-action',
    'event_contract' => [
        'required_event_types' => ['test.recorded'],
        'allowed_event_types' => ['test.recorded'],
        'event_rules' => [
            'test.recorded' => [
                'min_count' => 1,
                'max_count' => 1,
                'aggregate_type' => 'test_aggregate',
                'source_type' => 'test-consumer-action',
                'aggregate_version' => 1,
            ],
        ],
        'eventless_reason' => '',
        'activation_blocked_until_event_contract' => false,
        'consumers' => ['test.recorded' => ['test.consumer']],
    ],
];
$missingConsumerRejected = false;
$missingConsumerDetail = [];
try {
    $runtimeActivationGate->assertExecutable($consumerDefinition, 'test-consumer-action');
} catch (CashierV3CommandException $exception) {
    $missingConsumerRejected = $exception->getResultCode() === CashierV3ResultCode::ACTION_NOT_IMPLEMENTED;
    $missingConsumerDetail = (array)($exception->getDetail()['missing'] ?? []);
}
$knownConsumerRegistry = new CashierV3EventConsumerRegistry([
    'test.consumer' => function (array $event): bool { return !empty($event); },
]);
$knownConsumerRegistry->freeze();
$knownConsumerGate = new CashierV3ActionActivationGate($knownConsumerRegistry);
$knownContract = $knownConsumerGate->assertExecutable($consumerDefinition, 'test-consumer-action');
$frozenMutationRejected = false;
try {
    $knownConsumerRegistry->register('late.consumer', function (array $event): bool { return !empty($event); });
} catch (\LogicException $exception) {
    $frozenMutationRejected = strpos($exception->getMessage(), 'freeze') !== false;
}
$memberContractAtRuntime = $runtimeActivationGate->assertExecutable(
    CashierV3ActionManifest::requireAction('create-member'),
    'create-member'
);
ok(
    '合同消费者必须注册且冻结目录不可篡改，零消费者合同仍合法',
    $missingConsumerRejected
        && in_array('event_consumer:test.consumer', $missingConsumerDetail, true)
        && ($knownContract['consumers']['test.recorded'] ?? []) === ['test.consumer']
        && is_callable($knownConsumerRegistry->requireConsumer('test.consumer'))
        && $frozenMutationRejected
        && ($memberContractAtRuntime['consumers']['member.created'] ?? null) === [],
    json_encode([
        'missing' => $missingConsumerDetail,
        'known' => $knownConsumerRegistry->codes(),
        'member' => $memberContractAtRuntime['consumers'] ?? null,
    ], JSON_UNESCAPED_UNICODE),
    'EO-14-14'
);

$cmdCount = 0;
$missingRecovery = 0;
foreach (CashierV3ActionManifest::commandActions() as $action => $def) {
    $cmdCount++;
    if (empty($def['recovery']['mode'])) {
        $missingRecovery++;
    }
}
ok('all commands have recovery', $cmdCount > 0 && $missingRecovery === 0, "cmds={$cmdCount} missing={$missingRecovery}", 'BR-10-03');
ok('submit-checkout recovery query', CashierV3ActionManifest::requireAction('submit-checkout')['recovery']['queryResultAction'] === 'query-checkout-result', '', 'BR-10-03');

section('unique_auth real mapping + super-admin rule');
$resolver = new CashierV3FeatureResolver();
// 真实登录链形状：menuResolver 模拟 getMenusList → unique_auth
$resolver->setMenuResolver(function (array $profile) {
    return is_array($profile['__menus_unique_auth'] ?? null) ? $profile['__menus_unique_auth'] : [];
});
$granted = $resolver->resolveGrantedFeatures([
    'level' => 1,
    'roles' => [1],
    '__menus_unique_auth' => [
        'cashier-cashier-index',
        'cashier-verify-index',
        'cashier-reservation-list',
        'cashier-hang-index',
        'cashier-order-index',
        'cashier-recharge-index',
        'cashier-index',
        'cashier-reservation',
        'cashier.v3.cashier',
    ],
]);
ok('maps cashier-cashier-index', in_array('cashier.v3.cashier', $granted, true), '', 'PM-7-01');
ok('maps cashier-verify-index', in_array('cashier.v3.writeoff', $granted, true), '', 'PM-7-01');
ok('maps cashier-reservation-list', in_array('cashier.v3.reservation', $granted, true), '', 'PM-7-01');
ok('ignores fake cashier-index', !in_array('cashier.v3.room', $granted, true), '', 'PM-7-01');
ok('ignores direct cashier.v3.* spoof for non-super', !in_array('cashier.v3.management_center', $granted, true), '', 'PM-7-01');
ok('no room without real menu', !in_array('cashier.v3.room', $granted, true), '', 'PM-7-01');

ok('level=0 is independent super rule', $resolver->isSuperAdminLevelRule(['level' => 0]), '', 'PM-7-01');
$super = (new CashierV3FeatureResolver())->resolveGrantedFeatures(['level' => 0, 'roles' => []]);
ok('level=0 grants all features', count($super) === count(CashierV3FeatureResolver::FEATURE_CODES), '', 'PM-7-01');
ok('super rule constant documented', CashierV3FeatureResolver::SUPER_ADMIN_LEVEL_RULE === 'level_0_super_admin_all_features', '', 'PM-7-01');

// 无 menu／无 roles：普通账号不可授予
$bare = (new CashierV3FeatureResolver())->resolveGrantedFeatures([
    'level' => 1,
    'unique_auth' => ['cashier-cashier-index'],
]);
ok('no roles → no grant from raw unique_auth', $bare === [], '', 'PM-7-01');

// 菜单服务异常 → 零权限（不得回退 profile.unique_auth）
$menuBoom = new CashierV3FeatureResolver();
$menuBoom->setMenuResolver(function () {
    throw new \RuntimeException('menus unavailable');
});
$boomGranted = $menuBoom->resolveGrantedFeatures([
    'level' => 1,
    'roles' => [1],
    'unique_auth' => ['cashier-cashier-index'],
    '__menus_unique_auth' => ['cashier-cashier-index'],
]);
ok('菜单异常零权限', $boomGranted === [], json_encode($boomGranted), 'PM-7-01');
$menuEmpty = new CashierV3FeatureResolver();
$menuEmpty->setMenuResolver(function () {
    return [];
});
ok('菜单空列表零权限', $menuEmpty->resolveGrantedFeatures([
    'level' => 1,
    'roles' => [1],
    'unique_auth' => ['cashier-cashier-index'],
]) === [], '', 'PM-7-01');

section('EmployeeDataScope fail-closed factory');
$mockScope = new class extends EmployeeDataScopeServices {
    public function __construct() {}
    public function isSuperAdmin(array $adminInfo): bool
    {
        return isset($adminInfo['force_data_super']) && $adminInfo['force_data_super'];
    }
    public function resolveEffectiveStoreIds(int $employeeId, int $contextStoreId = 0, array $adminInfo = [])
    {
        if (!empty($adminInfo['deny_all'])) {
            return [];
        }
        if (!empty($adminInfo['multi_stores'])) {
            return array_map('intval', $adminInfo['multi_stores']);
        }
        if ($employeeId <= 0) {
            return [];
        }
        return [$contextStoreId > 0 ? $contextStoreId : 8];
    }
};
$feat = new CashierV3FeatureResolver();
$feat->setMenuResolver(function ($p) {
    return $p['__menus_unique_auth'] ?? ['cashier-cashier-index'];
});
$factory = new CashierV3DataScopeFactory($feat, $mockScope);

$storeOnly = $factory->build(8, 2, [
    'id' => 2, 'level' => 1, 'roles' => [1], 'employee_id' => 20,
    '__menus_unique_auth' => ['cashier-cashier-index'],
]);
ok('本店可见', $storeOnly->allowsStore(8) && !$storeOnly->allowsStore(9), '', 'DS-6-01');

$multi = $factory->build(8, 3, [
    'id' => 3, 'level' => 1, 'roles' => [1], 'employee_id' => 30,
    '__menus_unique_auth' => ['cashier-cashier-index'],
    'multi_stores' => [8, 9, 10],
]);
ok('多门店', $multi->allowsStore(9) && $multi->allowsStore(10), '', 'DS-6-01');

$noEmp = $factory->build(8, 4, [
    'id' => 4, 'level' => 1, 'roles' => [1], 'employee_id' => 0,
    '__menus_unique_auth' => ['cashier-cashier-index'],
]);
ok('无 employee 绑定无门店', $noEmp->visibleStoreIds() === [], '', 'DS-6-01');

$denied = $factory->build(8, 5, [
    'id' => 5, 'level' => 1, 'roles' => [1], 'employee_id' => 50,
    '__menus_unique_auth' => ['cashier-cashier-index'],
    'deny_all' => true,
]);
ok('员工档案拒绝', $denied->visibleStoreIds() === [], '', 'DS-6-01');
ok('客户端只能收窄', $storeOnly->narrowVisibleStores([8, 99]) === [8], '', 'DS-6-01');
// 真实 TypeError：缺 EmployeeDataScope 不可构造
$ds03 = false;
$ds03Detail = '';
try {
    new CashierV3DataScopeFactory(new CashierV3FeatureResolver(), null);
    $ds03Detail = 'accepted_null';
} catch (\TypeError $e) {
    $ds03 = true;
    $ds03Detail = $e->getMessage();
} catch (\Throwable $e) {
    $ds03 = true;
    $ds03Detail = get_class($e) . ':' . $e->getMessage();
}
ok('缺 EmployeeDataScope fail-closed 构造已强制注入', $ds03, $ds03Detail, 'DS-6-03');

section('canonical selector entry');
$grants = new CashierV3SelectorGrantServices();
$policies = new CashierV3PermissionPolicyRegistry($grants);
$guard = new CashierV3PermissionGuard($policies);
$resFeat = new CashierV3FeatureResolver();
$resFeat->setMenuResolver(function () {
    return ['cashier-reservation-list'];
});
$resFactory = new CashierV3DataScopeFactory($resFeat, $mockScope);
$resScope = $resFactory->build(8, 2, [
    'id' => 2, 'level' => 1, 'roles' => [1], 'employee_id' => 20,
]);
try {
    $guard->assertAllowed(
        CashierV3ActionManifest::requireAction('query-member-selector'),
        $resScope,
        ['selectorContext' => 'not-a-real-entry']
    );
    ok('非法 selector entry 拒绝', false, '', 'PM-7-02');
} catch (CashierV3CommandException $e) {
    ok('非法 selector entry 拒绝', $e->getResultCode() === CashierV3ResultCode::PERMISSION_DENIED, '', 'PM-7-02');
}
$guard->assertAllowed(
    CashierV3ActionManifest::requireAction('query-member-selector'),
    $resScope,
    ['selectorEntry' => 'reservation']
);
$selectorOk = true;
ok('canonical selectorEntry 通过', $selectorOk && isset($resScope), '', 'PM-7-02');
try {
    $grants->issue($resScope, 'reservation', 'member');
    ok('issue 已废弃抛错', false, '', 'PM-7-02');
} catch (\LogicException $e) {
    $caughtObs = get_class($e) . ':' . $e->getMessage();
    ok('issue 已废弃抛错', $caughtObs !== '', $caughtObs, 'PM-7-02');
}

section('root schema + assembler not ready without partitions');
$assembler = new CashierV3RootDomainAssembler();
ok('C1 空壳 assembler 未就绪', !$assembler->isReadyForFullRoot(), '', 'RP-5-01');
$empty = CashierV3RootStateContract::emptyRoot('ctx-1', '1', [
    'storeName' => '瑞昊一店',
    'currentStore' => ['id' => 8, 'name' => '瑞昊一店'],
    'workspace' => ['id' => 'ws:8:1:ctx-1', 'revision' => 1, 'status' => 'editing', 'serverTime' => date('c')],
    'operator' => ['name' => '测', 'roleName' => ''],
    'featurePermissions' => ['cashier.v3.cashier' => true],
]);
$empty = CashierV3RootStateContract::encodeReady($empty);
ok('root schema valid', CashierV3RootStateContract::validate($empty) === [], implode(',', CashierV3RootStateContract::validate($empty)), 'RP-5-02');
ok('no members key', !array_key_exists('members', $empty));
ok('has memberCenter', array_key_exists('memberCenter', $empty));

section('alias conflict incl nullable');
try {
    CashierV3AliasResolver::resolveString(['roomId' => '1', 'room_id' => '2'], ['roomId', 'room_id']);
    ok('alias conflict rejected', false, '', 'CP-8-01');
} catch (CashierV3CommandException $e) {
    ok('alias conflict rejected', $e->getResultCode() === CashierV3ResultCode::INVALID_COMMAND_CONTEXT
        || $e->getResultStatus() === CashierV3ResultCode::STATUS_FAILED, '', 'CP-8-01');
}
ok('alias same ok', CashierV3AliasResolver::resolveString(['roomId' => '1', 'room_id' => '1'], ['roomId', 'room_id']) === '1', '', 'CP-8-01');
try {
    CashierV3AliasResolver::resolveNullableId(
        ['targetRoomId' => null, 'target_room_id' => '9'],
        ['targetRoomId', 'target_room_id']
    );
    ok('nullable alias null vs id conflict', false, '', 'CP-8-01');
} catch (CashierV3CommandException $e) {
    $caughtObs = get_class($e) . ':' . $e->getMessage();
    ok('nullable alias null vs id conflict', $caughtObs !== '', $caughtObs, 'CP-8-01');
}
try {
    CashierV3AliasResolver::resolveEnum(
        ['assignmentScope' => 'reservation_plan', 'assignment_scope' => 'active_service'],
        ['assignmentScope', 'assignment_scope'],
        true
    );
    ok('enum alias conflict', false, '', 'CP-8-01');
} catch (CashierV3CommandException $e) {
    $caughtObs = get_class($e) . ':' . $e->getMessage();
    ok('enum alias conflict', $caughtObs !== '', $caughtObs, 'CP-8-01');
}

section('checkout follow-up rejects client source payload');
$policiesFollow = new CashierV3ContextPolicyRegistry(true);
$followThrew = '';
try {
    $policiesFollow->requirePolicy('submit-checkout')->resolve(
        [
            'checkoutRequestId' => 'CR1',
            'serviceOrderId' => 'SO1',
            'reservationId' => 'RSV1',
        ],
        ['store_id' => 8, 'operator_id' => 1, 'state_context_id' => 'ctx-x']
    );
} catch (CashierV3CommandException $e) {
    $followThrew = (string)($e->getDetail()['reason'] ?? $e->getResultCode());
}
ok('follow-up 拒绝客户端夹带来源', $followThrew === 'follow_up_source_must_come_from_checkout_request', $followThrew, 'CP-8-02');
$followOk = $policiesFollow->requirePolicy('submit-checkout')->resolve(
    ['checkoutRequestId' => 'CR1'],
    ['store_id' => 8, 'operator_id' => 1, 'state_context_id' => 'ctx-x']
);
ok('follow-up 仅 checkout_request', !empty($followOk['expand_from_checkout_request'])
    && in_array('checkout_request', $followOk['required_touched_roles'], true), json_encode($followOk), 'CP-8-02');
ok('follow-up 无 service_order identity', !in_array('service_order', array_column($followOk['identities'], 'kind'), true), '', 'CP-8-02');

section('register freeze / duplicate zero side-effect');
$handlers = new CashierV3HandlerRegistry();
$handlers->registerCommand('save-member-query-settings', function () {
    return ['data' => [], 'touched' => []];
});
$before = $handlers->registeredActions();
try {
    $handlers->registerCommand('save-member-query-settings', function () {
        return ['data' => ['x' => 1], 'touched' => []];
    });
    ok('duplicate handler rejected', false, '', 'CR-4-03');
} catch (\LogicException $e) {
    $caughtObs = get_class($e) . ':' . $e->getMessage();
    ok('duplicate handler rejected', $caughtObs !== '', $caughtObs, 'CR-4-03');
}
ok('duplicate zero side-effect', $handlers->registeredActions() === $before, '', 'CR-4-03');
$handlers->freeze();
try {
    $handlers->registerProjection('query-members', function () {
        return ['data' => []];
    });
    ok('freeze rejects register', false, '', 'CR-4-04');
} catch (\LogicException $e) {
    $caughtObs = get_class($e) . ':' . $e->getMessage();
    ok('freeze rejects register', $caughtObs !== '', $caughtObs, 'CR-4-04');
}

$locks = new CashierV3ResourceLockServices();
$locks->registerLocker('cashier_workspace', function () {
    return true;
});
try {
    $locks->registerLocker('cashier_workspace', function () {
        return false;
    });
    ok('duplicate locker rejected', false, '', 'CR-4-03');
} catch (\LogicException $e) {
    $caughtObs = get_class($e) . ':' . $e->getMessage();
    ok('duplicate locker rejected', $caughtObs !== '', $caughtObs, 'CR-4-03');
}

$scopeR = new CashierV3ScopeResolver();
$versions = new CashierV3ResourceVersionServices($scopeR);
$versions->freeze();
try {
    $versions->registerProvider('room', new class implements \app\services\cashier\v3\CashierV3DataScopedVersionProvider {
        public function resolveScope(string $kind, string $resourceId, $operatorScope) { return null; }
        public function resolveScopeWithDataScope(string $kind, string $resourceId, $operatorScope, $dataScope) { return null; }
        public function lockAndReadVersion($scope, string $kind, string $resourceId) { return null; }
        public function lockAndReadVersionWithDataScope($scope, string $kind, string $resourceId, $dataScope) { return null; }
        public function bumpVersion($scope, string $kind, string $resourceId, string $action): int { return 1; }
        public function bumpVersionWithDataScope($scope, string $kind, string $resourceId, string $action, $dataScope): int { return 1; }
    });
    ok('freeze rejects provider', false, '', 'CR-4-04');
} catch (\LogicException $e) {
    $caughtObs = get_class($e) . ':' . $e->getMessage();
    ok('freeze rejects provider', $caughtObs !== '', $caughtObs, 'CR-4-04');
}

section('domain provider must be DataScoped');
$scopeR2 = new CashierV3ScopeResolver();
$versions2 = new CashierV3ResourceVersionServices($scopeR2);
try {
    $versions2->registerProvider('room', new class implements \app\services\cashier\v3\CashierV3ResourceVersionProvider {
        public function resolveScope(string $kind, string $resourceId, $operatorScope) { return null; }
        public function lockAndReadVersion($scope, string $kind, string $resourceId) { return null; }
        public function bumpVersion($scope, string $kind, string $resourceId, string $action): int { return 1; }
    });
    ok('non-DataScoped domain provider rejected', false, '', 'DS-6-02');
} catch (\LogicException $e) {
    $caughtObs = get_class($e) . ':' . $e->getMessage();
    ok('non-DataScoped domain provider rejected', $caughtObs !== '', $caughtObs, 'DS-6-02');
}

section('C1 context policy not auto-activated');
$emptyPolicies = new CashierV3ContextPolicyRegistry(false);
ok('C1 empty policy registry', $emptyPolicies->registeredActions() === [], '', 'CR-4-02');

section('idempotency prefixes exportable');
$ref = new ReflectionClass(CashierV3IdempotencyKeyServices::class);
ok('IdempotencyKeyServices exists', $ref->isInstantiable(), '', 'FE-11-02');

finish('unit-contract');
