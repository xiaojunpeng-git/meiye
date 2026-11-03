<?php
/** Executes the real export helper with a deterministic query adapter, without connecting to a database. */
namespace think\exception { class ValidateException extends \RuntimeException {} }
namespace {
require dirname(__DIR__,2).'/后端代码/app/services/activity/combination/CombinationPromotionServices.php';
use app\services\activity\combination\CombinationPromotionServices;
final class R44ExportCollection { private $rows; function __construct(array $rows){$this->rows=$rows;} function toArray(){return $this->rows;} }
final class R44ExportConnection {
    public $events=[],$active=false,$failAtBatch=0,$batches=0,$sizes=[];
    function getPdo(){return $this;} function inTransaction(){return $this->active;}
    function execute($sql){$this->events[]=$sql;} function startTrans(){$this->active=true;$this->events[]='begin';}
    function commit(){$this->active=false;$this->events[]='commit';} function rollback(){$this->active=false;$this->events[]='rollback';}
}
final class R44ExportQuery {
    private $rows,$connection,$aggregate=false,$cursor=null,$limit=0;
    function __construct(array $rows,$connection){$this->rows=$rows;$this->connection=$connection;}
    function getConnection(){return $this->connection;} function count(){return count($this->rows);}
    function field($sql){$this->aggregate=true;return $this;}
    function whereRaw($sql,$bindings){$this->cursor=$bindings;return $this;}
    function order($value){if($value!=='paid_at desc,id desc')throw new \RuntimeException('unstable order');return $this;}
    function limit($value){$this->limit=$value;return $this;}
    function select(){
        if(!$this->connection->active)throw new \RuntimeException('read outside export snapshot');
        if($this->aggregate)return new R44ExportCollection([['paid_amount'=>array_sum(array_column($this->rows,'paid_cents')),'refund_amount'=>array_sum(array_column($this->rows,'refund_cents')),'net_amount'=>array_sum(array_column($this->rows,'net_cents'))]]);
        ++$this->connection->batches;
        if($this->connection->failAtBatch===$this->connection->batches)throw new \RuntimeException('batch failure');
        $rows=array_filter($this->rows,function($r){return !$this->cursor||$r['paid_at']<$this->cursor['export_before_time']||($r['paid_at']===$this->cursor['export_same_time']&&$r['id']<$this->cursor['export_before_id']);});
        usort($rows,function($a,$b){return ($b['paid_at']<=>$a['paid_at'])?:($b['id']<=>$a['id']);});
        $rows=array_slice($rows,0,$this->limit);$this->connection->sizes[]=count($rows);return new R44ExportCollection($rows);
    }
}
function r44Check($condition,$message){if(!$condition)throw new \RuntimeException($message);}
$service=new CombinationPromotionServices();$method=new \ReflectionMethod($service,'exportDetails');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
$rows=[];for($i=1;$i<=10005;$i++)$rows[]=['id'=>$i,'order_no'=>'O'.$i,'paid_at'=>1700000000+intdiv($i,3),'group_status'=>$i%2?'formed':'failed','paid_cents'=>101,'refund_cents'=>1,'net_cents'=>100];
$connection=new R44ExportConnection();$out=$method->invoke($service,new R44ExportQuery($rows,$connection),['activity_ids'=>'2,3','status'=>'unformed','page'=>3,'uid'=>999]);
r44Check(count($out['export'])===10005&&$out['count']===10005,'all queried rows exported beyond former10000 cap');
r44Check(count(array_unique(array_column($out['export'],'id')))===10005,'no duplicate IDs across timestamp ties');
r44Check($out['export'][0]['id']===10005&&$out['export'][10004]['id']===1,'stable time/id descending order');
r44Check($out['summary']['net_amount']==='10005.00'&&$out['export'][0]['paid_amount']==='1.01','server summary and cents preserved');
r44Check($connection->sizes===[1000,1000,1000,1000,1000,1000,1000,1000,1000,1000,5],'batch bounded at1000');
r44Check($connection->events===['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY','begin','commit'],'consistent read-only snapshot committed');
r44Check(!isset($out['metadata']['filters']['uid'])&&!isset($out['metadata']['filters']['page'])&&$out['metadata']['filters']['activity_ids']==='2,3','only exact business filters in metadata');
r44Check(count($out['source_notes'])>=4&&count($out['columns'])===10,'full source and column contract');
$empty=$method->invoke($service,new R44ExportQuery([],new R44ExportConnection()),[]);
r44Check($empty['export']===[]&&$empty['summary']['net_amount']==='0.00','empty export retains summary and columns');
$failed=new R44ExportConnection();$failed->failAtBatch=2;
try{$method->invoke($service,new R44ExportQuery($rows,$failed),[]);throw new \RuntimeException('batch failure swallowed');}catch(\RuntimeException $e){r44Check($e->getMessage()==='batch failure'&&end($failed->events)==='rollback','failure rolls back without partial export');}
echo "PASS R44 export: 10005 rows, 11 batches, tied timestamps, authoritative totals, metadata, empty result and rollback\n";
}
