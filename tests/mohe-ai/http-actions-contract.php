<?php
declare(strict_types=1);
require __DIR__.'/http-routing-contract.php';
function app($name=null) { $container=\think\Container::getInstance(); return $name===null?$container:$container->make($name); }
function json($data=[],int $code=200) { return \think\Response::create($data,'json',$code); }
$gateway=new class {
    public $calls=[]; public $throws=false;
    public function handle($operation,$context,$input,$runId) {
        if ($this->throws) { throw new RuntimeException(is_string($this->throws)?$this->throws:'SECRET_SQL_PROMPT'); }
        $this->calls[]=[$operation,$context,$input,$runId]; return ['accepted'=>true];
    }
};
$app->instance(\app\services\ai\AiGatewayServices::class,$gateway);
$app->instance('json',new class {
    public function success($message,$data) { return json(['status'=>200,'msg'=>$message,'data'=>$data]); }
    public function fail($message,$data=[]) { return json(['status'=>400,'msg'=>$message,'data'=>$data],400); }
});
$controller=new class {
    use \app\controller\ai\AiHttpActions;
    public $request; public $refreshes=0; public $mobile=false; public $contextFailure=null;
    protected function aiGateway() { return app()->make(\app\services\ai\AiGatewayServices::class); }
    protected function buildAiContext() { if ($this->contextFailure) throw $this->contextFailure; return ['account_id'=>1,'terminal'=>'platform','can_use'=>true]; }
    protected function refreshAiContext() { ++$this->refreshes; return $this->buildAiContext(); }
    protected function isMobileAi() { return $this->mobile; }
};
$checks=0;
$check=function ($condition,$label) use (&$checks) { if (!$condition) { throw new RuntimeException($label); } ++$checks; };
$controller->request=(new \think\Request())->setMethod('PUT')->withInput('{"enabled":true,"model":"fixture"}');
$response=$controller->aiConfigSave();
$check($gateway->calls[0][0]==='config_save' && $gateway->calls[0][2]['enabled']===true,'PUT JSON body');
$gateway->calls[0][1]['_refresh'](); $check($controller->refreshes===1,'Fresh context callback');
$check($response->getHeader('Cache-Control')==='no-store','Response is not cached');
$controller->request=(new \think\Request())->setMethod('GET')->withHeader(['x-mohe-ai-client-session-id'=>'session','x-mohe-ai-run-delivery-token'=>'proof','x-mohe-ai-generation'=>'3']);
$controller->aiStatus(str_repeat('a',48));
$check($gateway->calls[1][2]===['client_session_id'=>'session','run_delivery_token'=>'proof','generation'=>'3'],'GET proof headers');
$controller->request=(new \think\Request())->setMethod('POST')->withInput('{"client_session_id":"session","run_delivery_token":"proof","generation":3,"client_elapsed_ms":1200}');
$controller->aiDelivery(str_repeat('a',48));
$check($gateway->calls[2][0]==='delivery' && ($gateway->calls[2][2]['client_elapsed_ms']??null)===1200,'Browser-visible timing accepts only JSON body telemetry');
$controller->request=(new \think\Request())->setMethod('GET')->withGet(['run_delivery_token'=>'query-secret']);
$check($controller->aiStatus(str_repeat('a',48))->getCode()===400 && count($gateway->calls)===3,'Query proof rejected');
foreach (['[]','{bad',str_repeat('x',262145)] as $raw) {
    $controller->request=(new \think\Request())->setMethod('POST')->withInput($raw);
    $check($controller->aiCreate()->getCode()===400 && count($gateway->calls)===3,'Malformed/oversized body rejected');
}
$controller->request=(new \think\Request())->setMethod('POST')->withInput('{}'); $gateway->throws=true;
$response=$controller->aiCreate();
$check(strpos(json_encode($response->getData()),'SECRET_SQL_PROMPT')===false,'Exception is redacted');
$gateway->throws='AI_PERMISSION_DENIED';
$response=$controller->aiCreate();
$data=$response->getData();
$check(($data['data']['error_code']??null)==='AI_PERMISSION_DENIED','Known AI denial is safely classified for browser navigation');
foreach ([\app\controller\admin\v1\ai\Ai::class,\app\controller\cashier\v3\Ai::class,\app\controller\mobile\merchant\Ai::class] as $class) {
    $check(class_exists($class) && method_exists($class,'aiExecute') && method_exists($class,'aiManagementRebase'),'Actual controller inheritance loads');
}
// Expired merchant auth fails before any query, preserving the shared public
// contract without exposing arbitrary exceptions from the AI gateway.
$controller->mobile=true;
$controller->request->setPathinfo('api/mobile/merchant/ai/runs')->withHeader(['x-mobile-request-id'=>'fixture-request']);
$controller->contextFailure=\app\services\mobile\protocol\MobileApiException::business('MERCHANT_SESSION_EXPIRED','商家会话已失效，请重新进入。','SESSION_TIMEOUT');
foreach (['aiCreate','aiBootstrap'] as $action) {
    $before=count($gateway->calls); $response=$controller->$action(); $data=$response->getData();
    $check($response->getCode()===401 && $data['errorCode']==='MERCHANT_SESSION_EXPIRED','Expired login remains a 401 mobile contract error');
    $check($data['sessionEndCause']==='SESSION_TIMEOUT' && $data['requestId']==='fixture-request','Session cause and request tracing survive AI handling');
    $check(count($gateway->calls)===$before && $response->getHeader('Cache-Control')==='no-store','Expired login never reaches data/model gateway or cache');
}
$controller->contextFailure=null; $gateway->throws=true;
$data=$controller->aiCreate()->getData();
$check($data['errorCode']==='AI_REQUEST_FAILED' && strpos(json_encode($data),'SECRET_SQL_PROMPT')===false,'Mobile raw exceptions still redacted');
echo 'PASS '.$checks." HTTP action assertions; no DB/model/application boot.\n";
