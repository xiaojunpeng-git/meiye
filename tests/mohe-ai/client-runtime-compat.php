<?php
namespace app\services\ai\model {
    function curl_init($url){return new \stdClass();}
    function curl_setopt_array($handle,$options){$GLOBALS['clientCompatOptions']=$options;return true;}
    function curl_exec($handle){
        $options=$GLOBALS['clientCompatOptions'];
        $key=defined('CURLOPT_XFERINFOFUNCTION')?constant('CURLOPT_XFERINFOFUNCTION'):CURLOPT_PROGRESSFUNCTION;
        if(!isset($options[$key]))throw new \RuntimeException('PROGRESS_CALLBACK_MISSING');
        if($options[$key]($handle,0,0,0,0)!==0)return false;
        $body=json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>'{"metric_codes":["cash_performance"],"query_shape":"summary","date_code":"TODAY","decision":"query"}']]],'usage'=>['prompt_tokens'=>10,'completion_tokens'=>5]]);
        return $options[CURLOPT_WRITEFUNCTION]($handle,$body)===strlen($body);
    }
    function curl_getinfo($handle,$option){return 200;}
    function curl_close($handle){}
}
namespace {
    $root=getenv('MOHE_AI_BACKEND_ROOT')?:dirname(__DIR__,2).'/后端代码';
    foreach(['contract/AiContractException.php','contract/AiStrictJson.php','model/SiliconFlowClient.php'] as $file)require_once $root.'/app/services/ai/'.$file;
    $client=new app\services\ai\model\SiliconFlowClient();
    $ok=$client->select([],['cash_performance'],'fixture/model','fixture-key',1000,static function(){});
    if($ok['selection']['metric_codes']!==['cash_performance'])throw new RuntimeException('SELECTION_INVALID');
    $calls=0;$cancelled=false;
    try{$client->select([],['cash_performance'],'fixture/model','fixture-key',1000,static function()use(&$calls){if(++$calls>1)throw new RuntimeException('stop');});}
    catch(Throwable $e){$cancelled=$e->getMessage()==='AI_CANCELLED';}
    if(!$cancelled)throw new RuntimeException('CANCELLATION_NOT_ENFORCED');
    echo 'Client runtime compatibility PASS php='.PHP_VERSION.' progress='.(defined('CURLOPT_XFERINFOFUNCTION')?'XFERINFO':'PROGRESS')." checks=2\n";
}
