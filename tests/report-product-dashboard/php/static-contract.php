<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    $source = file_get_contents($root . '/' . $path);
    if ($source === false) {
        throw new RuntimeException('无法读取：' . $path);
    }
    return $source;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$route = $read('后端代码/route/admin.php');
$controller = $read('后端代码/app/controller/admin/v1/report/UnifiedReport.php');
$service = $read('后端代码/app/services/report/ProductManagementDashboardServices.php');
$view = $read('前端代码/cashier-v3/src/views/ProductDashboardView.vue');
$api = $read('前端代码/cashier-v3/src/services/productDashboardApi.js');

$assert(str_contains($route, "Route::get('product-dashboard', 'v1.report.UnifiedReport/productDashboard')"), '缺少商品看板统一查询路由');
$assert(str_contains($controller, "hasMenuPermission('admin-report-product-dashboard')"), '商品看板必须使用独立菜单权限');
$assert(str_contains($controller, 'scopedStoreIds('), '商品看板必须由服务端裁剪组织和门店范围');
$assert(str_contains($service, "DASHBOARD_CODE = 'product_management_dashboard'"), '缺少商品看板契约编码');
$assert(str_contains($service, "\$card['metric_code']") && str_contains($service, "\$card['value_cents']"), '顶部汇总必须读取统一指标契约字段');
$assert(str_contains($service, "'metric_version'"), '接口必须返回指标版本');
$assert(str_contains($service, "'aggregation_caught_up'"), '接口必须返回聚合追平状态');
$assert(str_contains($service, "'data_as_of'"), '接口必须返回数据截至时间');
$assert(str_contains($service, 'inventory_batch_movement_fact'), '库存必须从批次库存事实读取');
$assert(str_contains($service, "'f.fact_status', 'SETTLED'"), '库存必须只计算已结算事实');
$assert(str_contains($service, "'OVERDUE' => '已过期'"), '临期必须固定返回六档');
$assert(str_contains($service, "'UNKNOWN' => '到期日未知'"), '临期必须包含到期日未知档');
$assert(str_contains($service, 'inventory_cost_cents') && str_contains($service, ': null'), '成本无权限时必须使用 null 而非零值');
$assert(!str_contains($service, 'store_product_attr_value'), '不得回读当前商品成本价计算历史毛利');
$assert(str_contains($api, "'/adminapi/report/product-dashboard'"), '前端必须请求统一商品看板接口');
$assert(str_contains($api, "'/adminapi/report/unified/scope'"), '前端必须复用统一组织范围接口');
$assert(str_contains($view, 'queryProductDashboard('), '商品看板视图必须绑定真实查询');
$assert(str_contains($view, 'overviewCards.value = []'), '接口异常必须清空结果，不能回退模拟数据');
$assert(str_contains($view, '15 * 60 * 1000') && str_contains($view, "setInterval(() => loadDashboard('刷新')"), '商品看板必须每 15 分钟自动刷新当前筛选');
$assert(str_contains($view, 'clearInterval(refreshTimer)'), '商品看板卸载时必须清理自动刷新定时器');
$periodHandler = preg_match('/function selectPeriod\(value\)\s*\{(.*?)\n\}/s', $view, $periodMatch) ? $periodMatch[1] : '';
$assert(str_contains($periodHandler, "loadDashboard('查询')"), '预设日期切换必须立即应用筛选，不能只改变按钮选中态');
$assert(str_contains($periodHandler, "value === 'custom'"), '自定义日期必须保留起止日期后再查询');

echo "product dashboard static contract: PASS\n";
