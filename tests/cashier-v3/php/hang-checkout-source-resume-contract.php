<?php

declare(strict_types=1);

/** Customer-source selection survives both hang snapshot paths without becoming a settlement fact. */

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangCheckoutSourceSnapshot.php';

use app\services\cashier\v3\hang\CashierV3HangCheckoutSourceSnapshot;

$failed = 0;
function expectHangSource(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
    if (!$condition) $failed++;
}

$selected = CashierV3HangCheckoutSourceSnapshot::normalize([
    'primarySourceId' => 23,
    'primarySourceNameSnapshot' => '老客转介绍',
    'secondarySourceId' => 91,
    'secondarySourceNameSnapshot' => '会员推荐',
    'rewardAmountCents' => 1200,
    'forgedCheckoutResult' => 'paid',
], 1001);
expectHangSource($selected['primarySourceId'] === 23 && $selected['secondarySourceId'] === 91
    && $selected['rewardAmountCents'] === 1200, 'member source IDs and reward survive the snapshot');
expectHangSource($selected['primarySourceNameSnapshot'] === '老客转介绍'
    && $selected['secondarySourceNameSnapshot'] === '会员推荐', 'source display names survive navigation');
expectHangSource(!array_key_exists('forgedCheckoutResult', $selected), 'unrelated checkout fields are never persisted');

$guest = CashierV3HangCheckoutSourceSnapshot::normalize($selected, 0);
expectHangSource($guest['primarySourceId'] === 0 && $guest['secondarySourceId'] === 0
    && $guest['rewardAmountCents'] === 0, 'guest resume cannot inherit a member source');
$invalid = CashierV3HangCheckoutSourceSnapshot::normalize([
    'primarySourceId' => -1,
    'secondarySourceId' => 91,
    'rewardAmountCents' => 'not-a-number',
], 1001);
expectHangSource($invalid['primarySourceId'] === 0 && $invalid['secondarySourceId'] === 0,
    'invalid or orphan source IDs are cleared');

$submission = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangSubmissionServices.php');
$resume = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangResumeServices.php');
$workbench = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue');
expectHangSource(strpos($submission, "\$local['checkoutBusinessSource'] = CashierV3HangCheckoutSourceSnapshot::normalize(") !== false
    && strpos($submission, "['checkoutBusinessSource' => \$checkoutBusinessSource]") !== false,
    'local and server-backed hangs save the source in their respective snapshots');
expectHangSource(strpos($resume, "'checkoutBusinessSource' => CashierV3HangCheckoutSourceSnapshot::normalize(") !== false
    && substr_count($resume, "'checkoutBusinessSource' => \$this->savedCheckoutBusinessSource(\$header)") >= 2,
    'direct and legacy resume return the saved source, with old hangs defaulting empty');
expectHangSource(substr_count($workbench, 'checkoutBusinessSource: currentHangCheckoutBusinessSource()') >= 2
    && strpos($workbench, 'restoreHangCheckoutBusinessSource(detail)') !== false
    && strpos($workbench, 'publishToolbarCheckoutContext()') !== false,
    'both save entries capture the toolbar source and resume republishes it');
expectHangSource(strpos($submission, 'payment_collected') === false
    && strpos($resume, 'payment_collected') === false,
    'hang source snapshot does not create a payment fact');

exit($failed > 0 ? 1 : 0);
