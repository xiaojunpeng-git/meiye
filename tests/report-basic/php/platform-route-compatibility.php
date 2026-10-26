<?php
declare(strict_types=1);

// 只测旧平台路由所需的权限投影，不连接数据库或修改岗位授权。
$root = dirname(__DIR__, 3);
require $root . '/后端代码/vendor/autoload.php';
require $root . '/后端代码/app/services/system/SystemMenusServices.php';

$method = new ReflectionMethod(\app\services\system\SystemMenusServices::class, 'withBasicReportRouteAuth');
$project = static function (array $auth) use ($method): array {
    return $method->invoke(null, $auth);
};
$expect = static function (bool $pass, string $case): void {
    if (!$pass) throw new RuntimeException($case);
};

$single = $project(['admin-report-basic_employee_item_sales']);
$expect($single === ['admin-report-basic_employee_item_sales', 'admin-report-basic'], '单表叶子未获得旧路由令牌');
$expect($project($single) === $single, '路由令牌重复添加');
$expect($project(['admin-report-basic_group-employee']) === ['admin-report-basic_group-employee'], '仅目录被误当成查询授权');
$expect($project(['admin-report-unknown']) === ['admin-report-unknown'], '未知报表被误授权');
$expect($project([]) === [], '空权限被误授权');
$expect($project(['admin-report-basic']) === ['admin-report-basic'], '已有旧路由令牌被改动');

foreach (\app\services\report\BasicReportCatalogServices::entries() as $entry) {
    $leaf = 'admin-report-' . $entry['code'];
    $expect($project([$leaf]) === [$leaf, 'admin-report-basic'], '目录叶子未覆盖：' . $leaf);
}

echo "PASS 21 basic-report route grants and denied non-leaf grants\n";
