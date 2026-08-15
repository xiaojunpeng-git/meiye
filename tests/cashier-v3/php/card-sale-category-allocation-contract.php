<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/后端代码/app/services/report/CardSaleCategoryAllocationServices.php';

use app\services\report\CardSaleCategoryAllocationServices;

$service = new CardSaleCategoryAllocationServices();
$rows = $service->allocate(100000, [
    ['stableKey'=>'category:22','projectId'=>252576,'categoryIdSnapshot'=>22,'categoryNameSnapshot'=>'六维/自营','amountWeightCents'=>100000],
]);
if (count($rows) !== 1 || $rows[0]['allocatedAmountCents'] !== 100000) {
    fwrite(STDERR, "FAIL single category allocation\n"); exit(1);
}
$rows = $service->allocate(1001, [
    ['stableKey'=>'category:20','projectId'=>20,'categoryIdSnapshot'=>20,'categoryNameSnapshot'=>'面部','amountWeightCents'=>1],
    ['stableKey'=>'category:10','projectId'=>10,'categoryIdSnapshot'=>10,'categoryNameSnapshot'=>'身体','amountWeightCents'=>2],
]);
if (array_sum(array_column($rows, 'allocatedAmountCents')) !== 1001
    || $rows[0]['stableKey'] !== 'category:10'
    || $rows[0]['allocatedAmountCents'] !== 667
    || $rows[1]['allocatedAmountCents'] !== 334) {
    fwrite(STDERR, "FAIL weighted cent conservation\n"); exit(1);
}
echo "PASS card sale category allocation contract\n";
