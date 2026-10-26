<?php
declare(strict_types=1);

// Pure aggregation fixtures: no framework boot or database writes are needed.
require dirname(__DIR__, 3) . '/后端代码/app/services/report/MemberManagementDashboardServices.php';
$service = new app\services\report\MemberManagementDashboardServices();
$add = new ReflectionMethod($service, 'addConsumptionRow');
$sort = new ReflectionMethod($service, 'rankConsumptionGroups');
$add->setAccessible(true);
$sort->setAccessible(true);
$rank = static function (array $rows) use ($service, $add, $sort): array {
    $groups = [];
    // Artificial batch boundaries ensure repeated members are retained once.
    foreach (array_chunk($rows, 2) as $batch) {
        foreach ($batch as $row) $add->invokeArgs($service, [&$groups, $row]);
    }
    return $sort->invoke($service, $groups);
};
$checks = 0;
$assert = static function ($condition, $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$rows = [
    ['item_name' => 'A', 'amount_cents' => 10000, 'member_id' => 1],
    ['item_name' => 'A', 'amount_cents' => 5000, 'member_id' => 1],
    ['item_name' => 'A', 'amount_cents' => 0, 'member_id' => 2],
    ['item_name' => 'A', 'amount_cents' => -1000, 'member_id' => 1],
    ['item_name' => 'Guest', 'amount_cents' => 2000, 'member_id' => 0],
    ['item_name' => 'Zero', 'amount_cents' => 0, 'member_id' => 3],
    ['item_name' => 'Negative', 'amount_cents' => -200, 'member_id' => 4],
    ['item_name' => '', 'amount_cents' => 99000, 'member_id' => 5],
];
$actual = $rank($rows);
$byName = array_column($actual, null, 'name');
$assert(count($actual) === 4, 'Only named projects rank');
$assert($byName['A']['consumption_cents'] === 14000, 'Signed amount conserved');
$assert($byName['A']['people'] === 2, 'Repeated service dedup; zero amount member counted');
$assert($byName['A']['average_cents'] === 7000, 'Average uses served members');
$assert($byName['Guest']['people'] === 0 && $byName['Guest']['average_cents'] === null, 'Guests do not invent member identity');
$assert($byName['Zero']['people'] === 1 && $byName['Zero']['average_cents'] === 0, 'Zero service remains zero');
$assert($byName['Negative']['consumption_cents'] === -200, 'Negative performance remains signed');
$assert(!isset($byName['A']['cash_income_cents']), 'Service rank is not a cash alias');
$assert($rank([]) === [], 'Empty remains empty');
$ties = array_map(static fn($name) => ['item_name' => $name, 'amount_cents' => 100, 'member_id' => 1], ['G','F','E','D','C','B','A']);
$assert(array_column($rank($ties), 'name') === ['A','B','C','D','E'], 'Stable ties and top five');
echo "PASS {$checks} R94 aggregation checks\n";
