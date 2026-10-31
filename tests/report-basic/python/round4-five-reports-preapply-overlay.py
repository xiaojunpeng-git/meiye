"""Read-only candidate check after the separately approved provenance migration.
Only fixed local report classes are evaluated in-memory; no deployment or business write.
"""
from pathlib import Path
import json,base64,hashlib,shlex,subprocess
R=Path(__file__).resolve().parents[3]
E=R/'tests/report-basic/evidence/round4-gap-audit'
p=(R/'tests/report-basic/php/round4-sidebar-gap-audit.php').read_text()
names=['Sidebar007DesignatedCustomerReadServices','Sidebar007OriginalCashReadServices','Sidebar007PkBottomReadServices','StoreReconciliationReceiptReader','StoreSummary007ReadServices','ItemConsumption007ReadServices','HistoricalSales007ReadServices','StoreItemSales007ReadServices','StoreItemSales007SourceCoverageServices','ProductSales007SourceCoverageServices','ProductSales007ReadServices','HistoricalPartnerSales007ReadServices','CompletedService007AmountIdentityReadServices','HistoricalCardService007ReadServices','ReportLegacyArchiveProofServices','Sidebar007ServiceOrderReadServices','Sidebar007PartnerServiceAllocationReadServices','ImportedPerformanceTypeProvenanceServices','Sidebar007PartnerAllocationReadServices','Sidebar007SalesCategoryReadServices','ServiceOrderArchiveProvenanceServices']
paths=['app/services/report/'+n+'.php' for n in names]
p=p.replace("$approved=['app/", "$approved=['"+"','".join(paths)+"','app/",1)
a=p.index(' $stores=');b=p.index('\n}finally')
p=p[:a]+r'''
 $GLOBALS['archiveProofOverlay']=$input['archiveProofOverlay'];$GLOBALS['laborProofOverlay']=$input['laborProofOverlay'];
 $result=[];$range=['start'=>'2026-10-01','end'=>'2026-10-30'];
 $id=(int)$input['store'];if(!in_array($id,[1,2,3,4,6,7,8,9,10,11],true))throw new RuntimeException('SCOPE');
 $reports=[];$stores=[['id'=>$id,'name'=>'authorized-store']];
 foreach(['sales_data','sales_analysis','sales_pk','store_efficiency','store_summary'] as $code){
  try{
   if($code==='sales_pk')$r=(new app\services\report\Sidebar007PkEfficiencyV3ReadServices())->readPk([$id],$stores,'2026-10',[],null,null,null,null,null,null,'2026-10-30');
   elseif($code==='store_efficiency')$r=(new app\services\report\Sidebar007PkEfficiencyV3ReadServices())->readEfficiency([$id],$stores,$range['start'],$range['end']);
   elseif($code==='store_summary')$r=(new app\services\report\StoreSummary007ReadServices())->read([$id],$range);
   else $r=(new app\services\report\Sidebar007SalesV3ReadServices())->read($code,[$id],$stores,$range['start'],$range['end']);
   $reports[$code]=diagnostics($r);
  }catch(Throwable $e){$reports[$code]=['error'=>get_class($e),'message'=>$e->getMessage(),'trace'=>$e->getTraceAsString()];}
 }
 echo json_encode(['store'=>$id,'reports'=>$reports,'business_writes'=>0],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
'''+p[b:]
overrides={}
for f in paths:
 raw=(R/'后端代码'/f).read_bytes()
 if f.endswith('Sidebar007PartnerServiceAllocationReadServices.php'):
  fields=['tenant_id','fact_id','natural_key','store_id','employee_id','employee_type_snapshot','employee_type_authority_version','performance_type','fact_direction','status','amount_cents','order_id','source_line_id','source_document_type','checkout_request_id','business_date','reversal_of','rule_code_snapshot','rule_version_snapshot','command_idempotency_key','business_event_no','immutable_fingerprint']
  raw=raw.replace(b".'p.employee_type_authority_version,p.fact_direction,p.amount_cents')", (".'p.employee_type_authority_version,p.fact_direction,p.amount_cents,"+','.join('p.'+x for x in fields)+"')").encode())
  raw=raw.replace(b"            if(count($rows)>100000)throw", b"            foreach($rows as &$proofRow){$key=(string)($proofRow['fact_id']??'');if(isset($GLOBALS['laborProofOverlay'][$key])&&hash_equals($GLOBALS['laborProofOverlay'][$key],ImportedPerformanceTypeProvenanceServices::fingerprint($proofRow)))$proofRow['employee_type_certified']=1;}unset($proofRow);\n            if(count($rows)>100000)throw")
 if f.endswith('ServiceOrderArchiveProvenanceServices.php'):
  raw=raw.replace(b"$proofReader=$proofReader ?: [$this,'proofs'];",b"$proofReader=$proofReader ?: static fn(array $ids):array=>array_values(array_filter($GLOBALS['archiveProofOverlay'],static fn(array $r):bool=>in_array($r['fact_id'],$ids,true)));")
 overrides[f]={'base64':base64.b64encode(raw).decode(),'sha256':hashlib.sha256(raw).hexdigest()}
code='eval(substr(base64_decode("'+base64.b64encode(p.encode()).decode()+'"),5));'
cmd='cd /www/wwwroot/010.cc3798.com && /www/server/php/74/bin/php -r '+shlex.quote(code)
plan_path=E/'010-service-order-archive-certification-plan.json'
plan_bytes=plan_path.read_bytes();plan_sha=hashlib.sha256(plan_bytes).hexdigest()
assert plan_sha=='f6c8afbf311af4df034fc01bf3c30fd3b14f3dab59728f6d455bf01141fec877'
plan=json.loads(plan_bytes)
overlay=[dict(x,purpose=plan['purpose'],source_batch_key=plan['source_marker']['batch_key'],source_archive_sha256=plan['source_marker']['source_archive_sha256'],plan_sha256=plan_sha) for x in plan['proofs']]
labor_bytes=(E/'010-labor-type-certification-plan.json').read_bytes()
labor_sha=hashlib.sha256(labor_bytes).hexdigest()
assert labor_sha=='6e35880bacbe7b8b5ebe600a82e000f8b86417a8ec7d3459b52c74fa23d09dc6'
labor_plan=json.loads(labor_bytes)
labor_overlay={x['fact_id']:x['snapshot_sha256'] for x in labor_plan['proofs']}
results={}
for store in [1,2,3,4,6,7,8,9,10,11]:
 out=subprocess.run(['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','mohe-server',cmd],input=json.dumps({'site':'010','store':store,'overrides':overrides,'archiveProofOverlay':overlay,'laborProofOverlay':labor_overlay}).encode(),capture_output=True,timeout=120)
 if out.returncode:results[store]={'error':out.stderr.decode()[-1500:],'stdout':out.stdout.decode()[-1000:]}
 else:results[store]=json.loads(out.stdout)
 (E/'010-five-reports-preapply-overlay.json').write_text(json.dumps({'stores':results,'source_hashes':{k:v['sha256'] for k,v in overrides.items()},'business_writes':0,'phase':'preapply_inmemory_proof_projection','plan_sha256':plan_sha,'labor_plan_sha256':labor_sha},ensure_ascii=False,indent=2)+'\n')
 print(store,{k:v.get('complete',v.get('error')) for k,v in results[store].get('reports',{}).items()},flush=True)
