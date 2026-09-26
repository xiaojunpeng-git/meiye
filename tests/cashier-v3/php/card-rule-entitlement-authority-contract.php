<?php
declare(strict_types=1);

/** Four-rule issued-card write-off authority contract; no database writes. */

$root = getenv('CASHIER_V3_BACKEND_ROOT');
$root = is_string($root) && $root !== '' ? rtrim($root, '/') : __DIR__ . '/../../../后端代码';

require_once $root . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $root . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $root . '/app/services/cashier/v3/card/CashierV3IssuedCardRuleStateServices.php';
require_once $root . '/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php';

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;

$passed = 0;
$failed = 0;
function ruleAuthorityCheck(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
}

$service = (new ReflectionClass(CashierV3CardRuleEntitlementAuthorityServices::class))
    ->newInstanceWithoutConstructor();
$normalize = new ReflectionMethod(CashierV3CardRuleEntitlementAuthorityServices::class, 'normalizeAuthority');
$normalize->setAccessible(true);

function ruleState(string $type, array $override = []): array
{
    return array_merge([
        'rule_type' => $type,
        'rule_version' => 1,
        'state_version' => 1,
        'selected_kind_count' => 0,
        'choice_limit' => 0,
        'shared_total_times' => 0,
        'shared_remaining_times' => 0,
        'valid_from' => 1700000000,
        'valid_through' => 1800000000,
        'status' => 'active',
    ], $override);
}

function ruleComponent(array $override = []): array
{
    $snapshot = ['configuredPriceCents' => 2500];
    return array_merge([
        'total_times' => 6,
        'remaining_times' => 4,
        'writeoff_amount_cents' => 0,
        'selection_status' => 'not_applicable',
        'component_snapshot_json' => json_encode($snapshot),
    ], $override);
}

$normal = $normalize->invoke($service, ruleState('normal'), ruleComponent());
ruleAuthorityCheck('CARD-RULE-01 normal card exposes independent component counters',
    $normal['remainingTimes'] === 4
    && $normal['totalTimes'] === 6
    && $normal['purchaseAmountCents'] === 15000
    && $normal['sourceKindLabel'] === '普通卡');

$choiceCandidate = $normalize->invoke(
    $service,
    ruleState('choice_kind', ['choice_limit' => 2, 'selected_kind_count' => 1]),
    ruleComponent(['selection_status' => 'candidate'])
);
$choiceFull = $normalize->invoke(
    $service,
    ruleState('choice_kind', ['choice_limit' => 2, 'selected_kind_count' => 2]),
    ruleComponent(['selection_status' => 'candidate'])
);
$choiceSelected = $normalize->invoke(
    $service,
    ruleState('choice_kind', ['choice_limit' => 2, 'selected_kind_count' => 2]),
    ruleComponent(['selection_status' => 'selected'])
);
ruleAuthorityCheck('CARD-RULE-02 choice-kind atomically admits only available candidates',
    $choiceCandidate['choiceAvailable'] === true
    && $choiceFull['choiceAvailable'] === false
    && $choiceSelected['choiceAvailable'] === true
    && $choiceCandidate['sourceKindLabel'] === '任选种数卡');

$sharedOne = $normalize->invoke(
    $service,
    ruleState('choice_count', ['shared_total_times' => 10, 'shared_remaining_times' => 7]),
    ruleComponent()
);
$sharedTwo = $normalize->invoke(
    $service,
    ruleState('choice_count', ['shared_total_times' => 10, 'shared_remaining_times' => 7]),
    ruleComponent(['remaining_times' => 0])
);
ruleAuthorityCheck('CARD-RULE-03 choice-count projects share one whole-card counter',
    $sharedOne['remainingTimes'] === 7
    && $sharedTwo['remainingTimes'] === 7
    && $sharedOne['totalTimes'] === 10
    && $sharedOne['sourceKindLabel'] === '任选次数卡');

$time = $normalize->invoke(
    $service,
    ruleState('time'),
    ruleComponent(['total_times' => 0, 'remaining_times' => 0, 'writeoff_amount_cents' => 8800])
);
ruleAuthorityCheck('CARD-RULE-04 time card is unlimited and freezes per-service amount',
    $time['remainingTimes'] === CashierV3CardRuleEntitlementAuthorityServices::TIME_CARD_VIRTUAL_TIMES
    && $time['unlimited'] === true
    && $time['sourceKind'] === 'time_card'
    && $time['writeoffAmountCents'] === 8800
    && $time['sourceKindLabel'] === '时间卡');

$authoritySource = (string)file_get_contents(
    $root . '/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php'
);
$writerSource = (string)file_get_contents(
    $root . '/app/services/cashier/v3/checkout/persistence/ThinkPhpCashierV3EntitlementCompletionWriter.php'
);
$providerSource = (string)file_get_contents(
    $root . '/app/services/cashier/v3/cashier/CashierV3EntitlementResourceVersionProvider.php'
);
$projectionSource = (string)file_get_contents(
    $root . '/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php'
);
$adapterSource = (string)file_get_contents(
    $root . '/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php'
);

ruleAuthorityCheck('CARD-RULE-05 every successful service advances whole-card state version',
    strpos($authoritySource, "\$changes['state_version'] = \$version + 1") !== false
    && strpos($authoritySource, "->where('state_version', \$version)") !== false
    && strpos($authoritySource, "if (\$ruleType === 'time')") !== false);
ruleAuthorityCheck('CARD-RULE-06 choice-kind first use freezes selected store and selection time',
    strpos($authoritySource, "\$changes['selection_status'] = 'selected'") !== false
    && strpos($authoritySource, "\$changes['selected_store_id'] = \$storeId") !== false
    && strpos($authoritySource, 'card_rule_choice_limit_exceeded') !== false);
ruleAuthorityCheck('CARD-RULE-07 shared pool decrements once by aggregate quantity',
    strpos($authoritySource, "if (\$ruleType === 'choice_count')") !== false
    && strpos($authoritySource, "\$after = \$remaining - \$totalQuantity") !== false
    && strpos($authoritySource, 'card_rule_shared_times_insufficient') !== false);
ruleAuthorityCheck('CARD-RULE-08 time card keeps compatibility counters unchanged',
    strpos($authoritySource, "'legacyMode' => \$ruleType === 'time' ? 'keep' : 'deduct'") !== false
    && strpos($writerSource, "=== 'keep'") !== false);
ruleAuthorityCheck('CARD-RULE-09 validity and immutable snapshots fail closed',
    strpos($authoritySource, 'card_rule_state_outside_validity') !== false
    && strpos($authoritySource, 'card_rule_state_fingerprint_invalid') !== false
    && strpos($authoritySource, 'card_rule_component_fingerprint_invalid') !== false);
ruleAuthorityCheck('CARD-RULE-10 idempotency receipt is established before any rule deduction',
    strpos($writerSource, '$receipt = $this->insertProcessingReceipt($plan);')
        < strpos($writerSource, '$ruleDeductions = $this->cardRules()->applyDeductionsInTx(')
    && strpos($writerSource, 'return $this->replayResult($plan, $existing);') !== false);
ruleAuthorityCheck('CARD-RULE-11 holder and detail fingerprints include shared rule state',
    substr_count($providerSource, "'card_rule_state' => \$ruleState") >= 2
    && strpos($providerSource, 'fingerprintSnapshotForHolder(') !== false
    && strpos($providerSource, 'fingerprintSnapshotForDetail(') !== false);
ruleAuthorityCheck('CARD-RULE-12 selector and final adapter both consume issued rule authority',
    strpos($projectionSource, 'authoritiesForHolder(') !== false
    && strpos($projectionSource, "'unlimited'") !== false
    && strpos($adapterSource, 'authorityForDetail(') !== false
    && strpos($adapterSource, "'issued-card-rule-'") !== false);
ruleAuthorityCheck('CARD-RULE-13 replacement targets compare physical detail remainder before shared deduction',
    strpos($authoritySource, 'replacementPhysicalRemainingInTx') !== false
    && strpos($authoritySource, "'cashier_v3_project_replacement'") !== false
    && strpos($authoritySource, "'replacementOperationId'") !== false
    && strpos($authoritySource, "->lock(true)") !== false);

echo "CARD_RULE_ENTITLEMENT_AUTHORITY_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
