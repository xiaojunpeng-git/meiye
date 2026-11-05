<?php
// 调用真实控制器、来源策略和图片转码；仅限本地容器，隔离缓存，不写业务库或上传配置。
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');$app->env->load('/var/www/html/.env');$app->initialize();
if (config('database.connections.mysql.hostname') !== 'mysql') throw new RuntimeException('Local container only');
$cache=config('cache');$cache['stores']['redis']['prefix']='poster-test-'.bin2hex(random_bytes(8)).':';config($cache,'cache');
class PosterRequest extends app\Request {
    public $values=[];
    public function postMore(array $params,bool $isList=false,bool $filter=true):array { return [$this->values['image']??'', $this->values['code']??'']; }
    public function domain($port=false):string {return 'https://local.example.com';}
}
// 模拟导入实例没有上传桶的真实条件；只替换桶列表读取，不伪造图片转换结果。
$app->instance(app\services\system\config\SystemStorageServices::class,new class { public function getColumn($where,$field){return [];} });
$request=new PosterRequest();$app->instance('request',$request);
$controller=new app\controller\api\v1\PublicController(new think\facade\App());
$url='https://qiniu007.cc3798.com/attach/2026/08/16b73202608231706435098.png';
$request->values=['image'=>$url];config(['image_origins'=>[]],'poster');
$denied=$controller->get_image_base64($request)->getData();
if($denied['data']['image']!==false)throw new RuntimeException('Missing origin was not rejected');
config(['image_origins'=>['https://qiniu007.cc3798.com']],'poster');
$result=$controller->get_image_base64($request)->getData();
if(strpos($result['data']['image']??'', 'data:image/png;base64,')!==0)throw new RuntimeException('Trusted imported product image did not convert');
$png=base64_decode(explode(',',$result['data']['image'],2)[1]);if(strlen($png)!==77212 || substr($png,0,8)!=="\x89PNG\r\n\x1a\n")throw new RuntimeException('Converted payload is not the actual image');
$request->values=['image'=>$url,'code'=>'https://untrusted.example/code.jpg'];
$denied=$controller->get_image_base64($request)->getData();if($denied['data']!==['code'=>false,'image'=>false])throw new RuntimeException('Untrusted QR source not rejected');
echo json_encode(['result'=>'PASS','actualProductPngBytes'=>strlen($png),'originMissing'=>'rejected','originConfigured'=>'converted','untrustedCode'=>'rejected','businessDatabaseWrites'=>0],JSON_UNESCAPED_SLASHES)."\n";
