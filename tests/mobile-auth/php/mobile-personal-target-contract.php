<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$migration = $root . '/后端代码/database/upgrades/2026-07-30-手机端个人月度目标V3/02-正式升级.sql';
$service = $root . '/后端代码/app/services/mobile/target/MobilePersonalMonthlyTargetServices.php';
$routes = $root . '/后端代码/route/api-mobile.php';
$controller = $root . '/后端代码/app/controller/mobile/merchant/PersonalMonthlyTarget.php';
$resolver = $root . '/后端代码/app/services/mobile/merchant/MobileMerchantRequestContextResolver.php';
foreach ([$migration, $service, $routes, $controller, $resolver] as $file) if (!is_file($file)) throw new RuntimeException('Missing target contract file: ' . $file);
$sql = file_get_contents($migration);
$code = file_get_contents($service);
$routeCode = file_get_contents($routes);
$controllerCode = file_get_contents($controller);
$resolverCode = file_get_contents($resolver);
foreach (['mobile_personal_monthly_target', 'mobile_personal_monthly_target_line', 'mobile_personal_monthly_target_command', 'mobile_personal_monthly_target_audit', 'uk_employee_staff_store_month', 'uk_employee_command_key'] as $needle) if (strpos($sql, $needle) === false) throw new RuntimeException('Missing migration rule: ' . $needle);
foreach (['SALES_PERFORMANCE', 'CONSUMPTION_PERFORMANCE', 'SERVICE_VISITS', 'METRIC_INTERFACE_PENDING', 'expectedVersion', 'idempotencyKey', 'mobile_personal_monthly_target_command'] as $needle) if (strpos($code, $needle) === false) throw new RuntimeException('Missing target service rule: ' . $needle);
foreach (["Db::name('store_target')", 'StaffYeji', 'StoreTargetServices'] as $forbidden) if (strpos($code, $forbidden) !== false) throw new RuntimeException('Legacy target writer leaked into mobile V3: ' . $forbidden);
foreach (['personal-monthly-target/current', "PersonalMonthlyTarget/current", 'personal-monthly-target', "PersonalMonthlyTarget/save"] as $needle) if (strpos($routeCode, $needle) === false) throw new RuntimeException('Missing target route: ' . $needle);
foreach (['TARGET_PERSONAL_VIEW', 'TARGET_PERSONAL_MANAGE', 'MobileApiResponse::MERCHANT_CONTRACT'] as $needle) if (strpos($controllerCode, $needle) === false) throw new RuntimeException('Missing target controller rule: ' . $needle);
if (strpos($resolverCode, 'MobileMerchantCapabilityCatalog::class') === false) throw new RuntimeException('Target actions must use the authoritative Vue 3 merchant capability catalogue.');
echo "MOBILE_PERSONAL_TARGET_CONTRACT=PASS\n";
