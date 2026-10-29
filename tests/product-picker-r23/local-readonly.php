<?php
/** 本地只读：执行控制器真实筛选归一和DAO查询，证明类型在分页前收敛且不会扩大卡项选品。 */
require '/var/www/html/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
set_exception_handler(function(Throwable $e){file_put_contents('php://stderr',$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$config=Db::connect()->getConfig();if(getenv('R23_LOCAL_ONLY')!=='1'||$config['database']!=='ruihao'||$config['hostname']!=='mysql')throw new RuntimeException('local only');
$controller=new class extends app\controller\admin\v1\product\StoreProduct {
    public function __construct() {}
    public function input(array $data){
        $this->request=new class($data){private $data;public function __construct($data){$this->data=$data;}
            public function get($key,$default=null){return $this->data[$key]??$default;}
            public function getMore($fields){$out=[];foreach($fields as $field)$out[$field[3]??$field[0]]=$this->data[$field[0]]??$field[1];return $out;}};
        $this->service=new class {public function searchList($where,$stock){return $where;}};
        return $this->search_list();
    }
    public function success($msg='',$data=[]){return is_array($msg)?$msg:$data;}
};
$checks=0;$check=function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks++;echo 'PASS '.$name."\n";};
$dao=app()->make(app\dao\product\product\StoreProductDao::class);
foreach([''=>[0,4,5,6],0=>[0],5=>[4,5],6=>[6]] as $type=>$expected){
    $where=$controller->input(['choose_type'=>98,'product_type'=>$type,'type'=>1]);
    $rows=$dao->search($where)->field('id,product_type')->limit(20)->select()->toArray();
    foreach($rows as $row)$check(in_array((int)$row['product_type'],$expected,true),'coupon type filter '.$row['product_type']);
    $check((int)$dao->getCount($where)===(int)$dao->search($where)->count(),'count and list use same scope');
}
$where=$controller->input(['choose_type'=>97,'product_type'=>0,'type'=>1]);$check($where['product_type']===6&&$where['type']===0&&$where['relation_id']===0,'forged card selection stays platform project');
$types=$dao->search($where)->group('product_type')->column('product_type');$check(array_diff(array_map('intval',$types),[6])===[],'card reader returns projects only');
try{$controller->input(['choose_type'=>98,'product_type'=>1]);throw new RuntimeException('invalid type accepted');}catch(think\exception\ValidateException $e){$check(true,'invalid coupon product type rejected');}
$warehouse=$controller->input(['choose_type'=>98,'type'=>2]);$check($warehouse['status']===2,'warehouse status preserved');
$check($controller->input(['choose_type'=>98,'type'=>99])['status']===1,'invalid status narrows to selling');
echo "$checks checks passed; no database writes\n";
