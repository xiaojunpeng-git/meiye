<?php
declare(strict_types=1);
// Disposable launcher only. No App bootstrap, .env or existing business DB connection.
ini_set('zend.exception_ignore_args','1');
$base=dirname(__DIR__,2).'/后端代码/app/services/ai/';
foreach(['contract/AiContractException','contract/AiIntentResultContract','registry/AiRegistryValue','registry/AiBusinessManifest','registry/AiBusinessRegistry','management/AiManagementPolicy','management/AiManagementStore'] as $f) require_once $base.$f.'.php';
use app\services\ai\management\AiManagementPolicy as Policy;
use app\services\ai\management\AiManagementStore as Store;
$port=getenv('MOHE_QUERY_TEST_PORT');$password=getenv('MOHE_QUERY_TEST_PASSWORD');
if(getenv('MOHE_QUERY_TEST_DISPOSABLE')!=='yes'||!preg_match('/^[0-9]{4,5}$/D',(string)$port)||strlen((string)$password)<32||!function_exists('pcntl_fork')) throw new RuntimeException('Disposable MySQL and pcntl required');
$connect=static function()use($port,$password){return new PDO('mysql:host=127.0.0.1;port='.$port.';dbname=mohe_query_fixture;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);};
$checks=0;
function mgCheck(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
$db=$connect();$db->exec(file_get_contents(dirname(__DIR__,2).'/后端代码/database/upgrades/2026-09-09-魔核AI管理配置/01-schema.sql'));
$store=new Store($db,'eb_','management.concurrent:fixture');$initial=$store->read();mgCheck($initial['revision']===1,'schema and initial state');
$doc=Policy::defaults();$doc['guidance']['max_rounds']=5;$draft=$store->saveDraft(1,$doc);mgCheck($draft['revision']===2,'draft saved');
// Do not inherit open MySQL sockets across forks. Concurrent publishers all use revision 2.
$store=null;$db=null;$children=[];
for($i=0;$i<8;$i++) {
    $pid=pcntl_fork();if($pid<0)throw new RuntimeException('fork failed');
    if($pid===0) {
        try{$child=new Store($connect(),'eb_','management.concurrent:fixture');$child->publish(2);exit(0);}
        catch(Throwable $e){if($e->getMessage()==='AI_MANAGEMENT_REVISION_CONFLICT')exit(10);fwrite(STDERR,'MANAGEMENT_CHILD_FAILED '.get_class($e).' '.(string)$e->getCode()."\n");exit(1);}
    }
    $children[]=$pid;
}
$success=0;$conflicts=0;
foreach($children as $pid){pcntl_waitpid($pid,$status);mgCheck(pcntl_wifexited($status),'child exited');$exit=pcntl_wexitstatus($status);if($exit===0)$success++;elseif($exit===10)$conflicts++;else throw new RuntimeException('publish child failed');}
mgCheck($success===1&&$conflicts===7,'exactly one publication');
$db=$connect();$store=new Store($db,'eb_','management.concurrent:fixture');$state=$store->read();$version=$state['active_version'];
mgCheck($state['revision']===3&&count($state['versions'])===1,'one immutable version and one revision advance');mgCheck($store->active()['document']===$doc,'published draft exact');
$store->saveDraft(3,Policy::defaults());mgCheck($store->version($version)['document']===$doc,'saved draft cannot mutate frozen run');
$rolled=$store->rollback(4,'source');mgCheck($rolled['revision']===5&&$rolled['active_version']!==$version&&count($rolled['versions'])===2,'rollback creates version');
mgCheck($store->active()['document']===Policy::defaults(),'rollback actual document');mgCheck($store->version($version)['document']===$doc,'published old run remains frozen');
$other=new Store($db,'eb_','management.other:fixture');mgCheck($other->active()['version']==='source','other instance source');
try{$other->version($version);throw new RuntimeException('cross instance leak');}catch(RuntimeException $e){mgCheck($e->getMessage()==='AI_MANAGEMENT_VERSION_NOT_FOUND','cross instance denied');}
$columns=$db->query('SHOW COLUMNS FROM eb_mohe_ai_management_version')->fetchAll(PDO::FETCH_ASSOC);mgCheck(count($columns)===7,'exact config-only schema');
mgCheck((int)$db->query("SELECT COUNT(*) FROM eb_mohe_ai_management_version WHERE instance_key='management.concurrent:fixture'")->fetchColumn()===2,'no orphan version');
echo "management-mysql: PASS ({$checks} checks, 8 concurrent publishers / 1 success / 7 conflicts; immutable rollback and instance isolation; disposable MySQL)\n";
