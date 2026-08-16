<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$files = [
    'route' => $root . '/后端代码/route/cashier-v3.php',
    'controller' => $root . '/后端代码/app/controller/cashier/v3/HangDraft.php',
    'submission' => $root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangSubmissionServices.php',
    'preparation' => $root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangPreparationServices.php',
    'workspace' => $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php',
    'view' => $root . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue',
    'api' => $root . '/前端代码/cashier-v3/src/services/hangDraftApi.js',
    'list' => $root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangOrderListServices.php',
    'resume' => $root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangResumeServices.php',
    'binding' => $root . '/后端代码/app/services/cashier/v3/hang/CashierV3HangCheckoutBindingServices.php',
    'plan' => $root . '/后端代码/app/services/cashier/v3/hang/authority/CashierV3HangOrderPlanV1.php',
];

$failed = 0;
function expectContract(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        echo "PASS {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
}

$source = [];
foreach ($files as $key => $file) {
    $source[$key] = (string)file_get_contents($file);
}

expectContract(strpos($source['route'], "Route::post('hang-drafts/save', 'HangDraft/save')") !== false, 'direct save route is registered');
expectContract(strpos($source['route'], "Route::post('hang-drafts/resume', 'HangDraft/resume')") !== false, 'direct resume route is registered');
expectContract(strpos($source['route'], "Route::post('cashier-drafts/clear', 'HangDraft/clearCart')") !== false, 'direct clear route is registered');
expectContract(strpos($source['controller'], 'public function save()') !== false
    && strpos($source['controller'], 'saveDraftDirectInTx') !== false, 'controller persists through direct draft service');
$directOffset = strpos($source['submission'], 'public function saveDraftDirectInTx');
$nextMethodOffset = $directOffset === false
    ? false
    : strpos($source['submission'], 'private function assertWorkspaceContext', $directOffset);
$directMethod = $directOffset === false
    ? ''
    : substr($source['submission'], $directOffset, $nextMethodOffset === false ? null : $nextMethodOffset - $directOffset);
expectContract($directMethod !== ''
    && strpos($directMethod, 'revalidateSubmissionInTx') === false
    && strpos($directMethod, "CashierV3HangOrderPlanV1::MODE_NORMAL") !== false
    && strpos($directMethod, 'currentSelectableRoomForDirectHang') === false
    && strpos($directMethod, 'roomGuard->claimInTx') === false
    && strpos($directMethod, "'roomOccupation' => null") !== false,
    'direct-save skips all business and room-occupancy checks; room is only draft association');
expectContract(strpos($source['submission'], 'transferToHangInTx(') !== false
    && strpos($source['submission'], "true,\n            false") !== false, 'direct save snapshots and clears without fingerprint preparation');
expectContract(strpos($source['workspace'], 'bool $retainMember = false') !== false
    && strpos($source['workspace'], "if (!\$retainMember)") !== false, 'direct clear can retain selected member');
$removeOffset = strpos($source['workspace'], 'public function removeLineInTx');
$removeEnd = $removeOffset === false ? false : strpos($source['workspace'], 'public function clearLinesInTx', $removeOffset);
$removeMethod = $removeOffset === false ? '' : substr($source['workspace'], $removeOffset, $removeEnd === false ? null : $removeEnd - $removeOffset);
expectContract($removeMethod !== ''
    && strpos($removeMethod, 'assertNotResumedHangMutation') === false
    && strpos($removeMethod, 'lockLine($workspaceId, $lineKey)') !== false
    && strpos($removeMethod, "->delete()") !== false,
    'single-line delete directly removes any current draft line without hang or entitlement eligibility checks');
expectContract(strpos($source['api'], 'hang-drafts/save') !== false, 'frontend posts to direct save endpoint');
expectContract(strpos($source['api'], 'hang-drafts/resume') !== false, 'frontend posts to direct resume endpoint');
expectContract(strpos($source['api'], 'cashier-drafts/clear') !== false, 'frontend posts to direct clear endpoint');
$resumeOffset = strpos($source['resume'], 'public function resumeDirectInTx');
$resumeEnd = $resumeOffset === false ? false : strpos($source['resume'], 'private function loadEligibleHeader', $resumeOffset);
$resumeMethod = $resumeOffset === false ? '' : substr($source['resume'], $resumeOffset, $resumeEnd === false ? null : $resumeEnd - $resumeOffset);
expectContract($resumeMethod !== ''
    && strpos($resumeMethod, 'loadEligibleHeader($hangOrderId') === false, 'direct resume skips legacy eligibility gate');
expectContract(strpos($source['list'], 'CashierV3HangOrderPlanV1::MODE_START_SERVICE') !== false
    && strpos($source['list'], "'canResume' => in_array") !== false,
    'normal and room drafts remain resumable without legacy contract checks');
expectContract(strpos($source['list'], "'room_name_snapshot'") !== false
    && strpos($source['list'], "'roomName' =>") !== false
    && strpos($source['list'], "queryText(\$payload, 'room_name')") !== false,
    'hang list exposes room association and filters by room name');
expectContract(strpos($source['resume'], "'sourceRetained' => true") !== false
    && strpos($source['resume'], '$deletedLines') === false
    && strpos($source['resume'], "'hang_status' => 'resumed_checkout'") === false,
    'resume keeps the source hang as an unchanged draft until checkout completion');
expectContract(strpos($source['binding'], "resumed_hang_order_id") !== false
    && strpos($source['binding'], "->where('hang_order_id', \$hangOrderId)\n            ->delete();") !== false
    && strpos($source['binding'], 'readResumedHeader(') !== false
    && strpos($source['binding'], 'hang_checkout_completion_source_delete_incomplete') === false,
    'successful checkout directly deletes the retained draft without treating it as a checkout resource');
expectContract(strpos($source['plan'], "'debt_amount_cents'") !== false
    && strpos($source['plan'], "'price_change_reason'") !== false
    && strpos($source['plan'], "'coupon_discount_cents'") !== false,
    'new hang snapshots preserve line debt, price changes, and coupons');
expectContract(strpos($source['workspace'], "'debt_amount_cents' => (int)(\$line['debt_amount_cents'] ?? 0)") !== false
    && strpos($source['workspace'], "'price_change_reason' => (string)(\$line['price_change_reason'] ?? '')") !== false
    && strpos($source['workspace'], "'coupon_discount_cents' => (int)(\$line['coupon_discount_cents'] ?? 0)") !== false,
    'hang resume restores the frozen line financial fields instead of resetting them');
expectContract(strpos($source['view'], 'saveHangDraft({') !== false
    && strpos($source['view'], "mode: 'normal'") !== false
    && strpos($source['view'], 'roomNameSnapshot: intent?.roomName ||') !== false
    && strpos($source['view'], 'isHangOrderOpen.value = true') === false,
    'normal and room hang save immediately without opening a mode or room-selection step');
expectContract(strpos($source['view'], "requestAction('submit-hang-order'") !== false, 'start-service path remains on dedicated command');

exit($failed > 0 ? 1 : 0);
