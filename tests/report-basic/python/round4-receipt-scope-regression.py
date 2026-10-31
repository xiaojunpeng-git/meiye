"""Read-only parity against the exact frozen pre-scope receipt reader, without saving a source copy."""
from pathlib import Path
import json,hashlib,base64,shlex,subprocess
R=Path(__file__).resolve().parents[3];E=R/'tests/report-basic/evidence/round4-gap-audit'
new=(R/'后端代码/app/services/report/StoreReconciliationReceiptReader.php').read_text()
old=new.replace(', ?array $originalOrderIds = null): array','): array')
a=old.index('        if($originalOrderIds!==null){');b=old.index('        $timezone',a);old=old[:a]+old[b:]
a=old.index('        // Optional exact original IDs');b=old.index('        // 一次核实归档证明',a);old=old[:a]+old[b:]
a=old.index('        // An explicit original-ID request');b=old.index('        // V3技术卡载体',a);old=old[:a]+old[b:]
assert hashlib.sha256(old.encode()).hexdigest()=='23973dd4f23ca7d728cba0d2b0e69e8409e48d4da52a01c634a188affa4b6cc4'
old=old.replace('final class StoreReconciliationReceiptReader','final class StoreReconciliationReceiptReaderBeforeScope',1)
src={'app/services/report/StoreReconciliationReceiptReader.php':new,'app/services/report/StoreReconciliationReceiptReaderBeforeScope.php':old}
for name in ['ReportLegacyArchiveProofServices']:
 path='app/services/report/'+name+'.php';src[path]=(R/'后端代码'/path).read_text()
p=(R/'tests/report-basic/php/round4-sidebar-gap-audit.php').read_text()
p=p.replace("$approved=['app/", "$approved=['"+"','".join(src)+"','app/",1)
a=p.index(' $stores=');b=p.index('\n}finally');p=p[:a]+r'''
 $before=new app\services\report\StoreReconciliationReceiptReaderBeforeScope();
 $after=new app\services\report\StoreReconciliationReceiptReader();$methods=$after->methods();$range=['start_date'=>'2026-10-01','end_date'=>'2026-10-30'];
 $defaultOld=$before->receipts([4],$methods,$range,'0');$defaultNew=$after->receipts([4],$methods,$range,'0');
 $businessOld=$before->receipts([4],$methods,$range,'0',true);$businessNew=$after->receipts([4],$methods,$range,'0',true);
 $scope=$after->receipts([4],$methods,$range,'0',true,[117195,117212]);
 $docs=think\facade\Db::name('store_order')->whereIn('id',[117195,117212])->column('order_id');
 $expected=array_values(array_filter($businessOld,static fn(array $r):bool=>in_array($r['source_kind'],['历史订单收款','历史组合收款'],true)&&in_array($r['document_no'],$docs,true)));
 $hash=static fn($r):string=>hash('sha256',json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 $checks=['default_unchanged'=>$hash($defaultOld)===$hash($defaultNew),'business_date_unchanged'=>$hash($businessOld)===$hash($businessNew),'narrowed_exact_originals'=>$hash($scope)===$hash($expected)];
 if(in_array(false,$checks,true))throw new RuntimeException('RECEIPT_SCOPE_PARITY');
 echo json_encode(['checks'=>$checks,'default_rows'=>count($defaultNew),'business_rows'=>count($businessNew),'scoped_rows'=>count($scope),'default_hash'=>$hash($defaultNew),'business_hash'=>$hash($businessNew),'scope_hash'=>$hash($scope),'business_writes'=>0],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
'''+p[b:]
ov={k:{'base64':base64.b64encode(v.encode()).decode(),'sha256':hashlib.sha256(v.encode()).hexdigest()} for k,v in src.items()}
code='eval(substr(base64_decode("'+base64.b64encode(p.encode()).decode()+'"),5));'
out=subprocess.run(['ssh','-o','BatchMode=yes','-o','LogLevel=ERROR','mohe-server','cd /www/wwwroot/010.cc3798.com && /www/server/php/74/bin/php -r '+shlex.quote(code)],input=json.dumps({'site':'010','overrides':ov}).encode(),capture_output=True,timeout=120)
assert out.returncode==0,out.stderr.decode()[:3000]
data=json.loads(out.stdout);data['source_sha256']=hashlib.sha256(new.encode()).hexdigest()
(E/'010-receipt-scope-regression.json').write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n');print(data)
