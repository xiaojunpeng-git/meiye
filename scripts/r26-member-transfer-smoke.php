<?php
declare(strict_types=1);
/** 发布后只读业务核验：调用真实预览服务并核对计数，不创建批次、不投递任务、不转移会员。 */
$site=$argv[1]??'';
$roots=['rh'=>['/www/wwwroot/rh.cc3798.com','ruihao','3306'],'008'=>['/www/wwwroot/008.cc3798.com','mohe_008','3307']];
if(!isset($roots[$site]))throw new RuntimeException('Explicit instance required');
[$root,$database,$port]=$roots[$site];chdir($root);
require $root.'/vendor/autoload.php';(new think\App($root.'/'))->initialize();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
if(config('database.connections.mysql.database')!==$database||(string)config('database.connections.mysql.hostport')!==$port)throw new RuntimeException('Wrong database');
use think\facade\Db;
Db::execute('SET TRANSACTION READ ONLY');Db::startTrans();
try{
 $admin=(int)Db::name('system_admin')->where('status',1)->where('is_del',0)->value('id');
 $store=(int)Db::name('system_store')->where('is_del',0)->where('is_show',1)->value('id');
 if(!$admin||!$store)throw new RuntimeException('No active admin/store for preview');
 $service=new app\services\user\UserStoreTransferBatchServices();$preview=$service->preview($store,$admin);
 $expected=(int)Db::name('user')->where('belong_store_id',$store)->where('is_del',0)->whereNull('delete_time')->count();
 if($preview['count']!==$expected||in_array($store,array_map('intval',array_column($preview['stores'],'id')),true))throw new RuntimeException('Preview contract mismatch');
 if(!class_exists(app\jobs\user\UserStoreTransferBatchJob::class))throw new RuntimeException('Queue job unavailable');
 $queue=(string)config('queue.connections.'.config('queue.default').'.batch_queue');
 if($queue==='')throw new RuntimeException('Missing batch queue');
 echo json_encode(['instance'=>$site,'result'=>'PASS','previewCountMatches'=>true,'targetExcludesSource'=>true,'queueClassLoaded'=>true,'queue'=>$queue,'writes'=>0]),PHP_EOL;
}finally{Db::rollback();}
