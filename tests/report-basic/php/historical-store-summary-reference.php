<?php
declare(strict_types=1);

/** 仅在010补导封存库上运行原007实现，独立产生日期子区间的核对基准；禁止写库。 */
chdir('/www/wwwroot/007.cc3798.com');
require 'vendor/autoload.php';(new \think\App())->initialize();
date_default_timezone_set('Asia/Shanghai');
$credentials=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
$config=config('database');
$config['connections']['mysql']=array_merge($config['connections']['mysql'],[
    'hostname'=>'127.0.0.1','hostport'=>'3307','database'=>'mohe_010_final_20261031',
    'username'=>$credentials['user']??'root','password'=>$credentials['password']]);
\think\facade\Config::set($config,'database');unset($credentials,$config);
$db=\think\facade\Db::connect();
if($db->query('SELECT DATABASE() db')[0]['db']!=='mohe_010_final_20261031')throw new RuntimeException('WRONG_REFERENCE_DATABASE');
$db->execute('SET TRANSACTION READ ONLY');$db->startTrans();
try{
    $cases=[['2026-10-01','2026-10-02',[9]],['2026-10-15','2026-10-15',[1,2,3,4,6,7,8,9,10,11]],
        ['2026-10-31','2026-10-31',[2,4,10]]];$out=[];
    $keys=$db->name('salary_field')->whereFindInSet('table_ids',3)->column('key');
    $staff=$db->name('system_store_staff')->where('is_fencheng',1)->column('id');
    $sources=$db->name('cash_source')->whereNotIn('id',[6,7,11,12])->column('id');
    $products=$db->name('store_product_relation')->where('relation_id',78)->where('type',1)->column('product_id');
    $service=app()->make(\app\services\salary\SalaryTableServices::class);
    foreach($cases as [$start,$end,$ids]){
        $rows=[];$stores=$db->name('system_store')->whereIn('id',$ids)->field('id,name')->order('id')->select()->toArray();
        foreach($stores as $s)$rows[]=array_merge($s,$service->getStoreReport($s,
            str_replace('-','/',$start).' 00:00:00',str_replace('-','/',$end).' 23:59:59',$keys,$staff,
            app()->make(\app\services\yeji\YejiPkServices::class),app()->make(\app\services\report\ReportServices::class),
            $sources,$products,app()->make(\app\dao\yeji\StaffYejiDao::class),3));
        $out[]=['start'=>$start,'end'=>$end,'stores'=>$ids,'rows'=>$rows];
    }
    echo json_encode(['mode'=>'READ_ONLY','cases'=>$out],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
}finally{$db->rollback();}
