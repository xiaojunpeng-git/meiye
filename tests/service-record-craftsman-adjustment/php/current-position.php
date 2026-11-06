<?php
/** Pure production allocator regression; no database or network effects. */
require '/var/www/html/vendor/autoload.php';
use app\services\cashier\v3\order\ServiceCraftsmanCurrentAllocation as A;
function ck($a,$b){if($a!==$b)throw new RuntimeException(json_encode([$a,$b]));}
$rows=[['positionId'=>1,'performanceIndependent'=>false,'allocationAmountCents'=>3850],['positionId'=>8,'performanceIndependent'=>true,'allocationAmountCents'=>3850]];
$r=A::calculate($rows,7700);ck(array_column($r,'allocationAmountCents'),[7700,7700]);ck(array_column($r,'laborWeight'),[100,100]);
$rows[0]['positionId']=8;$rows[0]['performanceIndependent']=true;$r=A::calculate($rows,7700);ck(array_column($r,'allocationAmountCents'),[3800,3900]);
$r[0]['allocationAmountManual']=true;$r[0]['allocationAmountCents']=2000;$r=A::calculate($r,7700);ck(array_column($r,'allocationAmountCents'),[2000,3900]);
$r=A::calculate([['positionId'=>2,'performanceIndependent'=>true,'laborWeight'=>100,'allocationAmountCents'=>3850],['positionId'=>8,'performanceIndependent'=>true,'laborWeight'=>100,'allocationAmountCents'=>3850]],7700);ck(array_column($r,'allocationAmountCents'),[7700,7700]);
$failed=false;try{A::calculate([['laborWeight'=>50.5]],7700);}catch(Throwable $e){$failed=true;}ck($failed,true);
echo "PASS current groups, independent amounts, integer tail, manual override, stale auto amount ignored, fractional ratio rejected\n";
