<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';

use app\services\product\inventory\query\InventoryOperationalUnifiedQueryContract;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryRegistrar;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryWorkerContextResolver;
use app\services\product\inventory\query\InventoryStatisticsUnifiedQueryContract;
use app\services\product\inventory\query\InventoryStatisticsUnifiedQueryRegistrar;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryWorkerContextResolverRegistry;

function operationalUqAssert(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS {$label}\n");
}

$registry = UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryOperationalUnifiedQueryRegistrar()]);
$expected = InventoryOperationalUnifiedQueryContract::PAGE_CODES;
operationalUqAssert('operational inventory UQ registers one isolated page code per document domain', $registry->pageCodes() === $expected);

foreach ($expected as $pageCode) {
    $page = $registry->page($pageCode);
    // Outbound jobs additionally freeze account namespace; other document pages keep their existing scope.
    $dimensions = $pageCode === 'inventory_outbound' ? ['location_id', 'inventory_actor_type'] : ['location_id'];
    operationalUqAssert("{$pageCode} has a stable row key and server scope dimension", $page['stableRowKey'] === 'record_id' && $page['scopeDimensions'] === $dimensions);
    operationalUqAssert("{$pageCode} uses the existing inventory view feature", $page['requiredFeature'] === 'inventory.batch.view');
}

$source = file_get_contents(dirname(__DIR__, 3) . '/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php');
$requestDefinition = InventoryOperationalUnifiedQueryContract::definition('inventory_request');
$transferField = $registry->page('inventory_transfer')['fields']['can_receive'] ?? [];
operationalUqAssert('store transfer unreceived shortcut filters only by server-projected receive permission',
    ($transferField['type'] ?? '') === 'boolean'
    && ($transferField['allowedOperations'] ?? []) === ['filter']
    && ($transferField['defaultVisible'] ?? true) === false
    && strpos($source, "\$row['can_receive'] = (string)\$row['document_status'] === 'DISPATCHED' && \$toCurrentStore;") !== false
);
operationalUqAssert('operational provider keeps a bounded full source window instead of reusing a 100-row list page',
    strpos($source, 'UnifiedQueryExecutionServices::MAX_SOURCE_ROWS + 1') !== false
    && strpos($source, 'list($storeId, $operatorId') === false
);
operationalUqAssert('only outbound enables document export with its typed worker context',
    InventoryOperationalUnifiedQueryContract::definition('inventory_inbound')['exportFeature'] === 'inventory.operation.export'
    && InventoryOperationalUnifiedQueryContract::definition('inventory_outbound')['exportFeature'] === 'inventory.batch.export'
);
operationalUqAssert('Excel import records are an isolated operational projection, not a local browser filter',
    in_array('inventory_import', InventoryOperationalUnifiedQueryContract::PAGE_CODES, true)
    && isset($registry->page('inventory_import')['fields']['source_file_name'])
    && strpos($source, "case 'inventory_import':") !== false
    && strpos($source, 'inventory_v3_import_record') !== false
);
operationalUqAssert('request unified-query keyword uses the authoritative request number and keeps the display alias',
    in_array('request_no', $requestDefinition['keywordFields'], true)
    && strpos($source, 'd.id,d.request_no,d.request_no order_sn') !== false
);

$statisticsRegistry = UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryStatisticsUnifiedQueryRegistrar()]);
operationalUqAssert('statistics UQ registers the four existing fact projections only', $statisticsRegistry->pageCodes() === InventoryStatisticsUnifiedQueryContract::PAGE_CODES);
foreach (InventoryStatisticsUnifiedQueryContract::PAGE_CODES as $pageCode) {
    $page = $statisticsRegistry->page($pageCode);
    operationalUqAssert("{$pageCode} has an isolated statistics query scope", $page['stableRowKey'] === 'record_id' && $page['scopeDimensions'] === ['location_id']);
}

$workerRegistry = UnifiedQueryPageRegistry::fromRegistrars([], [
    new InventoryOperationalUnifiedQueryRegistrar(),
    new InventoryStatisticsUnifiedQueryRegistrar(),
]);
$workerResolvers = new UnifiedQueryWorkerContextResolverRegistry($workerRegistry);
$workerResolvers->register(new InventoryOperationalUnifiedQueryWorkerContextResolver());
$workerResolvers->freeze();
operationalUqAssert('operational and statistics inventory pages all register a worker context resolver',
    $workerResolvers->isFrozen()
    && $workerResolvers->pageCodes() === array_merge(
        InventoryOperationalUnifiedQueryContract::PAGE_CODES,
        InventoryStatisticsUnifiedQueryContract::PAGE_CODES
    ));
