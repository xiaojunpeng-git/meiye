import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync} from 'node:child_process';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';

// Explicit fixed local inputs; this tool cannot deploy or synthesize authority.
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const pins=[
  ['app/services/cashier/v3/card/CashierV3CardReadAmountServices.php','c78ecd68414213640d3a158a224e96da4ef8e31096cd673cd64689ec16cae45c'],
  ['app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php','84597fe40dab93dc6aa6444919be2016ad481576814d57080771b82a28d66a99'],
  ['app/services/report/query/BasicBusinessQueryProvider.php','af494e196af6a271bb2006002fd2527bfae1ac438bbee3224c6e9a6b20348947'],
];
const overlays=pins.map(([relative,hash],i)=>{
  const bytes=fs.readFileSync(path.join(root,'后端代码',relative));
  assert.equal(sha(bytes),hash,`Fixed source changed: ${relative}`);
  if(i===2) assert.deepEqual(bytes,execFileSync('git',['show',`e0cc7880224f969657954fffd26511f37365e3a1:后端代码/${relative}`],{cwd:root}));
  return {relative,sha256:hash,class:relative.slice(0,-4).replaceAll('/','\\'),source:bytes.toString('base64'),accepted_provider:i===2};
});
const sample_codes=['basic_store_summary','basic_employee_performance_detail','basic_unconsumed_summary','basic_salary_realtime','basic_balance_order_detail'];
const template=fs.readFileSync(path.join(root,'scripts/r51-basic-report-card-read-recheck-010.php'),'utf8');
assert.equal(template.split('__R51_READ_RECHECK_REQUEST__').length-1,1);
const php=template.replace('__R51_READ_RECHECK_REQUEST__',Buffer.from(JSON.stringify({overlays,sample_codes})).toString('base64'));
if(process.argv.includes('--self-test')){
  const lint=execFileSync('/opt/homebrew/bin/php',['-l'],{input:php,encoding:'utf8'});
  assert.ok(lint.includes('No syntax errors'));
  assert.ok(php.indexOf('spl_autoload_register')<php.indexOf('(new \\think\\App'));
  assert.ok(php.indexOf("$output['before']=r51ReadRecheckDigest")<php.indexOf('(new \\think\\App'));
  assert.ok(php.includes("$output['after']=r51ReadRecheckDigest"));
  assert.ok(php.includes('r51SmokeAuthorizedContext($sample,$runtime'));
  assert.ok(php.includes("['file','line','class','function']"));
  assert.equal(sample_codes.length,5);
  // Exercise the real redactor locally: arguments/raw messages and arbitrary
  // exception reason strings must not escape into a diagnostic receipt.
  const functions=template.slice(0,template.indexOf("\nini_set('display_errors'"));
  const redactor=execFileSync('/opt/homebrew/bin/php',[],{encoding:'utf8',input:functions+`
function fixture($secret) { throw new RuntimeException($secret); }
try { fixture('PRIVATE_FIXTURE_VALUE'); } catch (Throwable $e) {
    $safe=r51ReadRecheckSafe($e); $json=json_encode($safe);
    if (strpos($json,'PRIVATE_FIXTURE_VALUE')!==false || strpos($json,'"args"')!==false || isset($safe['message']) || $safe['safe_code']!==null) exit(2);
}
$safe=r51ReadRecheckSafe(new RuntimeException('QUERY_INCOMPLETE'));
if ($safe['safe_code']!=='QUERY_INCOMPLETE') exit(3);
echo 'REDACTOR_PASS';
`});
  assert.equal(redactor,'REDACTOR_PASS');
  console.log(JSON.stringify({result:'PASS',source_pins:pins,generated_php_sha256:sha(php)}));
}else{
  assert.ok(process.argv.length===3&&['--run','--run-details'].includes(process.argv[2]));
  const filename=process.argv[2]==='--run-details'?'card-read-recheck-details.json':'card-read-recheck.json';
  const output=path.join(root,'tests/report-basic/evidence/release-010-repair',filename);
  assert.ok(!fs.existsSync(output),'Never overwrite evidence');
  let stdout;
  try{
    stdout=execFileSync('ssh',['-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=12','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2','mohe-server','/www/server/php/74/bin/php'],{input:php,encoding:'utf8',timeout:300000,maxBuffer:8*1024*1024,stdio:['pipe','pipe','pipe']});
  }catch(e){stdout=e.stdout;if(!stdout)throw new Error('SSH diagnostic failed; unsafe stderr suppressed');}
  const remote=JSON.parse(stdout);
  // Recheck local pins after the remote query too: concurrent main edits must
  // never be mistaken for the exact source that was tested in this receipt.
  const local_sources_still_pinned=pins.every(([relative,hash])=>sha(fs.readFileSync(path.join(root,'后端代码',relative)))===hash);
  const receipt={site:'010',result:remote.result==='PASS'&&local_sources_still_pinned?'PASS':'FAIL',local_sources_still_pinned,
    source_pins:pins,source_tree_basis:'a50d49c7f6f65988bb47de32559a662488e81235',generated_php_sha256:sha(php),
    script_sha256:sha(fs.readFileSync(fileURLToPath(import.meta.url))),template_sha256:sha(template),
    remote,collected_at:new Date().toISOString(),business_writes:false,remote_files_written:false};
  fs.writeFileSync(output,JSON.stringify(receipt,null,2)+'\n',{flag:'wx',mode:0o600});
  console.log(JSON.stringify({output,...receipt}));
}
