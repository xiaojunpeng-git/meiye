<?php
// Verify the source ID produces a Gregorian birthday, while age and tenure are date projections.
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';
require dirname(__DIR__, 3) . '/后端代码/app/helpers.php';

$service = (new ReflectionClass(\app\services\employee\EmployeePersonCompleteWriteServices::class))
    ->newInstanceWithoutConstructor();
$birth = new ReflectionMethod($service, 'birthFromCard');
$birth->setAccessible(true);
$metrics = new ReflectionMethod($service, 'profileDateMetrics');
$metrics->setAccessible(true);

if ($birth->invoke($service, '340702199312171029') !== '1993-12-17') {
    throw new RuntimeException('Valid ID birthday was not derived');
}
if ($birth->invoke($service, '340702199312171020') !== '') {
    throw new RuntimeException('Invalid ID checksum was accepted');
}
$result = $metrics->invoke($service, '1993-12-17', '2013-10-26');
if ($result['age'] === null || $result['tenure_text'] === '') {
    throw new RuntimeException('Age or tenure missing');
}
$empty = $metrics->invoke($service, null, null);
if ($empty['age'] !== null || $empty['tenure_text'] !== '') {
    throw new RuntimeException('Missing dates generated metrics');
}
echo "PASS valid ID, invalid ID, age, tenure and empty dates\n";
