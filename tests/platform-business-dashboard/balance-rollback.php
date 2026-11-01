<?php
declare(strict_types=1);
/** Synthetic local facts cover non-zero recharge and principal+bonus debit; every insert rolls back. */
require '/var/www/html/vendor/autoload.php';
(new think\App('/var/www/html/'))->initialize();
use think\facade\Db;
use app\services\statistics\PlatformBusinessDashboardServices;
if(config('database.connections.mysql.hostname')!=='mysql'||config('database.connections.mysql.database')!=='ruihao')throw new RuntimeException('local database only');
$prefix='r10-dashboard-test-'.bin2hex(random_bytes(6));
Db::startTrans();
try {
    $sample=Db::name('cashier_v3_balance_fact')->where('status','effective')->limit(1)->select()->toArray()[0];
    unset($sample['id']);
    foreach(['recharge_credit','order_payment'] as $type){
        $row=$sample;
        foreach(['fact_id','natural_key','business_event_no','command_idempotency_key','checkout_request_id','order_id','source_line_id'] as $key)$row[$key]=$prefix.'-'.$type;
        $row['tenant_id']='0';$row['store_id']=175;$row['business_date']='2099-01-01';
        $row['source_document_type']=$type==='recharge_credit'?'recharge':'cashier_snapshot';
        $row['balance_change_type']=$type;
        $row['principal_delta_cents']=$type==='recharge_credit'?37800:-19800;
        $row['bonus_delta_cents']=$type==='recharge_credit'?9200:-2200;
        $row['immutable_fingerprint']=hash('sha256',json_encode($row));
        Db::name('cashier_v3_balance_fact')->insert($row);
    }
    $s=new PlatformBusinessDashboardServices();$start=strtotime('2099-01-01');
    foreach(['recharge_amount'=>'378.00','balance_deduction_amount'=>'220.00'] as $metric=>$expected){
        $result=$s->moneyMetricDetail([175],$metric,$start,$start+86399,'',1,20);
        if($result['total']!==$expected||count($result['list'])!==1||$result['list'][0]['amount']!==$expected)throw new RuntimeException($metric.' principal/bonus boundary');
        $trend=$s->trend([175],$metric,$start,$start+86399);
        if($trend['series']!==[$expected])throw new RuntimeException($metric.' trend differs');
        echo "PASS $metric nonzero principal/bonus detail, total and trend\n";
    }
} finally {Db::rollback();}
if(Db::name('cashier_v3_balance_fact')->where('fact_id','like',$prefix.'%')->count()!==0)throw new RuntimeException('rollback left facts');
echo "PASS all synthetic facts rolled back\n";
