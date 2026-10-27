<?php
/** 实际线上19种业务枚举+未知值，统计/流水/明细必须同一中文投影。 */
require dirname(__DIR__,3).'/后端代码/vendor/autoload.php';
use app\services\product\inventory\InventoryMovementDisplayServices;
$types=['cashier_presale_stock_repair','cashier_sale','cashier_sale_void','cross_transfer_in','cross_transfer_out','cross_transfer_withdraw','gift_product_claim_outbound','gift_product_claim_void','manual_inbound','manual_inbound_reversal','manual_outbound','manual_outbound_reversal','presale_claim_outbound','presale_claim_void','salon_usage_issue','salon_usage_issue_reversal','salon_usage_return','stock_count_gain','stock_count_loss'];
$readers=[['app\\services\\product\\inventory\\InventoryMovementAnalyticsServices','sourceTypeName'],['app\\services\\product\\inventory\\InventoryMovementQueryServices','movementLabel'],['app\\services\\product\\inventory\\query\\InventoryMovementUnifiedQueryProvider','movementName']];
foreach($types as$type){$label=InventoryMovementDisplayServices::typeName($type);if(!preg_match('/[\x{4e00}-\x{9fff}]/u',$label)||$label==='其他库存业务')throw new RuntimeException('missing source:'.$type);foreach($readers as[$class,$method]){$obj=(new ReflectionClass($class))->newInstanceWithoutConstructor();$m=new ReflectionMethod($obj,$method);$m->setAccessible(true);if($m->invoke($obj,$type)!==$label)throw new RuntimeException('inconsistent label:'.$type);}}
if(InventoryMovementDisplayServices::typeName('new_internal_source')!=='其他库存业务')throw new RuntimeException('unknown code leaked');
echo json_encode(['result'=>'PASS','observedSourceTypes'=>count($types),'readers'=>count($readers),'unknownCodeChinese'=>true]),PHP_EOL;
