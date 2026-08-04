<?php
declare(strict_types=1);

$testRoot = getenv('CASHIER_V3_TEST_ROOT') ?: '/workspace/tests/cashier-v3';
require $testRoot . '/lib/boot-env.php';
require '/var/www/html/vendor/autoload.php';
require $testRoot . '/lib/_lib.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use think\facade\Db;

c1aBootThinkApp('/var/www/html/');

function cardRuleCanonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_keys($value) === ($value ? range(0, count($value) - 1) : [])) {
        return array_map('cardRuleCanonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = cardRuleCanonicalize($item);
    }
    return $value;
}

function cardRuleFingerprint(array $value): string
{
    return hash('sha256', json_encode(
        cardRuleCanonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
}

function insertRuleFixture(
    string $suffix,
    string $ruleType,
    int $holderId,
    int $memberId,
    int $choiceLimit,
    int $sharedTimes,
    array $components,
    int $validFrom,
    int $validThrough,
    bool $badFingerprint = false
): array {
    $now = time();
    $definition = ['fixture' => $suffix, 'ruleType' => $ruleType, 'components' => $components];
    $stateId = (int)Db::name('cashier_v3_card_rule_state')->insertGetId([
        'state_id' => 'CRS-TEST-' . $suffix,
        'tenant_id' => '0',
        'receipt_id' => 'CPR-TEST-' . $suffix,
        'sales_order_id' => 'SO-TEST-' . $suffix,
        'sales_order_line_id' => 'SOL-TEST-' . $suffix,
        'card_holder_id' => $holderId,
        'member_id' => $memberId,
        'issue_store_id' => 7,
        'catalog_product_id' => 800000 + $holderId,
        'catalog_sku_id' => 900000 + $holderId,
        'rule_type' => $ruleType,
        'rule_version' => 1,
        'definition_version' => 1,
        'choice_limit' => $choiceLimit,
        'selected_kind_count' => 0,
        'shared_total_times' => $sharedTimes,
        'shared_remaining_times' => $sharedTimes,
        'validity_mode' => 3,
        'valid_from' => $validFrom,
        'valid_through' => $validThrough,
        'immutable_fingerprint' => $badFingerprint ? str_repeat('0', 64) : cardRuleFingerprint($definition),
        'definition_snapshot_json' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'state_version' => 1,
        'status' => 'active',
        'occurred_at' => $now,
        'recorded_at' => $now,
        'add_time' => $now,
        'update_time' => $now,
    ]);
    $detailIds = [];
    foreach ($components as $index => $component) {
        $snapshot = [
            'configuredPriceCents' => (int)$component['configuredPriceCents'],
            'fixture' => $suffix . '-' . $index,
        ];
        $detailId = (int)$component['detailId'];
        Db::name('cashier_v3_card_rule_component')->insert([
            'component_state_id' => 'CRC-TEST-' . $suffix . '-' . $index,
            'tenant_id' => '0',
            'rule_state_id' => $stateId,
            'card_holder_id' => $holderId,
            'legacy_detail_id' => $detailId,
            'relation_id' => 700000 + $detailId,
            'project_product_id' => (int)$component['projectId'],
            'project_sku_id' => 600000 + $detailId,
            'project_sku_unique' => 'SKU-TEST-' . $suffix . '-' . $index,
            'project_type' => 6,
            'project_name_snapshot' => '测试项目' . $index,
            'total_times' => (int)$component['totalTimes'],
            'remaining_times' => (int)$component['remainingTimes'],
            'writeoff_amount_cents' => (int)$component['writeoffAmountCents'],
            'selection_status' => (string)$component['selectionStatus'],
            'selected_at' => 0,
            'selected_store_id' => 0,
            'immutable_fingerprint' => cardRuleFingerprint($snapshot),
            'component_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'state_version' => 1,
            'status' => 'active',
            'occurred_at' => $now,
            'recorded_at' => $now,
            'add_time' => $now,
            'update_time' => $now,
        ]);
        $detailIds[] = $detailId;
    }
    return ['stateId' => $stateId, 'detailIds' => $detailIds];
}

function deduction(int $holderId, int $detailId, int $projectId, int $quantity, int $expected): array
{
    return [
        'holder_id' => $holderId,
        'source_detail_id' => $detailId,
        'project_id' => $projectId,
        'deduct_physical_times' => $quantity,
        'expected_physical_remaining_times' => $expected,
    ];
}

$service = new CashierV3CardRuleEntitlementAuthorityServices();
$memberId = 990001;
$now = time();
$suffix = substr(hash('sha256', getmypid() . ':' . microtime(true)), 0, 10);
$holderBase = 990000000 + random_int(1000, 9000);
$detailBase = 980000000 + random_int(1000, 9000);
$fixtureStateIds = [];

Db::startTrans();
try {
    $normal = insertRuleFixture($suffix . '-normal', 'normal', $holderBase + 1, $memberId, 0, 0, [[
        'detailId' => $detailBase + 1, 'projectId' => 501, 'totalTimes' => 5,
        'remainingTimes' => 5, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1000,
        'selectionStatus' => 'not_applicable',
    ], [
        'detailId' => $detailBase + 2, 'projectId' => 502, 'totalTimes' => 5,
        'remainingTimes' => 5, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1000,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $normal['stateId'];
    $service->applyDeductionsInTx([
        deduction($holderBase + 1, $detailBase + 1, 501, 2, 5),
    ], ['tenant_id' => '0', 'store_id' => 8, 'member_id' => $memberId, 'recorded_at' => $now]);
    ok('normal card decrements only selected component',
        (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 1)->value('remaining_times') === 3
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 2)->value('remaining_times') === 5,
        '', 'CARD-RULE-MYSQL-01');

    $choice = insertRuleFixture($suffix . '-kind', 'choice_kind', $holderBase + 2, $memberId, 1, 0, [[
        'detailId' => $detailBase + 3, 'projectId' => 503, 'totalTimes' => 3,
        'remainingTimes' => 3, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1200,
        'selectionStatus' => 'candidate',
    ], [
        'detailId' => $detailBase + 4, 'projectId' => 504, 'totalTimes' => 3,
        'remainingTimes' => 3, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1200,
        'selectionStatus' => 'candidate',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $choice['stateId'];
    $service->applyDeductionsInTx([
        deduction($holderBase + 2, $detailBase + 3, 503, 1, 3),
    ], ['tenant_id' => '0', 'store_id' => 9, 'member_id' => $memberId, 'recorded_at' => $now]);
    $choiceBlocked = false;
    try {
        $service->applyDeductionsInTx([
            deduction($holderBase + 2, $detailBase + 4, 504, 1, 3),
        ], ['tenant_id' => '0', 'store_id' => 10, 'member_id' => $memberId, 'recorded_at' => $now]);
    } catch (CashierV3CommandException $exception) {
        $choiceBlocked = ($exception->getDetail()['reason'] ?? '') === 'card_rule_choice_limit_exceeded';
    }
    ok('choice-kind first use freezes selection and rejects a new kind over limit',
        $choiceBlocked
        && (int)Db::name('cashier_v3_card_rule_state')->where('id', $choice['stateId'])->value('selected_kind_count') === 1
        && (string)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 3)->value('selection_status') === 'selected'
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 3)->value('selected_store_id') === 9,
        '', 'CARD-RULE-MYSQL-02');

    $shared = insertRuleFixture($suffix . '-count', 'choice_count', $holderBase + 3, $memberId, 0, 8, [[
        'detailId' => $detailBase + 5, 'projectId' => 505, 'totalTimes' => 0,
        'remainingTimes' => 0, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1500,
        'selectionStatus' => 'not_applicable',
    ], [
        'detailId' => $detailBase + 6, 'projectId' => 506, 'totalTimes' => 0,
        'remainingTimes' => 0, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1600,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $shared['stateId'];
    $service->applyDeductionsInTx([
        deduction($holderBase + 3, $detailBase + 5, 505, 1, 8),
        deduction($holderBase + 3, $detailBase + 6, 506, 2, 8),
    ], ['tenant_id' => '0', 'store_id' => 11, 'member_id' => $memberId, 'recorded_at' => $now]);
    ok('choice-count aggregates different projects into one shared deduction',
        (int)Db::name('cashier_v3_card_rule_state')->where('id', $shared['stateId'])->value('shared_remaining_times') === 5
        && (int)Db::name('cashier_v3_card_rule_state')->where('id', $shared['stateId'])->value('state_version') === 2,
        '', 'CARD-RULE-MYSQL-03');

    $time = insertRuleFixture($suffix . '-time', 'time', $holderBase + 4, $memberId, 0, 0, [[
        'detailId' => $detailBase + 7, 'projectId' => 507, 'totalTimes' => 0,
        'remainingTimes' => 0, 'writeoffAmountCents' => 8800, 'configuredPriceCents' => 0,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $time['stateId'];
    $service->applyDeductionsInTx([
        deduction($holderBase + 4, $detailBase + 7, 507, 3, CashierV3CardRuleEntitlementAuthorityServices::TIME_CARD_VIRTUAL_TIMES),
    ], ['tenant_id' => '0', 'store_id' => 12, 'member_id' => $memberId, 'recorded_at' => $now]);
    ok('time card records a successful state transition without count deduction',
        (int)Db::name('cashier_v3_card_rule_state')->where('id', $time['stateId'])->value('state_version') === 2
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 7)->value('remaining_times') === 0
        && (int)Db::name('cashier_v3_card_rule_component')->where('legacy_detail_id', $detailBase + 7)->value('writeoff_amount_cents') === 8800,
        '', 'CARD-RULE-MYSQL-04');

    $expired = insertRuleFixture($suffix . '-expired', 'time', $holderBase + 5, $memberId, 0, 0, [[
        'detailId' => $detailBase + 8, 'projectId' => 508, 'totalTimes' => 0,
        'remainingTimes' => 0, 'writeoffAmountCents' => 9900, 'configuredPriceCents' => 0,
        'selectionStatus' => 'not_applicable',
    ]], $now - 3600, $now - 1);
    $fixtureStateIds[] = $expired['stateId'];
    $expiredBlocked = false;
    try {
        $service->applyDeductionsInTx([
            deduction($holderBase + 5, $detailBase + 8, 508, 1, CashierV3CardRuleEntitlementAuthorityServices::TIME_CARD_VIRTUAL_TIMES),
        ], ['tenant_id' => '0', 'store_id' => 13, 'member_id' => $memberId, 'recorded_at' => $now]);
    } catch (CashierV3CommandException $exception) {
        $expiredBlocked = ($exception->getDetail()['reason'] ?? '') === 'card_rule_state_outside_validity';
    }
    ok('expired time card fails closed before mutation', $expiredBlocked, '', 'CARD-RULE-MYSQL-05');

    $bad = insertRuleFixture($suffix . '-bad', 'normal', $holderBase + 6, $memberId, 0, 0, [[
        'detailId' => $detailBase + 9, 'projectId' => 509, 'totalTimes' => 2,
        'remainingTimes' => 2, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1000,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600, true);
    $fixtureStateIds[] = $bad['stateId'];
    $tamperBlocked = false;
    try {
        $service->snapshotForHolder('0', $holderBase + 6, false);
    } catch (CashierV3CommandException $exception) {
        $tamperBlocked = ($exception->getDetail()['reason'] ?? '') === 'card_rule_state_fingerprint_invalid';
    }
    ok('immutable issue snapshot tampering fails closed', $tamperBlocked, '', 'CARD-RULE-MYSQL-06');

    $race = insertRuleFixture($suffix . '-race', 'normal', $holderBase + 7, $memberId, 0, 0, [[
        'detailId' => $detailBase + 10, 'projectId' => 510, 'totalTimes' => 2,
        'remainingTimes' => 2, 'writeoffAmountCents' => 0, 'configuredPriceCents' => 1000,
        'selectionStatus' => 'not_applicable',
    ]], $now - 10, $now + 3600);
    $fixtureStateIds[] = $race['stateId'];
    $staleState = Db::name('cashier_v3_card_rule_state')->where('id', $race['stateId'])->find();
    $staleState = is_object($staleState) && method_exists($staleState, 'toArray')
        ? $staleState->toArray() : (array)$staleState;
    $updateState = new ReflectionMethod(CashierV3CardRuleEntitlementAuthorityServices::class, 'updateState');
    $updateState->setAccessible(true);
    $updateState->invoke($service, $staleState, [], $now);
    $staleBlocked = false;
    try {
        $updateState->invoke($service, $staleState, [], $now);
    } catch (CashierV3CommandException $exception) {
        $staleBlocked = ($exception->getDetail()['reason'] ?? '') === 'card_rule_state_update_conflict';
    }
    ok('stale concurrent state version is rejected by optimistic compare-and-swap',
        $staleBlocked
        && (int)Db::name('cashier_v3_card_rule_state')->where('id', $race['stateId'])->value('state_version') === 2,
        '', 'CARD-RULE-MYSQL-07');
} finally {
    Db::rollback();
}

ok('integration fixture transaction rolls back without persistent card-rule rows',
    (int)Db::name('cashier_v3_card_rule_state')->whereIn('id', $fixtureStateIds)->count() === 0,
    '', 'CARD-RULE-MYSQL-08');

finish('CARD_RULE_ENTITLEMENT_AUTHORITY_MYSQL56');
