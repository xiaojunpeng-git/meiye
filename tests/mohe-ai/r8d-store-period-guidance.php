<?php
// End-to-end proof for a unique authorized store shorthand with no period.
// The first turn must ask only for dates and the confirmed card must execute
// the same registered metric/store without making any model request.
require_once __DIR__.'/r6-gateway-harness.php';

$checks=0;
$assert=static function(bool $condition,string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    ++$checks;
};

$harness=new R6GatewayHarness(3,[1,2],'platform');
try {
    $card=$harness->start('一号的业绩是多少？');
    $assert(($card['status']??null)==='WAITING_CLARIFICATION','missing period returns a question card');
    $assert(array_column((array)($card['clarification']['fields']??[]),'key')===['summary_period'],
        'question card asks only for the missing period');
    $assert(($card['clarification']['confirmed_summary'][0]['value']??null)==='一号门店',
        'question card confirms the authorized resolved store');
    $assert($harness->models===0,'incomplete closed request never waits for a model');
    $assert($harness->queries===1,'only the authorized store catalogue is read before confirmation');

    $custom=$harness->choose($card,['summary_period'=>'CUSTOM']);
    $assert(($custom['status']??null)==='WAITING_CLARIFICATION'
        && array_column((array)($custom['clarification']['fields']??[]),'key')===['start_date','end_date'],
        'custom choice opens validated date-range fields');
    $answer=$harness->choose($custom,['start_date'=>'2026-09-01','end_date'=>'2026-09-30']);
    $assert(($answer['status']??null)==='COMPLETED','confirmed period completes the same run');
    $assert($harness->models===0,'confirmed card still makes no model request');
    $evidence=$harness->private->read($harness->row($answer)['evidence_ref']);
    $query=(array)($evidence['query']??[]);
    $assert(($query['metric_codes']??[])===['cash_performance'],'unqualified performance uses registered cash performance');
    $assert(($query['store_ids']??[])===[1],'query retains the exact authorized store id');
    $assert([($query['start_date']??null),($query['end_date']??null)]===['2026-09-01','2026-09-30'],
        'query uses the customer-confirmed period');
    $row=$harness->row($answer);
    $assert(strpos((string)($row['counters_json']??''),'deterministic_registered_store_summary_period_requested')!==false,
        'missing-period admission is auditable without storing question text');

    $quick=$harness->start('一号的业绩是多少？');
    $month=$harness->choose($quick,['summary_period'=>'THIS_MONTH']);
    $assert(($month['status']??null)==='COMPLETED','quick month choice completes without model');
    $monthEvidence=$harness->private->read($harness->row($month)['evidence_ref']);
    $assert(($monthEvidence['query']['metric_codes']??[])===['cash_performance']
        && ($monthEvidence['query']['store_ids']??[])===[1]
        && ($monthEvidence['query']['start_date']??null)===substr($monthEvidence['query']['end_date']??'',0,7).'-01',
        'quick month keeps object and metric and uses current month range');
    $assert($harness->models===0,'both custom and quick choices bypass model');

    $reordered=$harness->start('本月一号门店业绩是多少？');
    $assert(($reordered['status']??null)==='COMPLETED','date before store and metric is admitted');
    $reorderedEvidence=$harness->private->read($harness->row($reordered)['evidence_ref']);
    $assert(($reorderedEvidence['query']['store_ids']??[])===[1]
        && ($reorderedEvidence['query']['metric_codes']??[])===['cash_performance']
        && $harness->models===0,'reordered wording keeps the exact store and cash metric');
} finally {
    $harness->close();
}

echo 'R8D store period guidance: '.$checks.' checks PASS'.PHP_EOL;
