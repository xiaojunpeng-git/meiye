<?php
/** Local real-MySQL regression for the existing member debt entry. Fixtures
 * live only in connection-local temporary tables; no repayment command runs. */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');$app->env->load('/var/www/html/.env');$app->initialize();
if (getenv('MEMBER_DEBT_LOCAL_TEST') !== '1' || config('database.connections.mysql.hostname') !== 'mysql' || config('database.connections.mysql.database') !== 'ruihao') throw new RuntimeException('Local Docker only');
use think\facade\Db;
function debtCheck(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);echo 'PASS ', $message,PHP_EOL;}
/** Stream baseline rows rather than materializing an entire local debt history. */
function debtDigest(string $table): string {
    $pdo=Db::connect()->getPdo();$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    $statement=$pdo->query('SELECT * FROM eb_'.$table.' ORDER BY id');$digest=hash_init('sha256');
    while($row=$statement->fetch(PDO::FETCH_ASSOC))hash_update($digest,json_encode($row)."\n");
    $statement->closeCursor();$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
    return hash_final($digest);
}
class MemberDebtRequest extends app\Request {
    public $values=[];
    public function getMore(array $params,bool $isList=false,bool $filter=true): array {
        $out=[];foreach($params as $p)$out[$p[0]]=$this->values[$p[0]]??$p[1];return $isList?array_values($out):$out;
    }
}
$before=[];
Db::query('SELECT 1');
foreach(['store_debt','store_debt_item'] as $t){
    $before[$t]=debtDigest($t);
    $ddl=Db::query('SHOW CREATE TABLE eb_'.$t)[0]['Create Table'];
    Db::execute(preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl,1));
}
$uid=987654321;
for($i=1;$i<=27;$i++){
    Db::name('store_debt')->insert(['id'=>$i,'debt_no'=>'DEBT-LOCAL-'.$i,'order_id'=>2140000000+$i,'order_sn'=>'DEBT-ORDER-'.$i,'uid'=>$i===26?$uid+1:$uid,'store_id'=>0,'staff_id'=>0,'total_debt'=>100,'repaid_debt'=>$i===27?100:10,'status'=>$i===27?1:0,'add_time'=>time(),'update_time'=>time()]);
    Db::name('store_debt_item')->insert(['id'=>$i,'debt_id'=>$i,'order_id'=>2140000000+$i,'product_name'=>'LOCAL DEBT ITEM','debt_amount'=>100,'repaid_debt'=>$i===27?100:10,'add_time'=>time(),'update_time'=>time()]);
}
$request=new MemberDebtRequest();$request->uid=$uid;$app->instance('request',$request);
$service=$app->make(app\services\order\StoreDebtServices::class);
$controller=new app\controller\api\v1\order\StoreDebt();
$invoke=static function(array $values=[])use($controller,$request,$service):array{$request->values=$values;return $controller->lst($request,$service)->getData();};
$first=$invoke();
debtCheck($first['status']===200 && count($first['data']['list'])===20 && $first['data']['count']===25,'Existing controller and real SQL return first 20 of 25 pending records');
$second=$invoke(['page'=>2,'limit'=>20]);
debtCheck(count($second['data']['list'])===5 && $second['data']['count']===25,'Second page keeps complete authorized count');
foreach(array_merge($first['data']['list'],$second['data']['list']) as $r){
    debtCheck($r['uid']===$uid && $r['pending_debt']===90.0 && !$r['can_online_repay'],'Original member scope, debt amount and missing-order repayment guard preserved');
}
$clamped=$invoke(['page'=>-1,'limit'=>1000]);
debtCheck(count($clamped['data']['list'])===25,'Controller clamps negative page and excessive limit');
$request->uid=$uid+2;$empty=$invoke();
debtCheck($empty['data']['list']===[] && $empty['data']['count']===0,'Member without debt receives genuine empty success');
$request->uid=$uid;$summary=$controller->summary($request,$service)->getData();
debtCheck($summary['data']['count']===25 && $summary['data']['total_pending']===2250.0,'Existing debt summary remains unchanged');
foreach(['store_debt','store_debt_item'] as $t){Db::execute('DROP TEMPORARY TABLE eb_'.$t);debtCheck($before[$t]===debtDigest($t),'Permanent local '.$t.' unchanged');}
echo "MEMBER_DEBT_BACKEND_PASS\n";
