<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$servicePath = $root . '/后端代码/app/services/cashier/v3/config/CashierV3BusinessConfigServices.php';
$controllerPath = $root . '/后端代码/app/controller/admin/v1/product/CashierV3BusinessConfig.php';
$routePath = $root . '/后端代码/route/admin.php';
$pagePath = $root . '/前端代码/admin/src/pages/product/businessConfig/source.vue';
$apiPath = $root . '/前端代码/admin/src/api/productBusinessConfig.js';

foreach ([$servicePath, $controllerPath, $routePath, $pagePath, $apiPath] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FAIL missing file: {$file}\n");
        exit(1);
    }
}

$service = file_get_contents($servicePath);
$controller = file_get_contents($controllerPath);
$routes = file_get_contents($routePath);
$page = file_get_contents($pagePath);
$api = file_get_contents($apiPath);

assertContains("Db::name('cashier_v3_business_source')", $service, 'V3 source table authority');
assertContains('function existingSources()', $service, 'list existing source rows');
assertContains('return $this->sourceTree(false);', $service, 'list preserves existing source hierarchy');
assertContains('function updateExistingSource(', $service, 'restricted source update service');
assertContains('function createSecondarySource(', $service, 'restricted secondary source create service');
assertContains("Db::name('cashier_v3_business_source')->where('id', \$id)->lock(true)->find()", $service, 'update locks existing source row');
assertContains("'name' => \$name", $service, 'update writes name only');
assertContains("'status' => \$status", $service, 'update writes status only');
assertContains("\$allowed = ['name', 'status'];", $service, 'update payload whitelist');
assertContains("if ((int)\$row['parent_id'] === 0)", $service, 'primary source update is rejected');
assertContains("array_key_exists('isFixed', \$input)", $service, 'fixed source accepts its own minimal payload');
assertContains("'is_fixed' => \$isFixed", $service, 'fixed source writes only its database flag');
assertContains("'isFixed' => (int)(\$row['is_fixed'] ?? 0)", $service, 'source list returns the fixed source flag');
assertContains("\$allowed = ['parentId', 'name'];", $service, 'secondary create payload whitelist');
assertContains("(int)\$parent['parent_id'] !== 0", $service, 'new source parent must be primary');

assertContains('existingSources()', $controller, 'controller lists V3 source rows');
assertContains('updateExistingSource(', $controller, 'controller updates V3 source row');
assertContains('createSecondarySource(', $controller, 'controller creates secondary source only');

assertContains("Route::get('business-config/sources'", $routes, 'legacy source list route');
assertContains("Route::post('business-config/sources'", $routes, 'secondary source create route');
assertContains("Route::put('business-config/sources/:id'", $routes, 'legacy source update route');
assertNotContains("Route::delete('business-config/sources", $routes, 'no source delete route');
assertContains('businessSecondarySourceCreateApi', $api, 'frontend has secondary create API');
assertNotContains('新增来源', $page, 'page has no primary source control');
assertContains('新增二级来源', $page, 'page has child source control');
assertContains('parentId', $page, 'page submits selected primary source for child creation');
assertNotContains('attributionType', $page, 'page has no source type field');
assertContains('tree-node', $page, 'page uses a collapsible source tree');
assertContains("'二级来源'", $page, 'page labels second-level sources');
assertContains('businessSourceUpdateApi', $page, 'page updates existing source');
assertContains('sourcePayload(name, status)', $page, 'page sends only mutable fields');
assertContains('v-if="row.level === 2"', $page, 'only secondary source can change status');
assertContains('title="固定来源"', $page, 'page displays fixed source column');
assertContains('changeFixed(row, $event)', $page, 'page independently saves fixed source flag');

echo "PASS existing V3 cashier source configuration contract\n";

function assertContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL {$label}: missing {$needle}\n");
        exit(1);
    }
}

function assertNotContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL {$label}: unexpected {$needle}\n");
        exit(1);
    }
}
