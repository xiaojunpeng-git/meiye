<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$backend = $root . '/后端代码/app/services/cashier/v3';
$paths = [
    'module' => $backend . '/cashier/CashierV3CashierModule.php',
    'composite' => $backend . '/settlement/CashierV3CheckoutSubmissionResourceDiscoveryComposite.php',
    'orchestrator' => $backend . '/settlement/CashierV3CheckoutSubmissionOrchestrator.php',
    'portInterface' => $backend . '/settlement/CashierV3CheckoutSubmissionExecutionPort.php',
    'productionPort' => $backend . '/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php',
    'inventoryProvider' => $backend . '/checkout/provider/CashierV3InventoryResourceVersionProvider.php',
    'performanceRuleProvider' => $backend . '/checkout/provider/CashierV3PerformanceRuleProvider.php',
];

if (in_array('--self-check', $argv, true)) {
    $self = (string)file_get_contents(__FILE__);
    $required = [
        'C2-PW-01', 'C2-PW-02', 'C2-PW-03', 'C2-PW-04', 'C2-PW-05',
        'C2-PW-06', 'C2-PW-07', 'C2-PW-08', 'C2-PW-09', 'C2-PW-10',
        'C2-PW-11', 'C2-PW-12', 'C2-PW-13', 'C2-PW-14', 'C2-PW-15', 'C2-PW-16',
        'CashierV3CheckoutSubmissionOrchestrator',
        'ThinkPhpCashierV3CheckoutSubmissionExecutionPort',
        'CashierV3CheckoutSubmissionResourceDiscoveryComposite',
        'CashierV3StaffProfileProvider',
        'CashierV3PerformanceRuleProvider',
        'CashierV3EntitlementDebtGuardProvider',
        'CashierV3EntitlementOccupationGuardVersionProvider',
        'CashierV3EntitlementOccupationContributorVersionProvider',
        'CashierV3InventoryResourceVersionProvider',
        "['cashier_workspace', 'checkout_request']",
    ];
    $missing = [];
    foreach ($required as $token) {
        if (substr_count($self, $token) < 2) {
            $missing[] = $token;
        }
    }
    if ($missing) {
        fwrite(STDERR, 'C2_CHECKOUT_PRODUCTION_WIRING_CONTRACT_SELF_CHECK=FAIL missing='
            . json_encode($missing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    echo "C2_CHECKOUT_PRODUCTION_WIRING_CONTRACT_SELF_CHECK=PASS\n";
    exit(0);
}

$passed = 0;
$failed = 0;

function cpwCheck(string $id, string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$id} {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$id} {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function cpwRead(string $path): string
{
    return is_file($path) ? (string)file_get_contents($path) : '';
}

function cpwBlock(string $source, string $start, string $end): string
{
    $offset = strpos($source, $start);
    if ($offset === false) {
        return '';
    }
    $limit = strpos($source, $end, $offset + strlen($start));
    return $limit === false ? substr($source, $offset) : substr($source, $offset, $limit - $offset);
}

function cpwHasAll(string $source, array $needles): bool
{
    foreach ($needles as $needle) {
        if (strpos($source, $needle) === false) {
            return false;
        }
    }
    return true;
}

function cpwConstructedVariable(string $source, string $class): string
{
    $pattern = '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*new\s+'
        . preg_quote($class, '/') . '\s*\(/s';
    return preg_match($pattern, $source, $matches) === 1 ? (string)$matches[1] : '';
}

$missingFiles = array_keys(array_filter($paths, static function (string $path): bool {
    return !is_file($path);
}));
$module = cpwRead($paths['module']);
$composite = cpwRead($paths['composite']);
$orchestrator = cpwRead($paths['orchestrator']);
$portInterface = cpwRead($paths['portInterface']);
$productionPort = cpwRead($paths['productionPort']);
$inventoryProvider = cpwRead($paths['inventoryProvider']);
$performanceRuleProvider = cpwRead($paths['performanceRuleProvider']);

cpwCheck('C2-PW-01', 'all final wiring dependencies exist', !$missingFiles,
    'missing=' . implode(',', $missingFiles));
cpwCheck('C2-PW-02', 'orchestrator and production execution port keep their frozen roles',
    cpwHasAll($orchestrator, [
        'final class CashierV3CheckoutSubmissionOrchestrator',
        'CashierV3CheckoutSubmissionExecutionPort $port',
        'public function submitInTx(array $scope): array',
    ])
    && cpwHasAll($portInterface, [
        'interface CashierV3CheckoutSubmissionExecutionPort',
        'lockSubmissionAuthorityInTx',
        'persistEntitlementCompletionInTx',
        'completeWorkspaceInTx',
    ])
    && cpwHasAll($productionPort, [
        'final class ThinkPhpCashierV3CheckoutSubmissionExecutionPort',
        'implements CashierV3CheckoutSubmissionExecutionPort',
    ]));

cpwCheck('C2-PW-03', 'module imports the orchestrator and production execution port',
    cpwHasAll($module, [
        'use app\\services\\cashier\\v3\\settlement\\CashierV3CheckoutSubmissionOrchestrator;',
        'use app\\services\\cashier\\v3\\settlement\\ThinkPhpCashierV3CheckoutSubmissionExecutionPort;',
    ]));
$executionPortVariable = cpwConstructedVariable(
    $module,
    'ThinkPhpCashierV3CheckoutSubmissionExecutionPort'
);
$submissionVariable = cpwConstructedVariable($module, 'CashierV3CheckoutSubmissionOrchestrator');
cpwCheck('C2-PW-04', 'submit handler is constructed from orchestrator plus production port',
    $executionPortVariable !== ''
    && $submissionVariable !== ''
    && preg_match(
        '/\$' . preg_quote($submissionVariable, '/')
            . '\s*=\s*new\s+CashierV3CheckoutSubmissionOrchestrator\s*\(\s*\$'
            . preg_quote($executionPortVariable, '/') . '\s*\)/s',
        $module
    ) === 1);
cpwCheck('C2-PW-05', 'sale-only service is not installed directly as submit handler',
    strpos($module, '$checkoutSubmission = new CashierV3SaleOnlyCheckoutSubmissionServices(') === false
    && $submissionVariable !== ''
    && strpos(
        $module,
        "registerCommand('submit-checkout', function (array \$scope) use (\$"
            . $submissionVariable
    ) !== false);

$discoveryVariable = cpwConstructedVariable(
    $module,
    'CashierV3CheckoutSubmissionResourceDiscoveryComposite'
);
cpwCheck('C2-PW-06', 'final snapshot submit keeps discovery without legacy preparation policy',
    cpwHasAll($composite, [
        'final class CashierV3CheckoutSubmissionResourceDiscoveryComposite',
        'public function discover(array $scope): array',
    ])
    && cpwHasAll($module, [
        'use app\\services\\cashier\\v3\\settlement\\CashierV3CheckoutSubmissionResourceDiscoveryComposite;',
        'new CashierV3CheckoutSubmissionResourceDiscoveryComposite(',
    ])
    && $discoveryVariable !== ''
    && strpos($module, "'direct_snapshot_submission'") !== false
    && strpos($module, 'registerSubmissionPreparationPolicy') === false);

$singleKindProviders = [
    'CashierV3StaffProfileProvider' => 'staff_profile',
    'CashierV3PerformanceRuleProvider' => 'performance_rule',
    'CashierV3EntitlementDebtGuardProvider' => 'entitlement_debt_guard',
    'CashierV3EntitlementOccupationGuardVersionProvider' => 'entitlement_occupation_guard',
];
$singleProvidersWired = true;
foreach ($singleKindProviders as $class => $kind) {
    $variable = cpwConstructedVariable($module, $class);
    $singleProvidersWired = $singleProvidersWired
        && $variable !== ''
        && preg_match(
            '/registerProvider\(\s*' . preg_quote($class, '/')
                . '::KIND\s*,\s*\$' . preg_quote($variable, '/') . '\s*\)/s',
            $module
        ) === 1;
}
cpwCheck('C2-PW-07', 'staff performance debt and occupation guard providers are registered',
    $singleProvidersWired);

$occupationContributorVariable = cpwConstructedVariable(
    $module,
    'CashierV3EntitlementOccupationContributorVersionProvider'
);
cpwCheck('C2-PW-08', 'service-order and reservation share the contributor version provider',
    $occupationContributorVariable !== ''
    && strpos($module, 'CashierV3EntitlementOccupationContributorVersionProvider::KINDS as $kind') !== false
    && strpos(
        $module,
        '$versionServices->registerProvider($kind, $' . $occupationContributorVariable . ')'
    ) !== false);

$inventoryKinds = [
    'inventory_policy',
    'inventory_recipe',
    'inventory_stock',
    'inventory_batch',
    'inventory_shortage_cursor',
];
$allInventoryKinds = true;
foreach ($inventoryKinds as $kind) {
    $allInventoryKinds = $allInventoryKinds
        && strpos($inventoryProvider, "'{$kind}'") !== false;
}
$inventoryVariable = cpwConstructedVariable($module, 'CashierV3InventoryResourceVersionProvider');
cpwCheck('C2-PW-09', 'all five inventory resource kinds use the inventory version provider',
    $allInventoryKinds
    && $inventoryVariable !== ''
    && strpos($module, 'CashierV3InventoryResourceVersionProvider::KINDS as $kind') !== false
    && strpos(
        $module,
        '$versionServices->registerProvider($kind, $' . $inventoryVariable . ')'
    ) !== false);

cpwCheck('C2-PW-15', 'cross-store entitlement completion retains the execution-store scope without rejecting the source-store project',
    cpwHasAll($performanceRuleProvider, [
        'CashierV3EntitlementProviderDataScope::assertStore(',
        'private function assertProject(int $projectId): void',
        '$this->assertProject($projectId);',
    ])
    && strpos($performanceRuleProvider, "relation_id'] !== \$storeId") === false);

$submitBlock = cpwBlock(
    $module,
    "registerCommand('submit-checkout'",
    "if (\$handlers->hasProjection('query-checkout-result'))"
);
$prepareSubmissionBlock = cpwBlock(
    $module,
    "registerCommand('prepare-checkout-submission'",
    "if (\$handlers->hasCommand('submit-checkout'))"
);
cpwCheck('C2-PW-10', 'submit handler delegates only through the final orchestrator',
    $submitBlock !== ''
    && $submissionVariable !== ''
    && (strpos($submitBlock, '$' . $submissionVariable . '->submitInTx($scope)') !== false
        || strpos($submitBlock, '$' . $submissionVariable . '->submitInTx($submitScope)') !== false)
    && strpos($submitBlock, 'CashierV3SaleOnlyCheckoutSubmissionServices') === false);
cpwCheck('C2-PW-11', 'business number branches by composition to order number or receipt id',
    cpwHasAll($submitBlock, [
        '$submitted[\'composition\']',
        '$submitted[\'salesOrder\']',
        "['orderNo']",
        '$submitted[\'entitlementCompletion\']',
        "['receiptId']",
        "'business_no'",
    ]));
cpwCheck('C2-PW-12', 'submit response explicitly preserves nullable sales and payment slices',
    strpos($submitBlock, "'checkoutSubmission' => \$submitted") !== false
    && strpos($orchestrator, "'salesOrder' => \$salesOrder ?: null") !== false
    && strpos($orchestrator, "'paymentCollection' => \$paymentCollection ?: null") !== false);

$touchedBaseLiteral = "\$touched = ['cashier_workspace', 'checkout_request'];";
$touchedBalanceBranch = "foreach ((array)(\$scope['contexts'] ?? []) as \$context) {\n                if (in_array('checkout_member_balance', (array)(\$context['roles'] ?? []), true)) {\n                    \$touched[] = 'checkout_member_balance';\n                    break;\n                }\n            }";
cpwCheck('C2-PW-13', 'submit keeps the technical base touched list and conditionally reports the authoritative balance resource',
    substr_count($submitBlock, $touchedBaseLiteral) === 1
    && strpos($submitBlock, $touchedBalanceBranch) !== false);
$forbiddenTouched = [
    'member_benefit_pool', 'card_holder', 'performance_rule',
    'entitlement_occupation_guard', 'inventory_stock', 'inventory_batch',
    'service_order', 'reservation', 'sales_order',
];
$touchedOnlyTechnical = true;
$touchedOffset = strpos($submitBlock, "'touched' =>");
if ($touchedOffset === false) {
    $touchedOnlyTechnical = false;
} else {
    $touchedTail = substr($submitBlock, $touchedOffset, 240);
    foreach ($forbiddenTouched as $kind) {
        $touchedOnlyTechnical = $touchedOnlyTechnical && strpos($touchedTail, "'{$kind}'") === false;
    }
}
cpwCheck('C2-PW-14', 'domain resources are mutated by their owners and never Gateway-bumped',
    $touchedOnlyTechnical);
cpwCheck('C2-PW-16', 'legacy submission preparation policy is removed',
    strpos($module, "'prepare-checkout-submission'") === false);

echo "C2_CHECKOUT_PRODUCTION_WIRING_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
