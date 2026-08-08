<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$service = (string)file_get_contents($root . '/后端代码/app/services/product/inventory/InventorySalonUsageServices.php');
$controller = (string)file_get_contents($root . '/后端代码/app/controller/store/product/inventory/InventorySalonUsage.php');
$routes = (string)file_get_contents($root . '/后端代码/route/store.php');
$api = (string)file_get_contents($root . '/前端代码/inventory-vue3/src/services/inventoryApi.js');
$modal = (string)file_get_contents($root . '/前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue');

$failed = 0;
function selectorAssert(string $name, bool $condition): void
{
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$condition) $failed++;
}
function selectorContains(string $haystack, string $needle): bool
{
    return strpos($haystack, $needle) !== false;
}

selectorAssert('project picker resolves the scope from the authenticated current store',
    selectorContains($service, 'public function projects(int $storeId, int $operatorId, string $keyword')
    && selectorContains($service, '$scope = $this->scope($storeId, $operatorId)')
    && selectorContains($service, "->where('p.product_type', 6)")
    && selectorContains($service, "->where('p.is_show', 1)")
    && selectorContains($service, "->whereOr('p.relation_id', \$scope['storeId'])"));

selectorAssert('project keyword search uses the authoritative project name, keywords and code fields',
    selectorContains($service, "whereLike('p.store_name|p.keyword|p.code|p.bar_code', \$like)"));

selectorAssert('project picker route remains behind the store authenticated controller',
    selectorContains($controller, 'public function projects()')
    && selectorContains($controller, '$this->services->projects((int)$this->storeId,(int)$this->storeStaffId')
    && selectorContains($routes, "Route::get('v3/salon-usage/projects', 'product.inventory.InventorySalonUsage/projects')"));

selectorAssert('browser submits a selected project instead of accepting free project identity inputs',
    selectorContains($api, "salonUsageProjects(query = {}) { return request('/v3/salon-usage/projects', { query }) }")
    && selectorContains($modal, 'usageProjectKeyword')
    && selectorContains($modal, 'loadUsageProjects')
    && selectorContains($modal, 'selectUsageProject')
    && !selectorContains($modal, '关联项目 ID')
    && !selectorContains($modal, '输入本次核销项目 ID'));

selectorAssert('write authorization remains the final project authority',
    selectorContains($service, '$command = $this->authorizeProject($scope, $command)')
    && selectorContains($service, 'private function authorizeProject(array $scope,array $command):array'));

echo "INVENTORY_SALON_USAGE_PROJECT_SELECTOR_CONTRACT_RESULT failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
