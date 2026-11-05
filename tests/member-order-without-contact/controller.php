<?php
declare(strict_types=1);
// Run the real create controller and authoritative group-store guard. Replace
// database/payment transports so omission and forgery cases cannot write money.
namespace think\exception { class ValidateException extends \RuntimeException {} }
namespace think\facade { class Log { static function error($message){throw new \RuntimeException($message);} } }
namespace app {
    class Request {
        public $params; private $member;
        function __construct(array $params,array $member){$this->params=$params;$this->member=$member;}
        function uid(){return 7;}
        function user(){return new class($this->member){private $data;function __construct($d){$this->data=$d;}function toArray(){return $this->data;}};}
        function postMore($fields,$indexed){$out=[];foreach($fields as$f){$key=is_array($f[0])?$f[0][0]:$f[0];$out[]=$this->params[$key]??$f[1];}return $out;}
        function isApp(){return false;}
        function getFromType(){return 'test';}
    }
}
namespace app\services\member {class MemberCommerceSettingsServices {public static $enabled=false;static function configured(){return self::$enabled;}}}
namespace app\model\activity\combination {class StoreCombination {static function where(...$args){return new class{function value($key){return 0;}};}}}
namespace app\services\order {
    class StoreOrderServices {public $cart;public $existing=false;function getOne(...$args){return $this->existing?['order_id'=>'EXISTING']:false;}function getCacheOrderInfo(...$args){return $this->cart;}}
    class StoreOrderCreateServices {public $calls=[];function setItem(...$args){}function reset(){}function createOrder(...$args){$this->calls[]=$args;return ['order_id'=>'LOCAL-NO-WRITE'];}}
}
namespace {
    class JsonBoundary {function fail($msg){return ['status'=>'fail','msg'=>$msg];}function status($status,$msg,$data){return compact('status','msg','data');}}
    function app($name){if($name==='json')return new JsonBoundary;throw new \RuntimeException('Unexpected dependency '.$name);}
    $root=getenv('MEMBER_CONTACT_TEST_ROOT')?:dirname(__DIR__,2);
    $backend=getenv('MEMBER_CONTACT_BACKEND_ROOT')?:$root.'/后端代码';
    require $backend.'/app/services/activity/combination/CombinationPurchaseStoreServices.php';
    require $backend.'/app/controller/api/v1/order/StoreOrder.php';
    function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
    function run(array $profile,array $params=[],array $cart=[],bool $existing=false):array {
        $s=new \app\services\order\StoreOrderServices;$s->existing=$existing;
        $s->cart=$cart?:['cartInfo'=>[['type'=>empty($params['combinationId'])?0:3,'store_id'=>11,'productInfo'=>['product_type'=>1]]],'other'=>['reservationInfo'=>['reservation_type'=>2]]];
        $create=new \app\services\order\StoreOrderCreateServices;
        $controller=new \app\controller\api\v1\order\StoreOrder($s);
        $response=$controller->create(new \app\Request($params+['shipping_type'=>2,'store_id'=>11,'payType'=>'weixin'],$profile),$create,'local-key');
        return [$response,$create->calls];
    }
    foreach([0,11] as $activity){
        [$r,$calls]=run([],['combinationId'=>$activity]);
        check($r['status']==='success'&&count($calls)===1,'empty member profile does not require contact, activity='.$activity);
        check($calls[0][5]['real_name']===''&&$calls[0][5]['phone']==='','empty identity stays empty without fabricated values');
        [$r,$calls]=run(['real_name'=>'会员·长姓名可保留','phone'=>'PROFILE_PHONE'],['combinationId'=>$activity,'real_name'=>'FORGED','phone'=>'FORGED']);
        check($r['status']==='success'&&$calls[0][5]['real_name']==='会员·长姓名可保留'&&$calls[0][5]['phone']==='PROFILE_PHONE','client contact cannot replace authenticated member identity');
        [$r,$calls]=run(['nickname'=>'昵称','record_phone'=>'RECORDED','phone'=>'PROFILE'],['combinationId'=>$activity]);
        check($calls[0][5]['real_name']==='昵称'&&$calls[0][5]['phone']==='RECORDED','existing member name/recorded-phone fallback retained');
    }
    [$r,$calls]=run([],['combinationId'=>11,'store_id'=>12]);check($r['status']==='fail'&&!$calls,'group purchase cannot change quoted store');
    [$r,$calls]=run([],['shipping_type'=>1],['cartInfo'=>[['type'=>0,'productInfo'=>['product_type'=>0]]]]);check($r['status']==='fail'&&!$calls,'delivery still requires an authorized shipping address');
    $address=['id'=>9,'real_name'=>'收件人','phone'=>'DELIVERY'];
    [$r,$calls]=run(['nickname'=>'会员'],['shipping_type'=>1,'addressId'=>9],['cartInfo'=>[['type'=>0,'productInfo'=>['product_type'=>0]]],'addr'=>$address]);
    check($r['status']==='success'&&$calls[0][5]===$address,'delivery recipient is not replaced by member profile');
    [$r,$calls]=run([],[],[],true);check($r['status']==='extend_order'&&!$calls,'existing idempotency response bypasses duplicate creation');
    \app\services\member\MemberCommerceSettingsServices::$enabled=true;
    [$r,$calls]=run([]);check($r['status']==='fail'&&!$calls,'normal purchase keeps its configured member-commerce entry');
}
