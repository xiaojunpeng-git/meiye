<?php
declare(strict_types=1);

/**
 * Real ThinkPHP read and locked writeoff-authority rehearsal on disposable 5.7.
 * V3 rule deductions run inside a rollback transaction: no synthetic checkout
 * service/performance fact survives, and no customer database is connected.
 */
$backend = getenv('M008_RIGHTS_TEST_BACKEND') ?: '/var/www/html';
$host = getenv('M008_RIGHTS_TEST_HOST') ?: 'm008-rights-isolate';
if ($host !== 'm008-rights-isolate') throw new RuntimeException('Disposable host guard');
require $backend . '/vendor/autoload.php';
$app = new \think\App($backend . '/');
$app->env->load($backend . '/.env');
foreach ([
    'database.hostname' => $host, 'DATABASE_HOSTNAME' => $host,
    'database.hostport' => '3306', 'DATABASE_HOSTPORT' => '3306',
    'database.database' => 'mohe_008', 'DATABASE_DATABASE' => 'mohe_008',
    'database.username' => 'root', 'DATABASE_USERNAME' => 'root',
    'database.password' => 'rights-test-local', 'DATABASE_PASSWORD' => 'rights-test-local',
] as $key => $value) $app->env->set($key, $value);
$envName = new ReflectionProperty($app, 'envName');
$envName->setAccessible(true);
$envName->setValue($app, 'rights_full_isolated_skip_dotenv_reload');
$app->initialize();

use app\services\cashier\v3\card\CashierV3CardRuleEntitlementAuthorityServices;
use app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy;
use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;
use app\services\cashier\v3\checkout\CashierV3DirectSnapshotEntitlementSettlementServices;
use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\user\UserCardHolderServices;
use think\facade\Db;

function rightsFullAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

/** One detail is identified by the immutable source key, not by a catalogue name. */
function rightsFullLine(int $sourceId): array
{
    $row = Db::name('migration_entitlement_scope')->alias('s')
        ->join('user_card_holder h', 'h.id=s.card_holder_id')
        ->join('store_order_cart_info c', 'c.oid=h.oid AND c.cart_type=2')
        ->where('s.source_detail_id', $sourceId)
        ->field('s.source_detail_id,h.id holder_id,h.uid,h.card_name,h.card_no,c.id detail_id,c.product_id,c.cart_info')
        ->find();
    if (!$row) throw new RuntimeException('Frozen source detail missing: ' . $sourceId);
    $snapshot = json_decode((string)$row['cart_info'], true);
    return [
        'line_id' => 'm008-check-' . $row['detail_id'],
        'source_id' => (int)$row['holder_id'],
        'entitlement_source_detail_id' => (int)$row['detail_id'],
        'project_id' => (int)$row['product_id'],
        'source_type' => 'card_holder',
        'source_name_snapshot' => (string)$row['card_name'],
        'source_code_snapshot' => (string)$row['card_no'],
        'project_name_snapshot' => (string)($snapshot['productInfo']['store_name'] ?? ''),
    ];
}

/** Call the same final locked authority used by checkout, with real scope. */
function rightsFullAuthority(array $line, int $storeId): array
{
    $reflection = new ReflectionClass(CashierV3DirectSnapshotEntitlementSettlementServices::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $property = $reflection->getProperty('cardRules');
    $property->setAccessible(true);
    $property->setValue($service, new CashierV3CardRuleEntitlementAuthorityServices());
    $method = $reflection->getMethod('loadEntitlementAuthorities');
    $method->setAccessible(true);
    $orgId = $storeId === 11 ? '3' : '2';
    $operator = new CashierV3OperatorScope($storeId, 1, $orgId, '0');
    $scope = new CashierV3DataScopeContext(1, 1, $storeId, '0', $orgId, [$storeId],
        CashierV3DataScopeContext::MODE_STORES, [], false, '', 'm008-isolated',
        ['cashier.v3.cashier'], []);
    return $method->invoke($service, [$line], 421, $operator, $scope)[$line['line_id']];
}

if ((string)Db::query('SELECT DATABASE() db')[0]['db'] !== 'mohe_008'
    || (int)Db::name('migration_entitlement_scope')->count() !== 6531) {
    throw new RuntimeException('Full disposable rights fixture missing');
}
$_GET['page'] = 1;
$_GET['limit'] = 20;
$memberCards = $app->make(UserCardHolderServices::class)->getCardHolderList(421);
$oldSample = Db::name('migration_entitlement_scope')->where('source_uid', 421)
    ->field('card_holder_id,source_snapshot_json')->select()->toArray();
$byHolder = [];
foreach ($oldSample as $row) {
    $source = json_decode((string)$row['source_snapshot_json'], true);
    $byHolder[(int)$row['card_holder_id']] = [(string)$source['purchase_name'],
        (int)$source['source_remaining_times']];
}
rightsFullAssert(count($memberCards['list']) === 15 && (int)$memberCards['count'] === 15,
    'UID421 H5 card count differs');
foreach ($memberCards['list'] as $card) {
    $expected = $byHolder[(int)$card['id']] ?? null;
    rightsFullAssert($expected !== null && (string)$card['card_name'] === $expected[0]
        && (int)$card['write_surplus_times'] === $expected[1]
        && (int)$card['historical_display_single'] === 1
        && (int)$card['status'] === 1, 'UID421 H5 name/remainder differs');
}
$detail = new CashierV3MemberDetailQueryServices();
$method = new ReflectionMethod($detail, 'cardsForMembers');
$method->setAccessible(true);
$meiyue = $method->invoke($detail, [421], '0', '', 'active', '', '', 11);
$geni = $method->invoke($detail, [421], '0', '', 'active', '', '', 22);
rightsFullAssert(count($meiyue[421] ?? []) === 15 && count($geni[421] ?? []) === 0,
    'V3 member detail cross-brand boundary differs');

$projectionClass = new ReflectionClass(CashierV3EntitlementProjectionServices::class);
$projection = $projectionClass->newInstanceWithoutConstructor();
$rulesProperty = $projectionClass->getProperty('cardRules');
$rulesProperty->setAccessible(true);
$rulesProperty->setValue($projection, new CashierV3CardRuleEntitlementAuthorityServices());
$load = $projectionClass->getMethod('loadRows');
$load->setAccessible(true);
$build = $projectionClass->getMethod('buildSources');
$build->setAccessible(true);
$operator = new CashierV3OperatorScope(11, 1, '3', '0');
$rows = $load->invoke($projection, 421, $operator, '0', false, null, null,
    true, false, false, false, false);
$sources = $build->invoke($projection, $rows, [], [], '0', false, true);
$choice = array_values(array_filter($sources, static function (array $source): bool {
    return (string)$source['cardRuleType'] === 'choice_count' && !empty($source['selectable']);
}));
rightsFullAssert(count($sources) === 15 && count($choice) > 0,
    'V3 selector lost normal/choice historical rights');
$geniRows = $load->invoke($projection, 421,
    new CashierV3OperatorScope(22, 1, '2', '0'), '0', false, null, null,
    true, false, false, false, false);
rightsFullAssert(count($geniRows['holders']) === 0, 'Geni selector can read Meiyue rights');

$normalLine = rightsFullLine(265);
$choiceLine = rightsFullLine(9905);
rightsFullAssert(mb_check_encoding($choiceLine['project_name_snapshot'], 'UTF-8')
    && strpos($choiceLine['project_name_snapshot'], 'æ') === false,
    'Imported catalogue project name has invalid encoding');
$closedOldStoreLine = Db::name('migration_entitlement_scope')
    ->where('source_detail_id', 7)->find();
rightsFullAssert($closedOldStoreLine && CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
    [(int)$closedOldStoreLine['card_holder_id']], '0', 11
) === [(int)$closedOldStoreLine['card_holder_id']], 'Closed-old-store right is not available at Meiyue');
rightsFullAssert(CashierV3HistoricalEntitlementScopePolicy::allowedHolderIds(
    [(int)$closedOldStoreLine['card_holder_id']], '0', 22
) === [], 'Closed-old-store right leaked to Geni');

Db::startTrans();
try {
    $normal = rightsFullAuthority($normalLine, 11);
    $shared = rightsFullAuthority($choiceLine, 11);
    rightsFullAssert((int)$normal['physicalRemainingTimes'] === 10
        && (int)$shared['physicalRemainingTimes'] === 9
        && (int)$normal['debtLimitedUsableTimes'] === 10
        && (int)$shared['debtLimitedUsableTimes'] === 9,
        'Locked normal/choice authority differs');
    $geniRejected = false;
    try {
        rightsFullAuthority($normalLine, 22);
    } catch (Throwable $error) {
        $geniRejected = true;
    }
    rightsFullAssert($geniRejected, 'Geni checkout authority admitted Meiyue right');
    // 008 currently trails the source version's explicit card-debt selector
    // guard. Exercise that bundled source change without retaining fake debt.
    $normalCart = Db::name('store_order_cart_info')
        ->where('id', $normalLine['entitlement_source_detail_id'])->find();
    $debtSnapshot = json_decode((string)$normalCart['cart_info'], true);
    $debtSnapshot['block_writeoff_on_debt'] = 1;
    Db::name('store_order_cart_info')->where('id', $normalLine['entitlement_source_detail_id'])
        ->update(['cart_info' => json_encode($debtSnapshot, JSON_UNESCAPED_UNICODE)]);
    Db::name('store_order')->where('id', (int)$normalCart['oid'])
        ->update(['debt_amount' => '100.00']);
    $debtRows = $load->invoke($projection, 421, $operator, '0', false, null, null,
        true, false, false, false, false);
    $debtSources = $build->invoke($projection, $debtRows, [], [], '0', false, true);
    $blocked = false;
    $debtProbe = [];
    foreach ($debtSources as $source) {
        if ((int)$source['id'] === (int)$normalLine['source_id']) {
            $debtProbe = ['selectable' => $source['selectable'],
                'disabledReason' => $source['disabledReason'],
                'outstandingDebtAmount' => $source['outstandingDebtAmount'],
                'projectReason' => $source['projects'][0]['disabledReason'] ?? ''];
            $blocked = empty($source['selectable'])
                && strpos((string)($source['projects'][0]['disabledReason'] ?? ''), '欠款') !== false;
            break;
        }
    }
    rightsFullAssert($blocked, 'Card-debt selector guard did not block unpaid card: '
        . json_encode($debtProbe, JSON_UNESCAPED_UNICODE));
    Db::name('store_order_cart_info')->where('id', $normalLine['entitlement_source_detail_id'])
        ->update(['cart_info' => $normalCart['cart_info']]);
    Db::name('store_order')->where('id', (int)$normalCart['oid'])
        ->update(['debt_amount' => '0.00']);
    $deductions = [];
    foreach ([[$normalLine, $normal], [$choiceLine, $shared]] as $pair) {
        [$line, $authority] = $pair;
        $deductions[] = [
            'holder_id' => $line['source_id'],
            'source_detail_id' => $line['entitlement_source_detail_id'],
            'project_id' => $line['project_id'],
            'deduct_physical_times' => 1,
            'expected_physical_remaining_times' => $authority['physicalRemainingTimes'],
        ];
    }
    $ruleResults = (new CashierV3CardRuleEntitlementAuthorityServices())->applyDeductionsInTx(
        $deductions, ['tenant_id' => '0', 'store_id' => 11, 'member_id' => 421,
            'occurred_at' => time()]
    );
    $normalRemaining = (int)Db::name('cashier_v3_card_rule_component')
        ->where('legacy_detail_id', $normalLine['entitlement_source_detail_id'])
        ->value('remaining_times');
    $choiceRemaining = (int)Db::name('cashier_v3_card_rule_state')
        ->where('card_holder_id', $choiceLine['source_id'])
        ->value('shared_remaining_times');
    rightsFullAssert(count($ruleResults) === 2 && $normalRemaining === 9
        && $choiceRemaining === 8, 'Locked V3 rule deduction differs');
    Db::rollback();
} catch (Throwable $error) {
    Db::rollback();
    throw $error;
}
rightsFullAssert((int)Db::name('cashier_v3_card_rule_state')
    ->where('card_holder_id', $choiceLine['source_id'])
    ->value('shared_remaining_times') === 9,
    'Isolated checkout rehearsal did not roll back');
echo json_encode(['h5_uid421_lines' => 15, 'v3_meiyue_cards' => 15,
    'v3_geni_cards' => 0, 'v3_selector_sources' => count($sources),
    'choice_sources' => count($choice), 'closed_old_store_allow_meiyue' => true,
    'geni_checkout_rejected' => true, 'locked_normal_and_choice_rule_deducted' => true,
    'unpaid_card_selector_rejected' => true,
    'rule_deduction_rolled_back' => true], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
