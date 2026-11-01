<?php
/**
 * Compile historical tender evidence for ALL source-matched paid sales.
 * This script does not write a database, call a payment service, or infer a
 * wallet deduction from a residual amount. Original combination rows outrank
 * legacy summary columns; every difference and unresolved lineage is retained.
 */
declare(strict_types=1);

function history010Cents($value): int
{
    $value=(string)$value;
    if(!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/D',$value,$m)) throw new RuntimeException('INVALID_MONEY:'.$value);
    return (int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
}

/** Historical balance snapshots may be negative; payment amounts must not be. */
function history010SignedBalanceCents($value): int
{
    $value=(string)$value;
    return substr($value,0,1)==='-' ? -history010Cents(substr($value,1)) : history010Cents($value);
}

/** A transition is proof only when both snapshots and the deduction reconcile. */
function history010ClosedLedgerTransition(array $before,array $after): ?array
{
    if((int)$before['uid']!==(int)$after['uid'] || (int)$before['id']>=(int)$after['id'] || (int)$after['pm']!==0) return null;
    $bp=history010SignedBalanceCents($before['ben_money']);$bg=history010SignedBalanceCents($before['give_money']);
    $ap=history010SignedBalanceCents($after['ben_money']);$ag=history010SignedBalanceCents($after['give_money']);
    $bt=history010SignedBalanceCents($before['balance']);$at=history010SignedBalanceCents($after['balance']);
    $p=$bp-$ap;$g=$bg-$ag;
    if($bp+$bg!==$bt || $ap+$ag!==$at || min($p,$g)<0 || $p+$g!==$bt-$at || $bt-$at!==history010Cents($after['number'])) return null;
    return ['principalCents'=>$p,'bonusCents'=>$g,'beforeLedgerId'=>(int)$before['id']];
}

/** 原银行转账已有独立记账代码；只按权威枚举5映射，不能再与其他收款合并。 */
function history010Tender(int $cashType, int $amount, array $names, string $sourceId): array
{
    $codes=[1=>'unionpay',2=>'dianping_voucher',3=>'douyin_voucher',4=>'wechat',
        5=>'bank_transfer',6=>'partner_collection',7=>'cash',8=>'other_income',10=>'other_collection'];
    if(!isset($names[$cashType])) throw new RuntimeException('SOURCE_CASH_TYPE_MISSING:'.$cashType);
    if($cashType===9) $kind='historical_entry';
    elseif(isset($codes[$cashType])) $kind='bookkeeping';
    else throw new RuntimeException('SOURCE_CASH_TYPE_UNMAPPED:'.$cashType);
    return ['sourceId'=>$sourceId,'kind'=>$kind,'sourceCashType'=>$cashType,
        'method'=>$kind==='historical_entry'?'old_card_entry':$codes[$cashType],
        'sourceName'=>$cashType===8?'其他收入':$names[$cashType],'sourceOriginalName'=>$names[$cashType],'amountCents'=>$amount];
}

/** Pure evidence transformation; no rounding, source mutations or payment replay. */
function history010PaymentDocument(array $order, array $sourceOrder, array $combination, array $names, array $ledgers, array $transitions): array
{
    $id=(int)$order['id'];$amount=history010Cents($sourceOrder['pay_price']);$issues=[];$tenders=[];
    if((string)$order['order_id']!==(string)$sourceOrder['order_id']) $issues[]='source_order_identity_mismatch';
    foreach(['pay_type','pay_price','paid','refund_status','pid','order_type','type'] as $field)
        if((string)$order[$field] !== (string)$sourceOrder[$field]) $issues[]='source_target_drift:'.$field;
    $payType=(string)$sourceOrder['pay_type'];
    try {
        if($payType==='yue') $tenders[]=['sourceId'=>'order:'.$id,'kind'=>'wallet','amountCents'=>$amount];
        elseif($payType==='cash') $tenders[]=history010Tender((int)$sourceOrder['cash_choose'],$amount,$names,'order:'.$id);
        elseif($payType==='combination') {
            if(!$combination) $issues[]='combination_details_missing';
            foreach($combination as $c) {
                $value=history010Cents($c['price']);$key='combination:'.$c['id'];
                if((int)$c['active_pay']===3) {
                    $subtype=(string)($c['pay_sub_type']??'balance');
                    if(!in_array($subtype,['','balance','card_upgrade'],true)) { $issues[]='unknown_wallet_subtype:'.$subtype;continue; }
                    $tenders[]=['sourceId'=>$key,'kind'=>$subtype==='card_upgrade'?'card_upgrade':'wallet','amountCents'=>$value,
                        'sourceUpgradeOrderId'=>(int)($c['upgrade_old_oid']??0),
                        'sourceUpgradeDetailId'=>(int)($c['upgrade_old_cart_info_id']??0)];
                } elseif((int)$c['active_pay']===2) $tenders[]=history010Tender((int)$c['cash_choose'],$value,$names,$key);
                else $issues[]='unknown_combination_active_pay:'.$c['active_pay'];
            }
        } else $issues[]='unsupported_source_pay_type:'.$payType;
    } catch(Throwable $e) { $issues[]=$e->getMessage(); }
    $totals=['wallet'=>0,'bookkeeping'=>0,'historical_entry'=>0,'card_upgrade'=>0];
    foreach($tenders as $t) $totals[$t['kind']]+=$t['amountCents'];
    if(array_sum($totals)!==$amount) $issues[]='payment_total_not_closed';
    $wallet=$totals['wallet'];$split=null;$evidence=[];
    if($wallet>0) {
        // The current approved metadata is usable only if it closes to the
        // independently reconstructed tender. It is never silently overwritten.
        $p=history010Cents($order['paid_ben_amount']);$g=history010Cents($order['paid_give_amount']);
        if((int)$order['paid_balance_ready']===1 && $p+$g===$wallet) {
            $split=['principalCents'=>$p,'bonusCents'=>$g,'rule'=>'existing_approved_source_metadata'];
        } else {
            $matching=array_values(array_filter($ledgers,static function(array $l) use($sourceOrder,$wallet):bool {
                return (int)$l['uid']===(int)$sourceOrder['uid'] && (int)$l['pm']===0 && history010Cents($l['number'])===$wallet;
            }));
            if(count($ledgers)!==1 || count($matching)!==1) $issues[]='wallet_ledger_not_unique_or_amount_mismatch';
            else {
                $l=$matching[0];$evidence=['ledgerId'=>(int)$l['id'],'sourceMemberId'=>(int)$l['uid'],
                    'principalAfterCents'=>history010SignedBalanceCents($l['ben_money']),'bonusAfterCents'=>history010SignedBalanceCents($l['give_money'])];
                if(isset($transitions[(int)$l['id']])) {
                    $split=$transitions[(int)$l['id']]+['rule'=>'original_ledger_closed_transition'];
                } else $split=['principalCents'=>0,'bonusCents'=>$wallet,'rule'=>'user_approved_unknown_split_to_bonus'];
                // No conversion to bonus is allowed on top of populated but
                // conflicting metadata: that is a source conflict to inspect.
                if((int)$order['paid_balance_ready']===1 || $p+$g>0) $issues[]='existing_wallet_source_conflicts_with_tender';
            }
        }
    }
    $differences=[];
    foreach(['wallet'=>'yue_pay_price','bookkeeping'=>'cash_pay_price'] as $kind=>$column)
        if($totals[$kind]!==history010Cents($order[$column]))
            $differences[$column]=['storedCents'=>history010Cents($order[$column]),'evidencedCents'=>$totals[$kind]];
    if((int)$order['pid']!==0 || (int)($order['has_children']??0)>0) $issues[]='parent_child_requires_group_materialization';
    if($totals['card_upgrade']>0 || (int)($order['card_upgrade_use_oid']??0)>0) $issues[]='card_upgrade_requires_linked_materialization';
    if((int)($order['has_transfer']??0)>0) $issues[]='card_transfer_requires_linked_materialization';
    if((int)$sourceOrder['refund_status']!==0) $issues[]='terminal_or_partial_requires_reversal_evidence';
    if(history010Cents($order['debt_amount'])>0) $issues[]='debt_requires_linked_materialization';
    if($amount%100 || count(array_filter($tenders,static function(array $t):bool{return $t['amountCents']%100!==0;}))
        || ($split && ($split['principalCents']%100 || $split['bonusCents']%100))) $issues[]='fractional_requires_integer_boundary';
    return ['sourceOrderId'=>$id,'sourceOrderNo'=>$sourceOrder['order_id'],'storeId'=>(int)$order['store_id'],
        'targetMemberId'=>(int)$order['uid'],'sourceMemberId'=>(int)$sourceOrder['uid'],
        'sourcePayType'=>$payType,'sourceRefundStatus'=>(int)$sourceOrder['refund_status'],
        'sourceAmountCents'=>$amount,'tenders'=>$tenders,'totals'=>$totals,'walletSplit'=>$split,'walletEvidence'=>$evidence,
        'summaryDifferences'=>$differences,'issues'=>array_values(array_unique($issues)),
        'evidenceSHA256'=>hash('sha256',json_encode([$order,$sourceOrder,$combination,$ledgers,$split],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))];
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__) return;
if(PHP_SAPI!=='cli' || ($argv[1]??'')!=='--collect') throw new RuntimeException('READ_ONLY_COLLECT_REQUIRED');
$c=parse_ini_file('/root/.mysql57-008.cnf',true,INI_SCANNER_RAW)['client'];
$db=new PDO('mysql:host=127.0.0.1;port=3307;dbname=mohe_010_app;charset=utf8mb4',
    $c['user']??'root',$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('SET SESSION MAX_EXECUTION_TIME=20000');$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
try {
    $names=$db->query('SELECT id,name FROM mohe_010_refresh.eb_cash_type ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $source=[];foreach($db->query('SELECT id,order_id,uid,pid,pay_type,cash_choose,pay_price,paid,refund_status,order_type,type FROM mohe_010_refresh.eb_store_order WHERE paid=1 AND order_type=0 AND type IN(0,11)') as $r) $source[(int)$r['id']]=$r;
    $comb=[];foreach($db->query('SELECT * FROM mohe_010_refresh.eb_combination_order WHERE type=1 ORDER BY id') as $r) $comb[(int)$r['order_id']][]=$r;
    $prior=[];$ledgers=[];$transitions=[];
    foreach($db->query('SELECT id,uid,link_id,pm,type,number,balance,ben_money,give_money FROM mohe_010_refresh.eb_user_money ORDER BY uid,id') as $l) {
        $uid=(int)$l['uid'];$p=$prior[$uid]??null;$prior[$uid]=$l;
        if($l['type']!=='pay_product') continue;
        $ledgers[(int)$l['link_id']][]=$l;
        if(!$p || (int)$l['pm']!==0) continue;
        $transition=history010ClosedLedgerTransition($p,$l);
        if($transition!==null)$transitions[(int)$l['id']]=$transition;
    }
    $rows=[];$foreign=[];$counts=['total'=>0,'paymentClosed'=>0,'tenderOnlyReady'=>0,'summaryDifferent'=>0,'walletMetadataNew'=>0];
    $reasons=[];$stores=[];$totals=['wallet'=>0,'bookkeeping'=>0,'historical_entry'=>0,'card_upgrade'=>0];
    $query=$db->query("SELECT o.id,o.order_id,o.uid,o.store_id,o.pid,o.pay_type,o.cash_choose,o.pay_price,o.paid,o.refund_status,o.order_type,o.type,
        o.yue_pay_price,o.cash_pay_price,o.debt_amount,o.paid_balance_ready,o.paid_ben_amount,o.paid_give_amount,o.card_upgrade_use_oid,
        EXISTS(SELECT 1 FROM eb_store_order child WHERE child.pid=o.id) has_children,
        EXISTS(SELECT 1 FROM eb_store_order_status st WHERE st.oid=o.id AND st.change_type='card_transfer') has_transfer
        FROM eb_store_order o WHERE o.paid=1 AND o.order_type=0 AND o.type IN(0,11) ORDER BY o.id");
    while($o=$query->fetch(PDO::FETCH_ASSOC)) {
        $id=(int)$o['id'];if(!isset($source[$id])) { $foreign[]=$id;continue; }
        $doc=history010PaymentDocument($o,$source[$id],$comb[$id]??[],$names,$ledgers[$id]??[],$transitions);
        ++$counts['total'];if(!in_array('payment_total_not_closed',$doc['issues'],true)) ++$counts['paymentClosed'];
        if(!$doc['issues']) ++$counts['tenderOnlyReady'];
        if($doc['summaryDifferences']) ++$counts['summaryDifferent'];
        if($doc['walletSplit'] && $doc['walletSplit']['rule']!=='existing_approved_source_metadata') ++$counts['walletMetadataNew'];
        foreach($doc['issues'] as $issue) $reasons[$issue]=($reasons[$issue]??0)+1;
        foreach($totals as $kind=>$value) $totals[$kind]+=$doc['totals'][$kind];
        $store=(int)$o['store_id'];if(!isset($stores[$store])) $stores[$store]=['total'=>0,'tenderOnlyReady'=>0,'summaryDifferent'=>0];
        ++$stores[$store]['total'];if(!$doc['issues']) ++$stores[$store]['tenderOnlyReady'];if($doc['summaryDifferences']) ++$stores[$store]['summaryDifferent'];
        $rows[]=$doc;
    }
    ksort($reasons);ksort($stores);
    echo json_encode(['mode'=>'read-only-payment-evidence-plan','source'=>'mohe_010_refresh','target'=>'mohe_010_app',
        'generatedAt'=>date(DATE_ATOM),'counts'=>$counts,'totalsCents'=>$totals,'issues'=>$reasons,'stores'=>$stores,
        'sourceCashTypeNames'=>$names,'snapshotExternalIds'=>$foreign,'documents'=>$rows,
        'limitations'=>['Tender readiness is not complete sales/card/service migration readiness.',
            'No database writes or current asset changes.','Fractional and linked/terminal records remain individually tracked.',
            'Names come from the 007 cash dictionary, not guessed from comments or 008 IDs.']],
        JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
} finally { $db->rollBack(); }
