"""Read-only candidate check after the separately approved provenance migration.
Only fixed local report classes are evaluated in-memory; no deployment or business write.
"""
from pathlib import Path
import json,base64,hashlib,shlex,subprocess
R=Path(__file__).resolve().parents[3]
E=R/'tests/report-basic/evidence/round4-gap-audit'
p=(R/'tests/report-basic/php/round4-sidebar-gap-audit.php').read_text()
names=['Sidebar007SoldProjectCountReadServices','Sidebar007DesignatedCustomerReadServices','Sidebar007OriginalCashReadServices','Sidebar007PkBottomReadServices','StoreReconciliationReceiptReader','StoreSummary007ReadServices','ItemConsumption007ReadServices','HistoricalSales007ReadServices','StoreItemSales007ReadServices','StoreItemSales007SourceCoverageServices','ProductSales007SourceCoverageServices','ProductSales007ReadServices','HistoricalPartnerSales007ReadServices','CompletedService007AmountIdentityReadServices','HistoricalCardService007ReadServices','ReportLegacyArchiveProofServices','Sidebar007ServiceOrderReadServices','Sidebar007PartnerServiceAllocationReadServices','ImportedPerformanceTypeProvenanceServices','Sidebar007PartnerAllocationReadServices','Sidebar007SalesCategoryReadServices','ServiceOrderArchiveProvenanceServices']
paths=['app/services/report/'+n+'.php' for n in names]
p=p.replace("$approved=['app/", "$approved=['"+"','".join(paths)+"','app/",1)
a=p.index(' $stores=');b=p.index('\n}finally')
p=p[:a]+r'''

 $result=[];$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
 $id=(int)$input['store'];if(!in_array($id,[1,2,3,4,6,7,8,9,10,11],true))throw new RuntimeException('SCOPE');
 $reports=[];$stores=[['id'=>$id,'name'=>'authorized-store']];
 foreach(['sales_data','sales_analysis','sales_pk','store_efficiency','store_summary'] as $code){
  try{
   if($code==='sales_pk')$r=(new app\services\report\Sidebar007PkEfficiencyV3ReadServices())->readPk([$id],$stores,'2026-10',[],null,null,null,null,null,null,'2026-10-30');
   elseif($code==='store_efficiency')$r=(new app\services\report\Sidebar007PkEfficiencyV3ReadServices())->readEfficiency([$id],$stores,$range['start'],$range['end']);
   elseif($code==='store_summary')$r=(new app\services\report\StoreSummary007ReadServices())->read([$id],$range);
   else $r=(new app\services\report\Sidebar007SalesV3ReadServices())->read($code,[$id],$stores,$range['start'],$range['end']);
   $reports[$code]=diagnostics($r);$reports[$code]['actual_rows']=$r['rows']??[];$reports[$code]['row_sha256']=hash('sha256',json_encode($r['rows']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  }catch(Throwable $e){$reports[$code]=['error'=>get_class($e),'message'=>$e->getMessage(),'trace'=>$e->getTraceAsString()];}
 }
 $partner=(new app\services\report\Sidebar007PartnerAllocationReadServices())->read([$id],$range);
 $service=(new app\services\report\Sidebar007ServiceOrderReadServices())->read([$id],$range);
 $inside=(new app\services\report\Sidebar007PartnerServiceAllocationReadServices())->read([$id],$range);
 unset($service['qualified_entries'],$service['historical_services'],$service['service_rows']);
 echo json_encode(['store'=>$id,'reports'=>$reports,'partner'=>$partner,'service'=>$service,'inside'=>$inside,'business_writes'=>0],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
'''+p[b:]
overrides={}
for f in paths:
 raw=(R/'后端代码'/f).read_bytes()
 overrides[f]={'base64':base64.b64encode(raw).decode(),'sha256':hashlib.sha256(raw).hexdigest()}
code='eval(substr(base64_decode("'+base64.b64encode(p.encode()).decode()+'"),5));'
cmd='cd /www/wwwroot/010.cc3798.com && /www/server/php/74/bin/php -r '+shlex.quote(code)
results={}
for store in [1,2,3,4,6,7,8,9,10,11]:
 out=subprocess.run(['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','mohe-server',cmd],input=json.dumps({'site':'010','store':store,'overrides':overrides}).encode(),capture_output=True,timeout=180)
 if out.returncode:results[store]={'error':out.stderr.decode()[-1500:],'stdout':out.stdout.decode()[-1000:]}
 else:
  results[store]=json.loads(out.stdout)
  previous=json.loads((E/'010-service-and-labor-preapply-overlay.json').read_text())['stores'][str(store)]
  results[store]['preapply_value_match']={k:results[store][k]['by_store']==previous[k]['by_store'] for k in ['partner','service','inside']}
 (E/'010-five-reports-postapply-readonly.json').write_text(json.dumps({'stores':results,'source_hashes':{k:v['sha256'] for k,v in overrides.items()},'business_writes':0,'phase':'postapply_real_database_proofs' },ensure_ascii=False,indent=2)+'\n')
 print(store,{k:v.get('complete',v.get('error')) for k,v in results[store].get('reports',{}).items()},flush=True)
