<?php
declare(strict_types=1);

/** The RH manual repair must plan exactly the PM-approved twelve operations without writing. */
if (getenv('MOHE_R27_CURRENT_POSITION_READONLY') !== '1') {
    throw new RuntimeException('explicit read-only test flag required');
}
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
$script = $backend . '/database/upgrades/2026-10-08-瑞昊服务调整当前岗位补录/01-当前岗位补录.php';
$argv = [$script];
ob_start();
require $script;
$report = json_decode((string)ob_get_clean(), true);
if (!is_array($report) || ($report['database'] ?? null) !== 'ruihao'
    || ($report['mode'] ?? null) !== 'read_only'
    || !preg_match('/^[a-f0-9]{64}$/D', (string)($report['planHash'] ?? ''))
    || count($report['rows'] ?? []) !== 22) {
    throw new RuntimeException('manual repair plan contract mismatch');
}
$operations = [];
$groups = [];
$laborFacts = [];
foreach ($report['rows'] as $row) {
    $operations[(string)$row['operationNo']] = true;
    $groups[(string)$row['group']] = ($groups[(string)$row['group']] ?? 0) + 1;
    $laborFacts[(string)$row['performanceFactId']] = true;
}
if (count($operations) !== 12 || count($laborFacts) !== 22
    || ($groups['independent:67'] ?? 0) !== 12 || ($groups['normal'] ?? 0) !== 10
    || count($groups) !== 2) {
    throw new RuntimeException('manual repair scope or current-position group drift');
}
echo 'PASS 12 operations / 22 unique facts / 12 independent + 10 normal; planHash='
    . $report['planHash'] . PHP_EOL;
