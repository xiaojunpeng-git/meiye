<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $relative) use ($root): string {
    $content = file_get_contents($root . '/' . $relative);
    if ($content === false) {
        throw new RuntimeException('cannot read ' . $relative);
    }
    return $content;
};
$mustContain = static function (string $content, string $needle, string $message): void {
    if (strpos($content, $needle) === false) {
        throw new RuntimeException($message . ': missing ' . $needle);
    }
};

$service = $read('后端代码/app/services/store/ServiceNameSettingsServices.php');
$controller = $read('后端代码/app/controller/store/system/ServiceNames.php');
$routes = $read('后端代码/route/cashier-v3.php');
$migration = $read('后端代码/database/upgrades/2026-10-18-门店服务名称基础资料/02-正式升级.sql');

foreach (['cashier_v3_service_name', 'cashier_v3_service_name_setting', 'cashier_v3_service_name_audit', "where('store_id', \$storeId)", 'Db::transaction', 'expectedVersion', 'requestToken', 'request_fingerprint', 'version', 'setEnabled', 'reorder', 'setSwitch', 'switchState', 'sort_order', 'is_enabled'] as $needle) {
    $mustContain($service, $needle, 'service write boundary incomplete');
}
foreach (['currentStoreId()', 'currentOperator()', 'ServiceNameSettingsServices', 'setSwitch'] as $needle) {
    $mustContain($controller, $needle, 'controller must derive scope and operator from the current request');
}
foreach (['AuthTokenMiddleware::class', 'ForceStoreSessionMiddleware::class', 'CashierCheckRoleMiddleware::class', 'store.system.ServiceNames/index', 'store.system.ServiceNames/setSwitch'] as $needle) {
    $mustContain($routes, $needle, 'route authorization contract incomplete');
}
foreach (['cashier_v3_service_name_setting', 'uk_store_service_name', 'uk_store_request_token', 'old_snapshot_json', 'new_snapshot_json', 'sort_order', 'is_enabled', 'idx_store_status_sort', '20261018-001-store-service-name-master'] as $needle) {
    $mustContain($migration, $needle, 'migration persistence contract incomplete');
}
foreach (['cashier_v3_reservation', 'cashier_v3_service_order', 'store_order', 'store_cart'] as $forbidden) {
    if (strpos($service, $forbidden) !== false) {
        throw new RuntimeException('service name persistence must not link business table: ' . $forbidden);
    }
}

echo "SERVICE_NAME_SETTINGS_CONTRACT_OK\n";
