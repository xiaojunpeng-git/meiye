<?php
/**
 * Local-only R9 inventory-menu permission regression. Synthetic roles/admin/documents are rolled back;
 * existing warehouse metadata is read only. Never change a real employee's grants or stock.
 */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();

use think\facade\Db;
use app\services\product\inventory\InventoryPlatformAccessPolicy;
use app\services\product\inventory\InventoryPlatformWarehouseServices;
use app\services\product\inventory\InventoryCrossSubjectTransferServices;
use app\services\product\inventory\InventoryHqLocationServices;
use app\services\system\SystemRoleServices;
use app\services\query\UnifiedQueryException;
use mohe\exceptions\AuthException;

if (!getenv('MOHE_LOCAL_DB_HOSTNAME')
    || config('database.connections.mysql.hostname') !== 'mysql'
    || Db::query('SELECT @@hostname AS host')[0]['host'] !== getenv('MOHE_LOCAL_DB_HOSTNAME')) {
    throw new RuntimeException('Local database guard failed');
}
function checkR9Transfer(bool $value, string $name): void {
    if (!$value) throw new RuntimeException($name);
    echo 'PASS ' . $name . PHP_EOL;
}
/** Exercise the real route middleware with controller dispatch deliberately excluded. */
class R9TransferRequest extends \app\Request {
    public $testRule = '';
    public $testMethod = 'get';
    public $testQuery = [];
    public function get($name = '', $default = null, $filter = '') { return $name === '' ? $this->testQuery : ($this->testQuery[$name] ?? $default); }
    public function rule() { return new class($this->testRule) {
        private $value;
        public function __construct($value) { $this->value = $value; }
        public function getRule() { return $this->value; }
    }; }
    public function method(bool $origin = false): string { return $this->testMethod; }
}
$tag = 'r9-transfer-' . bin2hex(random_bytes(6));
$policy = new InventoryPlatformAccessPolicy();
$roles = app()->make(SystemRoleServices::class);
$transfers = new InventoryCrossSubjectTransferServices();
$menuId = (int)Db::name('system_menus')->where('unique_auth', 'admin-stock-transfer')
    ->where('type', 1)->where('is_del', 0)->value('id');
$hq = Db::name('inventory_location')->where('location_type', 'HQ')->where('location_status', 'ACTIVE')
    ->where('is_default', 1)->find();
if (!$menuId || !$hq) throw new RuntimeException('Local transfer menu/HQ metadata required');

Db::startTrans();
try {
    // Use our own equivalent menu row for revocation checks; never update real menu metadata.
    $menuId = (int)Db::name('system_menus')->insertGetId([
        'menu_name' => $tag, 'type' => 1, 'auth_type' => 1, 'unique_auth' => 'admin-stock-transfer', 'is_del' => 0,
    ]);
    $roleId = (int)Db::name('system_role')->insertGetId(['role_name' => $tag, 'rules' => (string)$menuId, 'status' => 1]);
    $adminId = (int)Db::name('system_admin')->insertGetId([
        'account' => $tag, 'real_name' => $tag, 'roles' => (string)$roleId, 'level' => 1, 'status' => 1, 'is_del' => 0, 'admin_type' => 0,
    ]);
    $admin = ['id' => $adminId, 'roles' => [$roleId], 'real_name' => $tag];
    $request = new R9TransferRequest();
    $request->adminInfo = $admin;
    foreach (['locations', 'hq/cross-transfer', 'hq/cross-transfer/<id>/detail'] as $route) {
        $request->testRule = 'adminapi/product/inventory/v3/' . $route;
        checkR9Transfer($roles->verifiAuth($request), 'legacy transfer menu allows registered GET ' . $route);
    }
    foreach ([['get', 'dashboard'], ['get', 'batch-stock'], ['get', 'hq/inbound'],
        ['get', 'hq/request'], ['get', 'hq/cross-transfer/incoming-requests'],
        ['post', 'hq/cross-transfer'], ['post', 'hq/cross-transfer/<id>/dispatch'],
        ['post', 'hq/cross-transfer/<id>/receive'], ['post', 'hq/cross-transfer/<id>/withdraw'],
        ['post', 'hq/cross-transfer/<id>/reverse'], ['get', 'not-registered']] as $case) {
        [$request->testMethod, $route] = $case;
        $request->testRule = 'adminapi/product/inventory/v3/' . $route;
        try { $roles->verifiAuth($request); throw new RuntimeException('Unexpected route grant ' . $route); }
        catch (AuthException $expected) { echo 'PASS legacy menu denies ' . $case[0] . ' ' . $route . PHP_EOL; }
    }
    $access = $policy->resolve($admin);
    checkR9Transfer(!in_array(InventoryPlatformAccessPolicy::AUTH_VIEW, $access['unique_auth'], true)
        && !in_array('inventory.location.manage', $access['features'], true)
        && !in_array('inventory.batch.export', $access['features'], true), 'compatibility does not mint broad view/write/export grants');
    $locations = (new InventoryPlatformWarehouseServices())->locations($admin);
    checkR9Transfer(count($locations) > 0 && !array_filter($locations, static fn ($x) => $x['location_type'] !== 'HQ'), 'legacy HQ-only scope remains HQ-only');

    $docIds = [];
    foreach (['DRAFT', 'DISPATCHED'] as $status) {
        $docIds[] = (int)Db::name('inventory_cross_transfer_document')->insertGetId([
            'transfer_no' => $tag . '-' . $status, 'idempotency_key' => $tag . '-' . $status, 'tenant_id' => $hq['tenant_id'],
            'from_party_type' => 'HQ', 'from_location_id' => $hq['id'],
            'to_party_type' => 'HQ', 'to_location_id' => $hq['id'],
            'business_date' => date('Y-m-d'), 'document_status' => $status,
        ]);
    }
    $list = $transfers->listForHeadquarters($admin, (int)$hq['id'], $tag);
    checkR9Transfer($list['count'] === 2 && count($list['list']) === 2 && !$list['can_create'], 'actual transfer list works with menu-only grant');
    checkR9Transfer(!array_filter($list['list'], static fn ($x) => $x['can_dispatch'] || $x['can_receive']), 'draft/in-transit rows do not advertise write actions');
    checkR9Transfer((int)$transfers->detailForHeadquarters($admin, (int)$hq['id'], $docIds[0])['id'] === $docIds[0], 'actual transfer detail works with menu-only grant');
    // Domain guard is tested directly too, so direct service calls cannot bypass HTTP denial.
    foreach (['dispatchForHeadquarters', 'receiveForHeadquarters'] as $method) {
        try { $transfers->{$method}($admin, (int)$hq['id'], $docIds[0]); throw new RuntimeException('Domain write accepted'); }
        catch (UnifiedQueryException $expected) { echo 'PASS domain write denied ' . $method . PHP_EOL; }
    }
    try { (new InventoryHqLocationServices())->writableLocation($admin, (int)$hq['id']); throw new RuntimeException('Warehouse write accepted'); }
    catch (UnifiedQueryException $expected) { echo 'PASS warehouse create/write authority unchanged' . PHP_EOL; }
    Db::name('inventory_cross_transfer_document')->where('id', $docIds[0])->update(['tenant_id' => $tag]);
    checkR9Transfer($transfers->listForHeadquarters($admin, (int)$hq['id'], $tag)['count'] === 1, 'foreign-tenant documents excluded from list');
    try { $transfers->detailForHeadquarters($admin, (int)$hq['id'], $docIds[0]); throw new LogicException('Foreign document accepted'); }
    catch (RuntimeException $expected) { checkR9Transfer($expected->getMessage() === 'inventory_cross_transfer_not_found', 'foreign-tenant detail denied'); }

    foreach ([['system_role', $roleId, 'status', 0], ['system_role', $roleId, 'rules', ''],
        ['system_admin', $adminId, 'status', 0], ['system_admin', $adminId, 'is_del', 1],
        ['system_menus', $menuId, 'is_del', 1]] as $case) {
        [$table, $id, $column, $value] = $case;
        $old = Db::name($table)->where('id', $id)->value($column);
        Db::name($table)->where('id', $id)->update([$column => $value]);
        checkR9Transfer(!$policy->allowsLegacyMenuRead($adminId, 'product/inventory/v3/hq/cross-transfer', 'get'), 'revocation takes effect: ' . $table . '.' . $column);
        try { $policy->resolve($admin); throw new RuntimeException('Revoked access accepted'); }
        catch (UnifiedQueryException $expected) { echo 'PASS domain scope denied after revocation' . PHP_EOL; }
        Db::name($table)->where('id', $id)->update([$column => $old]);
    }
    // Each menu is isolated: a shared endpoint cannot turn inbound permission into count/outbound access.
    $matrix = [
        ['admin-inbound-manage', 'hq/inbound', 'inventory_inbound'],
        ['admin-outbound-manage', 'hq/outbound', 'inventory_outbound'],
        ['admin-inventory-count', 'hq/count/<id>/detail', 'inventory_count'],
        ['admin-inventory-details', 'hq/product-summary', 'inventory_batch_stock'],
        ['admin-stock-request', 'hq/request', 'inventory_request'],
        ['admin-stock-transfer', 'hq/cross-transfer', 'inventory_transfer'],
        ['admin-salon-usage', 'salon-usage', 'inventory_salon_usage'],
        ['admin-salon-recipe', '../recipe/list', ''],
        ['admin-stock-manage', 'dashboard', 'inventory_request'],
    ];
    foreach ($matrix as [$menuAuth, $route, $pageCode]) {
        $fixtureMenu = (int)Db::name('system_menus')->insertGetId([
            'menu_name' => $tag, 'type' => 1, 'auth_type' => 1, 'unique_auth' => $menuAuth, 'is_del' => 0,
        ]);
        Db::name('system_role')->where('id', $roleId)->update(['rules' => (string)$fixtureMenu]);
        $request->testMethod = 'get';
        $request->testQuery = [];
        $request->testRule = $route === '../recipe/list' ? 'adminapi/product/inventory/recipe/list' : 'adminapi/product/inventory/v3/' . $route;
        checkR9Transfer($roles->verifiAuth($request), 'own registered read route: ' . $menuAuth);
        $menuAccess = $policy->resolve($admin);
        checkR9Transfer(!in_array('inventory.location.manage', $menuAccess['features'], true), 'menu does not grant writes: ' . $menuAuth);
        if ($pageCode !== '') {
            $policy->assertQueryPage($menuAccess, $pageCode);
            checkR9Transfer(true, 'own domain query page: ' . $pageCode);
        }
        if (in_array($pageCode, ['inventory_inbound', 'inventory_outbound', 'inventory_count'], true)) {
            $request->testRule = 'adminapi/product/inventory/v3/unified-query/operational';
            $request->testQuery = ['pageCode' => $pageCode];
            checkR9Transfer($roles->verifiAuth($request), 'shared endpoint own page: ' . $pageCode);
            $foreignPage = $pageCode === 'inventory_count' ? 'inventory_inbound' : 'inventory_count';
            $request->testQuery = ['pageCode' => $foreignPage];
            try { $roles->verifiAuth($request); throw new RuntimeException('Shared endpoint leaked'); }
            catch (AuthException $expected) { echo 'PASS shared endpoint denies foreign page ' . $menuAuth . PHP_EOL; }
            try { $policy->assertQueryPage($menuAccess, $foreignPage); throw new RuntimeException('Domain query leaked'); }
            catch (UnifiedQueryException $expected) { echo 'PASS domain denies foreign page ' . $menuAuth . PHP_EOL; }
        }
        if ($menuAuth === 'admin-inbound-manage') {
            $read = (new \app\services\product\inventory\InventoryPlatformHqInboundServices())->index($admin, (int)$hq['id'], '');
            checkR9Transfer(isset($read['list']) && !array_filter($read['list'], static fn ($x) => $x['can_void']), 'real HQ inbound read has no void capability');
        }
        if ($menuAuth === 'admin-outbound-manage') {
            $read = (new \app\services\product\inventory\InventoryPlatformHqOutboundServices())->index($admin, (int)$hq['id'], '');
            checkR9Transfer(isset($read['list']) && !array_filter($read['list'], static fn ($x) => $x['can_void']), 'real HQ outbound read has no void capability');
        }
        if ($menuAuth === 'admin-stock-request') {
            $requests = new \app\services\product\inventory\InventoryStockRequestServices();
            checkR9Transfer(isset($requests->acceptancePartiesForHeadquarters($admin, (int)$hq['id'])['list']), 'request filter directory readable without write');
            $read = (new \app\services\product\inventory\InventoryStockRequestQueryServices())->listForHeadquarters($admin, (int)$hq['id'], '');
            checkR9Transfer(!array_filter($read['list'], static fn ($x) => $x['can_void'] || $x['can_transfer'] || $x['can_cancel'] || $x['can_terminate']), 'request list advertises no writes for menu-only role');
        }
        if ($menuAuth === 'admin-salon-usage') {
            // A visible salon menu does not create a store scope or grant stock mutation.
            $salon = new \app\services\product\inventory\InventorySalonUsageServices();
            try { $salon->listForPlatform($admin, 1, '', date('Y-m-d'), date('Y-m-d')); throw new LogicException('Unassigned store leaked'); }
            catch (RuntimeException $expected) { checkR9Transfer($expected->getMessage() === 'inventory_salon_usage_platform_store_denied', 'salon query rejects unassigned store'); }
            try {
                $salon->issueForPlatform($admin, 1, ['idempotency_key' => $tag, 'business_date' => date('Y-m-d'),
                    'project_id' => 0, 'project_name' => '', 'remark' => '',
                    'lines' => [['product_id' => 1, 'sku_id' => 1, 'sku_unique' => 'fixture', 'quantity' => '1']]]);
                throw new RuntimeException('Salon command accepted');
            }
            catch (UnifiedQueryException $expected) { echo 'PASS salon domain rejects menu-only write' . PHP_EOL; }
        }
        // No other legacy menu implies access to outbound, except outbound itself.
        if ($menuAuth !== 'admin-outbound-manage') {
            checkR9Transfer(!$policy->allowsLegacyMenuRead($adminId, 'product/inventory/v3/hq/outbound', 'get'), 'foreign outbound route denied: ' . $menuAuth);
        }
    }
    foreach ([['post', 'hq/inbound'], ['post', 'hq/outbound'], ['post', 'hq/count/confirm'], ['post', 'hq/request/apply'], ['post', 'salon-usage/issue'], ['post', 'import'], ['get', 'unified-query/export-task/<taskNo>'], ['get', 'hq/catalog'], ['get', 'hq/cross-transfer/incoming-requests']] as [$method, $route]) {
        checkR9Transfer(!$policy->allowsLegacyMenuRead($adminId, 'product/inventory/v3/' . $route, $method), 'legacy bridge never grants command/export/editor dependency ' . $route);
    }
    foreach (['', 'inventory_count', 'inventory_statistics_age', 'not-a-page'] as $wrongPage) {
        checkR9Transfer(!$policy->allowsLegacyMenuRead($adminId, 'product/inventory/v3/unified-query/operational', 'get', ['pageCode' => $wrongPage]), 'home menu cannot read unrelated/unknown unified page ' . $wrongPage);
    }
    checkR9Transfer(!$policy->allowsLegacyMenuRead($adminId, 'product/inventory/v3/unified-query/operational', 'get', ['pageCode' => ['inventory_request']]), 'malformed pageCode fails closed without string coercion');
    checkR9Transfer(!$policy->allowsLegacyMenuRead($adminId, 'product/inventory/v3/hq/request/arbitrary', 'get'), 'known prefix never grants an unknown route');
    // Existing function nodes authorize their own create commands, not a broad warehouse grant.
    $createCases = [
        ['inbound', 'admin-inbound-manage', 'admin-inbound-manage-add', 'hq/inbound'],
        ['outbound', 'admin-outbound-manage', 'admin-outbound-manage-add', 'hq/outbound'],
        ['count', 'admin-inventory-count', 'admin-inventory-count-add', 'hq/count/confirm'],
        ['request', 'admin-stock-request', 'admin-stock-request-add', 'hq/request/apply'],
        ['transfer', 'admin-stock-transfer', 'admin-stock-transfer-add', 'hq/cross-transfer'],
    ];
    foreach ($createCases as [$page, $pageAuth, $functionAuth, $route]) {
        $functionIds = [];
        foreach ([$pageAuth, $functionAuth] as $authCode) $functionIds[] = (int)Db::name('system_menus')->insertGetId([
            'menu_name' => $tag, 'type' => 1, 'auth_type' => 1, 'unique_auth' => $authCode, 'is_del' => 0,
        ]);
        Db::name('system_role')->where('id', $roleId)->update(['rules' => implode(',', $functionIds)]);
        $functionAccess = $policy->resolve($admin);
        checkR9Transfer($policy->canCreate($functionAccess, $page) && !in_array('inventory.location.manage', $functionAccess['features'], true), 'specific existing create function without broad write: ' . $page);
        $request->testQuery = []; $request->testMethod = 'post'; $request->testRule = 'adminapi/product/inventory/v3/' . $route;
        checkR9Transfer($roles->verifiAuth($request), 'actual middleware accepts corresponding create: ' . $page);
        $location = (new InventoryHqLocationServices())->writableLocation($admin, (int)$hq['id'], $page);
        checkR9Transfer((int)$location['location']['id'] === (int)$hq['id'], 'domain accepts corresponding create scope: ' . $page);
        try { (new InventoryHqLocationServices())->writableLocation($admin, (int)$hq['id']); throw new LogicException('Broad write leaked'); }
        catch (UnifiedQueryException $expected) { echo 'PASS general management still denied: ' . $page . PHP_EOL; }
        foreach ($createCases as [$otherPage, , , $otherRoute]) if ($otherPage !== $page) {
            $request->testRule = 'adminapi/product/inventory/v3/' . $otherRoute;
            try { $roles->verifiAuth($request); throw new LogicException('Foreign create leaked'); }
            catch (AuthException $expected) { echo 'PASS foreign create denied: ' . $page . ' -> ' . $otherPage . PHP_EOL; }
        }
        foreach (['hq/cross-transfer/<id>/dispatch', 'hq/cross-transfer/<id>/receive', 'hq/cross-transfer/<id>/withdraw',
            'hq/cross-transfer/<id>/reverse', 'hq/cross-transfer/<id>/update', 'hq/cross-transfer/<id>/delete-draft',
            'hq/inbound/<id>/void', 'locations', 'import', 'hq/count/draft/<id>/delete'] as $forbiddenRoute) {
            $request->testRule = 'adminapi/product/inventory/v3/' . $forbiddenRoute;
            // 第9轮确认：调拨编辑功能覆盖发收货，但不能借此开放其他库存命令。
            if ($page === 'transfer' && strpos($forbiddenRoute, 'hq/cross-transfer/') === 0) {
                checkR9Transfer($roles->verifiAuth($request), 'transfer function grants its workflow command: ' . $forbiddenRoute);
                continue;
            }
            try { $roles->verifiAuth($request); throw new LogicException('Unrelated command leaked'); }
            catch (AuthException $expected) { echo 'PASS function does not grant unrelated command: ' . $page . ' / ' . $forbiddenRoute . PHP_EOL; }
        }
        $request->testMethod = 'get'; $request->testRule = 'adminapi/product/inventory/v3/hq/catalog';
        checkR9Transfer($roles->verifiAuth($request), 'editor catalog HTTP dependency: ' . $page);
        $catalog = (new \app\services\product\inventory\InventoryPlatformHqCatalogServices())->search($admin, (int)$hq['id'], $tag);
        checkR9Transfer(isset($catalog['list']), 'actual editor catalog service: ' . $page);
        if ($page === 'transfer') {
            $request->testMethod = 'post';
            foreach (['update', 'delete-draft'] as $action) {
                $request->testRule = 'adminapi/product/inventory/v3/hq/cross-transfer/<id>/' . $action;
                checkR9Transfer($roles->verifiAuth($request), 'transfer function grants own draft command: ' . $action);
            }
            $request->testMethod = 'get';
            checkR9Transfer($transfers->listForHeadquarters($admin, (int)$hq['id'], $tag)['can_create'], 'transfer list returns existing function create capability');
            checkR9Transfer(isset($transfers->incomingRequestsForHeadquarters($admin, (int)$hq['id'])['list']), 'transfer editor incoming requests resolve');
            checkR9Transfer(isset($transfers->transferStaffCandidatesForHeadquarters($admin, (int)$hq['id'], 'HQ', 0, 0)['list']), 'transfer editor staff candidates resolve');
        }
        if ($page === 'request') {
            checkR9Transfer(isset((new \app\services\product\inventory\InventoryStockRequestServices())->requesterForHeadquarters($admin, (int)$hq['id'])['list']), 'request editor requester resolves');
        }
        if ($page === 'count') {
            // Saved form only: no inventory or movement facts are created, and outer transaction rolls it back.
            $drafts = new \app\services\product\inventory\InventoryStockCountDraftServices();
            $draft = $drafts->saveForHeadquarters($admin, (int)$hq['id'], ['business_date' => date('Y-m-d'), 'remark' => $tag,
                'lines' => [['product_id' => 1, 'sku_id' => 1, 'sku_unique' => $tag, 'book_quantity' => '0', 'counted_quantity' => '1']]]);
            $readBack = $drafts->detailForHeadquarters($admin, (int)$hq['id'], $draft['draft_id']);
            checkR9Transfer($readBack['remark'] === $tag && $readBack['version'] === 1, 'actual count draft save and read-back with existing function');
            try { $drafts->deleteForHeadquarters($admin, (int)$hq['id'], $draft['draft_id'], 1); throw new LogicException('Draft delete leaked'); }
            catch (UnifiedQueryException $expected) { echo 'PASS count add does not grant destructive draft deletion' . PHP_EOL; }
        }
        // Losing either the page or operation, deleting the function or disabling the role takes effect immediately.
        foreach ([(string)$functionIds[0], (string)$functionIds[1]] as $partialRules) {
            Db::name('system_role')->where('id', $roleId)->update(['rules' => $partialRules]);
            checkR9Transfer(!$policy->allowsFunctionRoute($adminId, 'product/inventory/v3/' . $route, 'post'), 'page and function both required: ' . $page);
        }
        Db::name('system_role')->where('id', $roleId)->update(['rules' => implode(',', $functionIds), 'status' => 0]);
        checkR9Transfer(!$policy->allowsFunctionRoute($adminId, 'product/inventory/v3/' . $route, 'post'), 'disabled role revokes create: ' . $page);
        Db::name('system_role')->where('id', $roleId)->update(['status' => 1]);
        Db::name('system_menus')->where('id', $functionIds[1])->update(['is_del' => 1]);
        checkR9Transfer(!$policy->allowsFunctionRoute($adminId, 'product/inventory/v3/' . $route, 'post'), 'deleted function revokes create: ' . $page);
    }
    // Remove test query state before checking the original native authority contract.
    $request->testQuery = [];
    $nativeIds = Db::name('system_menus')->where('type', 1)->where('is_del', 0)
        ->whereIn('unique_auth', [InventoryPlatformAccessPolicy::AUTH_VIEW, InventoryPlatformAccessPolicy::AUTH_WAREHOUSE_MANAGE])->column('id');
    $writeOnlyIds = Db::name('system_menus')->where('type', 1)->where('is_del', 0)
        ->where('unique_auth', InventoryPlatformAccessPolicy::AUTH_WAREHOUSE_MANAGE)->column('id');
    Db::name('system_role')->where('id', $roleId)->update(['rules' => implode(',', array_merge([$menuId], $writeOnlyIds))]);
    checkR9Transfer(!in_array('inventory.location.manage', $policy->resolve($admin)['features'], true), 'legacy menu does not replace native view prerequisite for write grants');
    Db::name('system_role')->where('id', $roleId)->update(['rules' => implode(',', $nativeIds)]);
    checkR9Transfer(in_array('inventory.location.manage', $policy->resolve($admin)['features'], true), 'existing explicit V3 view+write grants retain write capability');
    $nativeList = $transfers->listForHeadquarters($admin, (int)$hq['id'], $tag);
    checkR9Transfer($nativeList['can_create'] && $nativeList['list'][0]['can_receive'], 'native writer retains create/receive actions');
} finally {
    Db::rollback();
}
checkR9Transfer(!Db::name('system_admin')->where('account', $tag)->count()
    && !Db::name('system_role')->where('role_name', $tag)->count()
    && !Db::name('system_menus')->where('menu_name', $tag)->count()
    && !Db::name('inventory_stock_count_draft')->where('remark', $tag)->count()
    && !Db::name('inventory_cross_transfer_document')->whereLike('transfer_no', $tag . '%')->count(), 'synthetic fixtures rolled back');
