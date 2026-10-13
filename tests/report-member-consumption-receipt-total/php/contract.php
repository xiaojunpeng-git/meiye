<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = file_get_contents($root . '/后端代码/app/services/report/StoreUnifiedReportServices.php');
$view = file_get_contents($root . '/前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');

if ($service === false || $view === false) {
    fwrite(STDERR, "Unable to read member consumption report sources.\n");
    exit(1);
}

function receiptTotalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

receiptTotalAssert(
    str_contains($service, '$receiptTotal += $amount;'),
    'receipt total accumulates every successful accounting payment method'
);
receiptTotalAssert(
    !str_contains($service, "if (\$method['code'] === 'other_collection') \$receiptTotal = \$amount;"),
    'receipt total does not retain only other collection'
);
receiptTotalAssert(
    str_contains($view, '退款行按退款业务日显示负向退款调整'),
    'field guide distinguishes allocated sale receipts from refund adjustments'
);

$memberReport = explode('private function storeItemAnalysis', explode('private function memberConsumptionDetail', $service, 2)[1] ?? '', 2)[0];
receiptTotalAssert(
    str_contains($memberReport, "'refund' entry_kind")
        && str_contains($memberReport, "'sale' entry_kind")
        && str_contains($memberReport, '->unionAll($refundKeys)')
        && !str_contains($memberReport, "->having('SUM(member_receipt.amount_cents)>0')"),
    'member consumption includes zero-receipt sales and dated refund rows in one backend population'
);
receiptTotalAssert(
    str_contains($memberReport, "if (empty(\$input['_internal_all'])) \$ordered->page"),
    'export retains the complete filtered sale-and-refund scope without browser pagination'
);
