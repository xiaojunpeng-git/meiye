<?php
declare(strict_types=1);

require __DIR__.'/../../../后端代码/vendor/autoload.php';

use app\services\report\Sidebar007PkBottomReadServices;

function checkBottom(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$cash=static fn():array=>['by_store'=>[
    7=>['complete'=>true,'amount_cents'=>1200000],
    8=>['complete'=>true,'amount_cents'=>600000]]];
$partner=static fn():array=>['by_store'=>[7=>200000,8=>100000]];
$adjustments=static fn():array=>[
    ['store_id'=>7,'old_yeji'=>'200.00'],
    ['store_id'=>8,'old_yeji'=>'-200.00']];
$reader=new Sidebar007PkBottomReadServices($cash,$partner,$adjustments);
$ready=$reader->read([7,8],'2027-03');
checkBottom($ready['complete']&&$ready['scope']===['start'=>'2026-09-01','end'=>'2027-02-28']
    &&$ready['by_store'][7]===170000&&$ready['by_store'][8]===80000,
    '六个月现金减本店partner实配、加本店调整后除六并截整元');
$old=$reader->read([7,8],'2026-10');
checkBottom($old['complete']&&$old['by_store'][7]===170000
    &&$old['scope']===['start'=>'2026-04-01','end'=>'2026-09-30'],
    '已认证007原来源可覆盖上线前六个月，不得用V3上线日期误挡');
$outside=new Sidebar007PkBottomReadServices($cash,$partner,
    static fn():array=>[['store_id'=>9,'old_yeji'=>'1.00']]);
try{$outside->read([7,8],'2027-03');throw new RuntimeException('应阻断越权历史调整');}
catch(LogicException $expected){}
$incomplete=new Sidebar007PkBottomReadServices(static fn():array=>['by_store'=>[
    7=>['complete'=>false,'amount_cents'=>100],8=>['complete'=>true,'amount_cents'=>600000]]],
    $partner,$adjustments);
$partial=$incomplete->read([7,8],'2027-03');
checkBottom($partial['by_store'][7]===null&&$partial['by_store'][8]===80000,
    '单店缺原单证据只留该店底标缺口');

echo "sidebar-007-pk-bottom-read-contract PASS\n";
