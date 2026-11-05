<?php
declare(strict_types=1);
// Exercise the real time projection, DAO filters and member list service.
// Query/asset adapters are local doubles; no production data or payment is written.
namespace think\exception { class ValidateException extends \RuntimeException {} }
namespace app\dao {
    class Query {
        public $clauses = [];
        public function when($condition, $callback) { if ($condition) $callback($this); return $this; }
        public function where(...$args) { $this->clauses[] = $args; return $this; }
    }
    class BaseDao { public function search(array $where = []) { return new Query; } }
}
namespace app\services { class BaseServices { protected $dao; public function getPageValue() { return [1, 10]; } } }
namespace app\services\product\product { class StoreProductServices { public function productIdByBrandName($id) { return ''; } } }
namespace {
    $backend = getenv('COMBINATION_UPCOMING_BACKEND_ROOT') ?: dirname(__DIR__,2).'/后端代码';
    require $backend.'/app/services/activity/combination/CombinationActivityWindow.php';
    require $backend.'/app/dao/activity/combination/StoreCombinationDao.php';
    require $backend.'/app/services/activity/combination/StoreCombinationServices.php';
    use app\services\activity\combination\CombinationActivityWindow as Window;
    $checks=0;
    function check($ok,$label) { if(!$ok) throw new \RuntimeException($label); $GLOBALS['checks']++; echo 'PASS '.$label.PHP_EOL; }
    $activity=['start_time'=>1000,'stop_time'=>2000,'is_show'=>1,'is_del'=>0];
    foreach ([999=>'upcoming',1000=>'running',1999=>'running',2000=>'running',2001=>'ended'] as $now=>$phase) {
        $view=Window::present($activity,$now);
        check($view['activity_status']===$phase&&$view['server_time']===$now,'window boundary '.$now.' = '.$phase);
    }
    check(Window::present(array_replace($activity,['is_show'=>0]),999)['activity_status']==='unavailable','hidden cannot be previewed as available');
    check(Window::present(array_replace($activity,['is_del'=>1]),999)['activity_status']==='unavailable','deleted cannot be previewed as available');
    check(Window::present(['start_time'=>2000,'stop_time'=>1000],999)['activity_status']==='unavailable','invalid activity window unavailable');
    $dao=new \app\dao\activity\combination\StoreCombinationDao;
    $preview=$dao->search(['pinkDisplayTime'=>900]);
    check($preview->clauses===[['stop_time','>=',900]],'display includes future starts while excluding ended windows');
    $purchase=$dao->search(['pinkIngTime'=>[900,900]]);
    check($purchase->clauses===[['start_time','<=',900],['stop_time','>=',900]],'purchase query still requires activity to have started');
    function set_file_url($url) { return $url; }
    function app() { return new class { public function make($class) { return new $class; } }; }
    class ListDao extends \app\dao\activity\combination\StoreCombinationDao {
        public $criteria;
        public $field;
        public function combinationList(array $where,string $field='*',int $page=0,int $limit=0) {
            $this->criteria=$where; $this->field=$field;
            return [['id'=>11,'title'=>'Future','image'=>'image.png','price'=>'198','product_id'=>91,'product_price'=>'298',
                'people'=>2,'quota'=>100,'quota_show'=>100,'stock'=>100,'start_time'=>time()+3600,'stop_time'=>time()+7200]];
        }
        public function count($criteria) { $this->criteria=$criteria; return 1; }
    }
    $d=new ListDao;$service=new \app\services\activity\combination\StoreCombinationServices($d);
    $rows=$service->getCombinationList(['applicable_store_id'=>12]);
    check($rows[0]['activity_status']==='upcoming'&&$rows[0]['server_time']>0,'actual list presents future activity with server time');
    check(isset($d->criteria['pinkDisplayTime'])&&!isset($d->criteria['pinkIngTime']),'member list uses display qualification, not purchase qualification');
    check($d->criteria['is_show']===1&&$d->criteria['is_del']===0&&$d->criteria['applicable_store_id']===12&&$d->criteria['storeProductId']===true,'existing publication, source product and store scope retained');
    check(strpos($d->field,'start_time')!==false&&strpos($d->field,'stop_time')!==false,'member list carries both authoritative activity times');
    check($service->validCombination()===1&&isset($d->criteria['pinkDisplayTime']),'member entry remains visible when only upcoming activities exist');
    echo json_encode(['result'=>'PASS','checks'=>$checks,'writes'=>0]).PHP_EOL;
}
