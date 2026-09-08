<?php
declare(strict_types=1);
require __DIR__.'/http-actions-contract.php';
function config($key,$default=null) { return app()->config->get($key,$default); }
$seen=[];
$classes=[\app\http\middleware\InstallMiddleware::class,\app\http\middleware\AllowOriginMiddleware::class,
    \app\http\middleware\StationOpenMiddleware::class,\app\http\middleware\cashier\AuthTokenMiddleware::class,
    \app\http\middleware\cashier\ForceStoreSessionMiddleware::class];
foreach ($classes as $class) {
    $app->instance($class,new class($seen,$class) {
        private $seen; private $name;
        public function __construct(&$seen,$name) { $this->seen=&$seen; $this->name=$name; }
        public function handle($request,$next) { $this->seen[]=$this->name; return $next($request); }
    });
}
$guard=new \app\http\middleware\AiRequestGuardMiddleware();
$request=(new \app\Request())->setMethod('POST')->setPathinfo('cashierapi/v3/ai/runs')->withInput('{}');
$response=$guard->handle($request,function () { return json(['ok'=>true]); });
$check($seen===$classes,'Explicit install/origin/station/auth/session order');
$check($response->getHeader('Cache-Control')==='no-store','Guard responses are private');
$check(strpos($response->getHeader('Access-Control-Allow-Headers'),'X-Mohe-Ai-Run-Delivery-Token')!==false,'CORS permits proof headers');
$seen=[];
$request=(new \app\Request())->setMethod('POST')->setPathinfo('cashierapi/v3/ai/runs')->withHeader(['content-length'=>'262145'])->withInput('{}');
$response=$guard->handle($request,function () { throw new RuntimeException('Should not execute'); });
$check($response->getCode()===400 && $seen===[],'Wire size rejects before auth/JSON parsing');
$app->instance(\app\http\middleware\cashier\AuthTokenMiddleware::class,new class {
    public function handle($request,$next) { throw new RuntimeException('SECRET_AUTH_SQL_PROMPT'); }
});
$request=(new \app\Request())->setMethod('POST')->setPathinfo('cashierapi/v3/ai/runs')->withInput('{}');
$response=$guard->handle($request,function () { throw new RuntimeException('Should not execute'); });
$check($response->getCode()===400 && strpos(json_encode($response->getData()),'SECRET_AUTH')===false,'Authentication exception handled before generic report/logger');
echo "PASS 5 actual AI guard pipeline/boundary checks; middleware identities are test doubles.\n";
