<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$projection = (string)file_get_contents(
    $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php'
);
$selector = (string)file_get_contents(
    $root . '/前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue'
);

$checks = [
    'source debt comes from the authoritative source-order debt resolver' => str_contains($projection, '$outstandingDebtAmount = $this->pendingDebt(')
        && str_contains($projection, "'outstandingDebtAmount' => \$outstandingDebtAmount"),
    'debt column follows balance and renders the backend source value' => str_contains($selector, '<span>剩余余额</span>')
        && str_contains($selector, '<span>欠款</span>')
        && str_contains($selector, '{{ displayAmount(source.outstandingDebtAmount) }}'),
    // 欠款和取消额始终只属于父卡；新增单价不得打破项目行空列与表头对齐。
    'project rows do not repeat a card-level debt amount' => str_contains(
        $selector,
        'displayProjectRemainingAmount(source, project) }}</span>' . PHP_EOL
        . '              <span aria-hidden="true" />' . PHP_EOL
        . '              <span aria-hidden="true" />' . PHP_EOL
        . '              <span>{{ displayAmount(project.purchaseAmount) }}</span>'
    ),
    'R52 nine headers are ordered and cancel value comes from backend' => preg_match('/<span>操作按钮<\/span>\s*<span>卡名称<\/span>\s*<span>余次<\/span>\s*<span>剩余余额<\/span>\s*<span>欠款<\/span>\s*<span>取消欠款<\/span>\s*<span>购买金额<\/span>\s*<span>单价<\/span>\s*<span>有效期<\/span>/', $selector) === 1
        && str_contains($selector, '{{ displayAmount(source.cancelledDebtAmount) }}')
        && str_contains($projection, "'cancelledDebtAmount' => \$this->cancelledDebt("),
    'source and project operation precede name cell' => strpos($selector, '@click="chooseSourceOperation(source)"') < strpos($selector, '<div class="cashier-entitlement-table__source-name"')
        && strpos($selector, '@click="addProject(source, project)"') < strpos($selector, '<div class="cashier-entitlement-table__project-name"'),
    'all-card view retains historical unavailable cards as display-only rows' => str_contains($projection, 'bool $includeDisplayOnlyCards = false')
        && str_contains($projection, '已升级为其他卡项，当前不可使用')
        && str_contains($selector, 'source.disabledReason || sourceCardNo(source)'),
];

$passed = 0;
foreach ($checks as $name => $valid) {
    echo ($valid ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if ($valid) $passed++;
}
echo "entitlement selector debt display contract: {$passed}/" . count($checks) . " PASS\n";
exit($passed === count($checks) ? 0 : 1);
