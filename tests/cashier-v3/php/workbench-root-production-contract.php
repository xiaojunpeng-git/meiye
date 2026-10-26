<?php

error_reporting(E_ALL & ~E_DEPRECATED);

$repo = dirname(__DIR__, 3);
require $repo . '/后端代码/vendor/autoload.php';

use app\services\cashier\v3\CashierV3ResourceVersionServices;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\CashierV3StateContextServices;
use app\services\cashier\v3\projection\CashierV3RootDomainAssembler;
use app\services\cashier\v3\projection\CashierV3RootPartitionProvider;
use app\services\cashier\v3\projection\CashierV3RootProjector;
use app\services\cashier\v3\projection\CashierV3RootStateContract;
use app\services\cashier\v3\projection\CashierV3StructuredEmptyRootPartitionModule;
use app\services\cashier\v3\projection\CashierV3StructuredEmptyRootPartitionProvider;

$passed = 0;
$failed = 0;

function rootContractCheck(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo '[PASS] ' . $name . PHP_EOL;
        return;
    }
    $failed++;
    echo '[FAIL] ' . $name . ($detail !== '' ? ': ' . $detail : '') . PHP_EOL;
}

$required = CashierV3RootDomainAssembler::REQUIRED_BUSINESS_PARTITIONS;
$expectedStructured = array_values(array_diff($required, ['cashier', 'orderCenter']));
$actualStructured = CashierV3StructuredEmptyRootPartitionProvider::partitionKeys();
sort($expectedStructured, SORT_STRING);
sort($actualStructured, SORT_STRING);
rootContractCheck(
    'ROOT-PROD-01 structured providers cover only missing production domains',
    $expectedStructured === $actualStructured,
    json_encode(['expected' => $expectedStructured, 'actual' => $actualStructured])
);

$assembler = new CashierV3RootDomainAssembler(
    new CashierV3ResourceVersionServices(new CashierV3ScopeResolver())
);
foreach (['cashier', 'orderCenter'] as $realKey) {
    $assembler->registerPartitionProvider(new class($realKey) implements CashierV3RootPartitionProvider {
        /** @var string */
        private $key;

        public function __construct(string $key)
        {
            $this->key = $key;
        }

        public function partitionKey(): string
        {
            return $this->key;
        }

        public function readPartition(
            string $stateContextId,
            string $stateRevision,
            \app\services\cashier\v3\CashierV3OperatorScope $operatorScope,
            \app\services\cashier\v3\CashierV3DataScopeContext $dataScope,
            array $hints = []
        ): array {
            return ['ready' => true, 'payload' => ['provider' => 'real'], 'public_versions' => []];
        }
    });
}
CashierV3StructuredEmptyRootPartitionModule::install($assembler);
// A second install must be a no-op and must never replace a real provider.
CashierV3StructuredEmptyRootPartitionModule::install($assembler);

$registered = $assembler->registeredPartitionKeys();
$expectedRegistered = $required;
sort($registered, SORT_STRING);
sort($expectedRegistered, SORT_STRING);
rootContractCheck(
    'ROOT-PROD-02 production assembler has every required root partition',
    $registered === $expectedRegistered,
    json_encode(['expected' => $expectedRegistered, 'actual' => $registered])
);
rootContractCheck(
    'ROOT-PROD-03 production assembler reports workbench ready',
    $assembler->isReadyForFullRoot()
);

$stateContexts = (new ReflectionClass(CashierV3StateContextServices::class))
    ->newInstanceWithoutConstructor();
$projector = new CashierV3RootProjector($stateContexts);
$projector->setAssembler($assembler);
rootContractCheck(
    'ROOT-PROD-04 open-cashier-workbench readiness path is true',
    $projector->isReadyForFullRoot()
);

$operatorNameProbe = new class extends CashierV3RootDomainAssembler {
    public function displayName(array $profile): string
    {
        return $this->operatorDisplayName($profile);
    }
};
rootContractCheck(
    'ROOT-PROD-04A blank account falls back to staff display name',
    $operatorNameProbe->displayName([
        'account' => '',
        'staff_name' => 'Store operator',
    ]) === 'Store operator'
);

$extras = [
    'storeName' => 'Test store',
    'currentStore' => ['id' => 8, 'name' => 'Test store'],
    'featurePermissions' => ['cashier.v3.cashier' => true],
    'workspace' => [
        'id' => 'ws:8:1:ctx-root-production',
        'revision' => 1,
        'status' => 'editing',
        'serverTime' => '2026-07-29T00:00:00+08:00',
    ],
    'operator' => ['name' => 'Tester', 'roleName' => 'Cashier'],
    'cashier' => ['provider' => 'real'],
    'orderCenter' => ['provider' => 'real'],
];
foreach (CashierV3StructuredEmptyRootPartitionProvider::partitionKeys() as $key) {
    $extras[$key] = CashierV3StructuredEmptyRootPartitionProvider::payloadFor($key);
}
$root = CashierV3RootStateContract::encodeReady(
    CashierV3RootStateContract::emptyRoot('ctx-root-production', '1', $extras)
);
$rootProblems = CashierV3RootStateContract::validate($root);
rootContractCheck(
    'ROOT-PROD-05 structured empty payloads satisfy the root schema',
    $rootProblems === [],
    implode(',', $rootProblems)
);

$allExplicit = true;
foreach ($actualStructured as $key) {
    $availability = CashierV3StructuredEmptyRootPartitionProvider::availabilityFor($key);
    $allExplicit = $allExplicit
        && in_array($availability['status'] ?? '', ['not_activated', 'on_demand'], true)
        && ($availability['dataLoaded'] ?? null) === false
        && ($availability['businessFactsIncluded'] ?? null) === false;
}
$hangPayload = CashierV3StructuredEmptyRootPartitionProvider::payloadFor('hangOrders');
rootContractCheck(
    'ROOT-PROD-06 empty domains never claim authoritative business facts',
    $allExplicit
        && ($hangPayload['pendingCountAuthoritative'] ?? null) === false
        && CashierV3StructuredEmptyRootPartitionProvider::payloadFor('pendingHangCount') === 0
);

$bootstrapSource = file_get_contents(
    $repo . '/后端代码/app/services/cashier/v3/bootstrap/CashierV3Bootstrap.php'
);
$cashierInstall = strpos($bootstrapSource, 'CashierV3CashierModule::install');
$orderInstall = strpos($bootstrapSource, 'CashierV3OrderQueryModule::install');
$structuredInstall = strpos($bootstrapSource, 'CashierV3StructuredEmptyRootPartitionModule::install');
rootContractCheck(
    'ROOT-PROD-07 bootstrap installs structured empty domains after real domains',
    $cashierInstall !== false
        && $orderInstall !== false
        && $structuredInstall !== false
        && $structuredInstall > $cashierInstall
        && $structuredInstall > $orderInstall
);

$cashierModuleSource = file_get_contents(
    $repo . '/后端代码/app/services/cashier/v3/cashier/CashierV3CashierModule.php'
);
$saleSubmissionSource = file_get_contents(
    $repo . '/后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php'
);
$checkoutExecutionPortSource = file_get_contents(
    $repo . '/后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php'
);
rootContractCheck(
    'ROOT-PROD-08 ordinary product checkout remains a real production handler',
    strpos($cashierModuleSource, "registerCommand('choose-catalog-item'") !== false
        && strpos($cashierModuleSource, "registerCommand('submit-checkout'") !== false
        && strpos($cashierModuleSource, 'CashierV3CheckoutSubmissionOrchestrator') !== false
        && strpos($cashierModuleSource, 'ThinkPhpCashierV3CheckoutSubmissionExecutionPort') !== false
        && strpos($checkoutExecutionPortSource, 'CashierV3SaleOnlyCheckoutSubmissionServices') !== false
        && strpos($checkoutExecutionPortSource, 'submitSaleOnlyInTx') !== false
        && strpos($checkoutExecutionPortSource, '$this->saleOnly->submitInTx($scope)') !== false
        && strpos($saleSubmissionSource, "(int)(\$product['product_type'] ?? -1) !== 0") !== false
        && strpos($saleSubmissionSource, 'CashierV3SaleInventorySettlementServices') !== false
        && strpos($saleSubmissionSource, '$this->saleInventory->planInTx(') !== false
        && strpos($saleSubmissionSource, '$this->saleInventory->persistInTx($inventoryPlan)') !== false
        && strpos($saleSubmissionSource, "(int)(\$product['is_inventory'] ?? 1) !== 0") === false
        && strpos($saleSubmissionSource, 'paymentCollection') !== false
        && strpos($saleSubmissionSource, 'factFingerprint') !== false
);

$dispatcherSource = file_get_contents(
    $repo . '/后端代码/app/services/cashier/v3/CashierV3ActionDispatcher.php'
);
rootContractCheck(
    'ROOT-PROD-09 root projection merges only its defined handler result versions',
    strpos($dispatcherSource, "is_array(\$result['versions'] ?? null)") !== false
        && strpos($dispatcherSource, "is_array(\$outcome['versions'] ?? null)") === false
);

echo sprintf('workbench-root-production-contract: %d passed, %d failed', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
