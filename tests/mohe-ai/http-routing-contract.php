<?php
declare(strict_types=1);

// Load class mappings only: no project bootstrap, .env, database, provider or service initialization.
$backend=__DIR__.'/../../后端代码';
require_once $backend.'/vendor/composer/ClassLoader.php';
$loader=new \Composer\Autoload\ClassLoader();
foreach (require $backend.'/vendor/composer/autoload_psr4.php' as $prefix=>$paths) { $loader->setPsr4($prefix,$paths); }
$loader->addPsr4('app\\',$backend.'/app'); $loader->register();
error_reporting(E_ALL & ~E_DEPRECATED);
$app=new \think\App(sys_get_temp_dir().'/mohe-ai-route-no-application');
$app->config->set([], 'route');
$router=new class($app) extends \think\Route {
    public function matchOnly(\think\Request $request) {
        $this->request=$request; $this->host='localhost'; $this->init();
        return $this->check();
    }
};
$app->instance('think\\Route',$router);
require $backend.'/route/a-ai.php';
// Reproduce later legacy catch-all groups; AI routes must win registration order.
\think\facade\Route::group('adminapi',function () { \think\facade\Route::miss('Legacy/miss'); });
\think\facade\Route::group('cashierapi',function () { \think\facade\Route::miss('Legacy/miss'); });
\think\facade\Route::any('api/mobile/:path','Legacy/miss')->pattern(['path'=>'.*']);
$checks=0;
foreach (['adminapi/ai'=>'admin.v1.ai.','cashierapi/v3/ai'=>'cashier.v3.','api/mobile/merchant/ai'=>'mobile.merchant.'] as $base=>$controllerPrefix) {
    foreach ([['GET','bootstrap','aiBootstrap'],['POST','runs','aiCreate'],['PUT','config','aiConfigSave'],['POST','config/check','aiConfigCheck'],['GET','runs/'.str_repeat('a',48),'aiStatus'],['POST','runs/'.str_repeat('a',48).'/delivery','aiDelivery'],['POST','runs/'.str_repeat('a',48).'/execute','aiExecute'],['POST','runs/'.str_repeat('a',48).'/cancel','aiCancel'],['POST','runs/'.str_repeat('a',48).'/clarify','aiClarify'],['GET','runs/'.str_repeat('a',48).'/export','aiExport'],['POST','runs/'.str_repeat('a',48).'/export','aiExportCreate'],['GET','runs/'.str_repeat('a',48).'/export-status','aiExportStatus']] as $case) {
        $request=new \think\Request(); $request->setMethod($case[0])->setPathinfo($base.'/'.$case[1])->setHost('localhost');
        $dispatch=$router->matchOnly($request);
        $expected=[$controllerPrefix.'Ai',$case[2]];
        if ($dispatch->getDispatch()!==$expected) { throw new RuntimeException('Wrong route: '.$base.'/'.$case[1].' => '.var_export($dispatch->getDispatch(),true)); }
        ++$checks;
    }
    $request=new \think\Request(); $request->setMethod('OPTIONS')->setPathinfo($base.'/bootstrap')->setHost('localhost');
    if (!$router->matchOnly($request)->getDispatch() instanceof Closure) { throw new RuntimeException('Missing AI preflight route'); } ++$checks;
}
$request=new \think\Request();$request->setMethod('POST')->setPathinfo('adminapi/ai/management/rebase')->setHost('localhost');
if($router->matchOnly($request)->getDispatch()!==['admin.v1.ai.Ai','aiManagementRebase']) throw new RuntimeException('Missing admin management rebase route');
++$checks;
$request=new \think\Request();$request->setMethod('GET')->setPathinfo('adminapi/ai/metric-registry')->setHost('localhost');
if($router->matchOnly($request)->getDispatch()!==['admin.v1.ai.Ai','aiMetricRegistryGet']) throw new RuntimeException('Missing admin metric registry route');
++ $checks;
echo 'PASS '.$checks." real ThinkPHP route matches (no application/DB/model boot).\n";
