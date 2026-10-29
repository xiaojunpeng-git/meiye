<?php
declare(strict_types=1);
use app\services\cashier\v3\card\CashierV3OrdinaryCardSaleAdjustment as Rule;
use app\services\cashier\v3\CashierV3CommandException;
/** Reuse the real ordinary-card guard and its tampering regression suite. */
require __DIR__ . '/r49-ordinary-card-sale-adjustment.php';
$memberCard=$base;
$memberCard['definitionVersion']=23;
$memberCard['components'][0]['productVersion']=24;
$lightDraft=$base;$lightDraft['components']=[];
try{Rule::validate($memberCard,$lightDraft,1,12);throw new RuntimeException('unsealed member input unexpectedly accepted');}
catch(CashierV3CommandException $e){check49('reproduce unsealed member versus lightweight preparation',true);}
$memberSealed=Rule::seal($memberCard,1,12,60000);
check49('member proof validates with lightweight preparation',Rule::validate($memberSealed,$lightDraft,1,12)===0);
check49('member proof survives serialized frozen order',Rule::validate(json_decode(json_encode($memberSealed),true),$lightDraft,1,12)===0);
check49('member original proof keeps component identities and counts',$memberSealed['components'][0]['productId']===$base['components'][0]['productId']&&$memberSealed['components'][0]['writeTimes']===3);
rejected49('member proof does not permit deleting a purchased project',static function(&$p){array_pop($p['components']);},$memberSealed,$lightDraft);
rejected49('member proof does not permit lowering original counts',static function(&$p){$p['components'][0]['writeTimes']=1;},$memberSealed,$lightDraft);
rejected49('member proof does not permit replacing original SKU',static function(&$p){$p['components'][0]['skuUnique']='replacement';},$memberSealed,$lightDraft);
$other=['sourceKind'=>'card_package','ruleType'=>'choice_count','components'=>[['productId'=>31]]];
check49('non-normal card definitions remain unchanged',Rule::seal($other,1,12,60000)===$other);
