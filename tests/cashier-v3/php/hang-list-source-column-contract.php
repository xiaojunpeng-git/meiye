<?php

declare(strict_types=1);

/** Hang-list source is the saved label, including for old user column preferences. */

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangCheckoutSourceSnapshot.php';

use app\services\cashier\v3\hang\CashierV3HangCheckoutSourceSnapshot;

$failed = 0;
function expectHangListSource(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $message . "\n";
    if (!$condition) $failed++;
}

$saved = json_encode(['checkoutBusinessSource' => [
    'primarySourceId' => 23,
    'primarySourceNameSnapshot' => '老客转介绍',
    'secondarySourceId' => 91,
    'secondarySourceNameSnapshot' => '会员推荐',
]], JSON_UNESCAPED_UNICODE);
expectHangListSource(
    CashierV3HangCheckoutSourceSnapshot::displayLabelFromJson($saved, 1001) === '老客转介绍 / 会员推荐',
    'saved primary and secondary names form the historical list label'
);
expectHangListSource(
    CashierV3HangCheckoutSourceSnapshot::displayLabelFromJson($saved, 0) === '',
    'guest rows cannot expose a previous member source'
);
expectHangListSource(
    CashierV3HangCheckoutSourceSnapshot::displayLabelFromJson(null, 1001) === ''
        && CashierV3HangCheckoutSourceSnapshot::displayLabelFromJson('{bad', 1001) === '',
    'old and invalid sidecars have no fabricated source'
);

$list = (string)file_get_contents($root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangOrderListServices.php');
$view = (string)file_get_contents($root . '/前端代码/cashier-v3/src/views/HangOrderListView.vue');
expectHangListSource(
    strpos($list, "'member_id', 'local_draft_snapshot_json'") !== false
        && strpos($list, "'source' => CashierV3HangCheckoutSourceSnapshot::displayLabelFromJson(") !== false,
    'scoped list projection reads the saved hang source sidecar'
);
expectHangListSource(
    strpos($view, "{ key: 'source', label: '来源', defaultVisible: true }") !== false
        && strpos($view, "fields.splice(phoneIndex >= 0 ? phoneIndex + 1 : 0, 0, availableFields.get('source'))") !== false
        && strpos($view, 'source: record.source') !== false,
    'source stays directly after phone under both default and older saved settings'
);

exit($failed > 0 ? 1 : 0);
