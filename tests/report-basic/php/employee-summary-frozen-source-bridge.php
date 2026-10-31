<?php
declare(strict_types=1);
/** Missing imported fact projection may read its exact certified V3 order snapshot only. */
require (getenv('BACKEND_ROOT') ?: dirname(__DIR__,3).'/后端代码').'/vendor/autoload.php';
use app\services\report\EmployeeSummaryNewCustomerReadServices as Reader;
$s=['order_id'=>'import-1','store_id'=>4,'member_id'=>9,'business_date'=>'2026-10-01','business_source_primary_id'=>0,'business_source_primary_name_snapshot'=>''];
$h=array_replace($s,['source_document_type'=>'legacy_store_order','business_source_primary_id'=>18,'business_source_primary_name_snapshot'=>'大众']);
$m=['complete'=>true,'by_v3_id'=>[18=>1],'by_007_id'=>[1=>['v3_id'=>18,'name'=>'大众']]];
$r=Reader::withCertifiedOrderSource($s,$h,$m);
if($r['business_source_primary_id']!==18||$r['business_source_primary_name_snapshot']!=='大众')throw new RuntimeException('certified source not read');
foreach(['order_id','store_id','member_id','business_date','source_document_type','business_source_primary_name_snapshot'] as$key){$bad=$h;$bad[$key]='wrong';if(Reader::withCertifiedOrderSource($s,$bad,$m)!==$s)throw new RuntimeException('identity conflict accepted:'.$key);}
$bad=$m;$bad['complete']=false;if(Reader::withCertifiedOrderSource($s,$h,$bad)!==$s)throw new RuntimeException('missing audit accepted');
$existing=array_replace($s,['business_source_primary_id'=>19,'business_source_primary_name_snapshot'=>'抖音']);
if(Reader::withCertifiedOrderSource($existing,$h,$m)!==$existing)throw new RuntimeException('existing immutable source overwritten');
if(Reader::withCertifiedOrderSource($s,null,$m)!==$s)throw new RuntimeException('missing order guessed');
echo "employee-summary-frozen-source-bridge PASS\n";
