// A single approved existing controller is restored from immutable canonical
// Git. All current frontend and repayment sources/data remain untouched.
const fs=require('fs'),cp=require('child_process'),crypto=require('crypto'),path=require('path');
const source='40147d669ac4783c76146419578d62e416878178';
const directory='/www/backups/008.cc3798.com/20261026-member-debt-list-40147d66';
const base='/private/tmp/m008-debt-list-20261026';fs.mkdirSync(base,{recursive:true});
const command=(c,a,o={})=>cp.execFileSync(c,a,{encoding:'utf8',maxBuffer:16e6,...o});
const remote=(s,input)=>command('ssh',['-q','-o','BatchMode=yes','mohe-server',s],{input});
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const save=(n,d)=>fs.writeFileSync(path.join(base,n),JSON.stringify(d,null,2)+'\n');
function inspect(){
  const php=`<?php
$r='/www/wwwroot/008.cc3798.com';$env=parse_ini_file($r.'/.env',true,INI_SCANNER_RAW);$c=array_change_key_case($env['DATABASE']??$env['database']??[]);
if(($c['database']??'')!=='mohe_008'||(int)$c['hostport']!==3307||!in_array($c['hostname'],['127.0.0.1','localhost'],true))throw new RuntimeException('Wrong instance');
$p=new PDO('mysql:host='.$c['hostname'].';port=3307;dbname=mohe_008;charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('START TRANSACTION READ ONLY');
$out=['instance'=>'008','database'=>'mohe_008','environment'=>hash_file('sha256',$r.'/.env'),'files'=>[],'data'=>[]];
foreach(['app/controller/api/v1/order/StoreDebt.php','app/services/order/StoreDebtServices.php','route/api.php','public/index.html','public/system.html','public/view_cashier_v3/index.html'] as $f)$out['files'][$f]=hash_file('sha256',$r.'/'.$f);
foreach(['eb_store_debt','eb_store_debt_item','eb_store_debt_repay','eb_system_config','eb_diy'] as $table){$h=hash_init('sha256');$n=0;$p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);$q=$p->query('SELECT * FROM '.$table.' ORDER BY id');while($row=$q->fetch(PDO::FETCH_ASSOC)){hash_update($h,json_encode($row)."\\n");$n++;}$q->closeCursor();$p->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);$out['data'][$table]=['count'=>$n,'sha256'=>hash_final($h)];}
$out['upgrade']=$p->query('SELECT upgrade_key,code_version,executed_at FROM eb_database_upgrade_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$p->exec('ROLLBACK');echo json_encode($out,JSON_THROW_ON_ERROR);`;
  return JSON.parse(remote('/www/server/php/74/bin/php',php));
}
const mode=process.argv[2];
if(mode==='install'){
  if(fs.existsSync(path.join(base,'attempt.json')))throw Error('Existing attempt: inspect result, never resend blindly');
  const before=inspect();save('before.json',before);
  if(before.files['app/controller/api/v1/order/StoreDebt.php']!=='58a20362d8db5cec0f462c6a64e6c8744a0781e3110e3a02b088daae07d64ce3')throw Error('Controller baseline changed');
  const bytes=cp.execFileSync('git',['show',source+':后端代码/app/controller/api/v1/order/StoreDebt.php']);
  if(sha(bytes)!=='d8e46605b440a1a049d9d10f621f14d89e0b2169ba012ae30a2a64cd5a2b9226')throw Error('Candidate differs');
  remote('test ! -e '+directory+' && mkdir -p '+directory+' && chmod 700 '+directory);
  remote('cat > '+directory+'/candidate.php',bytes);
  const installer=fs.readFileSync('scripts/member-debt-list-008-install.php');remote('cat > '+directory+'/install.php',installer);
  if(remote('sha256sum '+directory+'/candidate.php').split(/\s/)[0]!==sha(bytes)||remote('sha256sum '+directory+'/install.php').split(/\s/)[0]!==sha(installer))throw Error('Transfer mismatch');
  save('attempt.json',{source,controllerSha256:sha(bytes),installerSha256:sha(installer),directory,status:'ATTEMPT_STARTED'});
  const result=JSON.parse(remote('/www/server/php/74/bin/php '+directory+'/install.php install'));save('installed.json',result);console.log(JSON.stringify(result));
}else if(mode==='verify'){
  const before=JSON.parse(fs.readFileSync(path.join(base,'before.json'))),after=inspect();save('after.json',after);
  if(after.files['app/controller/api/v1/order/StoreDebt.php']!=='d8e46605b440a1a049d9d10f621f14d89e0b2169ba012ae30a2a64cd5a2b9226')throw Error('Installed controller mismatch');
  for(const f of Object.keys(before.files).filter(f=>f!=='app/controller/api/v1/order/StoreDebt.php'))if(before.files[f]!==after.files[f])throw Error('Protected file changed: '+f);
  for(const k of ['environment','data','upgrade'])if(JSON.stringify(before[k])!==JSON.stringify(after[k]))throw Error('Protected state changed: '+k);
  const result={result:'PASS',filesChanged:1,frontendAndRepaymentSourcesUnchanged:true,debtDataUnchanged:true,configurationUnchanged:true,directory,source};save('verified.json',result);console.log(JSON.stringify(result));
}else throw Error('Use install or verify');
