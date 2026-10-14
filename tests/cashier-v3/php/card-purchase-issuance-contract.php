<?php
/** Card-sale entitlement issuance contract; no database writes. */

$root = getenv('CASHIER_V3_BACKEND_ROOT');
$root = is_string($root) && $root !== '' ? rtrim($root, '/') : __DIR__ . '/../../../后端代码';

require_once $root . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $root . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $root . '/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php';
require_once $root . '/app/services/cashier/v3/card/CashierV3IssuedCardRuleStateServices.php';
require_once $root . '/app/services/cashier/v3/settlement/CashierV3CheckoutDebtAuthorityServices.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\card\CashierV3CardPurchaseIssuanceServices;
use app\services\cashier\v3\card\CashierV3IssuedCardRuleStateServices;
use app\services\cashier\v3\settlement\CashierV3CheckoutDebtAuthorityServices;

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
$allocate = $reflection->getMethod('allocateIssueAmounts');
$allocate->setAccessible(true);
$three = $allocate->invoke($service, [
    'original_amount_cents' => 1188000,
    'discount_amount_cents' => 0,
    'coupon_payment_cents' => 100000,
    'sale_amount_cents' => 1188000,
], 3);
cardPurchaseCheck('CPU-00A three cards absorb the integer-yuan coupon tail on the final issue',
    $three === [
        1 => ['originalCents' => 396000, 'discountCents' => 0, 'couponCents' => 33300, 'saleCents' => 396000],
        2 => ['originalCents' => 396000, 'discountCents' => 0, 'couponCents' => 33300, 'saleCents' => 396000],
        3 => ['originalCents' => 396000, 'discountCents' => 0, 'couponCents' => 33400, 'saleCents' => 396000],
    ]);
$four = $allocate->invoke($service, [
    'original_amount_cents' => 1272000,
    'discount_amount_cents' => 0,
    'coupon_payment_cents' => 100000,
    'sale_amount_cents' => 1272000,
], 4);
cardPurchaseCheck('CPU-00B four-card allocation preserves the screenshot settlement total',
    count($four) === 4 && array_sum(array_column($four, 'saleCents')) === 1272000
    && array_sum(array_column($four, 'couponCents')) === 100000
    && array_sum(array_column($four, 'discountCents')) === 0
    && count(array_unique(array_column($four, 'saleCents'))) === 1);
$issuanceSource = file_get_contents($root . '/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php');
$refundSource = file_get_contents($root . '/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php');
cardPurchaseCheck('CPU-00B2 V3 receipt freezes tender without reusing legacy discount fields',
    str_contains($issuanceSource, "'couponPaymentCents' => (int)\$issueAmounts['couponCents']")
    && substr_count($issuanceSource, "'coupon_price' => '0.00'") >= 2
    && str_contains($refundSource, "\$receiptResult['couponPaymentCents'] ?? 0")
    && !str_contains($refundSource, "self::moneyCents((string)(\$candidate['coupon_price'] ?? '0'), true)"));
$tiny = $allocate->invoke($service, [
    'original_amount_cents' => 300,
    'discount_amount_cents' => 200,
    'sale_amount_cents' => 100,
], 3);
cardPurchaseCheck('CPU-00C tiny amounts never create a negative card sale',
    $tiny[3]['discountCents'] === 100
    && array_sum(array_column($tiny, 'saleCents')) === 100
    && min(array_column($tiny, 'saleCents')) >= 0);
// Exhaust low-yuan remainder/capacity collisions. Every card must balance
// independently in whole yuan and reconcile to the locked sales row.
$allBalanced = true;
for ($quantity = 1; $quantity <= 8 && $allBalanced; $quantity++) {
    for ($originalUnits = 0; $originalUnits <= 40 && $allBalanced; $originalUnits++) {
        for ($discountUnits = 0; $discountUnits <= $originalUnits; $discountUnits++) {
            $original = $originalUnits * 100;
            $discount = $discountUnits * 100;
            $sale = $original - $discount;
            $units = $allocate->invoke($service, [
                'original_amount_cents' => $original,
                'discount_amount_cents' => $discount,
                'sale_amount_cents' => $sale,
            ], $quantity);
            $allBalanced = count($units) === $quantity
                && array_sum(array_column($units, 'originalCents')) === $original
                && array_sum(array_column($units, 'discountCents')) === $discount
                && array_sum(array_column($units, 'saleCents')) === $sale;
            foreach ($units as $unit) {
                $allBalanced = $allBalanced
                    && $unit['originalCents'] >= 0 && $unit['discountCents'] >= 0
                    && $unit['saleCents'] >= 0
                    && $unit['originalCents'] === $unit['discountCents'] + $unit['saleCents']
                    && $unit['originalCents'] % 100 === 0
                    && $unit['discountCents'] % 100 === 0
                    && $unit['saleCents'] % 100 === 0;
            }
            if (!$allBalanced) break;
        }
    }
}
cardPurchaseCheck('CPU-00D whole-yuan allocations reconcile without fractional amounts', $allBalanced);
$fractionalReason = '';
try {
    $allocate->invoke($service, [
        'original_amount_cents' => 100001,
        'discount_amount_cents' => 100000,
        'sale_amount_cents' => 1,
    ], 3);
} catch (\Throwable $exception) {
    $sourceException = $exception->getPrevious() ?: $exception;
    if ($sourceException instanceof CashierV3CommandException) {
        $fractionalReason = (string)($sourceException->getDetail()['reason'] ?? '');
    }
}
cardPurchaseCheck('CPU-00E fractional-yuan source amounts fail without rounding the sale',
    $fractionalReason === 'card_purchase_amount_not_integer_yuan');
$debt = CashierV3CheckoutDebtAuthorityServices::exactAllocations(100100, [[
    'order_line_id' => 'card-three', 'quantity' => 3,
    'sale_amount_cents' => 1088000, 'debt_amount_cents' => 100100,
]]);
cardPurchaseCheck('CPU-00F card debt remains an exact whole-line authority without divisibility requirements',
    $debt === ['card-three' => 100100]);
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
$catalogSource = file_get_contents($root . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
$salesPlanSource = file_get_contents($root . '/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php');
$factAssemblerSource = file_get_contents($root . '/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php');
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
cardPurchaseCheck('CPU-07 final card issue locks identity without replaying catalogue version or price',
    strpos($source, '$this->catalog->selectCheckoutSaleLineInTx(') !== false
    && strpos($source, 'assertCardIssueSnapshotIdentity(') !== false
    && strpos($source, "\$salesLine['item_version']") === false
    && strpos($source, 'card_purchase_catalog_authority_changed') === false);
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
cardPurchaseCheck('CPU-12 issued state supports independent, choice, shared, time and combination cards without old-card backfill',
    strpos($ruleSource, "['normal', 'choice_kind', 'choice_count', 'time', 'combination']") !== false
    && strpos($ruleSource, "'shared_remaining_times' => \$sharedTimes") !== false
    && strpos($ruleSource, "'writeoff_amount_cents'") !== false
    && strpos($ruleMigration, 'No historic holder/card row is read or changed') !== false);

cardPurchaseCheck('CPU-13 card issue does not compare the snapshot with current catalogue cost',
    strpos($source, 'isAuthorizedManualPriceSettlement') === false
    && strpos($source, "\$line['configured_cost_cents']") === false);
cardPurchaseCheck('CPU-14 card issue does not compare a snapshot item version with the current catalogue',
    strpos($source, "\$line['source_version']") === false
    && strpos($source, "\$salesLine['item_version']") === false);
cardPurchaseCheck('CPU-15 card issue retains only immutable snapshot structure checks',
    strpos($source, 'card_purchase_snapshot_structure_invalid') !== false
    && strpos($source, "\$salesLine['catalog_sku_id']") !== false);
cardPurchaseCheck('CPU-16 final card issue locks SKU identity without reloading component structure',
    strpos($catalogSource, "assertInTransaction('cashierSaleCatalogCheckoutSelect')") !== false
    && strpos($catalogSource, 'selectCheckoutSaleLineInTx(') !== false
    && strpos($catalogSource, 'must not reload card components') !== false);
cardPurchaseCheck('CPU-17 issuance consumes the persisted browser card snapshot',
    strpos($source, 'salesLineCardPurchaseSnapshot($salesLine)') !== false
    && strpos($source, "\$salesLine['card_purchase_snapshot_json']") !== false
    && strpos($source, "\$line['authority_snapshot']['cardPurchase'] = \$purchase") !== false);
cardPurchaseCheck('CPU-18 sales order line carries the browser card snapshot into issuance',
    substr_count($salesPlanSource, "'card_purchase_snapshot_json' =>") >= 2);
cardPurchaseCheck('CPU-19 issued card rule does not require stripped browser version coordinates',
    strpos($ruleSource, '$ruleVersion <= 0') === false
    && strpos($ruleSource, '$definitionVersion <= 0') === false);
cardPurchaseCheck('CPU-20 purchase receipt fingerprints the same browser card snapshot',
    strpos($source, "'cardPurchaseSnapshot' => \$purchase") !== false
    && strpos($source, "'catalog' => \$snapshot") === false);
cardPurchaseCheck('CPU-21 formal fact input accepts the persisted card snapshot field',
    strpos($factAssemblerSource, "'manual_labor_fee_cents', 'card_purchase_snapshot_json'") !== false);
cardPurchaseCheck('CPU-22 legacy order and card cart use one issued-unit allocation, not independent division',
    substr_count($source, "\$issueAmounts['saleCents']") === 2
    && substr_count($source, "\$issueAmounts['originalCents']") === 2
    && substr_count($source, "\$issueAmounts['discountCents']") === 2
    && strpos($source, 'divideExactly(') === false);
cardPurchaseCheck('CPU-23 historical receipt replay precedes new whole-yuan allocation',
    strpos($source, 'return $this->replay($existing, $header, $salesLine, $issueNo, $commandIdempotencyKey);')
        < strpos($source, '$lineIssueAmounts = $this->allocateIssueAmounts($salesLine,'));

echo "CARD_PURCHASE_ISSUANCE_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
