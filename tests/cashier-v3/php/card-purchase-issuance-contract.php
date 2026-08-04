<?php
/** Card-sale entitlement issuance contract; no database writes. */

$root = getenv('CASHIER_V3_BACKEND_ROOT');
$root = is_string($root) && $root !== '' ? rtrim($root, '/') : __DIR__ . '/../../../后端代码';

require_once $root . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $root . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $root . '/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php';
require_once $root . '/app/services/cashier/v3/card/CashierV3IssuedCardRuleStateServices.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\card\CashierV3CardPurchaseIssuanceServices;
use app\services\cashier\v3\card\CashierV3IssuedCardRuleStateServices;

$passed = 0;
$failed = 0;
function cardPurchaseCheck(string $name, bool $condition): void
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

$reflection = new ReflectionClass(CashierV3CardPurchaseIssuanceServices::class);
$service = $reflection->newInstanceWithoutConstructor();
$validity = $reflection->getMethod('validity');
$validity->setAccessible(true);
$days = $validity->invoke($service, ['validity' => [
    'writeValid' => 2, 'writeDays' => 30, 'writeStart' => 0, 'writeEnd' => 0,
]], 1700000000);
cardPurchaseCheck('CPU-01 duration validity is issued from trusted settlement time',
    $days === ['writeValid' => 2, 'writeDays' => 30, 'writeStart' => 1700000000, 'writeEnd' => 1702592000]);

$fixed = $validity->invoke($service, ['validity' => [
    'writeValid' => 3, 'writeDays' => 0, 'writeStart' => 1700000000, 'writeEnd' => 1800000000,
]], 1750000000);
cardPurchaseCheck('CPU-02 fixed validity never drifts to checkout time',
    $fixed === ['writeValid' => 3, 'writeDays' => 0, 'writeStart' => 1700000000, 'writeEnd' => 1800000000]);

$customFixed = $validity->invoke($service, [
    'sourceKind' => 'custom_card',
    'validity' => [
        'writeValid' => 3, 'writeDays' => 0, 'writeStart' => 0, 'writeEnd' => 1800000000,
    ],
], 1750000000);
cardPurchaseCheck('CPU-02A custom-card fixed end activates at successful settlement time',
    $customFixed === ['writeValid' => 3, 'writeDays' => 0, 'writeStart' => 1750000000, 'writeEnd' => 1800000000]);

$invalidReason = '';
try {
    $validity->invoke($service, ['validity' => ['writeValid' => 2, 'writeDays' => 0]], 1700000000);
} catch (ReflectionException $exception) {
    $invalidReason = 'reflection';
} catch (\Throwable $exception) {
    $previous = $exception instanceof \ReflectionException ? null : $exception->getPrevious();
    $source = $previous instanceof CashierV3CommandException ? $previous : $exception;
    if ($source instanceof CashierV3CommandException) {
        $invalidReason = (string)($source->getDetail()['reason'] ?? '');
    }
}
cardPurchaseCheck('CPU-03 invalid duration fails closed', $invalidReason === 'card_purchase_validity_invalid');

$source = file_get_contents($root . '/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php');
$settlement = file_get_contents($root . '/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$inventory = file_get_contents($root . '/app/services/cashier/v3/settlement/CashierV3SaleInventorySettlementServices.php');
$migration = file_get_contents($root . '/database/upgrades/2026-07-30-收银V3卡项权益签发/02-正式升级.sql');
cardPurchaseCheck('CPU-04 receipt is idempotent per formal sales line and issued unit',
    strpos($source, "'uk_tenant_line_issue'") === false
    && strpos($migration, 'UNIQUE KEY `uk_tenant_line_issue` (`tenant_id`,`sales_order_line_id`,`issue_no`)') !== false
    && strpos($source, "->where('receipt_id', \$receiptId)") !== false);
cardPurchaseCheck('CPU-05 formal checkout signs cards before terminal facts are exposed',
    strpos($settlement, '$cardPurchaseResult = $this->cardPurchases->issueInTx(') !== false
    && strpos($settlement, '$factPlan = CashierV3SaleOnlyFactAssembler::assemble(') > strpos($settlement, '$cardPurchaseResult = $this->cardPurchases->issueInTx('));
cardPurchaseCheck('CPU-06 inventory ignores cards and non-stock projects but rejects unsupported sale types',
    strpos($inventory, "in_array((string)(\$line['item_type'] ?? ''), ['card', 'project'], true)") !== false
    && strpos($inventory, "if ((string)(\$line['item_type'] ?? '') !== 'product')") !== false);
cardPurchaseCheck('CPU-07 card catalog authority is re-read under the final transaction',
    strpos($source, '$this->catalog->selectSaleLineInTx(') !== false
    && strpos($source, 'card_purchase_catalog_authority_changed') !== false);
cardPurchaseCheck('CPU-08 holder and each project benefit receive a projected version',
    substr_count($source, "'member_benefit_pool'") >= 1
    && strpos($source, "'card_holder'") !== false
    && strpos($source, 'synchronizeProjectionVersion(') !== false);
cardPurchaseCheck('CPU-09 custom-card issuance settles immutable configuration and honours delayed activation',
    strpos($source, 'lockCustomConfiguration(') !== false
    && strpos($source, 'settleCustomConfiguration(') !== false
    && strpos($source, "? 'enabled' : 'disabled'") !== false
    && strpos($source, '购卡时选择暂不开卡') !== false);
cardPurchaseCheck('CPU-10 custom-card workspace comes from the locked checkout request',
    strpos($source, "\$lockedRequest = is_array(\$aggregate['request'] ?? null)") !== false
    && strpos($source, 'card_purchase_workspace_scope_mismatch') !== false
    && strpos($source, "->where('workspace_id', \$workspaceId)") !== false
    && strpos($source, "\$header['workspace_id']") === false);

$ruleSource = file_get_contents($root . '/app/services/cashier/v3/card/CashierV3IssuedCardRuleStateServices.php');
$ruleMigration = file_get_contents($root . '/database/upgrades/2026-08-03-收银V3新卡项规则签发状态/02-正式升级.sql');
cardPurchaseCheck('CPU-11 new configured cards persist immutable rule and component state in checkout transaction',
    strpos($source, '$this->ruleStates->issueInTx(') !== false
    && strpos($ruleSource, "CashierV3TransactionGuard::assertInTransaction('issuedCardRuleState')") !== false
    && strpos($ruleMigration, 'eb_cashier_v3_card_rule_state') !== false
    && strpos($ruleMigration, 'eb_cashier_v3_card_rule_component') !== false);
cardPurchaseCheck('CPU-12 issued state supports independent, choice, shared and time-card counters without old-card backfill',
    strpos($ruleSource, "['normal', 'choice_kind', 'choice_count', 'time']") !== false
    && strpos($ruleSource, "'shared_remaining_times' => \$sharedTimes") !== false
    && strpos($ruleSource, "'writeoff_amount_cents'") !== false
    && strpos($ruleMigration, 'No historic holder/card row is read or changed') !== false);

echo "CARD_PURCHASE_ISSUANCE_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
