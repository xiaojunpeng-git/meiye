<?php
/**
 * Direct V3 card operations, including the pending boundary for upgrades.
 * An upgrade must not mutate current rights before its bound checkout has
 * completed; checkout settlement owns the terminal mutation.
 */
require __DIR__ . '/../lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../lib/_lib.php';
require __DIR__ . '/../lib/TestGraphFactory.php';
require __DIR__ . '/../lib/MemberIntegrationFixture.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\CashierV3StateContextServices;
use app\services\cashier\v3\cashier\CashierV3EntitlementResourceVersionProvider;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3IssuedCardRuleStateServices;
use C1A\CashierV3\Test\MemberIntegrationFixture;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');
MemberIntegrationFixture::bindTestAdapters();
MemberIntegrationFixture::ensureLegacySchema();

function cardOpSection(string $title): void
{
    echo "\n== {$title} ==\n";
}

function cardOpRequireTables(): void
{
    $required = [
        'cashier_v3_command_receipt',
        'cashier_v3_resource_version',
        'cashier_v3_entitlement_resource_version',
        'cashier_v3_business_event',
        'cashier_v3_outbox',
        'cashier_v3_card_state',
        'cashier_v3_card_operation',
        'cashier_v3_card_operation_line',
        'cashier_v3_card_rule_state',
        'cashier_v3_card_rule_component',
    ];
    foreach ($required as $table) {
        if (!MemberIntegrationFixture::tableExists('eb_' . $table)) {
            throw new RuntimeException('CARD_OPERATION_REQUIRED_TABLE_MISSING:' . $table);
        }
    }
}

/**
 * The production permission resolver reads the active store-V3 position
 * assignment. Seed that exact chain for the isolated operator instead of
 * bypassing feature checks in the test graph.
 */
function cardOpGrantCashierFeature(): void
{
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_staff_channel_entry` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `staff_id` int unsigned NOT NULL,
      `employee_id` int unsigned NOT NULL,
      `store_id` int unsigned NOT NULL,
      `channel` varchar(32) NOT NULL,
      `status` tinyint NOT NULL DEFAULT 1,
      `is_del` tinyint NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_staff_channel` (`staff_id`,`employee_id`,`store_id`,`channel`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_position` (
      `id` int unsigned NOT NULL,
      `status` tinyint NOT NULL DEFAULT 1,
      `use_store` tinyint NOT NULL DEFAULT 1,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_staff_job_position` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `staff_id` int unsigned NOT NULL,
      `employee_id` int unsigned NOT NULL,
      `position_id` int unsigned NOT NULL,
      `status` tinyint NOT NULL DEFAULT 1,
      `is_del` tinyint NOT NULL DEFAULT 0,
      `end_time` int unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_staff_position` (`staff_id`,`employee_id`,`position_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("CREATE TABLE IF NOT EXISTS `eb_job_position_channel_rule` (
      `id` bigint unsigned NOT NULL AUTO_INCREMENT,
      `position_id` int unsigned NOT NULL,
      `channel` varchar(32) NOT NULL,
      `status` tinyint NOT NULL DEFAULT 1,
      `rules` varchar(255) NOT NULL DEFAULT '',
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_position_channel` (`position_id`,`channel`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    Db::execute("REPLACE INTO `eb_position` (`id`,`status`,`use_store`) VALUES (901,1,1)");
    Db::execute("REPLACE INTO `eb_staff_channel_entry`
      (`staff_id`,`employee_id`,`store_id`,`channel`,`status`,`is_del`) VALUES
      (1,1,8,'store_v3',1,0)");
    Db::execute("REPLACE INTO `eb_staff_job_position`
      (`staff_id`,`employee_id`,`position_id`,`status`,`is_del`,`end_time`) VALUES
      (1,1,901,1,0,0)");
    Db::execute("REPLACE INTO `eb_job_position_channel_rule`
      (`position_id`,`channel`,`status`,`rules`) VALUES
      (901,'store_v3',1,'301001')");
}

function cardOpSession(): array
{
    $session = MemberIntegrationFixture::projectionSession(1);
    $contexts = new CashierV3StateContextServices(app()->make(CashierV3IdempotencyKeyServices::class));
    $state = $contexts->resolve(
        MemberIntegrationFixture::STORE_ID,
        1,
        (string)$session['client_session_id'],
        ''
    );
    $session['state_context_id'] = (string)$state['state_context_id'];
    return $session;
}

function cardOpContext(string $kind, int $id, int $version): array
{
    return [
        'kind' => $kind,
        'id' => (string)$id,
        'expectedVersion' => $version,
    ];
}

function cardOpBody(
    array $session,
    array $payload,
    array $contexts,
    string $idempotencyKey
): array {
    // The Gateway context is the only accepted browser concurrency version.
    // Keep fixture planner versions out of the strict request payload just as
    // production does; the authority service derives them from the lock.
    unset($payload['sourceCardHolderVersion']);
    return array_merge($payload, [
        'action' => 'submit-card-operation',
        'clientSessionId' => (string)$session['client_session_id'],
        'stateContextId' => (string)$session['state_context_id'],
        'correlationId' => 'CORR-' . MemberIntegrationFixture::uuid(),
        'command' => [
            'action' => 'submit-card-operation',
            'idempotencyKey' => $idempotencyKey,
            'contexts' => $contexts,
        ],
    ]);
}

function cardOpVersion(string $kind, int $id): int
{
    return (int)Db::name('cashier_v3_entitlement_resource_version')
        ->where('resource_kind', $kind)
        ->where('resource_id', (string)$id)
        ->value('current_version');
}

function cardOpProjectionBody(array $session, int $memberId, string $cardOperationMode = ''): array
{
    return [
        'action' => 'open-add-card-service-project',
        'memberId' => $memberId,
        'cardOperationMode' => $cardOperationMode,
        'selectorRequestId' => 'ENTITLEMENT_SELECTOR-' . MemberIntegrationFixture::uuid(),
        'clientSessionId' => (string)$session['client_session_id'],
        'stateContextId' => (string)$session['state_context_id'],
        'correlationId' => 'CORR-' . MemberIntegrationFixture::uuid(),
    ];
}

function cardOpSelectorHasHolder(array $projection, int $holderId): bool
{
    foreach ((array)($projection['data']['entitlementSelector']['sources'] ?? []) as $source) {
        if ((int)($source['entitlementInstanceId'] ?? $source['id'] ?? 0) === $holderId) {
            return true;
        }
    }
    return false;
}

function cardOpSelectorProject(array $projection, int $holderId, int $detailId): array
{
    foreach ((array)($projection['data']['entitlementSelector']['sources'] ?? []) as $source) {
        if ((int)($source['entitlementInstanceId'] ?? $source['id'] ?? 0) !== $holderId) {
            continue;
        }
        foreach ((array)($source['projects'] ?? []) as $project) {
            if ((int)($project['entitlementSourceDetailId'] ?? $project['id'] ?? 0) === $detailId) {
                return $project;
            }
        }
    }
    return [];
}

function cardOpRejectCode(callable $callable, array &$detail = []): string
{
    try {
        $callable();
    } catch (CashierV3CommandException $exception) {
        $detail = $exception->getDetail();
        return $exception->getResultCode();
    } catch (Throwable $exception) {
        $detail = ['class' => get_class($exception), 'message' => $exception->getMessage()];
        return 'UNEXPECTED_THROWABLE';
    }
    return '';
}

try {
    cardOpRequireTables();
    $databaseName = (string)(Db::query('SELECT DATABASE() AS database_name')[0]['database_name'] ?? '');
    if ($databaseName !== 'cashier_v3_card_operation') {
        throw new RuntimeException(
            'CARD_OPERATION_ISOLATED_DATABASE_REQUIRED: current=' . $databaseName
        );
    }
    foreach ([
        'cashier_v3_card_operation_line',
        'cashier_v3_card_operation',
        'cashier_v3_card_state',
        'cashier_v3_card_rule_component',
        'cashier_v3_card_rule_state',
        'cashier_v3_entitlement_resource_version',
        'cashier_v3_business_event',
        'cashier_v3_outbox',
        'cashier_v3_command_receipt',
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
    cardOpGrantCashierFeature();
    MemberIntegrationFixture::seedMember(4101, '转让前会员', '13800004101', 8);
    MemberIntegrationFixture::seedMember(4102, '接收会员', '13800004102', 8);
    MemberIntegrationFixture::seedMember(4103, '转让候选会员', '13800004103', 8);

    $now = time();
    Db::name('store_order')->insert([
        'id' => 4501,
        'uid' => 4101,
        'store_id' => 8,
        'paid' => 1,
        'is_del' => 0,
        'is_system_del' => 0,
        'is_user_del' => 0,
        'refund_status' => 0,
        'terminal_action' => 0,
        'card_upgrade_use_oid' => 0,
        'order_id' => 'SO-CARDOP-4501',
        'mark' => '卡操作集成订单',
        'pay_price' => '100.00',
        'cash_pay_price' => '100.00',
        'yue_pay_price' => '0.00',
        'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00',
    ]);
    Db::name('user_card_holder')->insert([
        'id' => 4601,
        'uid' => 4101,
        'oid' => 4501,
        'card_name' => '卡操作测试次卡',
        'card_no' => 'CARDOP-4601',
        'store_id' => 8,
        'product_type' => 4,
        'write_times' => 10,
        'write_surplus_times' => 8,
        'write_start' => $now - 3600,
        'write_end' => $now + 86400,
        'is_del' => 0,
    ]);
    Db::name('store_order_cart_info')->insert([
        'id' => 4701,
        'oid' => 4501,
        'cart_id' => 'CART-CARDOP-4701',
        'product_id' => 4801,
        'cart_type' => 2,
        'product_type' => 6,
        'cart_info' => json_encode(['productInfo' => ['store_name' => '卡操作护理项目']], JSON_UNESCAPED_UNICODE),
        'write_times' => 10,
        'write_surplus_times' => 8,
        'is_writeoff' => 0,
        'write_start' => $now - 3600,
        'write_end' => $now + 86400,
        'pay_price' => '100.00',
        'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00',
        'is_gift' => 0,
    ]);
    Db::name('store_order_cart_info')->insert([
        'id' => 4702,
        'oid' => 4501,
        'cart_id' => 'CART-CARDOP-4702',
        'product_id' => 4804,
        'cart_type' => 2,
        'product_type' => 6,
        'cart_info' => json_encode(['productInfo' => ['store_name' => '不同价格护理项目']], JSON_UNESCAPED_UNICODE),
        'write_times' => 3,
        'write_surplus_times' => 3,
        'is_writeoff' => 0,
        'write_start' => $now - 3600,
        'write_end' => $now + 86400,
        'pay_price' => '45.00',
        'debt_amount' => '0.00',
        'repaid_debt_amount' => '0.00',
        'is_gift' => 0,
    ]);
    // Target is a current, store-scoped project SKU. The replacement command
    // receives only this SKU id; product identity and price are re-read by
    // server authority and never trusted from the browser preview.
    Db::name('store_product')->insert([
        'id' => 4802,
        'type' => 1,
        'relation_id' => 8,
        'product_type' => 6,
        'store_name' => '卡操作替换目标项目',
        'is_show' => 1,
        'is_del' => 0,
        'is_verify' => 1,
    ]);
    Db::name('store_product_attr_value')->insert([
        'id' => 4902,
        'product_id' => 4802,
        'product_type' => 6,
        'unique' => 'CARDOP-RPL-4802',
        'suk' => '默认',
        'price' => '88.00',
        'ot_price' => '88.00',
        'stock' => '0.0000',
        'is_show' => 1,
        'type' => 0,
        'write_times' => 1,
        'write_valid' => 1,
    ]);
    // Card upgrades must still verify a current target card SKU before they
    // can enter their pending-checkout state.
    Db::name('store_product')->insert([
        'id' => 4803,
        'type' => 1,
        'relation_id' => 8,
        'product_type' => 5,
        'store_name' => '卡操作升级目标卡项',
        'is_show' => 1,
        'is_del' => 0,
        'is_verify' => 1,
    ]);
    Db::name('store_product_attr_value')->insert([
        'id' => 4901,
        'product_id' => 4803,
        'product_type' => 5,
        'unique' => 'CARDOP-UPG-4803',
        'suk' => '默认',
        'price' => '188.00',
        'ot_price' => '188.00',
        'stock' => '0.0000',
        'is_show' => 1,
        'type' => 0,
        'write_times' => 1,
        'write_valid' => 1,
    ]);
    Db::name('store_card_related')->insert([
        'card_product_id' => 4803,
        'product_id' => 4802,
        'product_type' => 6,
        'product_attr_unique' => 'CARDOP-RPL-4802',
        'cost' => '0.00',
        'price' => '88.00',
        'write_times' => 1,
        'status' => 1,
    ]);

    // The source line represents a card already consumed twice. Seed the
    // issue-time state through the production service, then replay those two
    // historical consumptions against its component counter. The replacement
    // below must move the next two rights from this component to the target.
    $ruleComponent = [
        'relationId' => 9501,
        'productId' => 4801,
        'skuId' => 4902,
        'skuUnique' => 'CARDOP-SOURCE-4801',
        'productType' => 6,
        'nameSnapshot' => '卡操作护理项目',
        'writeTimes' => 10,
        'configuredPriceCents' => 1000,
        'writeoffAmountCents' => 0,
    ];
    $differentPriceRuleComponent = [
        'relationId' => 9502,
        'productId' => 4804,
        'skuId' => 4903,
        'skuUnique' => 'CARDOP-SOURCE-4804',
        'productType' => 6,
        'nameSnapshot' => '不同价格护理项目',
        'writeTimes' => 3,
        'configuredPriceCents' => 1500,
        'writeoffAmountCents' => 0,
    ];
    $ruleWriter = new CashierV3IssuedCardRuleStateServices();
    $ruleAuthority = new CashierV3CardRuleEntitlementAuthorityServices();
    Db::transaction(function () use (
        $ruleWriter,
        $ruleAuthority,
        $ruleComponent,
        $differentPriceRuleComponent,
        $now
    ): void {
        $ruleWriter->issueInTx(
            'CARDOP-RULE-4601',
            ['tenant_id' => '0', 'order_id' => 'SO-CARDOP-4501', 'member_id' => 4101, 'store_id' => 8],
            ['order_line_id' => 'SOL-CARDOP-4501'],
            ['catalog_product_id' => 4803, 'catalog_sku_id' => 4901],
            [
                'ruleType' => 'normal', 'ruleVersion' => 1, 'definitionVersion' => 1,
                'choiceLimit' => 0, 'sharedTimes' => 0,
                'components' => [$ruleComponent, $differentPriceRuleComponent],
            ],
            ['writeValid' => 2, 'writeStart' => $now - 3600, 'writeEnd' => $now + 86400],
            ['issuedComponents' => [
                ['detailId' => 4701, 'snapshot' => $ruleComponent],
                ['detailId' => 4702, 'snapshot' => $differentPriceRuleComponent],
            ]],
            ['holderId' => 4601],
            $now
        );
        $ruleAuthority->applyDeductionsInTx([[
            'holder_id' => 4601, 'source_detail_id' => 4701, 'project_id' => 4801,
            'deduct_physical_times' => 2, 'expected_physical_remaining_times' => 10,
        ]], ['tenant_id' => '0', 'store_id' => 8, 'member_id' => 4101, 'occurred_at' => $now]);
    });
    ok('新卡项规则来源权益与既有剩余次数一致',
        (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', 4701)->value('remaining_times') === 8,
        '', 'C2-CARDOP-BE-01A');

    $dispatcher = MemberIntegrationFixture::dispatcher();
    $operatorScope = MemberIntegrationFixture::operatorScope(1);
    $dataScope = MemberIntegrationFixture::dataScope($dispatcher, 1);
    $provider = $dispatcher->scopeResolver()->providerFor('card_holder');
    if (!$provider instanceof CashierV3EntitlementResourceVersionProvider) {
        throw new RuntimeException('CARD_OPERATION_ENTITLEMENT_PROVIDER_MISSING');
    }
    Db::transaction(function () use ($provider, $operatorScope, $dataScope): void {
        $provider->synchronizeProjectionVersion('member', '4101', $operatorScope, $dataScope);
        $provider->synchronizeProjectionVersion('member', '4102', $operatorScope, $dataScope);
        $provider->synchronizeProjectionVersion('member', '4103', $operatorScope, $dataScope);
        $provider->synchronizeProjectionVersion('card_holder', '4601', $operatorScope, $dataScope);
        $provider->synchronizeProjectionVersion('member_benefit_pool', '4701', $operatorScope, $dataScope);
        $provider->synchronizeProjectionVersion('member_benefit_pool', '4702', $operatorScope, $dataScope);
    });
    $session = cardOpSession();
    $sourceVersion = cardOpVersion('card_holder', 4601);
    $targetVersion = cardOpVersion('member', 4102);
    ok('卡与接收会员版本已由权威投影初始化', $sourceVersion === 1 && $targetVersion === 1, '', 'C2-CARDOP-BE-01');

    cardOpSection('transfer preserves historic sale ownership');
    $transferKey = 'CARD_OPERATION-' . MemberIntegrationFixture::uuid();
    $transferPayload = [
        'operationType' => 'card_transfer',
        'sourceCardHolderId' => 4601,
        'sourceCardHolderVersion' => $sourceVersion,
        'targetMemberId' => 4102,
        'reason' => '客户签署转让确认',
    ];
    $transferBody = cardOpBody(
        $session,
        $transferPayload,
        [cardOpContext('card_holder', 4601, $sourceVersion)],
        $transferKey
    );
    $transfer = $dispatcher->dispatch($transferBody, $session);
    $state = (array)Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->find();
    $operationCount = (int)Db::name('cashier_v3_card_operation')->count();
    $outboxCount = (int)Db::name('cashier_v3_outbox')->count();
    $transferAudit = (array)Db::name('cashier_v3_card_operation')
        ->where('command_idempotency_key', $transferKey)
        ->find();
    $transferResultSnapshot = json_decode(
        (string)($transferAudit['result_snapshot_json'] ?? ''),
        true
    );
    $transferEvent = (array)Db::name('cashier_v3_business_event')
        ->where('event_type', 'card.operation.recorded')
        ->where('source_id', (string)($transferAudit['operation_id'] ?? ''))
        ->find();
    $transferEventPayload = json_decode((string)($transferEvent['payload'] ?? ''), true);
    ok('转让命令成功且产生业务单号', ($transfer['result']['status'] ?? '') === 'success'
        && preg_match('/^CK[0-9]{11}$/D', (string)($transfer['businessNo'] ?? '')) === 1
        && (string)($transferAudit['operation_no'] ?? '') === (string)($transfer['businessNo'] ?? ''),
        json_encode($transfer, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-02');
    ok('转让只更新当前持卡人和状态投影',
        (int)Db::name('user_card_holder')->where('id', 4601)->value('uid') === 4102
        && (int)($state['current_member_id'] ?? 0) === 4102
        && ($state['card_status'] ?? '') === 'enabled'
        && (int)($state['current_version'] ?? 0) === 2,
        json_encode($state, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-03');
    ok('历史销售订单会员不被转让改写',
        (int)Db::name('store_order')->where('id', 4501)->value('uid') === 4101,
        '', 'C2-CARDOP-BE-04');
    ok('转让操作冻结原始销售归属和本次归属快照',
        (string)($transferAudit['operation_type'] ?? '') === 'card_transfer'
        && (string)($transferAudit['operation_status'] ?? '') === 'succeeded'
        && (int)($transferAudit['origin_order_id'] ?? 0) === 4501
        && (int)($transferAudit['origin_member_id'] ?? 0) === 4101
        && (int)($transferAudit['member_id_before'] ?? 0) === 4101
        && (int)($transferAudit['member_id_after'] ?? 0) === 4102
        && (string)($transferAudit['store_name_snapshot'] ?? '') === '本店'
        && (string)($transferAudit['member_name_before_snapshot'] ?? '') === '转让前会员'
        && (string)($transferAudit['member_name_after_snapshot'] ?? '') === '接收会员'
        && (string)($transferAudit['operator_name_snapshot'] ?? '') === '操作员一'
        && (int)($transferAudit['source_card_holder_version'] ?? 0) === $sourceVersion
        && is_array($transferResultSnapshot)
        && (string)($transferResultSnapshot['operationStatus'] ?? '') === 'succeeded'
        && (int)($transferResultSnapshot['stateMutation']['currentMemberId'] ?? 0) === 4102
        && (string)($transferResultSnapshot['stateMutation']['cardStatus'] ?? '') === 'enabled'
        && (int)($transferResultSnapshot['stateVersionAfter'] ?? 0) === 2,
        json_encode($transferAudit, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-04A');
    ok('操作审计与事件在同一成功事务留下不可变维度快照',
        $operationCount === 1
        && (int)Db::name('cashier_v3_business_event')->where('event_type', 'card.operation.recorded')->count() === 1
        && (string)($transferEvent['store_name_snapshot'] ?? '') === '本店'
        && is_array($transferEventPayload)
        && (string)($transferEventPayload['storeNameSnapshot'] ?? '') === '本店'
        && (string)($transferEventPayload['memberNameBeforeSnapshot'] ?? '') === '转让前会员'
        && (string)($transferEventPayload['memberNameAfterSnapshot'] ?? '') === '接收会员'
        && (string)($transferEventPayload['operatorNameSnapshot'] ?? '') === '操作员一'
        // Current manifest has no card-operation outbox consumer. A row without
        // a consumer would be an unprocessable orphan and is therefore invalid.
        && $outboxCount === 0
        && (int)Db::name('cashier_v3_card_operation_line')->count() === 0,
        '', 'C2-CARDOP-BE-05');
    ok('项目权益影子绑定随当前持卡人移动',
        (int)Db::name('cashier_v3_entitlement_resource_version')
            ->where('resource_kind', 'member_benefit_pool')->where('resource_id', '4701')->value('member_id') === 4102,
        '', 'C2-CARDOP-BE-06');

    // 权益选择器只能读取当前收银工作台已选择的会员。转让操作不擅自切换
    // 正在收银的会员，因此显式打开接收会员的新工作台再验证其权益。
    $targetSelectionRequest = MemberIntegrationFixture::commandRequest(
        $dispatcher,
        'select-cashier-member',
        ['selectorEntry' => 'cashier', 'memberId' => '4102'],
        1,
        'CMD-' . MemberIntegrationFixture::uuid()
    );
    $targetSession = $targetSelectionRequest['session'];
    $targetSelection = $dispatcher->dispatch($targetSelectionRequest['body'], $targetSession);
    ok('接收会员在独立收银工作台选择成功',
        ($targetSelection['result']['status'] ?? '') === 'success',
        json_encode($targetSelection, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-06A');

    $transferredProjection = $dispatcher->dispatch(cardOpProjectionBody($targetSession, 4102), $targetSession);
    ok('转让后新会员可从权威投影读取原卡权益',
        cardOpSelectorHasHolder($transferredProjection, 4601),
        json_encode($transferredProjection, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-07');
    $formerSelectionRequest = MemberIntegrationFixture::commandRequest(
        $dispatcher,
        'select-cashier-member',
        ['selectorEntry' => 'cashier', 'memberId' => '4101'],
        1,
        'CMD-' . MemberIntegrationFixture::uuid()
    );
    $formerSession = $formerSelectionRequest['session'];
    $dispatcher->dispatch($formerSelectionRequest['body'], $formerSession);
    $formerHolderProjection = $dispatcher->dispatch(cardOpProjectionBody($formerSession, 4101), $formerSession);
    ok('转让后原会员不能再从权益选择器读取该卡',
        !cardOpSelectorHasHolder($formerHolderProjection, 4601),
        json_encode($formerHolderProjection, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-07A');

    $replay = $dispatcher->dispatch($transferBody, $session);
    ok('相同幂等键重放不重复转让、审计或事件',
        !empty($replay['replay'])
        && (int)Db::name('cashier_v3_card_operation')->count() === $operationCount
        && (int)Db::name('cashier_v3_business_event')->where('event_type', 'card.operation.recorded')->count() === 1
        && (int)Db::name('cashier_v3_outbox')->count() === $outboxCount,
        json_encode($replay, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-08');
    $changedReplay = $transferBody;
    $changedReplay['reason'] = '相同幂等键但内容不同';
    $replayDetail = [];
    $replayCode = cardOpRejectCode(function () use ($dispatcher, $changedReplay, $session): void {
        $dispatcher->dispatch($changedReplay, $session);
    }, $replayDetail);
    ok('相同幂等键内容变化被拒绝', $replayCode === 'IDEMPOTENCY_KEY_CONFLICT',
        json_encode($replayDetail, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-09');

    $staleBody = cardOpBody(
        $session,
        $transferPayload,
        [cardOpContext('card_holder', 4601, $sourceVersion)],
        'CARD_OPERATION-' . MemberIntegrationFixture::uuid()
    );
    $staleDetail = [];
    $staleCode = cardOpRejectCode(function () use ($dispatcher, $staleBody, $session): void {
        $dispatcher->dispatch($staleBody, $session);
    }, $staleDetail);
    ok('旧卡版本被拒绝且不新增操作', $staleCode === 'RESOURCE_VERSION_CONFLICT'
        && (int)Db::name('cashier_v3_card_operation')->count() === $operationCount,
        json_encode($staleDetail, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-10');

    // The receiving member is locked and re-read inside the authoritative
    // transaction; it is intentionally not a client-version context because
    // the shared selector never issues a member version for a non-selected
    // target. A target that becomes inactive must fail before source state,
    // audit or events change.
    Db::name('user')->where('uid', 4103)->update(['status' => 0]);
    $targetUnavailableBody = cardOpBody(
        $session,
        [
            'operationType' => 'card_transfer',
            'sourceCardHolderId' => 4601,
            'sourceCardHolderVersion' => cardOpVersion('card_holder', 4601),
            'targetMemberId' => 4103,
            'reason' => '目标会员不可用测试',
        ],
        [cardOpContext('card_holder', 4601, cardOpVersion('card_holder', 4601))],
        'CARD_OPERATION-' . MemberIntegrationFixture::uuid()
    );
    $targetUnavailableDetail = [];
    $targetUnavailableCode = cardOpRejectCode(function () use ($dispatcher, $targetUnavailableBody, $session): void {
        $dispatcher->dispatch($targetUnavailableBody, $session);
    }, $targetUnavailableDetail);
    ok('目标会员失效时拒绝转让且当前归属不改变',
        $targetUnavailableCode === 'RESOURCE_NOT_FOUND'
        && (int)Db::name('user_card_holder')->where('id', 4601)->value('uid') === 4102
        && (int)Db::name('cashier_v3_card_operation')->count() === $operationCount,
        json_encode($targetUnavailableDetail, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-10A');

    cardOpSection('disable, enable and extension use one current state');
    $disableVersion = cardOpVersion('card_holder', 4601);
    $disableBody = cardOpBody($session, [
        'operationType' => 'card_disable',
        'sourceCardHolderId' => 4601,
        'sourceCardHolderVersion' => $disableVersion,
        'reason' => '客户申请暂停使用',
    ], [cardOpContext('card_holder', 4601, $disableVersion)], 'CARD_OPERATION-' . MemberIntegrationFixture::uuid());
    $disable = $dispatcher->dispatch($disableBody, $session);
    $disabledProjection = $dispatcher->dispatch(cardOpProjectionBody($targetSession, 4102), $targetSession);
    $enableProjection = $dispatcher->dispatch(
        cardOpProjectionBody($targetSession, 4102, 'card-enable'),
        $targetSession
    );
    $disabledSource = [];
    $enableSource = [];
    foreach ((array)($disabledProjection['data']['entitlementSelector']['sources'] ?? []) as $source) {
        if ((int)($source['entitlementInstanceId'] ?? $source['id'] ?? 0) === 4601) {
            $disabledSource = (array)$source;
            break;
        }
    }
    foreach ((array)($enableProjection['data']['entitlementSelector']['sources'] ?? []) as $source) {
        if ((int)($source['entitlementInstanceId'] ?? $source['id'] ?? 0) === 4601) {
            $enableSource = (array)$source;
            break;
        }
    }
    ok('停用成功且普通权益投影将该卡标记为不可选',
        ($disable['result']['status'] ?? '') === 'success'
        && (string)Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->value('card_status') === 'disabled'
        && (string)($disabledSource['statusCode'] ?? '') === 'disabled'
        && ($disabledSource['selectable'] ?? true) === false,
        json_encode([
            'disable' => $disable,
            'cardStatus' => Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->value('card_status'),
            'disabledSelector' => $disabledProjection['data']['entitlementSelector'] ?? [],
        ], JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-11');
    ok('启用入口可读取停用卡且不将其放宽为可用权益',
        (string)($enableSource['statusCode'] ?? '') === 'disabled'
        && ($enableSource['selectable'] ?? true) === false
        && (string)($disabledSource['statusCode'] ?? '') === 'disabled'
        && ($disabledSource['selectable'] ?? true) === false,
        json_encode([
            'enableSelector' => $enableProjection['data']['entitlementSelector'] ?? [],
            'disabledSelector' => $disabledProjection['data']['entitlementSelector'] ?? [],
        ], JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-11A');

    $enableVersion = cardOpVersion('card_holder', 4601);
    $enableBody = cardOpBody($session, [
        'operationType' => 'card_enable',
        'sourceCardHolderId' => 4601,
        'sourceCardHolderVersion' => $enableVersion,
        'reason' => '客户恢复使用',
    ], [cardOpContext('card_holder', 4601, $enableVersion)], 'CARD_OPERATION-' . MemberIntegrationFixture::uuid());
    $enable = $dispatcher->dispatch($enableBody, $session);
    $extensionVersion = cardOpVersion('card_holder', 4601);
    $newEnd = $now + 86400 * 30;
    $extensionKey = 'CARD_OPERATION-' . MemberIntegrationFixture::uuid();
    $extensionBody = cardOpBody($session, [
        'operationType' => 'card_extension',
        'sourceCardHolderId' => 4601,
        'sourceCardHolderVersion' => $extensionVersion,
        'newWriteEnd' => $newEnd,
        'reason' => '活动延期三十天',
    ], [cardOpContext('card_holder', 4601, $extensionVersion)], $extensionKey);
    $extension = $dispatcher->dispatch($extensionBody, $session);
    $extensionAudit = (array)Db::name('cashier_v3_card_operation')
        ->where('command_idempotency_key', $extensionKey)
        ->find();
    $extensionResultSnapshot = json_decode(
        (string)($extensionAudit['result_snapshot_json'] ?? ''),
        true
    );
    $reenabledProjection = $dispatcher->dispatch(cardOpProjectionBody($targetSession, 4102), $targetSession);
    ok('启用与延期在当前状态和旧持卡人有效期同步生效',
        ($enable['result']['status'] ?? '') === 'success'
        && ($extension['result']['status'] ?? '') === 'success'
        && (string)Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->value('card_status') === 'enabled'
        && (int)Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->value('effective_write_end') === $newEnd
        && (int)Db::name('user_card_holder')->where('id', 4601)->value('write_end') === $newEnd
        && (int)Db::name('store_order_cart_info')->where('id', 4701)->value('write_end') === $newEnd,
        '', 'C2-CARDOP-BE-12');
    ok('启用后当前会员重新可见权益且延期审计不改写历史订单',
        cardOpSelectorHasHolder($reenabledProjection, 4601)
        && (string)($extensionAudit['operation_type'] ?? '') === 'card_extension'
        && (int)($extensionAudit['member_id_before'] ?? 0) === 4102
        && (int)($extensionAudit['member_id_after'] ?? 0) === 4102
        && (int)($extensionAudit['write_end_after'] ?? 0) === $newEnd
        && is_array($extensionResultSnapshot)
        && (string)($extensionResultSnapshot['operationStatus'] ?? '') === 'succeeded'
        && (int)($extensionResultSnapshot['stateMutation']['effectiveWriteEnd'] ?? 0) === $newEnd
        && (int)Db::name('store_order')->where('id', 4501)->value('uid') === 4101,
        json_encode($extensionAudit, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-12A');

    cardOpSection('project replacement merges differently priced sources and grants the selected target count');
    $replacementVersion = cardOpVersion('card_holder', 4601);
    $replacementKey = 'CARD_OPERATION-' . MemberIntegrationFixture::uuid();
    $replacementBody = cardOpBody($session, [
        'operationType' => 'project_replacement',
        'sourceCardHolderId' => 4601,
        'sourceCardHolderVersion' => $replacementVersion,
        'targetCatalogId' => 4902,
        'projectLines' => [
            ['sourceDetailId' => 4701, 'quantity' => 2],
            ['sourceDetailId' => 4702, 'quantity' => 2],
        ],
        'targetQuantity' => 3,
        'reason' => '客户确认更换护理项目',
    ], [cardOpContext('card_holder', 4601, $replacementVersion)], $replacementKey);
    $replacement = $dispatcher->dispatch($replacementBody, $session);
    $replacementAudit = (array)Db::name('cashier_v3_card_operation')
        ->where('command_idempotency_key', $replacementKey)->find();
    $replacementTarget = (array)Db::name('store_order_cart_info')
        ->where('oid', 4501)->where('product_id', 4802)->where('cart_type', 2)->where('product_type', 6)
        ->find();
    $replacementTargetCartInfo = json_decode((string)($replacementTarget['cart_info'] ?? ''), true);
    $replacementTargetCartInfo = is_array($replacementTargetCartInfo) ? $replacementTargetCartInfo : [];
    ok('项目替换扣减来源权益并按所选次数创建目标权益',
        ($replacement['result']['status'] ?? '') === 'success'
        && (int)Db::name('store_order_cart_info')->where('id', 4701)->value('write_surplus_times') === 6
        && (int)Db::name('store_order_cart_info')->where('id', 4702)->value('write_surplus_times') === 1
        && (int)($replacementTarget['write_times'] ?? 0) === 3
        && (int)($replacementTarget['write_surplus_times'] ?? 0) === 3
        && (int)($replacementTarget['uid'] ?? 0) === 4101
        && (string)($replacementTarget['pay_price'] ?? '') === '50.00'
        && (string)($replacementTargetCartInfo['productInfo']['store_name'] ?? '') === '卡操作替换目标项目'
        && (int)Db::name('user_card_holder')->where('id', 4601)->value('write_surplus_times') === 8,
        json_encode(['result' => $replacement, 'target' => $replacementTarget], JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-12B');
    ok('项目替换留不可变审计和来源/目标行且不改写历史销售',
        (string)($replacementAudit['operation_type'] ?? '') === 'project_replacement'
        && (string)($replacementAudit['operation_status'] ?? '') === 'succeeded'
        && (int)($replacementAudit['target_catalog_id'] ?? 0) === 4802
        && (int)($replacementAudit['source_remaining_value_cents'] ?? 0) === 5000
        && (int)Db::name('cashier_v3_card_operation_line')->where('operation_id', (string)($replacementAudit['operation_id'] ?? ''))->count() === 3
        && (int)Db::name('store_order')->where('id', 4501)->value('uid') === 4101
        && (int)Db::name('store_order')->count() === 1,
        json_encode($replacementAudit, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-12C');
    // 新生成的权益明确冻结整元口径；核销/再替换均把不能均分的整元留给末次。
    $amountAllocator = new \app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator();
    $auditLine = (array)Db::name('cashier_v3_card_operation_line')
        ->where('operation_id', (string)$replacementAudit['operation_id'])->where('line_no', 1)->find();
    $auditLineSnapshot = json_decode((string)($auditLine['line_snapshot_json'] ?? ''), true) ?: [];
    ok('替换目标整元分摊、末次尾差和金额扣减审计一致',
        ($replacementTargetCartInfo['amountCalculationVersion'] ?? '') === $amountAllocator::CALCULATION_VERSION
        && !$amountAllocator::isCentCapableSnapshot($replacementTargetCartInfo)
        && $amountAllocator::usesIndependentAmountSnapshot($replacementTargetCartInfo)
        && $amountAllocator::allocateForSnapshot('50.00', 3, 0, 1, $replacementTargetCartInfo) === '16.00'
        && $amountAllocator::allocateForSnapshot('50.00', 3, 2, 1, $replacementTargetCartInfo) === '18.00'
        && isset($auditLineSnapshot['replacementAmountAllocation']['roundingDeductionCents']),
        json_encode($auditLineSnapshot, JSON_UNESCAPED_UNICODE), 'R36-REPLACEMENT-WHOLE-YUAN');
    $replacementReplay = $dispatcher->dispatch($replacementBody, $session);
    ok('项目替换幂等重放不会重复扣权益或创建目标项目',
        !empty($replacementReplay['replay'])
        && (int)Db::name('store_order_cart_info')->where('oid', 4501)->where('product_id', 4802)->count() === 1
        && (int)Db::name('store_order_cart_info')->where('id', 4701)->value('write_surplus_times') === 6
        && (int)Db::name('store_order_cart_info')->where('id', 4702)->value('write_surplus_times') === 1,
        json_encode($replacementReplay, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-12D');
    $replacementTargetRule = $ruleAuthority->authorityForDetail(
        '0',
        4601,
        (int)($replacementTarget['id'] ?? 0)
    );
    $replacementSourceRule = $ruleAuthority->authorityForDetail('0', 4601, 4701);
    $replacementSecondSourceRule = $ruleAuthority->authorityForDetail('0', 4601, 4702);
    $replacementTargetComponent = (array)Db::name('cashier_v3_card_rule_component')
        ->where('legacy_detail_id', (int)($replacementTarget['id'] ?? 0))
        ->find();
    ok('项目替换在同一事务同步规则组件，后续权益选择可读取目标项目',
        (int)($replacementSourceRule['remainingTimes'] ?? -1) === 6
        && (int)($replacementSecondSourceRule['remainingTimes'] ?? -1) === 1
        && (int)($replacementTargetRule['remainingTimes'] ?? -1) === 3
        && (int)($replacementTargetRule['purchaseAmountCents'] ?? -1) === 5000
        && (int)($replacementTargetComponent['project_product_id'] ?? 0) === 4802
        && (string)($replacementTargetComponent['status'] ?? '') === 'active',
        json_encode($replacementTargetComponent, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-12E');

    // 隔离夹具先验证创建即登记，再模拟旧版本遗漏；重开选择器必须修复技术
    // 版本，而不增加任何替换记录或改变目标余次。
    ok('替换目标创建即登记既有资源正版本',
        cardOpVersion('member_benefit_pool', (int)$replacementTarget['id']) > 0,
        '', 'R36-REPLACEMENT-TARGET-VERSION');
    Db::name('cashier_v3_entitlement_resource_version')
        ->where('resource_kind', 'member_benefit_pool')
        ->where('resource_id', (string)$replacementTarget['id'])->delete();
    // The browser closes the replacement selector and reopens current rights
    // before a later card operation. Reproduce that projection sync here so
    // the following upgrade uses the post-replacement holder fingerprint.
    $postReplacementProjection = $dispatcher->dispatch(
        cardOpProjectionBody($targetSession, 4102),
        $targetSession
    );
    $replacementTargetProjection = cardOpSelectorProject(
        $postReplacementProjection,
        4601,
        (int)($replacementTarget['id'] ?? 0)
    );
    ok('旧替换目标重开权益自动补齐版本且不改权益',
        cardOpVersion('member_benefit_pool', (int)$replacementTarget['id']) > 0
        && (int)Db::name('store_order_cart_info')->where('id', (int)$replacementTarget['id'])->value('write_surplus_times') === 3,
        json_encode($postReplacementProjection, JSON_UNESCAPED_UNICODE), 'R36-REPLACEMENT-LEGACY-VERSION');
    // 已经停在结账确认页的旧快照不要求重新选卡。复用真实锁内来源读取，
    // 验证只补缺失登记且下一次读取不重置已有并发校验信息。
    Db::name('cashier_v3_entitlement_resource_version')
        ->where('resource_kind', 'member_benefit_pool')
        ->where('resource_id', (string)$replacementTarget['id'])->delete();
    $authority = new \app\services\cashier\v3\card\CashierV3CardOperationAuthorityServices();
    $loadSource = new ReflectionMethod($authority, 'loadSourceCardForUpdate');
    $loadSource->setAccessible(true);
    $recoveredSource = Db::transaction(function () use ($loadSource, $authority, $operatorScope, $dataScope, $replacementTarget): array {
        return $loadSource->invoke($authority, 4601, $operatorScope, 'project_upgrade', [
            'projectLines' => [['sourceDetailId' => (int)$replacementTarget['id'], 'quantity' => 1]],
        ], $dataScope);
    });
    $existingVersion = cardOpVersion('member_benefit_pool', (int)$replacementTarget['id']);
    Db::transaction(function () use ($loadSource, $authority, $operatorScope, $dataScope, $replacementTarget): void {
        $loadSource->invoke($authority, 4601, $operatorScope, 'project_upgrade', [
            'projectLines' => [['sourceDetailId' => (int)$replacementTarget['id'], 'quantity' => 1]],
        ], $dataScope);
    });
    ok('已打开结账的旧替换项目锁内自动补齐且保留权益',
        (int)($recoveredSource['projects'][0]['detailVersion'] ?? 0) > 0
        && cardOpVersion('member_benefit_pool', (int)$replacementTarget['id']) === $existingVersion
        && (int)($recoveredSource['projects'][0]['remainingTimes'] ?? 0) === 3,
        json_encode($recoveredSource, JSON_UNESCAPED_UNICODE), 'R36-REPLACEMENT-CHECKOUT-RECOVERY');
    ok('项目替换后重新打开权益会同步卡级版本供后续卡操作使用',
        cardOpSelectorHasHolder($postReplacementProjection, 4601)
        && cardOpVersion('card_holder', 4601) > $replacementVersion,
        json_encode($postReplacementProjection, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-12F');
    ok('项目替换生成权益按自身购买次数和金额正常选择',
        (string)($replacementTargetProjection['purchaseAmount'] ?? '') === '50.00'
        && (string)($replacementTargetProjection['remainingAmount'] ?? '') === '50.00'
        && (int)($replacementTargetProjection['purchaseTimes'] ?? 0) === 3
        && (int)($replacementTargetProjection['remainingTimes'] ?? 0) === 3
        && (int)($replacementTargetProjection['availableTimes'] ?? 0) === 3
        && !empty($replacementTargetProjection['selectable'])
        && empty($replacementTargetProjection['disabledReason']),
        json_encode($replacementTargetProjection, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-12G');

    cardOpSection('permission and pending upgrade safety');
    $beforeDenied = (int)Db::name('cashier_v3_card_operation')->count();
    $denySession = $session;
    $denySession['operator_profile'] = [
        'id' => 1,
        'level' => 1,
        'roles' => [],
        'employee_id' => 1,
        'account' => 'no-cashier-permission',
    ];
    // V3 permissions come from the active store_v3 position rule, rather
    // than the legacy roles field. Revoke that exact authority for this
    // negative case and restore it before the following upgrade flow.
    $originalChannelRule = (array)Db::name('job_position_channel_rule')
        ->where('position_id', 901)->where('channel', 'store_v3')->find();
    Db::name('job_position_channel_rule')
        ->where('id', (int)$originalChannelRule['id'])
        ->update(['status' => 0, 'rules' => '']);
    $denyVersion = cardOpVersion('card_holder', 4601);
    $stateBeforeDenied = (array)Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->find();
    $denyBody = cardOpBody($denySession, [
        'operationType' => 'card_disable',
        'sourceCardHolderId' => 4601,
        'sourceCardHolderVersion' => $denyVersion,
        'reason' => '无权限测试',
    ], [cardOpContext('card_holder', 4601, $denyVersion)], 'CARD_OPERATION-' . MemberIntegrationFixture::uuid());
    $denyDetail = [];
    $denyCode = cardOpRejectCode(function () use ($dispatcher, $denyBody, $denySession): void {
        $dispatcher->dispatch($denyBody, $denySession);
    }, $denyDetail);
    ok('无收银权限不能办理卡操作且无副作用', $denyCode === 'PERMISSION_DENIED'
        && (int)Db::name('cashier_v3_card_operation')->count() === $beforeDenied
        && (array)Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->find() === $stateBeforeDenied,
        json_encode($denyDetail, JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-13');

    Db::name('job_position_channel_rule')
        ->where('id', (int)$originalChannelRule['id'])
        ->update([
            'status' => (int)($originalChannelRule['status'] ?? 1),
            'rules' => (string)($originalChannelRule['rules'] ?? '301001'),
        ]);

    $upgradeSelectionRequest = MemberIntegrationFixture::commandRequest(
        $dispatcher,
        'select-cashier-member',
        ['selectorEntry' => 'cashier', 'memberId' => '4102'],
        1,
        'CMD-' . MemberIntegrationFixture::uuid()
    );
    $upgradeSession = $upgradeSelectionRequest['session'];
    $upgradeSelection = $dispatcher->dispatch($upgradeSelectionRequest['body'], $upgradeSession);
    $upgradeWorkspaceId = (string)$upgradeSelectionRequest['workspace_id'];
    $upgradeWorkspaceVersion = (int)Db::name('cashier_v3_resource_version')
        ->where('resource_kind', 'cashier_workspace')
        ->where('resource_id', $upgradeWorkspaceId)
        ->value('current_version');
    $upgradeVersion = cardOpVersion('card_holder', 4601);
    $upgradeBody = cardOpBody($upgradeSession, [
        'operationType' => 'card_upgrade',
        'sourceCardHolderId' => 4601,
        'sourceCardHolderVersion' => $upgradeVersion,
        'targetCatalogId' => 4901,
        'reason' => '待结账升级测试',
    ], [
        cardOpContext('card_holder', 4601, $upgradeVersion),
        cardOpContext('cashier_workspace', 0, $upgradeWorkspaceVersion),
    ], 'CARD_OPERATION-' . MemberIntegrationFixture::uuid());
    $upgradeBody['command']['contexts'][1]['id'] = $upgradeWorkspaceId;
    $upgradeStateBefore = (array)Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->find();
    $upgrade = $dispatcher->dispatch($upgradeBody, $upgradeSession);
    $upgradeOperation = (array)($upgrade['data']['cardOperation'] ?? []);
    $upgradeStateAfter = (array)Db::name('cashier_v3_card_state')->where('card_holder_id', 4601)->find();
    ok('卡升级只创建受保护补价行且不提前推进来源权益',
        ($upgradeSelection['result']['status'] ?? '') === 'success'
        && ($upgrade['result']['status'] ?? '') === 'success'
        && ($upgradeOperation['operationStatus'] ?? '') === 'awaiting_checkout'
        && !empty($upgradeOperation['requiresCheckout'])
        && (int)Db::name('cashier_v3_card_operation')->count() === $beforeDenied + 1
        && $upgradeStateAfter === $upgradeStateBefore
        && cardOpVersion('card_holder', 4601) === $upgradeVersion
        && (int)Db::name('cashier_v3_workspace_line')
            ->where('workspace_id', $upgradeWorkspaceId)
            ->count() === 1,
        json_encode(['result' => $upgrade, 'operation' => $upgradeOperation], JSON_UNESCAPED_UNICODE), 'C2-CARDOP-BE-14');
} catch (Throwable $exception) {
    $detail = $exception instanceof CashierV3CommandException
        ? json_encode($exception->getDetail(), JSON_UNESCAPED_UNICODE)
        : '';
    ok(
        '卡操作集成测试初始化',
        false,
        get_class($exception) . ': ' . $exception->getMessage() . ($detail === '' ? '' : ' detail=' . $detail),
        'C2-CARDOP-BE-00'
    );
}

finish('card-operation-integration');
