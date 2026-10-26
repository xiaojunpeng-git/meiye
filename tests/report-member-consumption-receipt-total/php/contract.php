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
    str_contains($view, "receipt_total: '本行销售明细分摊到的所有成功记账收款方式金额合计；未成功的收款不计入。'"),
    'field guide explains the per-sale-line receipt total definition'
);

$memberReport = explode('private function storeItemAnalysis', explode('private function memberConsumptionDetail', $service, 2)[1] ?? '', 2)[0];
receiptTotalAssert(
    str_contains($memberReport, "->name('cashier_v3_payment_sale_allocation_fact')")
        && str_contains($memberReport, "->having('SUM(member_receipt.amount_cents)>0')"),
    'member consumption rows require positive net effective receipt allocation'
);
receiptTotalAssert(
    str_contains($memberReport, "if (empty(\$input['_internal_all'])) \$rowQuery->page"),
    'export keeps the same paid-row scope without browser pagination'
);
