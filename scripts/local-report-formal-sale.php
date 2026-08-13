<?php
declare(strict_types=1);

// Local-only QA helper. It drives the production V3 dispatcher; it never writes
// sale/payment/report facts directly and intentionally leaves the test order.
require '/var/www/html/vendor/autoload.php';
$app = new \think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');
$app->initialize();

use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\CashierV3StateContextServices;
use app\services\cashier\v3\CashierV3IdempotencyKeyServices;
use app\services\cashier\v3\CashierV3ResourceScope;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\settlement\CashierV3CheckoutProjectionServices;
use app\services\cashier\v3\settlement\ThinkPhpCashierV3CheckoutResultReadRepository;
use think\facade\Db;

$storeId = 118;
$operatorId = 654;
$employeeId = 788;
$memberId = 913081;
$skuId = 95035;
$profile = ['id'=>$operatorId,'store_id'=>$storeId,'level'=>0,'roles'=>[1], 'employee_id'=>$employeeId, 'account'=>'A13637098083'];
$dispatcher = CashierV3Bootstrap::dispatcher();
function qaUuid(): string { return sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0,0xffffffff), random_int(0,0xffff), random_int(0,0xfff), random_int(0x8000,0xbfff), random_int(0,0xffffffffffff)); }
$keyServices = app()->make(CashierV3IdempotencyKeyServices::class);
$stateServices = new CashierV3StateContextServices($keyServices);
$session = ['store_id'=>$storeId,'operator_id'=>$operatorId,'operator_profile'=>$profile,'client_session_id'=>'SESSION-'.sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0,0xffffffff), random_int(0,0xffff), random_int(0,0xfff), random_int(0x8000,0xbfff), random_int(0,0xffffffffffff)),'operator_ip'=>'127.0.0.1'];
$state = $stateServices->resolve($storeId, $operatorId, $session['client_session_id'], '');
$session['state_context_id'] = $state['state_context_id'];
$workspaceId = sprintf('ws:%d:%d:%s', $storeId, $operatorId, $state['state_context_id']);
Db::transaction(function () use ($dispatcher, $workspaceId, $storeId) {
    $dispatcher->versionServices()->ensureRegistered(CashierV3ResourceScope::of('store', (string)$storeId), 'cashier_workspace', $workspaceId);
});

function qaBody(string $action, array $payload, string $workspaceId, int $version, array $session, string $key, string $requestId = '', int $requestVersion = 0): array {
    $contexts = [['kind'=>'cashier_workspace','id'=>$workspaceId,'expectedVersion'=>$version]];
    if ($requestId !== '') $contexts[] = ['kind'=>'checkout_request','id'=>$requestId,'expectedVersion'=>$requestVersion];
    return array_merge($payload, ['action'=>$action,'clientSessionId'=>$session['client_session_id'],'stateContextId'=>$session['state_context_id'],'correlationId'=>'QA-CORR-'.bin2hex(random_bytes(8)),'command'=>['action'=>$action,'idempotencyKey'=>$key,'contexts'=>$contexts]]);
}
function qaVersion(string $kind, string $id): int { return (int)Db::name('cashier_v3_resource_version')->where('resource_kind',$kind)->where('resource_id',$id)->value('current_version'); }
function qaDispatch($dispatcher, array $body, array $session): array { $result=$dispatcher->dispatch($body,$session); if (($result['result']['status']??'')!=='success') throw new RuntimeException(json_encode($result,JSON_UNESCAPED_UNICODE)); return $result; }
function qaProjection($service, string $workspaceId, array $session, $operatorScope, $dataScope): array { $p=$service->readCurrent($workspaceId,$session['state_context_id'],$operatorScope,$dataScope); if (!is_array($p)) throw new RuntimeException('checkout_projection_missing'); return $p; }

$openBody=['action'=>'open-cashier-workbench','silent'=>true,'clientSessionId'=>$session['client_session_id'],'stateContextId'=>$session['state_context_id'],'correlationId'=>'CORR-'.qaUuid()];
qaDispatch($dispatcher, $openBody, $session);
$operatorScope = new CashierV3OperatorScope($storeId, $operatorId, '', '0');
$dataScope = $dispatcher->dataScopeFactory()->build($storeId, $operatorId, $profile, '0', '');
$projectionService = new CashierV3CheckoutProjectionServices(null, new ThinkPhpCashierV3CheckoutResultReadRepository());
$workspaceVersion = qaVersion('cashier_workspace', $workspaceId);
qaDispatch($dispatcher, qaBody('select-cashier-member', ['selectorEntry'=>'cashier','memberId'=>(string)$memberId], $workspaceId, $workspaceVersion, $session, 'CMD-'.qaUuid()), $session);
$workspaceVersion = qaVersion('cashier_workspace', $workspaceId);
qaDispatch($dispatcher, qaBody('choose-catalog-item', ['itemId'=>$skuId,'catalogKind'=>'项目'], $workspaceId, $workspaceVersion, $session, 'CMD-'.qaUuid()), $session);
$workspaceVersion = qaVersion('cashier_workspace', $workspaceId);
$prepKey='CHECKOUT_PREPARE-'.qaUuid();
qaDispatch($dispatcher, qaBody('prepare-checkout', ['preparationRequestId'=>$prepKey], $workspaceId, $workspaceVersion, $session, $prepKey), $session);
$workspaceVersion = qaVersion('cashier_workspace', $workspaceId);
$checkout=qaProjection($projectionService,$workspaceId,$session,$operatorScope,$dataScope); $requestId=(string)$checkout['checkoutRequestId']; $requestVersion=(int)$checkout['checkoutRequestVersion'];
$common=['checkoutRequestId'=>$requestId,'checkoutRequestVersion'=>$requestVersion,'preparationRequestId'=>(string)$checkout['preparationRequestId'],'preparationToken'=>(string)$checkout['preparationToken']];
qaDispatch($dispatcher, qaBody('add-payment-method', $common+['paymentMethodId'=>'wechat'], $workspaceId, $workspaceVersion, $session, 'CMD-'.qaUuid(), $requestId, $requestVersion), $session);
$workspaceVersion=qaVersion('cashier_workspace',$workspaceId); $checkout=qaProjection($projectionService,$workspaceId,$session,$operatorScope,$dataScope); $requestVersion=(int)$checkout['checkoutRequestVersion'];
$common=['checkoutRequestId'=>$requestId,'checkoutRequestVersion'=>$requestVersion,'preparationRequestId'=>(string)$checkout['preparationRequestId'],'preparationToken'=>(string)$checkout['preparationToken']];
$subPrep='CHECKOUT_PREPARE-'.qaUuid(); qaDispatch($dispatcher, qaBody('prepare-checkout-submission',$common,$workspaceId,$workspaceVersion,$session,$subPrep,$requestId,$requestVersion),$session);
$workspaceVersion=qaVersion('cashier_workspace',$workspaceId); $checkout=qaProjection($projectionService,$workspaceId,$session,$operatorScope,$dataScope); $requestVersion=(int)$checkout['checkoutRequestVersion'];
$common=['checkoutRequestId'=>$requestId,'checkoutRequestVersion'=>$requestVersion,'preparationRequestId'=>(string)$checkout['preparationRequestId'],'preparationToken'=>(string)$checkout['preparationToken']];
$submitKey='CHECKOUT-'.qaUuid(); $submitted=qaDispatch($dispatcher, qaBody('submit-checkout',$common,$workspaceId,$workspaceVersion,$session,$submitKey,$requestId,$requestVersion),$session);
echo json_encode(['request_id'=>$requestId,'submit_key'=>$submitKey,'result'=>$submitted['data']['checkoutSubmission']??$submitted],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
