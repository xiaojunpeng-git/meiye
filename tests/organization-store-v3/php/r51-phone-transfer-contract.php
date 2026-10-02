<?php
/** 路由权限和唯一写入路径合同；不得把静态结果当作真实调店成功。 */
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require $backend.'/vendor/autoload.php'; (new think\App($backend.'/'))->initialize();
$middleware=new app\http\middleware\cashier\CashierCheckRoleMiddleware();
$method=new ReflectionMethod($middleware,'directOperationFeature'); $method->setAccessible(true);
foreach(['GET','POST'] as $http) {
    if ($method->invoke($middleware,'cashierapi/v3/management/staff/phone-transfer',$http)!=='cashier.v3.management_center') throw new RuntimeException('not staff list permission');
}
$service=file_get_contents($backend.'/app/services/store/StoreStaffPhoneTransferServices.php');
$routes=file_get_contents($backend.'/route/cashier-v3.php');
$checks=[
    'shared list permission GET/POST'=>true,
    'route before general staff save'=>strpos($routes,"staff/phone-transfer")<strpos($routes,"Route::post('staff/:id'"),
    'unique transfer kernel'=>strpos($service,'->executeTransferKernel(')!==false,
    'shared strict idempotency'=>strpos($service,'OrganizationStrictIdempotencyServices::class)->run(')!==false,
    'positive employee version'=>strpos($service,'if ($version <= 0)')!==false,
    'frozen candidate protection'=>strpos($service,'hash_equals($current[\'snapshot_hash\']')!==false,
    'authentication revocation'=>strpos($service,'->afterEmployeeAuthChanged(')!==false,
    'no new transfer feature'=>strpos($service,'cashier.v3.staff.transfer')===false,
];
foreach($checks as $label=>$pass) { if(!$pass)throw new RuntimeException('FAIL '.$label); echo 'PASS '.$label.PHP_EOL; }
