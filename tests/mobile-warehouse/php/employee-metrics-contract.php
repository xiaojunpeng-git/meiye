<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/query/metric/MetricDefinitionRegistry.php';
require_once dirname(__DIR__, 3) . '/后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php';

use app\services\mobile\warehouse\MobileWarehouseServices;
use app\services\query\metric\MetricDefinitionRegistry;

$units = [
    'staff_sales_yeji' => 'fen',
    'staff_labor_yeji' => 'fen',
    'staff_point_visit_num' => 'customer_tenth',
    'staff_service_num' => 'customer_tenth',
    'staff_project_num' => 'project_count_micro',
    'staff_labor_fee' => 'fen',
];
foreach ($units as $code => $unit) {
    if (MetricDefinitionRegistry::get($code)['storage_unit'] !== $unit) {
        throw new RuntimeException("Employee metric unit changed: {$code}");
    }
}

// Split service and project allocations must remain exact text, including
// negative adjustments and values below one, while whole counts omit .0.
$format = new ReflectionMethod(MobileWarehouseServices::class, 'displayScaledCount');
if (PHP_VERSION_ID < 80100) $format->setAccessible(true);
foreach ([
    [15, 1, '1.5'],
    [10, 1, '1'],
    [-5, 1, '-0.5'],
    [333333, 6, '0.333333'],
    [1000000, 6, '1'],
    [0, 6, '0'],
] as [$raw, $places, $expected]) {
    if ($format->invoke(null, $raw, $places) !== $expected) {
        throw new RuntimeException("Employee count display changed: {$raw}/{$places}");
    }
}

// A sale number wins; service-only labor facts must show the completed service
// document rather than the opaque entitlement completion receipt or line ID.
$documentNo = new ReflectionMethod(MobileWarehouseServices::class, 'detailDocumentNo');
if (PHP_VERSION_ID < 80100) $documentNo->setAccessible(true);
$receipt = 'ECR-8737350ec76c11de7395e089e64e5e761e926fe9';
foreach ([
    ['XS2610100234', 'FW26101200001', 'SO26101200001', 'XS2610100234'],
    ['', 'FW26101200001', 'SO26101200001', 'FW26101200001'],
    [$receipt, 'FW26101200001', 'SO26101200001', 'FW26101200001'],
    [$receipt, '', 'SO26101200001', 'SO26101200001'],
    [$receipt, '', $receipt, ''],
] as [$sale, $service, $serviceDocument, $expected]) {
    if ($documentNo->invoke(null, $sale, $service, $serviceDocument) !== $expected) {
        throw new RuntimeException('Employee detail document number precedence changed');
    }
}

echo "mobile warehouse employee metrics and document numbers: PASS\n";
