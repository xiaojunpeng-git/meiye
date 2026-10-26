<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    $source = file_get_contents($root . $path);
    if ($source === false) throw new RuntimeException('missing ' . $path);
    return $source;
};
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
};

$registry = $read('/后端代码/app/services/query/metric/MetricDefinitionRegistry.php');
$dictionary = $read('/后端代码/app/services/metric/MetricDictionaryServices.php');
$lifecycle = $read('/后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php');
$renderer = $read('/后端代码/app/services/ai/presentation/AiAnswerRenderer.php');
$exporter = $read('/后端代码/app/services/query/metric/MetricReadViewExportProvider.php');

$check(strpos($registry, "'staff_project_num' => self::projectCount('personnel_fact_sum'") !== false,
    '工资项目数必须登记为独立人员指标，而不是复用销售数量');
$check(strpos($registry, "'performance_type' => 'labor_performance_allocated'") !== false
    && strpos($registry, 'project_count_decimal') !== false
    && strpos($registry, "'project_count_micro'") !== false,
    '工资项目数必须读取劳动业绩事实中的精确项目数快照');
$check(strpos($dictionary, '用于工资计算，不等同于销售数量。') !== false
    && strpos($dictionary, '销售数量、项目成交件数') !== false,
    '指标字典必须区分工资项目数与销售数量');
$check(strpos($lifecycle, 'whereIn(\'reversal_of\', $forwardFactIds)') !== false
    && strpos($lifecycle, '项目数与金额一样是有符号事实值') !== false
    && strpos($lifecycle, 'negateProjectCountDecimal') !== false,
    '销售单作废必须补齐未冲销业绩事实，并对项目数写入反向值');
$check(strpos($renderer, "'project_count_micro'") !== false
    && strpos($renderer, 'formatProjectCount') !== false
    && strpos($exporter, "'project_count_micro'") !== false,
    '统一查询与导出必须按精确项目数单位展示，而不能把它当金额或整数销售数量');

echo "PASS project-count-fact-registry-contract\n";
