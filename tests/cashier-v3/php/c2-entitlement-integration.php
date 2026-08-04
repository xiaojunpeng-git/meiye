<?php
/**
 * C2-00002-A1 权威权益投影与服务端购物车草稿集成门禁。
 */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';
require __DIR__ . '/../lib/TestGraphFactory.php';
require __DIR__ . '/../lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResourceKindCatalog;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator;
use app\services\cashier\v3\cashier\CashierV3CashierReadinessGuard;
use app\services\cashier\v3\cashier\CashierV3CashierWorkspaceServices;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
MemberIntegrationFixture::bindTestAdapters();
MemberIntegrationFixture::ensureLegacySchema();

final class C2EntitlementTestConfig
{
    public $crossStore = 1;

    public function get(string $name)
    {
        if ($name === 'cross_store_verification') {
            return $this->crossStore;
        }
        return $name === 'h5_avatar' ? '/test/member-avatar.png' : '';
    }
}

$config = new C2EntitlementTestConfig();
$container = app();
if (method_exists($container, 'instance')) {
    $container->instance('sysConfig', $config);
} else {
    $container->bindTo('sysConfig', $config);
}

function c2Section(string $title): void
{
    echo "\n== {$title} ==\n";
}

function c2Code(callable $callable, array &$detail = []): string
{
    try {
        $callable();
    } catch (CashierV3CommandException $exception) {
        $detail = $exception->getDetail();
        return $exception->getResultCode();
    } catch (\Throwable $throwable) {
        $detail = ['class' => get_class($throwable), 'message' => $throwable->getMessage()];
        return 'UNEXPECTED_THROWABLE';
    }
    return '';
}

function c2SessionBody(array $session, string $action, array $payload = []): array
{
    return array_merge($payload, [
        'action' => $action,
        'clientSessionId' => $session['client_session_id'],
        'stateContextId' => $session['state_context_id'],
        'correlationId' => 'CORR-' . MemberIntegrationFixture::uuid(),
    ]);
}

function c2CommandBody(
    array $session,
    string $action,
    array $payload,
    array $contexts,
    string $idempotencyKey
): array {
    $body = c2SessionBody($session, $action, $payload);
    $body['command'] = [
        'action' => $action,
        'idempotencyKey' => $idempotencyKey,
        'contexts' => $contexts,
    ];
    return $body;
}

function c2ContextsForSelection(array $selector, int $holderId, int $detailId): array
{
    return array_values(array_filter((array)($selector['commandContexts'] ?? []), static function (array $context) use ($holderId, $detailId): bool {
        $kind = (string)($context['kind'] ?? '');
        $id = (string)($context['id'] ?? '');
        return $kind === 'cashier_workspace'
            || $kind === 'member'
            || ($kind === 'card_holder' && $id === (string)$holderId)
            || ($kind === 'member_benefit_pool' && $id === (string)$detailId);
    }));
}

function c2ContextsWithWorkspaceVersion(array $contexts, string $workspaceId, int $version): array
{
    return array_map(static function (array $context) use ($workspaceId, $version): array {
        if ((string)($context['kind'] ?? '') === 'cashier_workspace'
            && (string)($context['id'] ?? '') === $workspaceId) {
            $context['expectedVersion'] = $version;
        }
        return $context;
    }, $contexts);
}

function c2WithoutContextKind(array $contexts, string $kind): array
{
    return array_values(array_filter($contexts, static function (array $context) use ($kind): bool {
        return (string)($context['kind'] ?? '') !== $kind;
    }));
}

function c2FindProject(array $selector, int $holderId, int $detailId): array
{
    foreach ((array)($selector['sources'] ?? []) as $source) {
        if ((int)($source['entitlementInstanceId'] ?? 0) !== $holderId) {
            continue;
        }
        foreach ((array)($source['projects'] ?? []) as $project) {
            if ((int)($project['entitlementSourceDetailId'] ?? 0) === $detailId) {
                return ['source' => $source, 'project' => $project];
            }
        }
    }
    return [];
}

function c2FindDraftLine(array $draft, string $lineId): array
{
    foreach ((array)($draft['lines'] ?? []) as $line) {
        if ((string)($line['id'] ?? '') === $lineId) {
            return $line;
        }
    }
    return [];
}

function c2AddIntentId(): string
{
    return 'ENTITLEMENT_ADD-' . MemberIntegrationFixture::uuid();
}

function c2EntitlementLineId(string $addIntentId): string
{
    return 'entitlement:' . substr(hash('sha256', $addIntentId), 0, 48);
}

function c2OldAuthoritySnapshot(): string
{
    $snapshot = [];
    foreach (['user', 'user_card_holder', 'store_order', 'store_order_cart_info', 'store_reservation_order', 'store_debt'] as $table) {
        $rows = Db::name($table)->order($table === 'user' ? 'uid asc' : 'id asc')->select();
        if (is_object($rows) && method_exists($rows, 'toArray')) {
            $rows = $rows->toArray();
        }
        $snapshot[$table] = is_array($rows) ? $rows : [];
    }
    return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function c2CountIfExists(string $table): int
{
    $physical = 'eb_' . $table;
    $rows = Db::query(
        'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
        [$physical]
    );
    if ((int)($rows[0]['c'] ?? 0) !== 1) {
        return 0;
    }
    return (int)Db::name($table)->count();
}

foreach ([
    'cashier_v3_command_receipt',
    'cashier_v3_resource_version',
    'cashier_v3_state_context',
    'cashier_v3_workspace_line',
    'cashier_v3_workspace_draft',
    'cashier_v3_entitlement_resource_version',
    'cashier_v3_business_event',
    'cashier_v3_outbox',
    'cashier_v3_outbox_attempt',
    'cashier_v3_consumer_once',
    'store_reservation_order',
    'store_debt',
    'store_order_cart_info',
    'user_card_holder',
    'store_order',
    'store_user',
    'user',
] as $table) {
    if (MemberIntegrationFixture::tableExists('eb_' . $table)) {
        Db::execute('DELETE FROM `eb_' . $table . '`');
    }
}
MemberIntegrationFixture::resetAndSeed();
MemberIntegrationFixture::seedMember(101, '权益会员甲', '13800000101', 8);
MemberIntegrationFixture::seedMember(102, '权益会员乙', '13800000102', 8);

$now = time();
Db::name('store_order')->insertAll([
    [
        'id' => 501, 'uid' => 101, 'store_id' => 8, 'paid' => 1, 'is_del' => 0,
        'is_system_del' => 0, 'is_user_del' => 0, 'refund_status' => 0,
        'terminal_action' => 0, 'card_upgrade_use_oid' => 0, 'order_id' => 'SO-C2-501', 'mark' => 'C2 购买备注 A',
        'pay_price' => '100.00', 'cash_pay_price' => '80.00', 'yue_pay_price' => '0.00',
        'debt_amount' => '20.00', 'repaid_debt_amount' => '0.00',
    ],
    [
        'id' => 502, 'uid' => 101, 'store_id' => 9, 'paid' => 1, 'is_del' => 0,
        'is_system_del' => 0, 'is_user_del' => 0, 'refund_status' => 0,
        'terminal_action' => 0, 'card_upgrade_use_oid' => 0, 'order_id' => 'SO-C2-502', 'mark' => 'C2 购买备注 B',
        'pay_price' => '80.00', 'cash_pay_price' => '80.00', 'yue_pay_price' => '0.00',
        'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
    ],
    [
        'id' => 503, 'uid' => 102, 'store_id' => 8, 'paid' => 1, 'is_del' => 0,
        'is_system_del' => 0, 'is_user_del' => 0, 'refund_status' => 0,
        'terminal_action' => 0, 'card_upgrade_use_oid' => 0, 'order_id' => 'SO-C2-503', 'mark' => 'C2 三次分摊',
        'pay_price' => '100.00', 'cash_pay_price' => '100.00', 'yue_pay_price' => '0.00',
        'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00',
    ],
]);
Db::name('user_card_holder')->insertAll([
    [
        'id' => 1001, 'uid' => 101, 'oid' => 501, 'card_name' => '护理次卡 A',
        'card_no' => '7000001', 'store_id' => 8, 'product_type' => 4, 'write_times' => 10, 'write_surplus_times' => 8,
        'write_start' => $now - 3600, 'write_end' => $now + 86400, 'is_del' => 0,
    ],
    [
        'id' => 1002, 'uid' => 101, 'oid' => 502, 'card_name' => '护理次卡 B',
        'card_no' => '7000002', 'store_id' => 9, 'product_type' => 5, 'write_times' => 5, 'write_surplus_times' => 5,
        'write_start' => $now - 3600, 'write_end' => $now + 86400, 'is_del' => 0,
    ],
    [
        'id' => 1003, 'uid' => 102, 'oid' => 503, 'card_name' => '三次守恒卡',
        'card_no' => '7000003', 'store_id' => 8, 'product_type' => 4, 'write_times' => 3, 'write_surplus_times' => 3,
        'write_start' => $now - 3600, 'write_end' => $now + 86400, 'is_del' => 0,
    ],
]);
$projectSnapshot = json_encode(['productInfo' => ['store_name' => '深层护理']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
Db::name('store_order_cart_info')->insertAll([
    [
        'id' => 2001, 'oid' => 501, 'cart_id' => 'CART-C2-2001', 'product_id' => 301,
        'cart_type' => 2, 'product_type' => 6, 'cart_info' => $projectSnapshot,
        'write_times' => 10, 'write_surplus_times' => 8, 'is_writeoff' => 0,
        'write_start' => $now - 3600, 'write_end' => $now + 86400,
        'pay_price' => '100.00', 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00', 'is_gift' => 0,
    ],
    [
        'id' => 2002, 'oid' => 502, 'cart_id' => 'CART-C2-2002', 'product_id' => 301,
        'cart_type' => 2, 'product_type' => 6, 'cart_info' => $projectSnapshot,
        'write_times' => 5, 'write_surplus_times' => 5, 'is_writeoff' => 0,
        'write_start' => $now - 3600, 'write_end' => $now + 86400,
        'pay_price' => '80.00', 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00', 'is_gift' => 0,
    ],
    [
        'id' => 2003, 'oid' => 503, 'cart_id' => 'CART-C2-2003', 'product_id' => 302,
        'cart_type' => 2, 'product_type' => 6, 'cart_info' => $projectSnapshot,
        'write_times' => 3, 'write_surplus_times' => 3, 'is_writeoff' => 0,
        'write_start' => $now - 3600, 'write_end' => $now + 86400,
        'pay_price' => '100.00', 'debt_amount' => '0.00', 'repaid_debt_amount' => '0.00', 'is_gift' => 0,
    ],
]);
Db::name('store_reservation_order')->insertAll([
    ['id' => 9001, 'cart_info_id' => 2001, 'status' => 0, 'is_del' => 0, 'is_system_del' => 0],
    ['id' => 9002, 'cart_info_id' => 2001, 'status' => 3, 'is_del' => 0, 'is_system_del' => 0],
]);
Db::name('store_debt')->insert([
    'id' => 8001, 'order_id' => 501, 'status' => 0, 'total_debt' => '20.00', 'repaid_debt' => '0.00',
]);

$dispatcher = MemberIntegrationFixture::dispatcher();

c2Section('member selection binds authoritative workspace draft');
$selectRequest = MemberIntegrationFixture::commandRequest(
    $dispatcher,
    'select-cashier-member',
    ['selectorEntry' => 'cashier', 'memberId' => '101'],
    1,
    'CMD-' . MemberIntegrationFixture::uuid()
);
$select = $dispatcher->dispatch($selectRequest['body'], $selectRequest['session']);
$session = $selectRequest['session'];
$workspaceId = $selectRequest['workspace_id'];
ok('选择会员成功', ($select['result']['status'] ?? '') === 'success', json_encode($select, JSON_UNESCAPED_UNICODE), 'C2-A1-BE-01');
ok(
    '会员与 workspace 草稿同事务绑定',
    (int)Db::name('cashier_v3_workspace_draft')->where('workspace_id', $workspaceId)->value('member_id') === 101,
    '',
    'C2-A1-BE-01'
);

c2Section('projection versions, debt limit, reservation occupation and cross-store');
$requestId = 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid();
$projection = $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
    'memberId' => 101,
    'selectorRequestId' => $requestId,
]), $session);
$selector = (array)($projection['data']['entitlementSelector'] ?? []);
$pair = c2FindProject($selector, 1001, 2001);
$unknownSource = [];
foreach ((array)($selector['sources'] ?? []) as $source) {
    if ((int)($source['id'] ?? 0) === 1002) {
        $unknownSource = (array)$source;
        break;
    }
}
ok('权益投影为 action-bound data', !isset($projection['state']) && !empty($selector['ready']), '', 'C2-A1-BE-02');
ok('projection 顶层版本完整透传', count((array)($projection['versions'] ?? [])) >= 6, json_encode($projection['versions'] ?? []), 'C2-A1-BE-02');
ok('同名项目按卡来源分开', count((array)($selector['sources'] ?? [])) === 2, json_encode($selector['sources'] ?? [], JSON_UNESCAPED_UNICODE), 'C2-A1-BE-03');
ok(
    '欠款限制后再扣预约占用',
    (int)($pair['project']['remainingTimes'] ?? -1) === 8
        && (int)($pair['project']['debtBlockedTimes'] ?? -1) === 2
        && (int)($pair['project']['occupiedTimes'] ?? -1) === 2
        && (int)($pair['project']['availableTimes'] ?? -1) === 4,
    json_encode($pair, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-04'
);
ok('投影不泄露核销码', strpos(json_encode($projection, JSON_UNESCAPED_UNICODE), 'verify_code') === false, '', 'C2-A1-BE-05');
ok(
    '权益展示字段由后端权威投影，未知旧卡类型不伪标',
    ($pair['source']['sourceKind'] ?? '') === 'count_card'
        && ($pair['source']['sourceKindLabel'] ?? '') === '次卡'
        && (int)($pair['source']['purchaseTimes'] ?? -1) === 10
        && (string)($pair['source']['purchaseAmount'] ?? '') === '100.00'
        && (string)($pair['source']['remainingAmount'] ?? '') === '80.00'
        && ($pair['source']['orderRemark'] ?? '') === 'C2 购买备注 A'
        && (int)($pair['project']['purchaseTimes'] ?? -1) === 10
        && (string)($pair['project']['purchaseAmount'] ?? '') === '100.00'
        && (string)($pair['project']['remainingAmount'] ?? '') === '80.00'
        && (int)($pair['project']['totalPurchaseTimes'] ?? -1) === 10
        && (int)($pair['project']['consumedTimesAtSelection'] ?? -1) === 2
        && substr(
            (string)($pair['project']['amountCalculationVersion'] ?? ''),
            -strlen('cumulative-half-up-cent-v2')
        ) === 'cumulative-half-up-cent-v2'
        && (string)($pair['project']['expiryDate'] ?? '') === date('Y-m-d', $now + 86400)
        && (($pair['project']['orderRemark'] ?? '') === 'C2 购买备注 A')
        && (($unknownSource['sourceKind'] ?? '') === 'unknown')
        && array_key_exists('sourceKindLabel', $unknownSource)
        && (($unknownSource['sourceKindLabel'] ?? null) === null),
    json_encode($selector['sources'] ?? [], JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-05'
);

c2Section('unsafe legacy display values are not substituted by the frontend or another payment field');
$unsafeSnapshot = json_encode([
    'productInfo' => ['store_name' => '深层护理'],
    'rh_source' => ['source_line_paid_amount' => '80.001'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
Db::name('store_order_cart_info')->where('id', 2002)->update(['cart_info' => $unsafeSnapshot]);
$unsafeProjection = $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
    'memberId' => 101,
    'selectorRequestId' => 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid(),
]), $session);
$unsafePair = c2FindProject((array)($unsafeProjection['data']['entitlementSelector'] ?? []), 1002, 2002);
ok(
    '非法历史分摊金额不回退为其它支付金额',
    array_key_exists('purchaseAmount', (array)($unsafePair['project'] ?? []))
        && ($unsafePair['project']['purchaseAmount'] ?? null) === null
        && array_key_exists('remainingAmount', (array)($unsafePair['project'] ?? []))
        && ($unsafePair['project']['remainingAmount'] ?? null) === null
        && (int)($unsafePair['project']['totalPurchaseTimes'] ?? -1) === 0
        && (int)($unsafePair['project']['consumedTimesAtSelection'] ?? -1) === 0
        && !array_key_exists('actualUnitAmount', (array)($unsafePair['project'] ?? []))
        && empty($unsafePair['project']['selectable'])
        && ($unsafePair['project']['disabledReason'] ?? '') === '权益实际金额不完整'
        && ($unsafePair['project']['amountCalculationVersion'] ?? '') === 'rh-source-line-payment-invalid-v1',
    json_encode($unsafePair, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-05'
);
Db::name('store_order_cart_info')->where('id', 2002)->update([
    'cart_info' => $projectSnapshot,
    'write_start' => $now + 3600,
    'write_end' => $now - 3600,
]);
$invalidValidityProjection = $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
    'memberId' => 101,
    'selectorRequestId' => 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid(),
]), $session);
$invalidValidityPair = c2FindProject((array)($invalidValidityProjection['data']['entitlementSelector'] ?? []), 1002, 2002);
ok(
    '倒置有效期不可选择并返回明确原因',
    !empty($invalidValidityPair['project']['disabled'])
        && ($invalidValidityPair['project']['validThroughLabel'] ?? '') === '有效期异常'
        && ($invalidValidityPair['project']['disabledReason'] ?? '') === '权益有效期异常',
    json_encode($invalidValidityPair, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-05'
);
Db::name('store_order_cart_info')->where('id', 2002)->update([
    'write_start' => $now - 3600,
    'write_end' => $now + 86400,
]);

c2Section('preserve authoritative sale line and append entitlement line');
Db::name('cashier_v3_workspace_line')->insert([
    'workspace_id' => $workspaceId,
    'line_key' => 'sale:sku-1',
    'line_role' => 'sale',
    'member_id' => 101,
    'holder_id' => 0,
    'source_detail_id' => 0,
    'project_id' => 401,
    'quantity' => 1,
    'source_version' => 1,
    'detail_version' => 1,
    'service_object' => '',
    'craftsmen_json' => '[]',
    'display_snapshot_json' => json_encode([
        'name' => '本次购买项目', 'kind' => '项目', 'originalAmount' => '60.00',
        'finalAmount' => '50.00', 'amount' => '50.00',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'sort_no' => 1,
    'add_time' => time(),
    'update_time' => time(),
]);
$oldAuthorityBefore = c2OldAuthoritySnapshot();
$eventBefore = c2CountIfExists('cashier_v3_business_event');
$outboxBefore = c2CountIfExists('cashier_v3_outbox');
$contexts = c2ContextsForSelection($selector, 1001, 2001);
ok(
    '会员资源固定早于权益池、卡实例和工作台加锁',
    CashierV3ResourceKindCatalog::lockOrderOf('member')
        < CashierV3ResourceKindCatalog::lockOrderOf('member_benefit_pool')
        && CashierV3ResourceKindCatalog::lockOrderOf('member')
            < CashierV3ResourceKindCatalog::lockOrderOf('card_holder')
        && CashierV3ResourceKindCatalog::lockOrderOf('member')
            < CashierV3ResourceKindCatalog::lockOrderOf('cashier_workspace'),
    '',
    'C2-A1-BE-23'
);
$linePayload = [
    'entitlementInstanceId' => 1001,
    'entitlementInstanceType' => 'card_holder',
    'entitlementSourceDetailId' => 2001,
    'entitlementSourceVersion' => (int)$pair['source']['version'],
    'projectId' => (int)$pair['project']['projectId'],
    'projectVersion' => (int)$pair['project']['version'],
    'quantity' => 1,
    'serviceObject' => '本人',
    'craftsmen' => [],
];
$firstAddIntentId = c2AddIntentId();
$firstEntitlementLineId = c2EntitlementLineId($firstAddIntentId);
$addKey = 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid();
$addBody = c2CommandBody($session, 'add-checkout-entitlement-lines', [
    'memberId' => 101,
    'selectorRequestId' => $requestId,
    'selectorToken' => $selector['selectorToken'],
    'addIntentId' => $firstAddIntentId,
    'mutationMode' => 'append',
    'lines' => [$linePayload],
], $contexts, $addKey);
$missingMemberAdd = $addBody;
$missingMemberAdd['command']['idempotencyKey'] = 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid();
$missingMemberAdd['command']['contexts'] = c2WithoutContextKind($contexts, 'member');
$missingMemberAddDetail = [];
$missingMemberAddCode = c2Code(static function () use ($dispatcher, $missingMemberAdd, $session): void {
    $dispatcher->dispatch($missingMemberAdd, $session);
}, $missingMemberAddDetail);
ok(
    '权益加入缺少会员首锁上下文时 fail-closed',
    $missingMemberAddCode === CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
    json_encode($missingMemberAddDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-23'
);
$added = $dispatcher->dispatch($addBody, $session);
$draft = (array)($added['data']['cashierDraft'] ?? []);
$roles = array_values(array_unique(array_map(static function (array $line): string {
    return (string)($line['lineRole'] ?? '');
}, (array)($draft['lines'] ?? []))));
sort($roles);
$draftEntitlement = c2FindDraftLine($draft, $firstEntitlementLineId);
ok('加入权益命令成功', ($added['result']['status'] ?? '') === 'success', json_encode($added, JSON_UNESCAPED_UNICODE), 'C2-A1-BE-06');
ok('销售行与权益行同一权威草稿保留', $roles === ['entitlement_service', 'sale'] && count($draft['lines']) === 2, json_encode($draft, JSON_UNESCAPED_UNICODE), 'C2-A1-BE-06');
ok(
    '权益行展示购买时实际分摊金额且不冒充应收',
    (string)($draftEntitlement['purchaseAmount'] ?? '') === '100.00'
        && (int)($draftEntitlement['totalPurchaseTimes'] ?? 0) === 10
        && (int)($draftEntitlement['consumedTimesAtSelection'] ?? -1) === 2
        && (string)($draftEntitlement['actualAmount'] ?? '') === '10.00'
        && ($draftEntitlement['amountRole'] ?? '') === 'entitlement_actual'
        && !array_key_exists('finalAmount', $draftEntitlement),
    json_encode($draftEntitlement, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-06'
);
ok(
    '混合购物车主按钮由后端返回',
    ($draft['checkoutComposition']['primaryAction'] ?? '') === 'collect_and_complete'
        && ($draft['primaryActionLabel'] ?? '') === '收款并完成服务'
        && (string)($draft['summary']['receivableAmount'] ?? '') === '50.00',
    json_encode($draft, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-07'
);
ok('A1 不改变旧权益权威源', c2OldAuthoritySnapshot() === $oldAuthorityBefore, '', 'C2-A1-BE-08');
ok(
    'A1 不写经营事件或 Outbox',
    c2CountIfExists('cashier_v3_business_event') === $eventBefore
        && c2CountIfExists('cashier_v3_outbox') === $outboxBefore,
    '',
    'C2-A1-BE-08'
);

c2Section('idempotent replay and payload conflict');
$lineCount = (int)Db::name('cashier_v3_workspace_line')->where('workspace_id', $workspaceId)->count();
$replayed = $dispatcher->dispatch($addBody, $session);
ok('同键重放不重复行', !empty($replayed['replay']) && (int)Db::name('cashier_v3_workspace_line')->where('workspace_id', $workspaceId)->count() === $lineCount, '', 'C2-A1-BE-09');
$changedBody = $addBody;
$changedBody['addIntentId'] = c2AddIntentId();
$conflictDetail = [];
$conflictCode = c2Code(static function () use ($dispatcher, $changedBody, $session): void {
    $dispatcher->dispatch($changedBody, $session);
}, $conflictDetail);
ok('同键不同选择冲突', $conflictCode === CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT, json_encode($conflictDetail), 'C2-A1-BE-09');
$legacyModeBody = $addBody;
$legacyModeBody['command']['idempotencyKey'] = 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid();
$legacyModeBody['addIntentId'] = c2AddIntentId();
$legacyModeBody['mutationMode'] = 'increment';
$legacyModeDetail = [];
$legacyModeCode = c2Code(static function () use ($dispatcher, $legacyModeBody, $session): void {
    $dispatcher->dispatch($legacyModeBody, $session);
}, $legacyModeDetail);
ok(
    '旧累加合同被服务端拒绝且不新增草稿行',
    $legacyModeCode === CashierV3ResultCode::INVALID_COMMAND_CONTEXT
        && (int)Db::name('cashier_v3_workspace_line')->where('workspace_id', $workspaceId)->count() === $lineCount,
    json_encode($legacyModeDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-14'
);

c2Section('every click appends one independent entitlement line');
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$reuseIntentBody = c2CommandBody($session, 'add-checkout-entitlement-lines', [
    'memberId' => 101,
    'selectorRequestId' => $requestId,
    'selectorToken' => $selector['selectorToken'],
    'addIntentId' => $firstAddIntentId,
    'mutationMode' => 'append',
    'lines' => [$linePayload],
], c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion), 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid());
$reuseIntentDetail = [];
$reuseIntentCode = c2Code(static function () use ($dispatcher, $reuseIntentBody, $session): void {
    $dispatcher->dispatch($reuseIntentBody, $session);
}, $reuseIntentDetail);
ok(
    '同一添加意图换幂等键必须冲突且不增加行',
    $reuseIntentCode === CashierV3ResultCode::IDEMPOTENCY_KEY_CONFLICT
        && (int)Db::name('cashier_v3_workspace_line')->where('workspace_id', $workspaceId)->count() === $lineCount,
    json_encode($reuseIntentDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-14'
);

$secondAddIntentId = c2AddIntentId();
$secondEntitlementLineId = c2EntitlementLineId($secondAddIntentId);
$secondAddBody = c2CommandBody($session, 'add-checkout-entitlement-lines', [
    'memberId' => 101,
    'selectorRequestId' => $requestId,
    'selectorToken' => $selector['selectorToken'],
    'addIntentId' => $secondAddIntentId,
    'mutationMode' => 'append',
    'lines' => [$linePayload],
], c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion), 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid());
$secondAdded = $dispatcher->dispatch($secondAddBody, $session);
$secondDraft = (array)($secondAdded['data']['cashierDraft'] ?? []);
$firstIndependentLine = c2FindDraftLine($secondDraft, $firstEntitlementLineId);
$secondIndependentLine = c2FindDraftLine($secondDraft, $secondEntitlementLineId);
ok(
    '新添加意图新增独立行且不累加旧行',
    $firstEntitlementLineId !== $secondEntitlementLineId
        && (int)($firstIndependentLine['quantity'] ?? 0) === 1
        && (int)($secondIndependentLine['quantity'] ?? 0) === 1
        && (string)($firstIndependentLine['actualAmount'] ?? '') === '10.00'
        && (string)($secondIndependentLine['actualAmount'] ?? '') === '10.00'
        && (int)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $workspaceId)
            ->where('line_role', 'entitlement_service')
            ->count() === 2,
    json_encode($secondAdded, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-14'
);
$secondReplay = $dispatcher->dispatch($secondAddBody, $session);
ok(
    '第二次点击同键重放不增加第三行',
    !empty($secondReplay['replay'])
        && (int)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $workspaceId)
            ->where('line_role', 'entitlement_service')
            ->count() === 2,
    json_encode($secondReplay, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-14'
);

c2Section('authoritative draft quantity and removal commands');
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$mutationContexts = c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion);
$quantityBody = c2CommandBody($session, 'change-cart-line-quantity', [
    'lineId' => $firstEntitlementLineId,
    'delta' => 1,
], $mutationContexts, 'CHANGE_CART_QUANTITY-' . MemberIntegrationFixture::uuid());
$missingMemberQuantity = $quantityBody;
$missingMemberQuantity['command']['idempotencyKey'] = 'CHANGE_CART_QUANTITY-' . MemberIntegrationFixture::uuid();
$missingMemberQuantity['command']['contexts'] = c2WithoutContextKind($mutationContexts, 'member');
$missingMemberQuantityDetail = [];
$missingMemberQuantityCode = c2Code(static function () use ($dispatcher, $missingMemberQuantity, $session): void {
    $dispatcher->dispatch($missingMemberQuantity, $session);
}, $missingMemberQuantityDetail);
ok(
    '权益改次数缺少会员首锁上下文时 fail-closed',
    $missingMemberQuantityCode === CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
    json_encode($missingMemberQuantityDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-23'
);
$quantityChanged = $dispatcher->dispatch($quantityBody, $session);
$changedDraft = (array)($quantityChanged['data']['cashierDraft'] ?? []);
$changedEntitlement = c2FindDraftLine($changedDraft, $firstEntitlementLineId);
$unchangedEntitlement = c2FindDraftLine($changedDraft, $secondEntitlementLineId);
ok(
    '改单行数量按同源所有独立行合计校验并返回完整草稿',
    (int)($changedEntitlement['quantity'] ?? 0) === 2
        && (string)($changedEntitlement['actualAmount'] ?? '') === '20.00'
        && (int)($unchangedEntitlement['quantity'] ?? 0) === 1
        && (string)($unchangedEntitlement['actualAmount'] ?? '') === '10.00'
        && !empty($quantityChanged['data']['cashierDraft']['complete']),
    json_encode($quantityChanged, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-14'
);

$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$quantityBeforeOverflow = (int)Db::name('cashier_v3_workspace_line')
    ->where('workspace_id', $workspaceId)
    ->where('line_key', $firstEntitlementLineId)
    ->value('quantity');
$overflowDetail = [];
$overflowCode = c2Code(static function () use (
    $dispatcher,
    $session,
    $contexts,
    $workspaceId,
    $workspaceVersion,
    $firstEntitlementLineId
): void {
    $dispatcher->dispatch(c2CommandBody($session, 'change-cart-line-quantity', [
        'lineId' => $firstEntitlementLineId,
        'delta' => 2,
    ], c2ContextsWithWorkspaceVersion(
        $contexts,
        $workspaceId,
        $workspaceVersion
    ), 'CHANGE_CART_QUANTITY-' . MemberIntegrationFixture::uuid()), $session);
}, $overflowDetail);
ok(
    '多条独立行合计超过权威可用次数时整包回滚',
    $overflowCode === CashierV3ResultCode::ENTITLEMENT_SELECTION_CHANGED
        && (int)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $workspaceId)
            ->where('line_key', $firstEntitlementLineId)
            ->value('quantity') === $quantityBeforeOverflow,
    json_encode($overflowDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-14'
);

c2Section('cart service object, craftsmen and experience are authoritative draft settings');
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$settingsContexts = c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion);
$settingsKey = 'CART_SERVICE_SETTINGS-' . MemberIntegrationFixture::uuid();
$settingsBody = c2CommandBody($session, 'update-cart-line-service-settings', [
    'lineId' => $firstEntitlementLineId,
    'serviceObject' => 'friend',
    'craftsmen' => [[
        'id' => 20,
        'name' => '客户端伪造姓名',
    ]],
    'isExperience' => true,
], $settingsContexts, $settingsKey);
$missingMemberSettings = $settingsBody;
$missingMemberSettings['command']['idempotencyKey'] = 'CART_SERVICE_SETTINGS-' . MemberIntegrationFixture::uuid();
$missingMemberSettings['command']['contexts'] = c2WithoutContextKind($settingsContexts, 'member');
$missingMemberSettingsDetail = [];
$missingMemberSettingsCode = c2Code(static function () use ($dispatcher, $missingMemberSettings, $session): void {
    $dispatcher->dispatch($missingMemberSettings, $session);
}, $missingMemberSettingsDetail);
ok(
    '权益服务设置缺少会员首锁上下文时 fail-closed',
    $missingMemberSettingsCode === CashierV3ResultCode::INVALID_COMMAND_CONTEXT,
    json_encode($missingMemberSettingsDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-23'
);
$settingsSaved = $dispatcher->dispatch($settingsBody, $session);
$settingsDraft = (array)($settingsSaved['data']['cashierDraft'] ?? []);
$settingsLine = c2FindDraftLine($settingsDraft, $firstEntitlementLineId);
$unmodifiedSettingsLine = c2FindDraftLine($settingsDraft, $secondEntitlementLineId);
$storedSettings = Db::name('cashier_v3_workspace_line')
    ->where('workspace_id', $workspaceId)
    ->where('line_key', $firstEntitlementLineId)
    ->find();
ok(
    '权益项目三项服务设置同命令保存',
    ($settingsSaved['result']['status'] ?? '') === 'success'
        && ($settingsLine['serviceObject'] ?? '') === '朋友'
        && !empty($settingsLine['isExperience'])
        && (int)($settingsLine['craftsmen'][0]['id'] ?? 0) === 20
        && ($unmodifiedSettingsLine['serviceObject'] ?? '') === '本人'
        && empty($unmodifiedSettingsLine['isExperience'])
        && ($unmodifiedSettingsLine['craftsmen'] ?? null) === [],
    json_encode($settingsSaved, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-16'
);
ok(
    '手艺人姓名由当前门店权威档案重建',
    ($settingsLine['craftsmen'][0]['name'] ?? '') === '专属服务人甲'
        && strpos(json_encode($settingsLine, JSON_UNESCAPED_UNICODE), '客户端伪造姓名') === false
        && ($storedSettings['service_object'] ?? '') === 'friend'
        && (int)($storedSettings['is_experience'] ?? 0) === 1,
    json_encode($settingsLine, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-16'
);
$settingsReplay = $dispatcher->dispatch($settingsBody, $session);
ok(
    '服务设置同键重放不重复推进或改写',
    !empty($settingsReplay['replay'])
        && c2FindDraftLine((array)($settingsReplay['data']['cashierDraft'] ?? []), $firstEntitlementLineId) === $settingsLine,
    json_encode($settingsReplay, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-17'
);

$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$partialContexts = c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion);
$partialSaved = $dispatcher->dispatch(c2CommandBody($session, 'update-cart-line-service-settings', [
    'lineId' => $firstEntitlementLineId,
    'isExperience' => false,
], $partialContexts, 'CART_SERVICE_SETTINGS-' . MemberIntegrationFixture::uuid()), $session);
$partialLine = c2FindDraftLine((array)($partialSaved['data']['cashierDraft'] ?? []), $firstEntitlementLineId);
ok(
    '单字段更新保留已选服务对象和手艺人',
    ($partialLine['serviceObject'] ?? '') === '朋友'
        && empty($partialLine['isExperience'])
        && ($partialLine['craftsmen'][0]['name'] ?? '') === '专属服务人甲',
    json_encode($partialLine, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-17'
);

$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$saleSettings = $dispatcher->dispatch(c2CommandBody($session, 'update-cart-line-service-settings', [
    'lineId' => 'sale:sku-1',
    'serviceObject' => 'self',
    'craftsmanIds' => [20],
    'isExperience' => true,
], [[
    'kind' => 'cashier_workspace',
    'id' => $workspaceId,
    'expectedVersion' => $workspaceVersion,
]], 'CART_SERVICE_SETTINGS-' . MemberIntegrationFixture::uuid()), $session);
$saleSettingsLine = c2FindDraftLine((array)($saleSettings['data']['cashierDraft'] ?? []), 'sale:sku-1');
ok(
    '本次购买的服务项目复用同一服务设置合同',
    ($saleSettings['result']['status'] ?? '') === 'success'
        && ($saleSettingsLine['serviceObject'] ?? '') === '本人'
        && !empty($saleSettingsLine['isExperience'])
        && ($saleSettingsLine['craftsmen'][0]['name'] ?? '') === '专属服务人甲',
    json_encode($saleSettings, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-18'
);

Db::name('system_store_staff')->where('id', 20)->update(['status' => 0]);
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$inactiveDetail = [];
$inactiveCode = c2Code(static function () use (
    $dispatcher,
    $session,
    $contexts,
    $workspaceId,
    $workspaceVersion,
    $firstEntitlementLineId
): void {
    $dispatcher->dispatch(c2CommandBody($session, 'update-cart-line-service-settings', [
        'lineId' => $firstEntitlementLineId,
        'craftsmanIds' => [20],
    ], c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion), 'CART_SERVICE_SETTINGS-' . MemberIntegrationFixture::uuid()), $session);
}, $inactiveDetail);
Db::name('system_store_staff')->where('id', 20)->update(['status' => 1]);
ok(
    '停用手艺人 fail-closed 且不覆盖原草稿',
    $inactiveCode === CashierV3ResultCode::ENTITLEMENT_LINE_INVALID
        && (string)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $workspaceId)
            ->where('line_key', $firstEntitlementLineId)
            ->value('craftsmen_json') === (string)($storedSettings['craftsmen_json'] ?? ''),
    json_encode($inactiveDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-18'
);

$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$thirdAddIntentId = c2AddIntentId();
$thirdEntitlementLineId = c2EntitlementLineId($thirdAddIntentId);
$readdPreserveBody = c2CommandBody($session, 'add-checkout-entitlement-lines', [
    'memberId' => 101,
    'selectorRequestId' => $requestId,
    'selectorToken' => $selector['selectorToken'],
    'addIntentId' => $thirdAddIntentId,
    'mutationMode' => 'append',
    'lines' => [$linePayload],
], c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion), 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid());
$readdPreserved = $dispatcher->dispatch($readdPreserveBody, $session);
$readdPreservedDraft = (array)($readdPreserved['data']['cashierDraft'] ?? []);
$readdPreservedLine = c2FindDraftLine($readdPreservedDraft, $firstEntitlementLineId);
$newIndependentLine = c2FindDraftLine($readdPreservedDraft, $thirdEntitlementLineId);
ok(
    '新增独立权益行不覆盖其它行已确认的服务设置',
    ($readdPreservedLine['serviceObject'] ?? '') === '朋友'
        && empty($readdPreservedLine['isExperience'])
        && ($readdPreservedLine['craftsmen'][0]['name'] ?? '') === '专属服务人甲'
        && ($newIndependentLine['serviceObject'] ?? '') === '本人'
        && empty($newIndependentLine['isExperience'])
        && ($newIndependentLine['craftsmen'] ?? null) === [],
    json_encode([$readdPreservedLine, $newIndependentLine], JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-19'
);

$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$removeBody = c2CommandBody($session, 'remove-cart-line', [
    'lineId' => $firstEntitlementLineId,
], [[
    'kind' => 'cashier_workspace',
    'id' => $workspaceId,
    'expectedVersion' => $workspaceVersion,
]], 'REMOVE_CART_LINE-' . MemberIntegrationFixture::uuid());
$removed = $dispatcher->dispatch($removeBody, $session);
$removedLines = (array)($removed['data']['cashierDraft']['lines'] ?? []);
ok(
    '删除一条权益行不影响其它独立行',
    count($removedLines) === 3
        && c2FindDraftLine((array)($removed['data']['cashierDraft'] ?? []), $firstEntitlementLineId) === []
        && c2FindDraftLine((array)($removed['data']['cashierDraft'] ?? []), $secondEntitlementLineId) !== []
        && c2FindDraftLine((array)($removed['data']['cashierDraft'] ?? []), $thirdEntitlementLineId) !== [],
    json_encode($removed, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-14'
);

$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$readdContexts = c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion);
$fourthAddIntentId = c2AddIntentId();
$fourthEntitlementLineId = c2EntitlementLineId($fourthAddIntentId);
$readdBody = c2CommandBody($session, 'add-checkout-entitlement-lines', [
    'memberId' => 101,
    'selectorRequestId' => $requestId,
    'selectorToken' => $selector['selectorToken'],
    'addIntentId' => $fourthAddIntentId,
    'mutationMode' => 'append',
    'lines' => [$linePayload],
], $readdContexts, 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid());
$readded = $dispatcher->dispatch($readdBody, $session);
ok(
    '删除后可按新命令重新加入且旧权益零副作用',
    count((array)($readded['data']['cashierDraft']['lines'] ?? [])) === 4
        && c2FindDraftLine((array)($readded['data']['cashierDraft'] ?? []), $fourthEntitlementLineId) !== []
        && c2OldAuthoritySnapshot() === $oldAuthorityBefore
        && c2CountIfExists('cashier_v3_business_event') === $eventBefore
        && c2CountIfExists('cashier_v3_outbox') === $outboxBefore,
    json_encode($readded, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-14'
);

c2Section('legacy source change requires successful projection resync');
$shadowBefore = (int)Db::name('cashier_v3_entitlement_resource_version')
    ->where('resource_kind', 'member_benefit_pool')
    ->where('resource_id', '2001')
    ->value('current_version');
Db::name('store_order_cart_info')->where('id', 2001)->update(['write_surplus_times' => 7]);
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$staleContexts = c2ContextsWithWorkspaceVersion($contexts, $workspaceId, $workspaceVersion);
$staleBody = c2CommandBody($session, 'add-checkout-entitlement-lines', [
    'memberId' => 101,
    'selectorRequestId' => $requestId,
    'selectorToken' => $selector['selectorToken'],
    'addIntentId' => c2AddIntentId(),
    'mutationMode' => 'append',
    'lines' => [$linePayload],
], $staleContexts, 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid());
$staleDetail = [];
$staleCode = c2Code(static function () use ($dispatcher, $staleBody, $session): void {
    $dispatcher->dispatch($staleBody, $session);
}, $staleDetail);
$shadowAfterFailedCommand = (int)Db::name('cashier_v3_entitlement_resource_version')
    ->where('resource_kind', 'member_benefit_pool')
    ->where('resource_id', '2001')
    ->value('current_version');
ok('旧源变化使旧 contexts 冲突', $staleCode === CashierV3ResultCode::RESOURCE_VERSION_CONFLICT, json_encode($staleDetail), 'C2-A1-BE-10');
ok('失败命令不签发临时版本', $shadowAfterFailedCommand === $shadowBefore, '', 'C2-A1-BE-10');
$refreshId = 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid();
$refreshed = $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
    'memberId' => 101,
    'selectorRequestId' => $refreshId,
]), $session);
$shadowAfterProjection = (int)Db::name('cashier_v3_entitlement_resource_version')
    ->where('resource_kind', 'member_benefit_pool')
    ->where('resource_id', '2001')
    ->value('current_version');
ok('成功重查持久推进恰好一版', $shadowAfterProjection === $shadowBefore + 1, '', 'C2-A1-BE-10');

$refreshedSelector = (array)($refreshed['data']['entitlementSelector'] ?? []);
$refreshedPair = c2FindProject($refreshedSelector, 1001, 2001);
$sourceChangeContexts = c2ContextsForSelection($refreshedSelector, 1001, 2001);
$sourceChangeLine = $linePayload;
$sourceChangeLine['entitlementSourceVersion'] = (int)($refreshedPair['source']['version'] ?? 0);
$sourceChangeLine['projectVersion'] = (int)($refreshedPair['project']['version'] ?? 0);
Db::name('user_card_holder')->where('id', 1001)->update(['product_type' => 5, 'write_times' => 11]);
Db::name('store_order')->where('id', 501)->update(['mark' => 'C2 已变更备注']);
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$sourceChangeBody = c2CommandBody($session, 'add-checkout-entitlement-lines', [
    'memberId' => 101,
    'selectorRequestId' => $refreshId,
    'selectorToken' => $refreshedSelector['selectorToken'],
    'addIntentId' => c2AddIntentId(),
    'mutationMode' => 'append',
    'lines' => [$sourceChangeLine],
], c2ContextsWithWorkspaceVersion($sourceChangeContexts, $workspaceId, $workspaceVersion), 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid());
$sourceChangeDetail = [];
$sourceChangeCode = c2Code(static function () use ($dispatcher, $sourceChangeBody, $session): void {
    $dispatcher->dispatch($sourceChangeBody, $session);
}, $sourceChangeDetail);
ok(
    '卡类型、购买次数或订单备注变化使旧 contexts 冲突',
    $sourceChangeCode === CashierV3ResultCode::RESOURCE_VERSION_CONFLICT,
    json_encode($sourceChangeDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-10'
);
Db::name('user_card_holder')->where('id', 1001)->update(['product_type' => 4, 'write_times' => 10]);
Db::name('store_order')->where('id', 501)->update(['mark' => 'C2 购买备注 A']);

c2Section('cross-store fail-closed and duplicate debt fail-closed');
$config->crossStore = 0;
Db::name('user_card_holder')->where('id', 1001)->update(['store_id' => 9]);
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$mismatchedStoreBody = c2CommandBody($session, 'add-checkout-entitlement-lines', [
    'memberId' => 101,
    'selectorRequestId' => $refreshId,
    'selectorToken' => $refreshedSelector['selectorToken'],
    'addIntentId' => c2AddIntentId(),
    'mutationMode' => 'append',
    'lines' => [$sourceChangeLine],
], c2ContextsWithWorkspaceVersion($sourceChangeContexts, $workspaceId, $workspaceVersion), 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid());
$mismatchedStoreDetail = [];
$mismatchedStoreCode = c2Code(static function () use ($dispatcher, $mismatchedStoreBody, $session): void {
    $dispatcher->dispatch($mismatchedStoreBody, $session);
}, $mismatchedStoreDetail);
ok(
    '卡实例门店与订单门店不一致时写命令拒绝',
    $mismatchedStoreCode === CashierV3ResultCode::RESOURCE_NOT_FOUND,
    json_encode($mismatchedStoreDetail, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-11'
);
Db::name('user_card_holder')->where('id', 1001)->update(['store_id' => 8]);
$localOnly = $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
    'memberId' => 101,
    'selectorRequestId' => 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid(),
]), $session);
$localSources = (array)($localOnly['data']['entitlementSelector']['sources'] ?? []);
ok('关闭跨店后只返回来源本店权益', count($localSources) === 1 && (int)$localSources[0]['entitlementInstanceId'] === 1001, json_encode($localSources), 'C2-A1-BE-11');
$config->crossStore = 1;
Db::name('store_debt')->insert([
    'id' => 8002, 'order_id' => 501, 'status' => 0, 'total_debt' => '1.00', 'repaid_debt' => '0.00',
]);
$duplicateDebtDetail = [];
$duplicateDebtCode = c2Code(static function () use ($dispatcher, $session): void {
    $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
        'memberId' => 101,
        'selectorRequestId' => 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid(),
    ]), $session);
}, $duplicateDebtDetail);
ok('重复欠款权威行拒绝投影', $duplicateDebtCode === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, json_encode($duplicateDebtDetail), 'C2-A1-BE-12');
Db::name('store_debt')->where('id', 8002)->delete();
$corruptionLineTemplate = Db::name('cashier_v3_workspace_line')
    ->where('workspace_id', $workspaceId)
    ->where('line_key', $secondEntitlementLineId)
    ->find();
if (!$corruptionLineTemplate) {
    throw new \RuntimeException('C2 corruption fixture entitlement line missing');
}

c2Section('member switch clears only customer-bound entitlement lines');
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$switchBody = c2CommandBody($session, 'select-cashier-member', [
    'selectorEntry' => 'cashier',
    'memberId' => 102,
], [[
    'kind' => 'cashier_workspace',
    'id' => $workspaceId,
    'expectedVersion' => $workspaceVersion,
]], 'CMD-' . MemberIntegrationFixture::uuid());
$switched = $dispatcher->dispatch($switchBody, $session);
$switchedLines = (array)($switched['data']['cashierDraft']['lines'] ?? []);
ok(
    '更换会员清权益但保留销售行',
    count($switchedLines) === 1 && ($switchedLines[0]['lineRole'] ?? '') === 'sale'
        && (int)($switched['data']['cashierDraft']['memberId'] ?? 0) === 102
        && (int)($switchedLines[0]['memberId'] ?? 0) === 102,
    json_encode($switched, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-13'
);
ok(
    '更换会员同步修复数据库销售行归属且权益行归零',
    (int)Db::name('cashier_v3_workspace_line')
        ->where('workspace_id', $workspaceId)
        ->where('line_key', 'sale:sku-1')
        ->value('member_id') === 102
        && (int)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $workspaceId)
            ->where('line_role', 'entitlement_service')
            ->count() === 0,
    '',
    'C2-A1-BE-13'
);

c2Section('cumulative cent allocation preserves the full purchase amount');
ok(
    '累计分摊黄金向量覆盖尾差、已用次数、极小金额和零金额',
    CashierV3EntitlementActualAmountAllocator::allocate('100.00', 3, 0, 1) === '33.33'
        && CashierV3EntitlementActualAmountAllocator::allocate('100.00', 3, 0, 2) === '66.67'
        && CashierV3EntitlementActualAmountAllocator::allocate('100.00', 3, 0, 3) === '100.00'
        && CashierV3EntitlementActualAmountAllocator::allocate('100.00', 3, 1, 1) === '33.34'
        && CashierV3EntitlementActualAmountAllocator::allocate('100.00', 3, 1, 2) === '66.67'
        && CashierV3EntitlementActualAmountAllocator::allocate('0.01', 3, 0, 3) === '0.01'
        && CashierV3EntitlementActualAmountAllocator::allocate('0.00', 3, 0, 3) === '0.00',
    '',
    'C2-A1-BE-21'
);
$roundRequestId = 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid();
$roundProjection = $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
    'memberId' => 102,
    'selectorRequestId' => $roundRequestId,
]), $session);
$roundSelector = (array)($roundProjection['data']['entitlementSelector'] ?? []);
$roundPair = c2FindProject($roundSelector, 1003, 2003);
$roundContexts = c2ContextsForSelection($roundSelector, 1003, 2003);
ok(
    '三次权益投影保留完整 100.00 且声明累计到分口径',
    (string)($roundPair['project']['remainingAmount'] ?? '') === '100.00'
        && (int)($roundPair['project']['totalPurchaseTimes'] ?? 0) === 3
        && (int)($roundPair['project']['consumedTimesAtSelection'] ?? -1) === 0
        && substr(
            (string)($roundPair['project']['amountCalculationVersion'] ?? ''),
            -strlen('cumulative-half-up-cent-v2')
        ) === 'cumulative-half-up-cent-v2',
    json_encode($roundPair, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-21'
);
$roundLine = [
    'entitlementInstanceId' => 1003,
    'entitlementInstanceType' => 'card_holder',
    'entitlementSourceDetailId' => 2003,
    'entitlementSourceVersion' => (int)($roundPair['source']['version'] ?? 0),
    'projectId' => (int)($roundPair['project']['projectId'] ?? 0),
    'projectVersion' => (int)($roundPair['project']['version'] ?? 0),
    'quantity' => 1,
];
$roundResults = [];
$roundLineIds = [];
for ($roundIndex = 0; $roundIndex < 3; $roundIndex++) {
    $roundWorkspaceVersion = (int)Db::name('cashier_v3_resource_version')
        ->where('resource_kind', 'cashier_workspace')
        ->where('resource_id', $workspaceId)
        ->value('current_version');
    $roundIntentId = c2AddIntentId();
    $roundLineIds[] = c2EntitlementLineId($roundIntentId);
    $roundResults[] = $dispatcher->dispatch(c2CommandBody($session, 'add-checkout-entitlement-lines', [
        'memberId' => 102,
        'selectorRequestId' => $roundRequestId,
        'selectorToken' => $roundSelector['selectorToken'],
        'addIntentId' => $roundIntentId,
        'mutationMode' => 'append',
        'lines' => [$roundLine],
    ], c2ContextsWithWorkspaceVersion(
        $roundContexts,
        $workspaceId,
        $roundWorkspaceVersion
    ), 'ADD_ENTITLEMENT-' . MemberIntegrationFixture::uuid()), $session);
}
$roundFinalDraft = (array)($roundResults[2]['data']['cashierDraft'] ?? []);
$roundOneLine = c2FindDraftLine($roundFinalDraft, $roundLineIds[0]);
$roundTwoLine = c2FindDraftLine($roundFinalDraft, $roundLineIds[1]);
$roundThreeLine = c2FindDraftLine($roundFinalDraft, $roundLineIds[2]);
$roundAllocatedTotal = bcadd(
    bcadd(
        (string)($roundOneLine['actualAmount'] ?? '0'),
        (string)($roundTwoLine['actualAmount'] ?? '0'),
        2
    ),
    (string)($roundThreeLine['actualAmount'] ?? '0'),
    2
);
ok(
    '100÷3 拆成三条独立行仍按 33.33／33.34／33.33 守恒且不进入应收',
    (string)($roundOneLine['actualAmount'] ?? '') === '33.33'
        && (string)($roundTwoLine['actualAmount'] ?? '') === '33.34'
        && (string)($roundThreeLine['actualAmount'] ?? '') === '33.33'
        && $roundAllocatedTotal === '100.00'
        && (int)($roundOneLine['quantity'] ?? 0) === 1
        && (int)($roundTwoLine['quantity'] ?? 0) === 1
        && (int)($roundThreeLine['quantity'] ?? 0) === 1
        && (string)($roundResults[0]['data']['cashierDraft']['summary']['receivableAmount'] ?? '') === '50.00'
        && (string)($roundResults[1]['data']['cashierDraft']['summary']['receivableAmount'] ?? '') === '50.00'
        && (string)($roundResults[2]['data']['cashierDraft']['summary']['receivableAmount'] ?? '') === '50.00',
    json_encode([$roundOneLine, $roundTwoLine, $roundThreeLine], JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-21'
);

$guestWorkspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$guestBody = c2CommandBody($session, 'set-guest-order', [
    'selectorEntry' => 'cashier',
], [[
    'kind' => 'cashier_workspace',
    'id' => $workspaceId,
    'expectedVersion' => $guestWorkspaceVersion,
]], 'CMD-' . MemberIntegrationFixture::uuid());
$guest = $dispatcher->dispatch($guestBody, $session);
$guestLines = (array)($guest['data']['cashierDraft']['lines'] ?? []);
ok(
    '切换游客保留销售行并清除会员归属',
    count($guestLines) === 1 && ($guestLines[0]['lineRole'] ?? '') === 'sale'
        && (int)($guest['data']['cashierDraft']['memberId'] ?? -1) === 0
        && (int)($guestLines[0]['memberId'] ?? -1) === 0,
    json_encode($guest, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-13'
);
ok(
    '游客切换同步清除数据库销售行会员归属及全部权益行',
    (int)Db::name('cashier_v3_workspace_line')
        ->where('workspace_id', $workspaceId)
        ->where('line_key', 'sale:sku-1')
        ->value('member_id') === 0
        && (int)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $workspaceId)
            ->where('line_role', 'entitlement_service')
            ->count() === 0,
    '',
    'C2-A1-BE-13'
);

$emptyWorkspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$emptyDraftResult = $dispatcher->dispatch(c2CommandBody($session, 'remove-cart-line', [
    'lineId' => 'sale:sku-1',
], [[
    'kind' => 'cashier_workspace',
    'id' => $workspaceId,
    'expectedVersion' => $emptyWorkspaceVersion,
]], 'REMOVE_CART_LINE-' . MemberIntegrationFixture::uuid()), $session);
$emptyDraft = (array)($emptyDraftResult['data']['cashierDraft'] ?? []);
ok(
    '删除最后一行返回完整空草稿而非伪造收款动作',
    !empty($emptyDraft['complete'])
        && ($emptyDraft['lines'] ?? null) === []
        && (int)($emptyDraft['summary']['selectedCount'] ?? -1) === 0
        && (string)($emptyDraft['summary']['receivableAmount'] ?? '') === '0.00'
        && ($emptyDraft['checkoutComposition']['lineRoles'] ?? null) === []
        && empty($emptyDraft['checkoutComposition']['hasSale'])
        && empty($emptyDraft['checkoutComposition']['hasEntitlement'])
        && ($emptyDraft['checkoutComposition']['primaryAction'] ?? null) === ''
        && ($emptyDraft['checkoutComposition']['primaryActionLabel'] ?? null) === ''
        && ($emptyDraft['checkoutComposition']['steps'] ?? null) === []
        && ($emptyDraft['primaryAction'] ?? null) === ''
        && ($emptyDraft['primaryActionLabel'] ?? null) === '',
    json_encode($emptyDraft, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-20'
);

c2Section('soft-deleted member and duplicate active holder fail closed');
$workspaceVersion = (int)Db::name('cashier_v3_resource_version')
    ->where('resource_kind', 'cashier_workspace')
    ->where('resource_id', $workspaceId)
    ->value('current_version');
$backToMember = $dispatcher->dispatch(c2CommandBody($session, 'select-cashier-member', [
    'selectorEntry' => 'cashier',
    'memberId' => 101,
], [[
    'kind' => 'cashier_workspace',
    'id' => $workspaceId,
    'expectedVersion' => $workspaceVersion,
]], 'CMD-' . MemberIntegrationFixture::uuid()), $session);
ok('游客后可重新绑定会员草稿', (int)($backToMember['data']['cashierDraft']['memberId'] ?? 0) === 101, '', 'C2-A1-BE-15');

c2Section('persisted cashier draft corruption fails closed');
$corruptionLine = $corruptionLineTemplate;
unset($corruptionLine['id']);
$corruptionLine['workspace_id'] = $workspaceId;
$corruptionLine['member_id'] = 101;
$corruptionLine['add_time'] = time();
$corruptionLine['update_time'] = time();
$corruptionLineId = (int)Db::name('cashier_v3_workspace_line')->insertGetId($corruptionLine);
$workspaceReader = new CashierV3CashierWorkspaceServices(new CashierV3CashierReadinessGuard());
$fingerprintMethod = new \ReflectionMethod(CashierV3CashierWorkspaceServices::class, 'lineFingerprint');
$fingerprintMethod->setAccessible(true);
$syncDraftFingerprint = static function () use (
    $workspaceReader,
    $fingerprintMethod,
    $workspaceId
): void {
    $rows = Db::name('cashier_v3_workspace_line')
        ->where('workspace_id', $workspaceId)
        ->order('sort_no asc,id asc')
        ->select();
    if (is_object($rows) && method_exists($rows, 'toArray')) {
        $rows = $rows->toArray();
    }
    $fingerprint = (string)$fingerprintMethod->invoke(
        $workspaceReader,
        is_array($rows) ? array_values($rows) : []
    );
    Db::name('cashier_v3_workspace_draft')
        ->where('workspace_id', $workspaceId)
        ->update(['line_fingerprint' => $fingerprint, 'update_time' => time()]);
};
$syncDraftFingerprint();
$normalCorruptionFixture = $workspaceReader->readDraft(
    $workspaceId,
    (string)$session['state_context_id'],
    MemberIntegrationFixture::operatorScope(1)
);
$restoreLine = $corruptionLine;
$runLineCorruption = static function (array $changes) use (
    $workspaceReader,
    $workspaceId,
    $session,
    $corruptionLineId,
    $restoreLine,
    $syncDraftFingerprint
): string {
    Db::name('cashier_v3_workspace_line')->where('id', $corruptionLineId)->update($changes);
    $syncDraftFingerprint();
    $detail = [];
    $code = c2Code(static function () use ($workspaceReader, $workspaceId, $session): void {
        $workspaceReader->readDraft(
            $workspaceId,
            (string)$session['state_context_id'],
            MemberIntegrationFixture::operatorScope(1)
        );
    }, $detail);
    Db::name('cashier_v3_workspace_line')->where('id', $corruptionLineId)->update($restoreLine);
    $syncDraftFingerprint();
    return $code;
};
$lineCorruptionCodes = [
    $runLineCorruption(['line_role' => 'unknown_role']),
    $runLineCorruption(['member_id' => 102]),
    $runLineCorruption(['service_object' => 'unknown_object']),
    $runLineCorruption(['is_experience' => 2]),
    $runLineCorruption(['craftsmen_json' => 'not-json']),
    $runLineCorruption(['craftsmen_json' => '{"staffId":20}']),
    $runLineCorruption(['craftsmen_json' => '[{"staffId":20},{"staffId":20}]']),
    $runLineCorruption(['display_snapshot_json' => 'not-json']),
];
$draftBeforeCorruption = Db::name('cashier_v3_workspace_draft')
    ->where('workspace_id', $workspaceId)
    ->find();
$runDraftCorruption = static function (array $changes) use (
    $workspaceReader,
    $workspaceId,
    $session,
    $draftBeforeCorruption
): string {
    Db::name('cashier_v3_workspace_draft')->where('workspace_id', $workspaceId)->update($changes);
    $detail = [];
    $code = c2Code(static function () use ($workspaceReader, $workspaceId, $session): void {
        $workspaceReader->readDraft(
            $workspaceId,
            (string)$session['state_context_id'],
            MemberIntegrationFixture::operatorScope(1)
        );
    }, $detail);
    Db::name('cashier_v3_workspace_draft')->where('workspace_id', $workspaceId)->update([
        'member_id' => (int)$draftBeforeCorruption['member_id'],
        'customer_mode' => (string)$draftBeforeCorruption['customer_mode'],
        'draft_status' => (string)$draftBeforeCorruption['draft_status'],
        'line_fingerprint' => (string)$draftBeforeCorruption['line_fingerprint'],
        'update_time' => time(),
    ]);
    return $code;
};
$draftCorruptionCodes = [
    $runDraftCorruption(['customer_mode' => 'guest']),
    $runDraftCorruption(['member_id' => 0]),
    $runDraftCorruption(['draft_status' => 'unknown_status']),
    $runDraftCorruption(['line_fingerprint' => str_repeat('f', 64)]),
];
ok(
    '正常持久化草稿仍可公开读取',
    (string)($normalCorruptionFixture['lines'][0]['lineRole'] ?? '') === 'entitlement_service'
        && !empty($normalCorruptionFixture['complete']),
    json_encode($normalCorruptionFixture, JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-22'
);
ok(
    '非法行类型、归属、服务设置、JSON 及草稿状态全部 fail-closed',
    count(array_filter(array_merge($lineCorruptionCodes, $draftCorruptionCodes), static function (string $code): bool {
        return $code !== CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE;
    })) === 0,
    json_encode([$lineCorruptionCodes, $draftCorruptionCodes], JSON_UNESCAPED_UNICODE),
    'C2-A1-BE-22'
);
Db::name('cashier_v3_workspace_line')->where('id', $corruptionLineId)->delete();
$syncDraftFingerprint();

Db::name('user')->where('uid', 101)->update(['is_del' => 1]);
$deletedMemberDetail = [];
$deletedMemberCode = c2Code(static function () use ($dispatcher, $session): void {
    $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
        'memberId' => 101,
        'selectorRequestId' => 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid(),
    ]), $session);
}, $deletedMemberDetail);
ok('软删会员不可读取权益', $deletedMemberCode === CashierV3ResultCode::RESOURCE_NOT_FOUND, json_encode($deletedMemberDetail), 'C2-A1-BE-15');
Db::name('user')->where('uid', 101)->update(['is_del' => 0]);

Db::execute('ALTER TABLE `eb_user_card_holder` DROP INDEX `uk_uid_oid`, ADD KEY `idx_uid_oid` (`uid`,`oid`)');
Db::name('user_card_holder')->where('id', 1002)->update(['oid' => 501, 'store_id' => 8]);
$duplicateHolderDetail = [];
$duplicateHolderCode = c2Code(static function () use ($dispatcher, $session): void {
    $dispatcher->dispatch(c2SessionBody($session, 'open-add-card-service-project', [
        'memberId' => 101,
        'selectorRequestId' => 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid(),
    ]), $session);
}, $duplicateHolderDetail);
ok('同订单重复有效卡实例拒绝投影', $duplicateHolderCode === CashierV3ResultCode::COMMAND_RESULT_INCOMPLETE, json_encode($duplicateHolderDetail), 'C2-A1-BE-15');

echo "GATE_PASS=C2-A1-BE-01\n";
echo "GATE_PASS=C2-A1-BE-02\n";
echo "GATE_PASS=C2-A1-BE-03\n";
echo "GATE_PASS=C2-A1-BE-04\n";
echo "GATE_PASS=C2-A1-BE-05\n";
echo "GATE_PASS=C2-A1-BE-06\n";
echo "GATE_PASS=C2-A1-BE-07\n";
echo "GATE_PASS=C2-A1-BE-08\n";
echo "GATE_PASS=C2-A1-BE-09\n";
echo "GATE_PASS=C2-A1-BE-10\n";
echo "GATE_PASS=C2-A1-BE-11\n";
echo "GATE_PASS=C2-A1-BE-12\n";
echo "GATE_PASS=C2-A1-BE-13\n";
echo "GATE_PASS=C2-A1-BE-14\n";
echo "GATE_PASS=C2-A1-BE-15\n";
echo "GATE_PASS=C2-A1-BE-16\n";
echo "GATE_PASS=C2-A1-BE-17\n";
echo "GATE_PASS=C2-A1-BE-18\n";
echo "GATE_PASS=C2-A1-BE-19\n";
echo "GATE_PASS=C2-A1-BE-20\n";
echo "GATE_PASS=C2-A1-BE-21\n";
echo "GATE_PASS=C2-A1-BE-22\n";
echo "GATE_PASS=C2-A1-BE-23\n";
finish('C2-entitlement-integration');
