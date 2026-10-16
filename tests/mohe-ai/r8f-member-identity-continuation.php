<?php
/** Synthetic Run contract: a missing selected member must survive the metric
 * clarification, then resolve only after an authorized exact-name correction. */
namespace think\facade {
    final class Db {
        public static function name($table) { return new \R8fMemberQuery($table); }
    }
}
namespace {
require __DIR__.'/r6-gateway-harness.php';
final class R8fMemberQuery {
    private $table; private $field='';
    public function __construct($table) { $this->table=$table; }
    public function __call($name,$args) {
        if ($name==='field') $this->field=$args[0];
        foreach ($args as $arg) if ($arg instanceof \Closure) $arg($this);
        return $this;
    }
    public function select() { return $this; }
    public function column() { return []; }
    public function toArray() {
        if ($this->table!=='user') return [];
        if ($this->field==='u.real_name') return [['real_name'=>'测试乙']];
        if ($this->field==='u.nickname') return [];
        return [['uid'=>19,'real_name'=>'测试乙','nickname'=>'']];
    }
}
$h=new R6GatewayHarness(3,[1],'platform');
try {
    $h->auth['member_data_authorized']=true;$h->context['member_data_authorized']=true;
    $h->understandingOverride=['goal'=>'查询会员业绩','status'=>'needs_clarification','requirements'=>[[
        'id'=>'r1','meaning'=>'会员业绩','fields'=>['object_kind','object_relation','metric_codes'],
        'values'=>['object_kind'=>'member','object_relation'=>'selection','metric_terms'=>['业绩']],
        'evidence'=>[['message_id'=>'current','quote'=>'管慧的业绩是多少？']],
    ]]];
    $h->semanticIntent=['object_kind'=>'member','object_relation'=>'selection','object_term'=>'管慧','operation'=>'summary',
        'metric_codes'=>[],'action_codes'=>[],'needs_metric_choice'=>true,
        'ranking'=>['direction'=>'unspecified','limit'=>null],'periods'=>[],'scope'=>'authorized','unresolved_fragments'=>[]];
    $metric=$h->start('管慧的业绩是多少？');
    r6hCheck($metric['status']==='WAITING_CLARIFICATION','ambiguous metric asks a question');
    r6hCheck(($metric['clarification']['fields'][0]['options'][0]['label']??null)==='实际收款销售额','registered metric offered');
    $h->understandingOverride=['goal'=>'查询实际收款销售额','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'实际收款销售额','fields'=>['metric_codes'],
        'values'=>['metric_terms'=>['实际收款销售额']],
        'evidence'=>[['message_id'=>'current','quote'=>'实际收款销售额']],
    ]]];
    $h->semanticIntent['metric_codes']=['sales_collected_amount'];$h->semanticIntent['needs_metric_choice']=false;
    $identity=$h->choose($metric,['semantic_reply'=>'member_metric_1']);
    r6hCheck($identity['status']==='WAITING_CLARIFICATION','unknown member must ask identity: '.json_encode($identity,JSON_UNESCAPED_UNICODE));
    r6hCheck(strpos($identity['clarification']['question'],'会员')!==false,'identity card is specific');
    r6hCheck((int)$h->db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE target_code='unified_metric_query'")->fetchColumn()===0,
        'unresolved identity cannot become an aggregate fact read');
    $beforeModels=$h->models;
    $stillMissing=$h->choose($identity,['semantic_reply'=>'__supplement__','supplement_text'=>'测试丙']);
    r6hCheck($stillMissing['status']==='WAITING_CLARIFICATION'
        && strpos($stillMissing['clarification']['question'],'核对完整姓名')!==false,
        'another missing name keeps a useful correction card');
    r6hCheck((int)$h->db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE target_code='unified_metric_query'")->fetchColumn()===0,
        'a second missing name still cannot read aggregate facts');
    $result=$h->choose($stillMissing,['semantic_reply'=>'__supplement__','supplement_text'=>'测试乙']);
    r6hCheck($result['status']==='COMPLETED','corrected exact member completes: '.($result['reason']??''));
    r6hCheck($result['run_id']===$metric['run_id'],'correction continues same Run');
    r6hCheck($h->models===$beforeModels,'identity correction does not reinterpret metric or date');
    $evidence=$h->private->read($h->row($result)['evidence_ref']);
    r6hCheck($evidence['query']['business_filters']['selection_ref']==='member:19','authorized exact identity bound');
    r6hCheck($evidence['query']['metric_codes']===['sales_collected_amount'],'selected metric frozen');
    $answer=$h->private->read($h->row($result)['answer_ref']);
    r6hCheck(strpos(json_encode($answer,JSON_UNESCAPED_UNICODE),'会员“测试乙”')!==false,
        'answer names the corrected authorized member: '.json_encode(array_keys($answer)));
    r6hCheck((int)$h->db->query("SELECT COUNT(*) FROM mohe_ai_attempt WHERE target_code='unified_metric_query' AND state='SUCCEEDED'")->fetchColumn()===1,
        'one fact read only after exact identity');
    // The identity gate uses the accepted object term and literal source,
    // not a fixed Chinese performance question template.
    $h->understandingOverride=['goal'=>'查询会员本月实际收款销售额','status'=>'understood','requirements'=>[[
        'id'=>'r1','meaning'=>'会员本月实际收款销售额','fields'=>['object_kind','object_relation','metric_codes','periods'],
        'values'=>['object_kind'=>'member','object_relation'=>'selection','metric_terms'=>['实际收款销售额'],
            'periods'=>[['kind'=>'month_offset','offset_months'=>0]]],
        'evidence'=>[['message_id'=>'current','quote'=>'会员管慧在本月实际收款销售额多少？']],
    ]]];
    $h->semanticIntent['periods']=[['kind'=>'month_offset','offset_months'=>0]];
    $unknown=$h->start('会员管慧在本月实际收款销售额多少？');
    r6hCheck($unknown['status']==='WAITING_CLARIFICATION','dated member request asks identity');
    $dated=$h->choose($unknown,['semantic_reply'=>'__supplement__','supplement_text'=>'测试乙']);
    r6hCheck($dated['status']==='COMPLETED','dated correction completes in same Run');
    $datedEvidence=$h->private->read($h->row($dated)['evidence_ref']);
    r6hCheck($datedEvidence['query']['business_filters']['selection_ref']==='member:19','dated correction binds exact member');
    r6hCheck($datedEvidence['query']['start_date']!==$evidence['query']['start_date'],'accepted month remains distinct from initial default day');
    $failure=new \ReflectionMethod($h->gateway,'failureGuidance');
    if (PHP_VERSION_ID<80100) $failure->setAccessible(true);
    $contract=$failure->invoke($h->gateway,'AI_QUERY_CONTRACT_UNAVAILABLE',true);
    r6hCheck(strpos($contract['question']??'','暂不支持查询')!==false,
        'Reader contract mismatch is explained as unsupported rather than a transient retry');
    echo "PASS R8F member identity continuation (synthetic only)\n";
} finally { $h->close(); }
}
