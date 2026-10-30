<?php
declare(strict_types=1);
/** Isolated execution of the real callback URL builder; no credentials, gateway or DB. */
namespace mohe\services\wechat\config { class MiniProgramConfig {} }
namespace mohe\exceptions { class PayException extends \RuntimeException {} }
namespace {
require __DIR__.'/../../后端代码/mohe/services/wechat/DefaultConfig.php';
require __DIR__.'/../../后端代码/mohe/services/wechat/HwcPayService.php';
$base='';
function app(){return new class { function make($class){return new class { function getConfig($key){return $GLOBALS['base'];} };} };}
$class=new ReflectionClass(\mohe\services\wechat\HwcPayService::class);
$object=$class->newInstanceWithoutConstructor();$method=$class->getMethod('callbackUrl');if(PHP_VERSION_ID<80100)$method->setAccessible(true);
foreach(['https://example.test'=>'https://example.test', 'https://example.test/admin'=>'https://example.test',
 'https://example.test/admin/'=>'https://example.test','http://localhost:8080/admin?x=1'=>'http://localhost:8080'] as $input=>$origin){
 $base=$input;$actual=$method->invoke($object,'wechat','product');
 if($actual!==$origin.'/api/pay/fynotify/wechat/product')throw new RuntimeException($actual);
 echo "PASS callback origin: $input\n";
}
foreach(['ftp://example.test/admin','https://user:secret@example.test/admin','invalid']as $input){
 $base=$input;try{$method->invoke($object,'wechat','product');throw new RuntimeException('invalid callback accepted');}catch(\mohe\exceptions\PayException $e){echo "PASS invalid callback rejected\n";}
}
}
