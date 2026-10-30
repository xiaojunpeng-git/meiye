"""008 named holder projection, in-memory candidate only and READ ONLY transaction.
Never deploys PHP, creates an appointment or changes entitlement records."""
import pathlib,json,base64,runpy,subprocess
root=pathlib.Path(__file__).resolve().parents[1];m=runpy.run_path(str(root/'scripts/r15-occupied-publish.py'),run_name='readonly')
source=(root/'scripts/r15-occupied-runtime.php').read_text();source=source[:source.index(' $svc=new')]
candidate=(root/'后端代码/app/services/cashier/v3/reservation/ImportedEntitlementReservationStoreServices.php').read_text().replace('<?php','',1)
source+=' eval(base64_decode('+json.dumps(base64.b64encode(candidate.encode()).decode())+'));\n'
source+=''' $h=$db->name('user_card_holder')->where('id',6550)->where('uid',421)->find();if(!$h)throw new RuntimeException('Current member holder mismatch');
$rows=app()->make(app\\services\\order\\StoreOrderCartInfoServices::class)->getOrderCardRelatedCartInfo((int)$h['oid'],421);
$hash=function()use($db,$h){return hash('sha256',json_encode($db->name('store_order_cart_info')->where('oid',(int)$h['oid'])->order('id')->select()->toArray()));};$before=$hash();
$out=(new app\\services\\cashier\\v3\\reservation\\ImportedEntitlementReservationStoreServices())->withCardBenefitsReservationUsage(421,$rows);$view=[];foreach($out as$row)if((int)$row['product_type']===6)$view[]=['detailId'=>(int)$row['id'],'physicalRemaining'=>(int)$row['write_surplus_times'],'usage'=>$row['reservation_usage'],'waiting'=>$row['waiting_service']];if($before!==$hash())throw new RuntimeException('Read mutated right');
echo json_encode(['result'=>'PASS','holderId'=>6550,'orderId'=>(int)$h['oid'],'projects'=>$view,'unchangedPhysicalRights'=>true,'businessWrites'=>0]);
}finally{$db->execute('ROLLBACK');}'''
try: result=json.loads(m['ssh']('008','/www/server/php/74/bin/php -- 008',source.encode()))
except subprocess.CalledProcessError as error:
 print(error.stderr.decode()[-1200:]);raise
(root/'tests/r15-card-occupancy/evidence/008-readonly.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));print(json.dumps(result,ensure_ascii=False))
