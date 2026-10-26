<?php
declare(strict_types=1);

/**
 * A hang order is only a cashier draft. Deleting it must physically remove
 * its header and lines without treating it as a sales-order reversal.
 */

$root = __DIR__ . '/../../../后端代码';
$voidSource = (string)file_get_contents(
    $root . '/app/services/cashier/v3/hang/CashierV3HangVoidServices.php'
);
$moduleSource = (string)file_get_contents(
    $root . '/app/services/cashier/v3/hang/CashierV3HangModule.php'
);
$manifestSource = (string)file_get_contents(
    $root . '/app/services/cashier/v3/manifest/CashierV3ActionManifest.php'
);
$routeSource = (string)file_get_contents(__DIR__ . '/../../../后端代码/route/cashier-v3.php');
$controllerSource = (string)file_get_contents(
    $root . '/app/controller/cashier/v3/HangDraft.php'
);

$passed = 0;
$failed = 0;
$assert = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}\n";
};

$assert(
    'delete removes all persisted hang lines without line-status or line-count gates',
    str_contains($voidSource, "->where('hang_order_id', \$hangOrderId)\n            ->delete();")
        && !str_contains($voidSource, "->where('line_status', 'held')\n            ->delete();")
        && !str_contains($voidSource, 'hang_delete_line_cas_conflict')
);
$assert(
    'delete accepts every hang status and deletes the header by scoped version only',
    !str_contains($voidSource, "->whereIn('hang_status', [\n                CashierV3HangOrderPlanV1::STATUS_PENDING_CHECKOUT")
        && !str_contains($voidSource, '(int)($row[\'line_count\'] ?? 0) <= 0')
);
$assert(
    'deleted hang is not version-bumped after the physical delete',
    str_contains($voidSource, '$touched = [\'cashier_workspace\'];')
        && !str_contains($voidSource, '$touched = [\'hang_order\'];')
        && str_contains($voidSource, "'accessMode' => 'read'")
        && str_contains($moduleSource, "'touched' => ['cashier_workspace'],")
);
$assert(
    'resume and delete are eventless draft operations',
    str_contains($manifestSource, "'eventless_reason' => 'hang_draft_snapshot_restore'")
        && str_contains($manifestSource, "'eventless_reason' => 'hang_draft_physical_delete'")
);
$assert(
    'delete uses a dedicated direct draft endpoint, not the command gateway',
    str_contains($routeSource, "Route::post('hang-drafts/delete', 'HangDraft/delete')")
        && str_contains($controllerSource, 'deleteDirectInTx')
);

echo "hang-draft-delete-contract: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
