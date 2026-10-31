<?php
declare(strict_types=1);
/** 真实Request解析和控制器响应合同；仅本地事务夹具，不伪造业务查询结果或跳过领域权限。 */
putenv('R61_MODE=library');require dirname(__DIR__).'/combination-r61/local-acceptance.php';
set_exception_handler(static function($e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);});
use think\facade\Db;
use app\controller\admin\v1\marketing\combination\StoreCombination;
function flowController(array $params,array $admin):StoreCombination {
    $request=(new app\Request())->withGet($params)->withServer(['REQUEST_METHOD'=>'GET']);
    // 只注入认证中间件正常给出的对象，不替换查询、计数、权限或导出服务。
    $controller=(new ReflectionClass(StoreCombination::class))->newInstanceWithoutConstructor();
    foreach(['request'=>$request,'adminInfo'=>$admin]as$key=>$value){$p=new ReflectionProperty($controller,$key);$p->setAccessible(true);$p->setValue($controller,$value);}
    return$controller;
}
Db::startTrans();try {
    $f=fixture(6,2,'yue');$admin=['id'=>1,'level'=>0,'admin_type'=>1];
    $filters=json_encode([['fieldKey'=>'paid_amount','operator'=>'greater_or_equal','value'=>'99']],JSON_UNESCAPED_UNICODE);
    $data=flowController(['filters'=>$filters],$admin)->activityFlow($f['cid'])->getData()['data'];
    check($data['count']===1&&$data['summary']['paid_amount']==='100.00','real Request preserves JSON filter structure into list and summary');
    $export=flowController(['filters'=>$filters,'view'=>'export'],$admin)->activityFlow($f['cid'])->getData()['data'];
    check($export['count']===1&&$export['export'][0]['paid_amount']==='100.00','controller export uses same submitted JSON filter');
    $plain=flowController([],$admin)->activityFlow($f['cid'])->getData()['data'];check($plain['count']===1,'missing optional filters means unrestricted within authorized activity');
    $empty=flowController(['filters'=>json_encode([['fieldKey'=>'phone','operator'=>'equal','value'=>'NONE']])],$admin)->activityFlow($f['cid'])->getData()['data'];check($empty['count']===0&&$empty['summary']['paid_amount']==='0.00','wire-level field filter affects full count and aggregate');
    $failed=false;try{flowController(['filters'=>'[broken'],$admin)->activityFlow($f['cid']);}catch(Throwable$e){$failed=true;}check($failed,'malformed wire JSON fails closed');
    $failed=false;try{flowController([],['id'=>2,'level'=>1,'admin_type'=>1])->activityFlow($f['cid']);}catch(Throwable$e){$failed=true;}check($failed,'controller cannot bypass domain management grant');
    echo "ACTIVITY_FLOW_CONTROLLER_LOCAL_PASS\n";
}finally{Db::rollback();}
