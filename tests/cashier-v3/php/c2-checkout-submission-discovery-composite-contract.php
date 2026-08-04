<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$backend = $root . '/后端代码/app/services/cashier/v3';
$balanceDiscoverySource = (string)file_get_contents(
    $backend . '/settlement/CashierV3CheckoutBalanceAuthorityDiscovery.php'
);
require_once $backend . '/CashierV3ResultCode.php';
require_once $backend . '/CashierV3CommandException.php';
require_once $backend . '/CashierV3ResourceScope.php';
require_once $backend . '/CashierV3ResourceKindCatalog.php';
require_once $backend . '/settlement/CashierV3CheckoutSubmissionResourceDiscoveryComposite.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\settlement\CashierV3CheckoutSubmissionResourceDiscoveryComposite;

$passed = 0;
$failed = 0;

function csdcOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n";
}

function csdcResource(
    string $kind,
    string $id,
    int $version,
    array $roles,
    string $provider,
    string $fingerprintSeed,
    string $accessMode = 'read'
): array {
    return [
        'kind' => $kind,
        'id' => $id,
        'expectedVersion' => $version,
        'roles' => $roles,
        'accessMode' => $accessMode,
        'providerContractVersion' => $provider,
        'authorityFingerprint' => hash('sha256', $fingerprintSeed),
    ];
}

function csdcPack(string $version, array $resources): array
{
    return ['contractVersion' => $version, 'resources' => $resources];
}

function csdcFind(array $resources, string $kind, string $id): ?array
{
    foreach ($resources as $resource) {
        if ($resource['kind'] === $kind && $resource['id'] === $id) {
            return $resource;
        }
    }
    return null;
}

csdcOk(
    'C2-CSDC-00 balance discovery supports preparation replay without accepting terminal states',
    strpos(
        $balanceDiscoverySource,
        "->whereIn('request_status', ['editing', 'ready_for_submit'])"
    ) !== false
        && strpos($balanceDiscoverySource, "->where('request_status', 'editing')") === false
);

$sale = csdcPack('cashier-v3-checkout-preparation-discovery-v1', [
    csdcResource('catalog_sku', '90', 9, ['catalog_sku:90'], 'cashier-sale-catalog-resource-v1', 'sale-sku-90'),
    csdcResource('member_benefit_pool', '501', 12, ['checkout_entitlement_pool:501'], 'cashier-entitlement-projection-resource-v1', 'legacy-pool-501'),
    csdcResource('catalog_product', '11', 7, ['catalog_product:11'], 'cashier-sale-catalog-resource-v1', 'sale-product-11'),
    csdcResource('member', '1001', 4, ['checkout_member'], 'cashier-entitlement-projection-resource-v1', 'legacy-member-1001'),
    csdcResource('card_holder', '2001', 8, ['checkout_card_holder:2001'], 'cashier-entitlement-projection-resource-v1', 'legacy-holder-2001'),
    csdcResource('catalog_product', '11', 7, ['sale_line:2'], 'cashier-sale-catalog-resource-v1', 'sale-product-11'),
]);
$authorityPoolFingerprint = hash('sha256', 'authority-pool-501');
$authority = csdcPack('cashier-v3-entitlement-completion-discovery-v1', [
    csdcResource('performance_rule', '7001', 3, ['performance_rule:line-1'], 'cashier-v3-performance-rule-provider-v1', 'performance-7001'),
    csdcResource('card_holder', '2001', 8, ['card_holder:2001'], 'cashier-v3-entitlement-completion-authority-v1', 'authority-holder-2001'),
    csdcResource('member_benefit_pool', '501', 12, ['benefit_pool:501'], 'cashier-v3-entitlement-completion-authority-v1', 'authority-pool-501', 'mutate'),
    csdcResource('member', '1001', 4, ['member'], 'cashier-v3-entitlement-completion-authority-v1', 'authority-member-1001'),
    csdcResource('inventory_stock', '300', 6, ['inventory_stock:line-1'], 'inventory-entitlement-completion-provider-v1', 'inventory-stock-300'),
]);
$scope = ['action' => 'prepare-checkout-submission', 'marker' => 'same-scope'];
$saleScopes = [];
$authorityScopes = [];
$composite = new CashierV3CheckoutSubmissionResourceDiscoveryComposite(
    static function (array $input) use (&$saleScopes, $sale): array {
        $saleScopes[] = $input;
        return $sale;
    },
    static function (array $input) use (&$authorityScopes, $authority): array {
        $authorityScopes[] = $input;
        return $authority;
    }
);
$result = $composite->discover($scope);
$resources = $result['resources'];

csdcOk('C2-CSDC-01 both discoverers receive the identical server scope',
    $saleScopes === [$scope] && $authorityScopes === [$scope]);
csdcOk('C2-CSDC-02 mixed discovery de-duplicates by physical kind and id',
    count($resources) === 7
    && count(array_unique(array_map(static function (array $row): string {
        return $row['kind'] . "\0" . $row['id'];
    }, $resources))) === 7);
csdcOk('C2-CSDC-03 sale catalog resources survive the composite unchanged',
    csdcFind($resources, 'catalog_product', '11') === [
        'kind' => 'catalog_product',
        'id' => '11',
        'expectedVersion' => 7,
        'roles' => ['catalog_product:11', 'sale_line:2'],
        'accessMode' => 'read',
        'providerContractVersion' => 'cashier-sale-catalog-resource-v1',
        'authorityFingerprint' => hash('sha256', 'sale-product-11'),
    ]
    && csdcFind($resources, 'catalog_sku', '90') !== null);
$pool = csdcFind($resources, 'member_benefit_pool', '501');
csdcOk('C2-CSDC-04 overlapping entitlement resource keeps authority provider metadata',
    $pool !== null
    && $pool['providerContractVersion'] === 'cashier-v3-entitlement-completion-authority-v1'
    && $pool['authorityFingerprint'] === $authorityPoolFingerprint
    && $pool['accessMode'] === 'mutate');
csdcOk('C2-CSDC-05 overlap merges de-duplicates and sorts every role',
    $pool['roles'] === ['benefit_pool:501', 'checkout_entitlement_pool:501']
    && csdcFind($resources, 'member', '1001')['roles'] === ['checkout_member', 'member']
    && csdcFind($resources, 'card_holder', '2001')['roles'] === [
        'card_holder:2001', 'checkout_card_holder:2001',
    ]);
csdcOk('C2-CSDC-06 canonical resource catalog order is used',
    array_map(static function (array $row): string {
        return $row['kind'] . ':' . $row['id'];
    }, $resources) === [
        'member:1001',
        'member_benefit_pool:501',
        'card_holder:2001',
        'catalog_product:11',
        'catalog_sku:90',
        'performance_rule:7001',
        'inventory_stock:300',
    ], json_encode(array_column($resources, 'kind')));
csdcOk('C2-CSDC-07 output uses the frozen composite pack and exact resource DTO',
    $result['contractVersion'] === CashierV3CheckoutSubmissionResourceDiscoveryComposite::CONTRACT_VERSION
    && array_keys($pool) === [
        'kind', 'id', 'expectedVersion', 'roles', 'accessMode',
        'providerContractVersion', 'authorityFingerprint',
    ]);

$versionConflict = new CashierV3CheckoutSubmissionResourceDiscoveryComposite(
    static function () use ($sale): array { return $sale; },
    static function () use ($authority): array {
        $changed = $authority;
        foreach ($changed['resources'] as &$resource) {
            if ($resource['kind'] === 'member_benefit_pool' && $resource['id'] === '501') {
                $resource['expectedVersion'] = 13;
            }
        }
        unset($resource);
        return $changed;
    }
);
$versionReason = '';
$versionCode = '';
try {
    $versionConflict->discover($scope);
} catch (CashierV3CommandException $exception) {
    $versionReason = (string)($exception->getDetail()['reason'] ?? '');
    $versionCode = $exception->getResultCode();
}
csdcOk('C2-CSDC-08 same physical resource with different version fails closed',
    $versionCode === CashierV3ResultCode::RESOURCE_VERSION_CONFLICT
    && $versionReason === 'checkout_submission_discovery_version_conflict');

$sameOriginProviderConflict = new CashierV3CheckoutSubmissionResourceDiscoveryComposite(
    static function () use ($sale): array {
        $changed = $sale;
        $changed['resources'][] = csdcResource(
            'catalog_product', '11', 7, ['sale_line:3'],
            'different-sale-provider-v1', 'different-sale-product-11'
        );
        return $changed;
    },
    static function (): array { return csdcPack('authority-v1', []); }
);
$providerReason = '';
try {
    $sameOriginProviderConflict->discover($scope);
} catch (CashierV3CommandException $exception) {
    $providerReason = (string)($exception->getDetail()['reason'] ?? '');
}
csdcOk('C2-CSDC-09 contradictory metadata inside one provider fails closed',
    $providerReason === 'checkout_submission_discovery_provider_conflict');

$pureSale = new CashierV3CheckoutSubmissionResourceDiscoveryComposite(
    static function (): array {
        return csdcPack('sale-v1', [
            csdcResource('catalog_product', '2', 2, ['sale:2'], 'sale-provider-v1', 'sale-2'),
        ]);
    },
    static function (): array { return csdcPack('authority-v1', []); }
);
csdcOk('C2-CSDC-10 sale-only checkout is preserved when authority discovery is empty',
    $pureSale->discover($scope)['resources'][0]['kind'] === 'catalog_product');

$balanceScopes = [];
$withBalance = new CashierV3CheckoutSubmissionResourceDiscoveryComposite(
    static function (): array {
        return csdcPack('sale-v1', [
            csdcResource('catalog_product', '2', 2, ['sale:2'], 'sale-provider-v1', 'sale-2'),
        ]);
    },
    static function (): array { return csdcPack('authority-v1', []); },
    static function (array $input) use (&$balanceScopes): array {
        $balanceScopes[] = $input;
        return csdcPack('member-balance-v1', [
            csdcResource(
                'member_balance',
                '1001',
                6,
                ['checkout_member_balance'],
                'cashier-v3-member-balance-authority-v1',
                'member-balance-1001',
                'mutate'
            ),
        ]);
    }
);
$balanceResources = $withBalance->discover($scope)['resources'];
csdcOk('C2-CSDC-11 optional balance authority joins the same server scope and lock order',
    $balanceScopes === [$scope]
    && array_map(static function (array $row): string {
        return $row['kind'] . ':' . $row['id'];
    }, $balanceResources) === ['member_balance:1001', 'catalog_product:2']
    && csdcFind($balanceResources, 'member_balance', '1001')['accessMode'] === 'mutate');

$source = (string)file_get_contents(
    $backend . '/settlement/CashierV3CheckoutSubmissionResourceDiscoveryComposite.php'
);
csdcOk('C2-CSDC-12 composite remains read-only and does not register production wiring',
    strpos($source, 'Db::') === false
    && strpos($source, 'CashierV3CashierModule') === false
    && strpos($source, 'register') === false
    && strpos($source, 'ActionManifest') === false);

echo "C2_CHECKOUT_SUBMISSION_DISCOVERY_COMPOSITE passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
