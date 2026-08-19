<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$backend = $root . '/后端代码';
require_once $backend . '/vendor/autoload.php';

use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3RequestNormalizer;

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
};

$payload = [
    'preparationRequestId' => 'CHECKOUT_PREPARE-00000000-0000-4000-8000-000000000001',
    'checkoutSnapshot' => [
        'customerMode' => 'member',
        'memberId' => 7,
        'customerSource' => ['id' => 12, 'name' => '老客转介绍'],
        'coupon' => ['id' => 44, 'amountCents' => 300],
        'salesManagerSelections' => [['id' => 8, 'name' => '经理甲']],
        'lines' => [
            ['id' => 'local-sale-1', 'lineRole' => 'sale', 'itemId' => 101, 'quantity' => 2, 'salespeople' => [], 'inventoryOutboundRequired' => 0, 'isPresale' => 1],
            [
                'id' => 'local-entitlement-1', 'lineRole' => 'entitlement_service',
                'entitlementInstanceId' => 201, 'entitlementSourceDetailId' => 202,
                'entitlementSourceVersion' => 3, 'projectId' => 203,
                'projectVersion' => 4, 'quantity' => 1, 'craftsmen' => [],
            ],
        ],
    ],
];
$normalized = CashierV3RequestNormalizer::normalize('prepare-checkout', $payload)['normalized'];
$snapshot = $normalized['checkoutSnapshot'] ?? [];
$check('snapshot member binding is normalized', ($snapshot['customerMode'] ?? '') === 'member' && ($snapshot['memberId'] ?? 0) === 7);
$check('snapshot lines retain canonical role identity and quantity',
    ($snapshot['lines'][0]['lineId'] ?? '') === 'local-sale-1'
    && ($snapshot['lines'][0]['itemId'] ?? 0) === 101
    && ($snapshot['lines'][0]['quantity'] ?? 0) === 2
    && ($snapshot['lines'][1]['lineRole'] ?? '') === 'entitlement_service'
    && ($snapshot['lines'][1]['entitlementSourceDetailId'] ?? 0) === 202);
$check('snapshot retains non-authoritative interaction metadata for final persistence',
    ($snapshot['customerSource']['id'] ?? 0) === 12
    && ($snapshot['coupon']['id'] ?? 0) === 44
    && ($snapshot['salesManagerSelections'][0]['id'] ?? 0) === 8
    && ($snapshot['lines'][0]['inventoryOutboundRequired'] ?? null) === 0
    && ($snapshot['lines'][0]['isPresale'] ?? null) === 1);

$duplicate = $payload;
$duplicate['checkoutSnapshot']['lines'][1]['id'] = 'local-sale-1';
$rejected = false;
try {
    CashierV3RequestNormalizer::normalize('prepare-checkout', $duplicate);
} catch (CashierV3CommandException $exception) {
    $rejected = ($exception->getDetail()['reason'] ?? '') === 'line_identity_invalid';
}
$check('duplicate snapshot line identity is rejected', $rejected);

$invalidBinding = $payload;
$invalidBinding['checkoutSnapshot']['memberId'] = 0;
$rejected = false;
try {
    CashierV3RequestNormalizer::normalize('prepare-checkout', $invalidBinding);
} catch (CashierV3CommandException $exception) {
    $rejected = ($exception->getDetail()['reason'] ?? '') === 'member_binding_invalid';
}
$check('member snapshot cannot be submitted without a member id', $rejected);

$service = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php');
$rebuilder = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutDraftAuthorityRebuilder.php');
$executionPort = file_get_contents($backend . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php');
$saleOnly = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php');
$workspace = file_get_contents($backend . '/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php');
$start = is_string($service) ? strpos($service, 'public function prepareInTx') : false;
$end = is_string($service) ? strpos($service, '$authority = $this->workspace->checkoutSaleSourceSetInTx', $start === false ? 0 : $start) : false;
$prefix = $start === false || $end === false ? '' : substr($service, $start, $end - $start);
$check('snapshot path builds authority before any workspace materializer',
    str_contains($prefix, 'authorityFromCheckoutSnapshot(')
    && !str_contains($prefix, 'materializeCheckoutSnapshotInTx(')
    && !str_contains($prefix, 'checkoutSaleSourceSetInTx('));
$check('browser snapshot authority does not depend on workspace projection version',
    is_string($service)
    && str_contains($service, "\$authoritySnapshotVersion = \$browserSnapshot !== []")
    && str_contains($service, "? 1\n            : \$this->contextVersion(\$contexts, 'cashier_workspace', \$workspaceId)")
);
$check('no row-by-row snapshot workspace materializer remains',
    is_string($service) && !str_contains($service, 'function materializeCheckoutSnapshotInTx'));
$check('snapshot completion clears the shell without comparing legacy workspace lines',
    is_string($service) && str_contains($service, "'type' => 'cashier_snapshot'")
    && is_string($executionPort) && str_contains($executionPort, 'completeSnapshotCheckoutInTx('));
$check('snapshot salespeople never fall back to legacy workspace rows',
    is_string($saleOnly)
    && str_contains($saleOnly, "source_document_type'] ?? '') === 'cashier_snapshot'")
    && is_string($executionPort)
    && str_contains($executionPort, "source_document_type'] ?? '') === 'cashier_snapshot'")
    && substr_count($saleOnly, 'lockedSalespeopleByCheckoutLineInTx(') === 1
    && substr_count($executionPort, 'lockedSalespeopleByCheckoutLineInTx(') === 1);
$check('empty salesperson snapshot is a valid no-salesperson selection',
    is_string($workspace)
    && str_contains($workspace, 'A persisted snapshot with no salesperson is a valid empty')
    && str_contains($workspace, '$result[$checkoutLineId] = [];'));
$catalog = file_get_contents($backend . '/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php');
$check('snapshot sale authority preserves locked catalog category for service facts',
    is_string($service)
    && str_contains($service, "'categoryIdSnapshot' => (int)(\$server['category_id_snapshot'] ?? \$server['categoryId'] ?? 0)")
    && is_string($catalog)
    && str_contains($catalog, "'category_id_snapshot' => (int)(\$normalized['categoryId'] ?? 0)"));
$check('snapshot entitlement authority preserves selected service settings',
    is_string($service)
    && str_contains($service, "'craftsmen' => is_array(\$line['craftsmen'] ?? null) ? \$line['craftsmen'] : []")
    && str_contains($service, "'serviceObject' => (string)(\$line['serviceObject'] ?? '')"));
$check('entitlement service intent is decoded separately from sale personnel snapshot',
    is_string($rebuilder)
    && str_contains($rebuilder, "'craftsmen' => self::entitlementCraftsmenSnapshot(")
    && str_contains($rebuilder, 'private static function entitlementCraftsmenSnapshot($json)')
    && str_contains($rebuilder, 'checkout_draft_entitlement_craftsmen_weight_invalid'));
$check('final snapshot sale validation does not reject product lifecycle status',
    is_string($saleOnly)
    && !str_contains($saleOnly, "->where('is_del', 0)"));
$projection = file_get_contents($backend . '/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php');
$check('snapshot salesperson array participates in projection fingerprint',
    is_string($projection)
    && str_contains($projection, '$salespeopleJson = $row[\'salespeople_snapshot_json\'] ?? null;')
    && str_contains($projection, "\$fingerprintInput['salespeople'] = \$salespeople;")
    && str_contains($projection, 'checkout_projection_salespeople_weight_invalid'));
$repository = file_get_contents($backend . '/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutRequestRepository.php');
$check('snapshot projection keeps a positive workspace version when the shell is uninitialized',
    is_string($repository)
    && str_contains($repository, "'workspaceCurrentVersion' => \$isSnapshot\n                ? max(\$workspaceVersion, 1)\n                : \$workspaceVersion,"));

echo sprintf('checkout-snapshot-direct-authority-contract: %d passed, %d failed', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
