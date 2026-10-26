<?php
declare(strict_types=1);

namespace app\services\cashier\v3 { class CashierV3ScopeResolver { public const TENANT_SCOPE_ID = 'test-tenant'; } }
namespace app\services\query { class UnifiedQueryException extends \RuntimeException { public function __construct(string $code, string $message, array $details) { parent::__construct($message); } } }
namespace think\facade {
    class Db { public static function name(string $table): Query { return new Query($table); } }
    class Query {
        private $table;
        public function __construct(string $table) { $this->table = $table; }
        public function __call(string $method, array $args): self { return $this; }
        public function column(string $column): array { return $this->table === 'inventory_location' ? [2, 3, 4] : [2, 4, 5]; }
    }
}
namespace {
    use app\services\product\inventory\InventoryPlatformAccessPolicy;

    $root = dirname(__DIR__, 3);
    require $root . '/后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php';
    $access = [
        'unique_auth' => [InventoryPlatformAccessPolicy::AUTH_VIEW],
        'store_ids' => [2], 'features' => ['inventory.batch.view'],
        'permission_version' => 'original', 'is_super_admin' => false,
    ];
    $policy = new InventoryPlatformAccessPolicy();
    foreach (['inventory_inbound', 'inventory_outbound', 'inventory_request', 'inventory_transfer', 'inventory_salon_usage'] as $page) {
        $read = $policy->forOperationalRead($access, $page);
        if ($read['store_ids'] !== [2, 4] || $read['features'] !== $access['features']
            || $access['store_ids'] !== [2] || $read['permission_version'] === $access['permission_version']) {
            throw new \RuntimeException('read-only scope failed: ' . $page);
        }
    }
    try { $policy->forOperationalRead($access, 'inventory_count'); throw new \RuntimeException('unapproved page accepted'); }
    catch (\app\services\query\UnifiedQueryException $expected) {}
    $remark = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventoryDocumentRemarkServices.php');
    $context = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php');
    $route = (string)file_get_contents($root . '/后端代码/route/admin.php');
    if (strpos($remark, 'readPlatformStoreDocument($access, $type, (int)$id, $storeId)') === false
        || strpos($remark, "throw new \\RuntimeException('inventory_document_remark_denied');") === false
        || strpos($context, '$access = $policy->forOperationalRead($access, $pageCode);') === false
        || strpos($route, "v3/organization-scope', 'v1.product.inventory.InventoryPlatformWarehouse/organizationScope") === false) {
        throw new \RuntimeException('read/detail/write route contract failed');
    }
    echo "PLATFORM_ORGANIZATION_STORE_READ_OK: page scope, active warehouse, original write scope\n";
}
