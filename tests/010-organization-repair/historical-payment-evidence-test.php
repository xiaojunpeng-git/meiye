<?php
/** Evidence-policy regression: read-only compiler; never boots an app or connects a database. */
declare(strict_types=1);
require dirname(__DIR__,2).'/scripts/010-history-audit/historical_payment_plan.php';
$checks=[];
$check=static function(bool $ok,string $name)use(&$checks):void{
    if(!$ok) throw new RuntimeException($name);
    $checks[]=$name;
};
$names=[1=>'银联扫码',2=>'大众验劵',3=>'抖音验券',4=>'企微收款',5=>'银行转账',
    6=>'合作方收款',7=>'现金收款',8=>'其他收款',9=>'旧数据录入',10=>'充值赠送'];
$base=['id'=>7870,'order_id'=>'sample','uid'=>6143,'store_id'=>1,'pay_type'=>'combination',
    'pay_price'=>'118.00','paid'=>1,'refund_status'=>0,'pid'=>0,'order_type'=>0,'type'=>0,
    'paid_ben_amount'=>'0.00','paid_give_amount'=>'0.00','paid_balance_ready'=>0,
    'yue_pay_price'=>'0.00','cash_pay_price'=>'118.00','debt_amount'=>'0.00'];
$combo=[['id'=>77,'active_pay'=>3,'pay_sub_type'=>'balance','price'=>'118.00']];
$ledger=[['id'=>107,'uid'=>6143,'pm'=>0,'number'=>'118.00','ben_money'=>'280.01','give_money'=>'778.00']];
$doc=history010PaymentDocument($base,$base,$combo,$names,$ledger,[]);
$check($doc['totals']['wallet']===11800 && $doc['totals']['bookkeeping']===0,'actual_combo_overrides_wrong_cash_summary');
$check(count($doc['summaryDifferences'])===2,'wrong_legacy_summaries_remain_auditable');
$check($doc['walletSplit']['bonusCents']===11800 && $doc['walletSplit']['principalCents']===0,'unknown_genuine_wallet_split_to_bonus');
$check($doc['issues']===[],'closed_integer_independent_tender');
$known=$base;$known['paid_balance_ready']=1;$known['paid_ben_amount']='100.00';$known['paid_give_amount']='18.00';
$doc=history010PaymentDocument($known,$base,$combo,$names,$ledger,[]);
$check($doc['walletSplit']['principalCents']===10000 && $doc['walletSplit']['rule']==='existing_approved_source_metadata','preserve_approved_known_split');
$doc=history010PaymentDocument($base,$base,$combo,$names,$ledger,[107=>['principalCents'=>10000,'bonusCents'=>1800,'beforeLedgerId'=>106]]);
$check($doc['walletSplit']['rule']==='original_ledger_closed_transition','recover_original_closed_transition');
$doc=history010PaymentDocument($base,$base,$combo,$names,array_merge($ledger,$ledger),[]);
$check($doc['walletSplit']===null && in_array('wallet_ledger_not_unique_or_amount_mismatch',$doc['issues'],true),'ambiguous_ledger_cannot_create_wallet_split');
$conflict=$known;$conflict['paid_ben_amount']='101.00';
$doc=history010PaymentDocument($conflict,$base,$combo,$names,$ledger,[]);
$check(in_array('existing_wallet_source_conflicts_with_tender',$doc['issues'],true),'conflicting_existing_split_blocks_readiness');
$upgrade=$combo;$upgrade[0]['pay_sub_type']='card_upgrade';
$doc=history010PaymentDocument($base,$base,$upgrade,$names,[],[]);
$check($doc['walletSplit']===null && $doc['totals']['wallet']===0 && in_array('card_upgrade_requires_linked_materialization',$doc['issues'],true),'upgrade_credit_is_not_wallet_payment');
$check(history010Tender(8,100,$names,'x')['method']==='other_income','source_type8_is_actual_other_income');
$check(history010Tender(5,100,$names,'x')['method']==='bank_transfer' && history010Tender(7,100,$names,'x')['method']==='cash','actual_007_cash_dictionary_ids');
$check(history010Tender(9,100,$names,'x')['kind']==='historical_entry','old_entry_is_not_cash_collection');
$fraction=$base;$fraction['pay_price']='118.01';$fractionCombo=$combo;$fractionCombo[0]['price']='118.01';
$doc=history010PaymentDocument($fraction,$fraction,$fractionCombo,$names,[],[]);
$check(in_array('fractional_requires_integer_boundary',$doc['issues'],true),'fractional_values_are_not_silently_rounded');
$terminal=$base;$terminal['refund_status']=2;$terminal['pid']=123;
$doc=history010PaymentDocument($terminal,$terminal,$combo,$names,$ledger,[]);
$check(in_array('terminal_or_partial_requires_reversal_evidence',$doc['issues'],true) && in_array('parent_child_requires_group_materialization',$doc['issues'],true),'terminal_parent_require_linked_evidence');
$bad=$combo;$bad[0]['price']='117.00';
$doc=history010PaymentDocument($base,$base,$bad,$names,$ledger,[]);
$check(in_array('payment_total_not_closed',$doc['issues'],true),'underpaid_combo_blocks_readiness');
$check(history010SignedBalanceCents('-48.00')===-4800,'negative_historical_balance_preserved');
$rejected=false;try{history010Cents('-48.00');}catch(RuntimeException $e){$rejected=true;}
$check($rejected,'negative_tender_stays_invalid');
$before=['id'=>106,'uid'=>6143,'balance'=>'218.00','ben_money'=>'200.00','give_money'=>'18.00'];
$after=['id'=>107,'uid'=>6143,'pm'=>0,'number'=>'118.00','balance'=>'100.00','ben_money'=>'100.00','give_money'=>'0.00'];
$check(history010ClosedLedgerTransition($before,$after)===['principalCents'=>10000,'bonusCents'=>1800,'beforeLedgerId'=>106],'closed_ledger_snapshots_prove_split');
$before['balance']='219.00';$after['balance']='101.00';
$check(history010ClosedLedgerTransition($before,$after)===null,'equal_unknown_offsets_do_not_prove_split');
$before['uid']=99;
$check(history010ClosedLedgerTransition($before,$after)===null,'cross_member_ledger_never_proves_split');
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'databaseWrites'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),PHP_EOL;
