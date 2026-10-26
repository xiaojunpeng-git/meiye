<?php
/** R37补漏：只读执行实际商品编辑元数据；本进程配置替身覆盖0/1/缺省，不修改数据库配置或卡内金额。 */
declare(strict_types=1);
chdir(getenv('MOHE_BACKEND_ROOT') ?: dirname(__DIR__,3).'/后端代码');
require 'vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e):void{file_put_contents('php://stderr',$e->getMessage().PHP_EOL);exit(1);});
eval('namespace app\\services\\product\\product; function sys_config($key,$default=""){return $key==="cashier_labor_fee_mode"?$GLOBALS["r37Mode"]:\\sys_config($key,$default);}');
think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();
try{
 $service=app()->make(app\services\product\product\StoreProductServices::class);$cases=[];
 foreach([0,625943,625056]as$id){$expected=null;foreach([0,1,null]as$mode){$GLOBALS['r37Mode']=$mode;$data=$service->otherInfo($id,0);if($data['labor_fee_visible']!==($mode!==1))throw new RuntimeException('显示配置不一致');unset($data['labor_fee_visible']);$hash=hash('sha256',json_encode($data));if($expected!==null&&$hash!==$expected)throw new RuntimeException('显隐改变了商品原数据');$expected=$hash;$cases[]=['id'=>$id,'mode'=>$mode,'visible'=>$mode!==1,'otherResponseSHA256'=>$hash];}}
 $data=$service->otherInfo(0,1);if(array_key_exists('labor_fee_visible',$data))throw new RuntimeException('扩散到了独立佣金接口');
 echo json_encode(['result'=>'PASS','cases'=>$cases,'databaseWrites'=>0,'otherResponseUnchanged'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),PHP_EOL;
}finally{think\facade\Db::rollback();}
