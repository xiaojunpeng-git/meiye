<?php
declare(strict_types=1);

/** Local-only scope check; synthetic role and account never leave the transaction. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\InventoryDocumentRemarkServices;
use app\services\organization\OrganizationScopeService;
use app\services\product\inventory\query\InventoryOperationalUnifiedQueryRegistrar;
use app\services\product\inventory\query\InventoryPlatformUnifiedQueryContextFactory;
use app\services\query\UnifiedQueryContextFactory;
use app\services\query\UnifiedQueryPageRegistry;
use app\services\query\UnifiedQueryRuntime;
use think\facade\Db;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME')
    || config('database.connections.mysql.hostname') !== 'mysql'
    || (string)(Db::query('SELECT @@hostname AS host')[0]['host'] ?? '') !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}

$pageMenus = [
    'inventory_inbound' => 'admin-inbound-manage',
    'inventory_outbound' => 'admin-outbound-manage',
    'inventory_request' => 'admin-stock-request',
    'inventory_transfer' => 'admin-stock-transfer',
    'inventory_salon_usage' => 'admin-salon-usage',
];
$menuIds = [];
foreach ($pageMenus as $page => $auth) {
    $menuIds[$page] = (int)Db::name('system_menus')->where('unique_auth', $auth)
        ->where('type', 1)->where('is_del', 0)->value('id');
    if ($menuIds[$page] <= 0) throw new RuntimeException('Missing local menu: ' . $auth);
}
$location = Db::name('inventory_location')->alias('l')->join('system_store s', 's.id=l.store_id')
    ->where('l.tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
    ->where('l.location_type', 'STORE')->where('l.location_status', 'ACTIVE')->where('l.is_default', 1)
    ->where('s.is_del', 0)->where('s.is_show', 1)->field('l.id,l.store_id')->find();
if (!$location) throw new RuntimeException('Local active default store warehouse required');

Db::startTrans();
try {
    $tag = 'inventory-single-store-' . bin2hex(random_bytes(5));
    $roleId = (int)Db::name('system_role')->insertGetId([
        'role_name' => $tag, 'rules' => implode(',', array_values($menuIds)), 'status' => 1,
    ]);
    $adminId = (int)Db::name('system_admin')->insertGetId([
        'account' => $tag, 'real_name' => $tag, 'roles' => (string)$roleId,
        'level' => 1, 'status' => 1, 'is_del' => 0, 'admin_type' => 0,
    ]);
    $admin = ['id' => $adminId];
    $policy = new InventoryPlatformAccessPolicy();
    $original = $policy->resolve($admin);
    if ($original['store_ids'] !== [] || in_array('inventory.location.manage', $original['features'], true)) {
        throw new RuntimeException('Synthetic account unexpectedly has inventory write scope');
    }
    $registry = UnifiedQueryPageRegistry::fromRegistrars([], [new InventoryOperationalUnifiedQueryRegistrar()]);
    $factory = new InventoryPlatformUnifiedQueryContextFactory(new UnifiedQueryContextFactory($registry));
    $runtime = UnifiedQueryRuntime::runtime();
    foreach ($pageMenus as $page => $auth) {
        $read = $policy->forOperationalRead($original, $page);
        if (!in_array((int)$location['store_id'], $read['store_ids'], true)
            || $original['store_ids'] !== [] || $read['features'] !== $original['features']) {
            throw new RuntimeException('Read scope or write isolation failed: ' . $page);
        }
        if (!$policy->allowsLegacyMenuRead($adminId, 'product/inventory/v3/organization-scope', 'get', ['pageCode' => $page])) {
            throw new RuntimeException('Page menu cannot open organization scope: ' . $page);
        }
        $context = $factory->make($adminId, $admin, date('Y-m-d'), (int)$location['store_id'], 'STORE', $page);
        if ((int)$context['store_id'] !== (int)$location['store_id']
            || !in_array((int)$location['id'], array_map('intval', $context['scope_dimensions']['location_id']), true)) {
            throw new RuntimeException('Selected store did not resolve its own warehouse: ' . $page);
        }
        $result = $runtime['providers']->resolve($page)->query($context, [
            'pageCode' => $page, 'page' => 1, 'limit' => 20, 'keyword' => '',
            'topFilterConditions' => [],
        ]);
        if (!is_array($result['records'] ?? null) || (int)($result['page'] ?? 0) !== 1
            || (int)($result['total'] ?? -1) < count($result['records'])) {
            throw new RuntimeException('Selected store query failed: ' . $page);
        }
        foreach ($result['records'] as $row) {
            if ((int)($row['store_id'] ?? 0) !== (int)$location['store_id']) {
                throw new RuntimeException('Foreign store row leaked: ' . $page);
            }
        }
    }
    // Select the same tree node exposed to the platform UI, including legacy organizations.
    $allowed = $policy->forOperationalRead($original, 'inventory_inbound')['store_ids'];
    $tree = app()->make(OrganizationScopeService::class)->buildPickerTree($allowed);
    $storesUnder = static function (array $node) use (&$storesUnder): array {
        if (($node['node_type'] ?? '') === 'store') return [(int)$node['id']];
        $ids = [];
        foreach ((array)($node['children'] ?? []) as $child) $ids = array_merge($ids, $storesUnder($child));
        return array_values(array_unique($ids));
    };
    $findOrganization = static function (array $nodes) use (&$findOrganization, $storesUnder, $allowed): ?array {
        foreach ($nodes as $node) {
            if (($node['node_type'] ?? '') !== 'org') continue;
            $nested = $findOrganization((array)($node['children'] ?? []));
            if ($nested !== null) return $nested;
            $count = count($storesUnder($node));
            if ($count >= 2 && $count < count($allowed)) return $node;
        }
        return null;
    };
    $organization = $findOrganization($tree);
    if ($organization === null) {
        foreach ($tree as $node) if (($node['node_type'] ?? '') === 'org' && count($storesUnder($node)) >= 2) { $organization = $node; break; }
    }
    if ($organization === null) throw new RuntimeException('Local organization with two active store warehouses required');
    $organizationStores = $storesUnder($organization);
    sort($organizationStores);
    $organizationTotals = [];
    $period = [['field' => 'business_date', 'operator' => 'between', 'value' => [date('Y-m-01'), date('Y-m-d')]]];
    $allRecordIds = static function (string $page, array $scope) use ($runtime, $period): array {
        $ids = []; $pageNumber = 1;
        do {
            $result = $runtime['providers']->resolve($page)->query($scope, [
                'pageCode' => $page, 'page' => $pageNumber, 'limit' => 20, 'topFilterConditions' => $period,
            ]);
            foreach ($result['records'] as $row) {
                $ids[] = in_array($page, ['inventory_inbound', 'inventory_outbound'], true)
                    ? $page . ':' . (int)$row['store_id'] . ':' . (string)$row['source_type'] . ':' . (string)$row['source_id'] . ':' . (int)$row['location_id']
                    : (string)($row['record_id'] ?? '');
            }
            $pageNumber++;
        } while (($pageNumber - 1) * 20 < (int)$result['total']);
        if (in_array('', $ids, true)) throw new RuntimeException('Missing stable record ID: ' . $page);
        return $ids;
    };
    foreach (array_keys($pageMenus) as $page) {
        $context = $factory->make($adminId, $admin, date('Y-m-d'), 0, 'STORE', $page, 0, (int)$organization['id']);
        $resolvedStores = array_map('intval', (array)$context['inventory_scope_store_ids']);
        sort($resolvedStores);
        if ($resolvedStores !== $organizationStores || empty($context['inventory_organization_read'])
            || (int)$context['store_id'] !== 0 || $original['store_ids'] !== []) {
            throw new RuntimeException('Organization scope or write isolation failed: ' . $page);
        }
        $result = $runtime['providers']->resolve($page)->query($context, [
            'pageCode' => $page, 'page' => 1, 'limit' => 20,
            'topFilterConditions' => $period,
        ]);
        $organizationTotals[$page] = (int)$result['total'];
        if ((int)$result['total'] < count($result['records']) || count($result['records']) > 20) {
            throw new RuntimeException('Organization paging failed: ' . $page);
        }
        foreach ($result['records'] as $row) {
            if (!in_array((int)($row['store_id'] ?? 0), $organizationStores, true)
                || !empty($row['can_edit']) || !empty($row['can_void'])) {
                throw new RuntimeException('Organization row scope or read-only projection failed: ' . $page);
            }
        }
        $groupIds = $allRecordIds($page, $context);
        $singleIds = [];
        foreach ($organizationStores as $storeId) {
            $single = $factory->make($adminId, $admin, date('Y-m-d'), $storeId, 'STORE', $page);
            $singleIds = array_merge($singleIds, $allRecordIds($page, $single));
        }
        $uniqueGroup = array_values(array_unique($groupIds));
        $uniqueSingle = array_values(array_unique($singleIds));
        sort($uniqueGroup); sort($uniqueSingle);
        if (count($groupIds) !== (int)$result['total'] || $uniqueGroup !== $uniqueSingle) {
            throw new RuntimeException('Organization union/dedup mismatch: ' . $page);
        }
    }
    echo 'PASS organization descendant scope for five pages: ' . count($organizationStores) . ' stores, totals ' . json_encode($organizationTotals) . "\n";
    foreach ([
        [0, 'STORE', 'inventory_inbound', 99999999],
        [$organizationStores[0], 'STORE', 'inventory_inbound', (int)$organization['id']],
        [0, 'HQ', 'inventory_inbound', (int)$organization['id']],
        [0, 'STORE', 'inventory_count', (int)$organization['id']],
    ] as [$storeId, $subject, $page, $orgId]) {
        try {
            $factory->make($adminId, $admin, date('Y-m-d'), $storeId, $subject, $page, 0, $orgId);
            throw new RuntimeException('Invalid organization scope accepted');
        } catch (\app\services\query\UnifiedQueryException $expected) {
            // Invalid, conflicting and unsupported selectors must fail closed.
        }
    }
    if ($policy->allowsLegacyMenuRead($adminId, 'product/inventory/v3/organization-scope', 'get', ['pageCode' => 'inventory_count'])) {
        throw new RuntimeException('Other inventory page unexpectedly opened organization scope');
    }
    $remarks = new InventoryDocumentRemarkServices();
    foreach (['request' => 'inventory_stock_request_document', 'transfer' => 'inventory_cross_transfer_document'] as $type => $table) {
        $query = Db::name($table)->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID);
        if ($type === 'request') {
            $query->where('store_id', (int)$location['store_id'])->where('location_id', (int)$location['id']);
        } else {
            $query->where('from_party_type', 'STORE')->where('from_party_id', (int)$location['store_id'])
                ->where('from_location_id', (int)$location['id']);
        }
        $documentId = (int)$query->value('id');
        if (!$documentId) {
            echo 'SKIP ' . $type . " detail: selected local store has no document\n";
            continue;
        }
        $detail = $remarks->detailForPlatform($admin, $type, (string)$documentId, ['store_id' => (int)$location['store_id']]);
        if (!isset($detail['lines']) || $detail['remark_editable'] !== false || !empty($detail['can_edit'])) {
            throw new RuntimeException('Store document detail unexpectedly editable: ' . $type);
        }
        try {
            $remarks->saveForPlatform($admin, $type, (string)$documentId, ['store_id' => (int)$location['store_id']], []);
            throw new RuntimeException('Read-only store document accepted remark write: ' . $type);
        } catch (RuntimeException $expected) {
            if ($expected->getMessage() !== 'inventory_document_remark_denied') throw $expected;
        }
        echo 'PASS ' . $type . " store detail readable, remark write denied\n";
    }
    echo "PLATFORM_ORGANIZATION_STORE_LOCAL_OK: five page grants, one store warehouse, original write scope unchanged\n";
} finally {
    Db::rollback();
}
