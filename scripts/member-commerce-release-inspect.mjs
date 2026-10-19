import {execFileSync} from 'node:child_process';
import {readFileSync, writeFileSync, mkdirSync} from 'node:fs';
import {createHash} from 'node:crypto';

// Read-only release inventory. Customer credentials stay on their own server;
// only file hashes, schema presence and non-sensitive release metadata return.
const instance = process.argv[2];
const sites = {
  rh: {host:'rh-server', domain:'rh.cc3798.com', db:'ruihao', port:'3306'},
  '008': {host:'mohe-server', domain:'008.cc3798.com', db:'mohe_008', port:'3307'},
};
if (!sites[instance]) throw Error('Explicit rh or 008 required');
const site = sites[instance];
const manifest = JSON.parse(readFileSync('tests/member-checkout/evidence/verification.json'));
const files = manifest.files.backend.map(row=>row.path.slice('后端代码/'.length));
const hash = value=>createHash('sha256').update(value).digest('hex');
const config = {...site, root:`/www/wwwroot/${site.domain}`, files};
const php = `<?php
try {
$site=json_decode(base64_decode('${Buffer.from(JSON.stringify(config)).toString('base64')}'),true);
$root=$site['root'];$out=['instance'=>'${instance}','php'=>PHP_VERSION,'backend'=>[],'front'=>[]];
foreach($site['files'] as $file) $out['backend'][$file]=is_file($root.'/'.$file)?hash_file('sha256',$root.'/'.$file):null;
$fronts=['admin'=>$root.'/public/system.html','member'=>$root.'/public/index.html',
 'cashier'=>$root.'/public/view_cashier_v3/index.html'];
if('${instance}'==='rh')$fronts['cashier_preview']='/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3/index.html';
foreach($fronts as $key=>$file){
 if(!is_file($file)){$out['front'][$key]=null;continue;}
 $html=file_get_contents($file);preg_match_all('~(?:src|href)="([^"]+\\.(?:js|css)(?:\\?[^"]*)?)"~',$html,$matches);
 $front=['path'=>$file,'index'=>hash('sha256',$html),'assets'=>[]];
 foreach(array_unique($matches[1]) as $asset){
  $relative=explode('?',$asset)[0];
  if(preg_match('~^(?:https?:)?//~',$relative))continue;
  $path=strpos($relative,'/')===0?$root.'/public'.$relative:dirname($file).'/'.$relative;
  $front['assets'][$asset]=is_file($path)?hash_file('sha256',$path):null;
 }
 $out['front'][$key]=$front;
}
chdir($root);require $root.'/vendor/autoload.php';(new think\\App($root.'/'))->initialize();
$cfg=config('database.connections.mysql');
if($cfg['database']!==$site['db']||(string)$cfg['hostport']!==$site['port']||!in_array($cfg['hostname'],['localhost','127.0.0.1'],true))throw new RuntimeException('Instance database mismatch');
think\\facade\\Db::execute('SET TRANSACTION READ ONLY');think\\facade\\Db::startTrans();
try{
 $out['database']=['name'=>$cfg['database'],'port'=>(string)$cfg['hostport'],'version'=>think\\facade\\Db::query('SELECT VERSION() AS v')[0]['v']];
 $out['migration']=think\\facade\\Db::name('database_upgrade_log')->order('id','desc')->limit(5)->field('id,upgrade_key')->select()->toArray();
 foreach(['member_commerce_settings','member_commerce_settings_audit','member_commerce_order','member_commerce_payment_session'] as $table){
  $name=$cfg['prefix'].$table;
  $exists=think\\facade\\Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$cfg['database'],$name])[0]['n'];
  $out['memberSchema'][$table]=(int)$exists;
 }
 $out['sources']=think\\facade\\Db::name('cashier_v3_business_source')->field('id,parent_id,name,status')->select()->toArray();
}finally{think\\facade\\Db::rollback();}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
`;
const result=JSON.parse(execFileSync('ssh',['-q','-o','BatchMode=yes','-o','ConnectTimeout=10',site.host,'php'],{input:php,encoding:'utf8',maxBuffer:32*1024*1024}));
const comparison=[];
for(const file of files){
 let head=null;
 try{head=hash(execFileSync('git',['show',`HEAD:后端代码/${file}`],{stdio:['ignore','pipe','ignore']}));}catch{}
 const candidate=hash(readFileSync(`后端代码/${file}`));
 comparison.push({file,live:result.backend[file],head,candidate,baselineMatches:result.backend[file]===head});
}
const output={...result,comparison};
const directory='tests/member-checkout/evidence/release';mkdirSync(directory,{recursive:true});
const path=`${directory}/${instance}-before.json`;
writeFileSync(path,JSON.stringify(output,null,2)+'\n',{flag:'wx'});
console.log(JSON.stringify({instance,database:result.database,migration:result.migration,memberSchema:result.memberSchema,
 baselineMismatches:comparison.filter(row=>!row.baselineMatches),front:result.front,sources:result.sources},null,2));
