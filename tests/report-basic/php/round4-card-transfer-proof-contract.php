<?php
declare(strict_types=1);
require dirname(__DIR__,3).'/后端代码/app/services/report/ReportCardTransferProofServices.php';
use app\services\report\ReportCardTransferProofServices as Proof;
/** 转卡只承认完整成功持有人链，反例保护旧订单不被任意绑定到当前会员。 */
$one=[['origin_member_id'=>11,'member_id_before'=>11,'member_id_after'=>22]];
$two=array_merge($one,[['origin_member_id'=>11,'member_id_before'=>22,'member_id_after'=>33]]);
$cases=[Proof::verify(11,22,$one)===[11,22],Proof::verify(11,33,$two)===[11,22,33],
 Proof::verify(11,33,$one)===[],Proof::verify(11,22,[])===[],
 Proof::verify(99,22,$one)===[],Proof::verify(11,33,array_reverse($two))===[],
 Proof::verify(11,22,array_merge($one,$one))===[],Proof::verify(0,22,$one)===[]];
foreach($cases as $i=>$ok)if(!$ok)throw new RuntimeException('transfer proof case '.$i);
echo 'round4-card-transfer-proof-contract PASS '.count($cases).PHP_EOL;
