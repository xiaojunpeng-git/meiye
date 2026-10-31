"""Read-only candidate check after the separately approved provenance migration.
Only fixed local report classes are evaluated in-memory; no deployment or business write.
"""
from pathlib import Path
import json,base64,hashlib,shlex,subprocess
R=Path(__file__).resolve().parents[3]
E=R/'tests/report-basic/evidence/round4-gap-audit'
p=(R/'tests/report-basic/php/round4-sidebar-gap-audit.php').read_text()
names=['Sidebar007PkBottomReadServices','Sidebar007PartnerAllocationReadServices','HistoricalPartnerSales007ReadServices','Sidebar007OriginalCashReadServices','StoreReconciliationReceiptReader','ReportLegacyArchiveProofServices','Sidebar007SalesCategoryReadServices','Sidebar007SourceMappingReadServices','ImportedPerformanceTypeProvenanceServices']
paths=['app/services/report/'+n+'.php' for n in names]
p=p.replace("$approved=['app/", "$approved=['"+"','".join(paths)+"','app/",1)
a=p.index(' $stores=');b=p.index('\n}finally')
p=p[:a]+r'''
 $result=[];$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
 $id=(int)$input['store'];if(!in_array($id,[1,2,3,4,6,7,8,9,10,11],true))throw new RuntimeException('SCOPE');
 $bottom=(new app\services\report\Sidebar007PkBottomReadServices())->read([$id],'2026-10');
 echo json_encode(['store'=>$id,'bottom'=>$bottom,'business_writes'=>0],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
'''+p[b:]
overrides={}
for f in paths:
 raw=(R/'后端代码'/f).read_bytes();overrides[f]={'base64':base64.b64encode(raw).decode(),'sha256':hashlib.sha256(raw).hexdigest()}
code='eval(substr(base64_decode("'+base64.b64encode(p.encode()).decode()+'"),5));'
cmd='cd /www/wwwroot/010.cc3798.com && /www/server/php/74/bin/php -r '+shlex.quote(code)
from decimal import Decimal
monthly=json.loads((E/'010-pk-historical-allstore-months-diagnostic.json').read_text())['months']
assert len(monthly)==60 and all(x['cash']['complete'] and x['partner']['complete'] for x in monthly.values())
results={}
for store in [1,2,3,4,6,7,8,9,10,11]:
 out=subprocess.run(['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','mohe-server',cmd],input=json.dumps({'site':'010','store':store,'overrides':overrides}).encode(),capture_output=True,timeout=120)
 if out.returncode:results[store]={'error':out.stderr.decode()[:4000],'stdout':out.stdout.decode()[-1000:]}
 else:
  results[store]=json.loads(out.stdout)
  months=[monthly[f'{store}:{m}'] for m in range(4,10)]
  cash=sum(x['cash']['by_store'][str(store)]['amount_cents'] for x in months)
  partner=sum(x['partner']['by_store'][str(store)] for x in months)
  adjustment=sum(int(Decimal(str(a['old_yeji']))*100) for x in months for a in x['adjustments'])
  total=cash-partner+adjustment
  expected=(abs(total)//600)*(1 if total>=0 else -1)*100
  results[store]['independent_monthly_sum']={'cash_cents':cash,'partner_cents':partner,'adjustment_cents':adjustment,'bottom_cents':expected}
  results[store]['exact_formula_match']=results[store]['bottom']['by_store'][str(store)]==expected

 (E/'010-pk-bottom-postapply-allstores.json').write_text(json.dumps({'stores':results,'source_hashes':{k:v['sha256'] for k,v in overrides.items()},'business_writes':0},ensure_ascii=False,indent=2)+'\n')
 print(store,{k:results[store].get(k,{}).get('complete') for k in ['bottom']},flush=True)
