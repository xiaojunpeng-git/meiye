<?php

declare(strict_types=1);

require __DIR__ . '/../../../后端代码/vendor/autoload.php';

use app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices;

// 同一共享组的两个项目指向同一笔已售金额；父卡只汇总一次，组内项目名展示不改金额事实。
$projection = (new ReflectionClass(CashierV3EntitlementProjectionServices::class))
    ->newInstanceWithoutConstructor();
$sourceAmounts = new ReflectionMethod(CashierV3EntitlementProjectionServices::class, 'sourceAmounts');
$shared = [
    'cardRuleType' => 'combination', 'combinationGroupNo' => 2,
    'combinationGroupRule' => 'choice_count',
    'purchaseAmount' => '40.00', 'combinationRawRemainingAmount' => '20.00',
    'amountCalculationVersion' => 'combination-shared-test',
];
$kind = [
    'cardRuleType' => 'combination', 'combinationGroupNo' => 1,
    'combinationGroupRule' => 'choice_kind',
    'purchaseAmount' => '60.00', 'combinationRawRemainingAmount' => '30.00',
    'amountCalculationVersion' => 'combination-kind-test',
];
$result = $sourceAmounts->invoke($projection, [$shared, $shared, $kind]);
if ($result['purchaseAmount'] !== '100.00' || $result['remainingAmount'] !== '50.00') {
    fwrite(STDERR, "组合卡共享组金额被重复汇总或遗漏。\n");
    exit(1);
}
$source = file_get_contents(__DIR__ . '/../../../后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php');
$projectAssembly = substr($source, strpos($source, '$project = ['),
    strpos($source, 'if ($includeVersionFields)', strpos($source, '$project = [')) - strpos($source, '$project = ['));
if (substr_count($projectAssembly, "'cardRuleType' =>") !== 1) {
    fwrite(STDERR, "组合卡项目的整卡规则类型被组规则覆盖。\n");
    exit(1);
}
echo "COMBINATION_SOURCE_AMOUNT_CONTRACT passed=2 failed=0\n";
