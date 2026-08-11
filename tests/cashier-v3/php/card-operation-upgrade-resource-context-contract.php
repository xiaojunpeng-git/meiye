<?php

$root = dirname(__DIR__, 3);
$files = [
    'catalog' => $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php',
    'workspace' => $root . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php',
    'preparation' => $root . '/后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php',
    'settlement' => $root . '/后端代码/app/services/cashier/v3/card/CashierV3CardOperationCheckoutSettlementServices.php',
    'frontend' => $root . '/前端代码/cashier-v3/src/views/CashierWorkbenchView.vue',
];

$failed = 0;
function upgradeResourceContextContract(bool $ok, string $name): void
{
    global $failed;
    if (!$ok) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name}\n");
        return;
    }
    echo "PASS: {$name}\n";
}

$source = [];
foreach ($files as $key => $file) {
    $source[$key] = is_file($file) ? (string)file_get_contents($file) : '';
}

// Regression fixture: the persisted operation selected 42580, while an older
// entitlement-selector projection still mentions 42632. The protected upgrade
// binding is the only authority allowed to contribute source benefit pools.
$binding = [
    'operationId' => 'COP-' . str_repeat('A', 40),
    'operationType' => 'project_upgrade',
    'projectMutations' => [[
        'sourceDetailId' => 42580,
        'sourceDetailVersion' => 7,
    ]],
    'sourceResources' => [[
        'kind' => 'member_benefit_pool',
        'id' => '42580',
        'version' => 7,
    ]],
];
$staleSelectorContexts = [[
    'kind' => 'member_benefit_pool',
    'id' => '42632',
    'expectedVersion' => 3,
]];
$sourceIds = array_values(array_map(
    static function (array $resource): string {
        return (string)$resource['id'];
    },
    array_values(array_filter(
        $binding['sourceResources'],
        static function (array $resource): bool {
            return ($resource['kind'] ?? '') === 'member_benefit_pool';
        }
    ))
));

upgradeResourceContextContract($sourceIds === ['42580'],
    'protected project-upgrade binding keeps the persisted source detail');
upgradeResourceContextContract(!in_array($staleSelectorContexts[0]['id'], $sourceIds, true),
    'stale entitlement-selector detail cannot replace the persisted source detail');

upgradeResourceContextContract(
    strpos($source['catalog'], "'projectMutations' => \$projectMutations") !== false
    && strpos($source['catalog'], "'sourceResources' => \$extraResources") !== false,
    'upgrade sale binding freezes project mutations and source resources together'
);
upgradeResourceContextContract(
    strpos($source['catalog'], "\$sourceResources = is_array(\$binding['sourceResources'] ?? null)") !== false
    && strpos($source['catalog'], "\$snapshot['cardOperationUpgrade'] = \$binding") !== false,
    'checkout rebuild restores source resources from the protected upgrade binding'
);
upgradeResourceContextContract(
    strpos($source['workspace'], "\$line['cardOperationUpgrade'] = \$cardOperationUpgrade") !== false,
    'workspace exposes the immutable upgrade binding on the protected sale line'
);
upgradeResourceContextContract(
    strpos($source['frontend'], "new Set(['cashier_workspace', 'checkout_request'])") !== false
    && strpos($source['frontend'], 'checkoutSubmissionCommandContexts(current.commandContexts)') !== false,
    'final checkout preparation does not forward selector entitlement contexts'
);
upgradeResourceContextContract(
    strpos($source['preparation'], 'discoverStoredLineResources') !== false,
    'checkout resource discovery is rebuilt from persisted checkout rows'
);
upgradeResourceContextContract(
    strpos($source['settlement'], "->where('line_role', 'source_project')") !== false
    && strpos($source['settlement'], "->where('operation_id', \$operationId)") !== false,
    'project-upgrade settlement resolves exact source lines by operation identity'
);

exit($failed === 0 ? 0 : 1);
