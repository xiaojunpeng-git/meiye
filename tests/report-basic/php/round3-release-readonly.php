<?php
/** 发布前候选只读探针：在独立 PHP 进程加载固定候选，仅输出字段、计数和摘要。 */
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$targets=['rh'=>['ruihao','3306',164],'008'=>['mohe_008','3307',113],'010'=>['mohe_010_app','3307',4]];
if(!isset($targets[$input['site']??'']))throw new RuntimeException('实例未授权');
[$database,$port,$store]=$targets[$input['site']];
require getcwd().'/vendor/autoload.php';
foreach($input['sources']??[] as $file=>$source){
    // 仅允许本轮四个领域/展示类；不覆盖环境、权限目录、路由或其他领域服务。
    if(!in_array($file,['BasicActivityReportServices.php','EmployeeItemSalesReportReadServices.php',
        'BasicReportHostServices.php','query/BasicRegistrationQueryRegistrar.php'],true))throw new RuntimeException('候选来源越界');
    $bytes=base64_decode($source['base64'],true);
    if($bytes===false||hash('sha256',$bytes)!==$source['sha256'])throw new RuntimeException('候选指纹错误');
    eval('?>'.$bytes);
}
(new think\App())->initialize();
$config=config('database.connections.mysql');
if($config['database']!==$database||(string)$config['hostport']!==$port
    ||$config['prefix']!=='eb_'||!in_array($config['hostname'],['localhost','127.0.0.1'],true))throw new RuntimeException('数据库身份错误');
think\facade\Db::execute('SET TRANSACTION READ ONLY');
think\facade\Db::startTrans();
try{
    $service=new app\services\report\BasicActivityReportServices();$reports=[];
    $range=['start_date'=>'2026-10-01','end_date'=>'2026-10-30','as_of_date'=>'2026-10-30','_report_scope'=>['mode'=>'stores']];
    foreach($service::codes() as $code){
        try{
            $result=$service->read($code,[$store],$range);
            $fields=array_values(array_filter($result['fields'],static fn($f)=>$f['defaultVisible']));
            // 回归比较所有已声明字段（含隐藏身份键）；内部未声明的临时计算键不构成报表契约。
            $contractKeys=array_fill_keys(array_column($result['fields'],'key'),true);
            $contractRows=array_map(static fn($row)=>array_intersect_key($row,$contractKeys),$result['rows']);
            $outside=0;
            foreach($result['rows'] as $row)if(isset($row['business_date'])&&($row['business_date']<'2026-10-01'||$row['business_date']>'2026-10-30'))++$outside;
            $reports[$code]=['rows'=>count($result['rows']),'labels'=>array_column($fields,'label'),
                'field_keys'=>array_column($fields,'key'),'fields_sha256'=>hash('sha256',json_encode($result['fields'])),
                'rows_sha256'=>hash('sha256',json_encode($contractRows)),'outside_business_date'=>$outside];
        }catch(Throwable $error){$reports[$code]=['error'=>get_class($error).':'.$error->getMessage()];}
    }
    $salary=array_values(array_filter((new app\services\report\BasicSalaryReportServices())->definitions('basic_salary_realtime'),static fn($f)=>$f['defaultVisible']));
    echo json_encode(['site'=>$input['site'],'database'=>$database,'store_id'=>$store,
        'migration'=>think\facade\Db::name('database_upgrade_log')->order('id','desc')->field('upgrade_key,code_version,executed_at')->find(),
        'reports'=>$reports,'salary_labels'=>array_column($salary,'label'),
        'registration_labels'=>array_column(app\services\report\query\BasicRegistrationQueryRegistrar::fields(),1),
        'business_writes'=>0],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
}finally{think\facade\Db::rollback();}
