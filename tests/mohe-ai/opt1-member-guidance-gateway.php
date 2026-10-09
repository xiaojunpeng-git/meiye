<?php
/** Synthetic identities only: exercise the real Run, masking and local binding
 * boundaries without loading a framework, customer DB or external provider. */
namespace think\facade {
    final class Db {
        public static function name($table){return new \Opt1MemberQuery($table);}
    }
}
namespace {
require __DIR__.'/r6-gateway-harness.php';
final class Opt1MemberQuery {
    private $table; private $field=''; private $phone=null;
    public function __construct($table){$this->table=$table;}
    public function __call($name,$args){
        if($name==='field')$this->field=$args[0];
        if($name==='where'&&($args[0]??null)==='u.phone')$this->phone=$args[1];
        foreach($args as $arg)if($arg instanceof \Closure)$arg($this);
        return $this;
    }
    public function select(){return $this;}
    public function column(){return [];}
    public function toArray(){
        if($this->table!=='user')return [];
        if($this->field==='u.real_name')return [['real_name'=>'测试甲']];
        if($this->field==='u.nickname')return [];
        $rows=[['uid'=>19,'real_name'=>'测试甲','nickname'=>''],['uid'=>20,'real_name'=>'测试甲','nickname'=>'']];
        return $this->phone==='13800000000'?[$rows[0]]:($this->phone!==null?[]:$rows);
    }
}
$h=new R6GatewayHarness(5,[1],'platform');
try {
    $h->auth['member_data_authorized']=true;$h->context['member_data_authorized']=true;
    $safeQuestion='会员[local_condition_1]在上个月实际收款销售额多少';
    $h->understandingOverride=['goal'=>'查询会员实际收款销售额','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'会员实际收款销售额','fields'=>['object_kind','object_relation','metric_codes','periods'],
        'values'=>['object_kind'=>'member','object_relation'=>'selection','metric_terms'=>['实际收款销售额'],
            'periods'=>[['kind'=>'month_offset','offset_months'=>-1]]],
        'evidence'=>[['message_id'=>'current','quote'=>$safeQuestion]],
    ]]];
    $h->semanticIntent=['object_kind'=>'member','object_relation'=>'selection','object_term'=>'[local_condition_1]',
        'operation'=>'summary','metric_codes'=>['sales_collected_amount'],'action_codes'=>[],
        'needs_metric_choice'=>false,'ranking'=>['direction'=>'unspecified','limit'=>null],
        'periods'=>[['kind'=>'month_offset','offset_months'=>-1]],'scope'=>'authorized','unresolved_fragments'=>[]];
    $first=$h->start('会员测试甲在上个月实际收款销售额多少');
    r6hCheck(($first['status']??null)==='WAITING_CLARIFICATION','same-name waits: '.($first['reason']??''));
    r6hCheck(strpos($first['clarification']['question'],'完整手机号')!==false,'asks only for member identity');
    r6hCheck((int)$h->db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE target_code='unified_metric_query'")->fetchColumn()===0,'ambiguous identity never reads facts');
    // Phone remains a local opaque condition, not a new business goal. The
    // accepted original member, metric and period must survive this reply.
    $h->understandingOverride=['goal'=>'补充会员身份','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'会员身份','fields'=>['object_kind','object_relation'],
        'values'=>['object_kind'=>'member','object_relation'=>'selection'],
        'evidence'=>[['message_id'=>'current','quote'=>'[local_condition_2]']],
    ]]];
    $identityModels=$h->models;$identityQueries=$h->queries;
    $wrongPhone=$h->choose($first,['semantic_reply'=>'__supplement__','supplement_text'=>'13900000000']);
    r6hCheck($wrongPhone['status']==='WAITING_CLARIFICATION' && strpos($wrongPhone['clarification']['question'],'未找到')!==false,
        'no exact identity match asks for correction rather than claiming zero consumption');
    r6hCheck($h->models===$identityModels && $h->queries===$identityQueries,'wrong phone uses local identity scope without model or facts');
    $next=$h->choose($wrongPhone,['semantic_reply'=>'__supplement__','supplement_text'=>'13800000000']);
    r6hCheck($h->models===$identityModels,'a full-phone identity slot never reinterprets accepted meaning');
    r6hCheck(($next['status']??null)==='COMPLETED','phone resumes: '.($next['reason']??''));
    r6hCheck($next['run_id']===$first['run_id'],'phone reply keeps the same Run');
    r6hCheck((int)$h->db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE target_code='unified_metric_query'")->fetchColumn()===1,'exact member is read once');
    foreach($h->modelInputs as $input) {
        $wire=json_encode($input,JSON_UNESCAPED_UNICODE);
        r6hCheck(strpos($wire,'测试甲')===false&&strpos($wire,'13800000000')===false,'identity never leaves the local boundary');
    }
    // A broad measurement must ask for its meaning before the identity step.
    // The accepted member and month survive both replies in this same Run.
    $h->understandingOverride=['goal'=>'查询会员消费','status'=>'needs_clarification','requirements'=>[[
        'id'=>'r1','meaning'=>'会员消费','fields'=>['object_kind','object_relation','metric_codes','periods'],
        'values'=>['object_kind'=>'member','object_relation'=>'selection','metric_terms'=>['消费'],
            'periods'=>[['kind'=>'month_offset','offset_months'=>-1]]],
        'evidence'=>[['message_id'=>'current','quote'=>'会员[local_condition_1]在上个月消费多少']],
    ]]];
    $broad=$h->start('会员测试甲在上个月消费多少');
    r6hCheck(($broad['status']??null)==='WAITING_CLARIFICATION','broad member metric waits');
    r6hCheck(($broad['clarification']['fields'][0]['options'][0]['label']??null)==='实际收款销售额',
        'card offers the registered measurement without assuming it');
    r6hCheck(($broad['clarification']['fields'][0]['options'][1]['value']??null)==='__supplement__',
        'card also accepts another intended statistical meaning');
    r6hCheck((int)$h->db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$h->db->quote($broad['run_id'])." AND target_code='unified_metric_query'")->fetchColumn()===0,
        'an unconfirmed member measurement never reads facts');
    $h->understandingOverride=['goal'=>'查询实际收款销售额','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'实际收款销售额','fields'=>['metric_codes'],
        'values'=>['metric_terms'=>['实际收款销售额']],
        'evidence'=>[['message_id'=>'current','quote'=>'实际收款销售额']],
    ]]];
    $metricReply=$h->choose($broad,['semantic_reply'=>'member_metric_1']);
    r6hCheck(($metricReply['status']??null)==='WAITING_CLARIFICATION'
        && strpos($metricReply['clarification']['question'],'完整手机号')!==false,
        'confirmed metric advances to the missing same-name identity');
    r6hCheck($metricReply['run_id']===$broad['run_id'],'metric reply keeps the same Run');
    $h->understandingOverride=['goal'=>'补充会员身份','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'会员身份','fields'=>['object_kind','object_relation'],
        'values'=>['object_kind'=>'member','object_relation'=>'selection'],
        'evidence'=>[['message_id'=>'current','quote'=>'[local_condition_2]']],
    ]]];
    $identityModels=$h->models;
    $completed=$h->choose($metricReply,['semantic_reply'=>'__supplement__','supplement_text'=>'13800000000']);
    r6hCheck($h->models===$identityModels,'metric then identity finishes without another model request');
    r6hCheck(($completed['status']??null)==='COMPLETED','member metric and identity clarification completes');
    r6hCheck($completed['run_id']===$broad['run_id'],'both replies keep the same Run');
    r6hCheck((int)$h->db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE run_id=".$h->db->quote($broad['run_id'])." AND target_code='unified_metric_query' AND state='SUCCEEDED'")->fetchColumn()===1,
        'the exact member is read once after both conditions are confirmed');
    foreach($h->modelInputs as $input) {
        $wire=json_encode($input,JSON_UNESCAPED_UNICODE);
        r6hCheck(strpos($wire,'测试甲')===false&&strpos($wire,'13800000000')===false,
            'clarification never sends the member identity to the provider');
    }
    // A long reading pause expires the original Run. Explicit recovery must
    // retain its private member/month/metric while receiving a fresh budget;
    // it must never revive the old Run or read an ambiguous member's facts.
    $h->understandingOverride=['goal'=>'查询会员消费','status'=>'needs_clarification','requirements'=>[[
        'id'=>'r1','meaning'=>'会员消费','fields'=>['object_kind','object_relation','metric_codes','periods'],
        'values'=>['object_kind'=>'member','object_relation'=>'selection','metric_terms'=>['消费'],
            'periods'=>[['kind'=>'month_offset','offset_months'=>-1]]],
        'evidence'=>[['message_id'=>'current','quote'=>'会员[local_condition_1]在上个月消费多少']],
    ]]];
    $pending=$h->start('会员测试甲在上个月消费多少');
    $h->understandingOverride=['goal'=>'查询实际收款销售额','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'实际收款销售额','fields'=>['metric_codes'],'values'=>['metric_terms'=>['实际收款销售额']],
        'evidence'=>[['message_id'=>'current','quote'=>'实际收款销售额']],
    ]]];
    $pending=$h->choose($pending,['semantic_reply'=>'member_metric_1']);
    r6hCheck($pending['clarification_deadline_at']>$pending['server_time'],'server exposes cumulative clarification deadline');
    $frozen=$h->private->read($pending['clarification']['id'])['envelope']['_member_identity_request']['compiled']['plan']['query'];
    $h->db->exec('UPDATE mohe_ai_run SET pause_at=pause_at-600001 WHERE run_id='.$h->db->quote($pending['run_id']));
    $expired=$h->gateway->handle('status',$h->context,$h->binding($pending),$pending['run_id']);
    r6hCheck($expired['reason']==='CLARIFICATION_EXPIRED' && $expired['failure_guidance']['kind']==='clarification_expired','expiry is explained rather than generic query failure');
    r6hCheck(strpos($expired['clarification']['question'],'完整手机号')!==false,'expired task retains its exact pending identity step');
    $before=$h->queries;
    $late=$h->choose($pending,['semantic_reply'=>'__supplement__','supplement_text'=>'13800000000']);
    r6hCheck($late['reason']==='CLARIFICATION_EXPIRED' && $h->queries===$before,'late POST cannot revive expired work or read facts');
    $source=['run_id'=>$expired['run_id']]+$h->binding($expired)+[
        'clarification_id'=>$expired['clarification']['id'],'choices'=>['semantic_reply'=>'__supplement__','supplement_text'=>'13800000000']];
    $input=['client_request_id'=>'expired-restart-1','conversation_id'=>'conversation1','client_session_id'=>'device1',
        'window_token'=>$h->boot['window_token'],'question'=>'会员测试甲在上个月消费多少','history'=>[],
        'output_format'=>'screen','guidance_schema_version'=>'mohe-clarification-v2','clarification_restart'=>$source];
    // Source proofs bind account, device and conversation, not just a guessed
    // Run ID. Rejected recovery must not create a new task or query facts.
    foreach (['run_delivery_token'=>'invalid','clarification_id'=>'wrong','run_id'=>str_repeat('a',48)] as $key=>$value) {
        $bad=$input;$bad['clarification_restart'][$key]=$value;
        try {$h->gateway->handle('create',$h->context,$bad);throw new \RuntimeException('FAIL tampered source accepted');}
        catch (\RuntimeException $e) {r6hCheck(strpos($e->getMessage(),'FAIL')!==0,'tampered source rejected');}
    }
    $h->auth['permission_version']='revoked';
    try {$h->gateway->handle('create',$h->context,$input);throw new \RuntimeException('FAIL authority drift accepted');}
    catch (\RuntimeException $e) {r6hCheck($e->getMessage()==='AI_AUTHORIZATION_CHANGED','restart rechecks source authority: '.$e->getMessage());}
    $h->auth['permission_version']='v1';
    $created=$h->gateway->handle('create',$h->context,$input);
    $again=$h->gateway->handle('create',$h->context,$input);
    r6hCheck($created['run_id']===$again['run_id'] && $created['run_id']!==$expired['run_id'],'lost restart acknowledgement reuses one fresh Run');
    $h->understandingOverride=['goal'=>'补充会员身份','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'会员身份','fields'=>['object_kind','object_relation'],
        'values'=>['object_kind'=>'member','object_relation'=>'selection'],
        'evidence'=>[['message_id'=>'current','quote'=>'[local_condition_2]']],
    ]]];
    $identityModels=$h->models;
    $recovered=$h->gateway->handle('execute',$h->context,$h->binding($created)+$input,$created['run_id']);
    r6hCheck($h->models===$identityModels,'expired identity restart needs no external interpretation');
    r6hCheck($recovered['status']==='COMPLETED','expired member continuation completes: '.($recovered['reason']??''));
    r6hCheck($h->queries===$before+1,'explicit recovery queries exact member once');
    $evidence=$h->private->read($h->row($recovered)['evidence_ref']);
    foreach (['metric_codes','start_date','end_date','store_ids'] as $slot) {
        r6hCheck($evidence['query'][$slot]===$frozen[$slot],'identity restart retains accepted '.$slot);
    }
    r6hCheck($evidence['query']['business_filters']['selection_ref']==='member:19','only the locally verified member reference enters facts');
    $old=$h->row($expired);r6hCheck($old['status']==='FAILED' && $old['reason']==='CLARIFICATION_EXPIRED','old expiration is immutable');
    foreach($h->modelInputs as $view) r6hCheck(strpos(json_encode($view),'13800000000')===false,'restart never sends phone to provider');
    echo "PASS OPT1 member guidance gateway (synthetic only)\n";
} finally {$h->close();}
}
