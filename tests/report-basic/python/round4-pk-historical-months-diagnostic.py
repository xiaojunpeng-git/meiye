"""Read-only candidate check after the separately approved provenance migration.
Only fixed local report classes are evaluated in-memory; no deployment or business write.
"""
from pathlib import Path
import json,base64,hashlib,shlex,subprocess
R=Path(__file__).resolve().parents[3]
E=R/'tests/report-basic/evidence/round4-gap-audit'
p=(R/'tests/report-basic/php/round4-sidebar-gap-audit.php').read_text()
names=['HistoricalPartnerSales007ReadServices','Sidebar007OriginalCashReadServices','StoreReconciliationReceiptReader','ReportLegacyArchiveProofServices','Sidebar007SalesCategoryReadServices','Sidebar007SourceMappingReadServices','ImportedPerformanceTypeProvenanceServices','Sidebar007PartnerAllocationReadServices']
paths=['app/services/report/'+n+'.php' for n in names]
p=p.replace("$approved=['app/", "$approved=['"+"','".join(paths)+"','app/",1)
a=p.index(' $stores=');b=p.index('\n}finally')
p=p[:a]+r'''
 $range=$input['range'];$id=(int)$input['store'];
 if(!in_array($id,[1,2,3,4,6,7,8,9,10,11],true)||$range['start']<'2026-04-01'||$range['end']>'2026-09-30')throw new RuntimeException('SCOPE');
 $cash=(new app\services\report\Sidebar007OriginalCashReadServices())->read([$id],$range);
 $partner=(new app\services\report\Sidebar007PartnerAllocationReadServices())->read([$id],$range);
 $from=(new DateTimeImmutable($range['start'].' 00:00:00',new DateTimeZone('Asia/Shanghai')))->getTimestamp();
 $to=(new DateTimeImmutable($range['end'].' 00:00:00',new DateTimeZone('Asia/Shanghai')))->modify('+1 day')->getTimestamp();
 $adjustments=think\facade\Db::name('yeji_pk')->where('store_id',$id)->where('date','>=',$from)->where('date','<',$to)->field('store_id,old_yeji')->select()->toArray();
 $cash['historical_original_count']=count($cash['historical_orders']);unset($cash['historical_orders'],$cash['by_order']);
 echo json_encode(['store'=>$id,'range'=>$range,'cash'=>$cash,'partner'=>$partner,'adjustments'=>$adjustments,'business_writes'=>0,'coverage_guard_bypassed_for_diagnosis'=>true],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
'''+p[b:]
overrides={}
for f in paths:
 raw=(R/'后端代码'/f).read_bytes()
 if f.endswith('Sidebar007OriginalCashReadServices.php'):
  raw=raw.replace(b" || $range['start'] < StoreUnifiedReportServices::COVERAGE_START",b'')
 if f.endswith('Sidebar007PartnerAllocationReadServices.php'):
  raw=raw.replace(b" || $range['start'] < StoreUnifiedReportServices::COVERAGE_START",b'')
 overrides[f]={'base64':base64.b64encode(raw).decode(),'sha256':hashlib.sha256(raw).hexdigest()}
code='eval(substr(base64_decode("'+base64.b64encode(p.encode()).decode()+'"),5));'
cmd='cd /www/wwwroot/010.cc3798.com && /www/server/php/74/bin/php -r '+shlex.quote(code)
results={}
import calendar,concurrent.futures

def one(store,month):
 period={'start':f'2026-{month:02d}-01','end':f'2026-{month:02d}-{calendar.monthrange(2026,month)[1]}'}
 out=subprocess.run(['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','mohe-server',cmd],input=json.dumps({'site':'010','store':store,'range':period,'overrides':overrides}).encode(),capture_output=True,timeout=120)
 if out.returncode:data={'error':out.stderr.decode()[:2500]}
 else:data=json.loads(out.stdout)
 return f'{store}:{month}',data
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
 futures=[pool.submit(one,store,month) for store in [1,2,3,4,6,7,8,9,10,11] for month in range(4,10)]
 for task in concurrent.futures.as_completed(futures):
  key,data=task.result();results[key]=data
  (E/'010-pk-historical-allstore-months-diagnostic.json').write_text(json.dumps({'months':results,'business_writes':0},ensure_ascii=False,indent=2)+'\n')
  print(key,{k:data.get(k,{}).get('complete') for k in ['cash','partner']},flush=True)
