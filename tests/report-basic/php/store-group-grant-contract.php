<?php
/** 门店基础报表分组授权合同：编号、目录、岗位映射和接口入口一致。 */
declare(strict_types=1);
error_reporting(E_ERROR);
require dirname(__DIR__, 3) . '/后端代码/vendor/autoload.php';
require dirname(__DIR__, 3) . '/后端代码/app/services/organization/StorePermissionCatalogServices.php';
require dirname(__DIR__, 3) . '/后端代码/app/http/middleware/cashier/CashierCheckRoleMiddleware.php';

use app\services\organization\StorePermissionCatalogServices as Catalog;
use app\http\middleware\cashier\CashierCheckRoleMiddleware;

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$defs = Catalog::definitions();
$check(($defs[301202]['feature_code'] ?? '') === 'cashier.v3.basic_reports', '独立功能码缺失');
$check(($defs[301202]['parent_id'] ?? 0) === -29023, '岗位功能不在基础报表分组');
$features = Catalog::explicitFeatures([301202]);
$check(in_array('cashier.v3.basic_reports', $features, true), '岗位授权不能解析新功能');
$check(!in_array('cashier.v3.data', $features, true), '新功能不应授予旧数据报表');
$navigation = Catalog::navigationItems();
$check(($navigation[-29023]['grantable'] ?? true) === false, '展示分组不能作为负编号保存');
$check(count(array_filter($navigation, static function ($row): bool {
    return ($row['parent_id'] ?? 0) === -29023 && !empty($row['navigation_key']);
})) === 21, '基础报表菜单应保留 21 个入口');
$rowsById = $defs + Catalog::navigationGroups() + $navigation;
// Initial installation moves the legacy top-level business nodes into the five display groups.
foreach ([301001=>-2801,301002=>-2801,301003=>-2801,301004=>-2801,301005=>-2801,
    301006=>-2806,301008=>-2801,301100=>-2802,301009=>-2801,301062=>-2804,
    301201=>-2804,301200=>-2804,301007=>-2805] as $id=>$parent) {
    $rowsById[$id]['parent_id'] = $parent;
}
$rows = array_values($rowsById);
$check(count((new Catalog())->validateRows($rows)) === count($rows), '完整权限目录校验失败');
$method = new ReflectionMethod(CashierCheckRoleMiddleware::class, 'directOperationFeature');
$method->setAccessible(true);
$middleware = new CashierCheckRoleMiddleware();
$check($method->invoke($middleware, 'cashierapi/v3/report/basic/query', 'GET') === 'cashier.v3.basic_reports', '基础查询接口鉴权错误');
$check($method->invoke($middleware, 'cashierapi/v3/report/unified/query', 'GET') === 'cashier.v3.data', '旧数据报表鉴权被扩大');
echo "PASS store basic report group grant contract\n";
