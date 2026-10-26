<?php

declare(strict_types=1);

require __DIR__ . '/../../../后端代码/app/services/cashier/v3/cashier/CashierV3CombinationRemainingTimes.php';

use app\services\cashier\v3\cashier\CashierV3CombinationRemainingTimes;

$kind = static function (int $group, int $remaining, string $status, int $selected, int $limit = 1): array {
    return [
        'cardRuleType' => 'combination', 'ruleType' => 'choice_kind',
        'combinationGroupNo' => $group, 'combinationChoiceLimit' => $limit,
        'combinationSelectedKindCount' => $selected,
        'selectionStatus' => $status, 'remainingTimes' => $remaining,
    ];
};
$shared = static function (int $remaining): array {
    return [
        'cardRuleType' => 'combination', 'ruleType' => 'choice_count',
        'combinationGroupNo' => 2, 'remainingTimes' => $remaining,
    ];
};

// 初售时可选种数只计选择名额；核销后已用项目归零，其他候选仍保留物理次数但不可兑现。
$before = [$kind(1, 1, 'candidate', 0), $kind(1, 1, 'candidate', 0),
    $shared(2), $shared(2), $kind(3, 1, 'candidate', 0)];
$after = [$kind(1, 0, 'selected', 1), $kind(1, 1, 'candidate', 1),
    $shared(1), $shared(1), $kind(3, 1, 'candidate', 0)];
if (CashierV3CombinationRemainingTimes::fromAuthorities($before) !== 4
    || CashierV3CombinationRemainingTimes::fromAuthorities($after) !== 2) {
    fwrite(STDERR, "组合卡父卡余次未按组独立扣除。\n");
    exit(1);
}

// 多名额时优先按可选项目中余次较多者计算当前最多可用次数。
$multi = [$kind(1, 1, 'candidate', 0, 2), $kind(1, 3, 'candidate', 0, 2),
    $kind(1, 2, 'candidate', 0, 2)];
if (CashierV3CombinationRemainingTimes::fromAuthorities($multi) !== 5) {
    fwrite(STDERR, "组合卡多名额余次计算错误。\n");
    exit(1);
}
echo "COMBINATION_REMAINING_TIMES_CONTRACT passed=3 failed=0\n";
