// One approved 008 repair only. The executor and its source inputs are frozen
// Git blobs; only private backup artifacts are uploaded, never application code.
const fs = require('fs'), cp = require('child_process'), crypto = require('crypto'), path = require('path');
const commit = 'de4128786921fb26bfa7780458d91ea2e67f5666';
const source = '后端代码/database/upgrades/2026-10-24-008门店地区数据修复';
const directory = '/www/backups/008.cc3798.com/20261024-region-data-1760affd';
const local = '/private/tmp/m008-region-data-20261024';
const run = (c,a,o={}) => cp.execFileSync(c,a,{encoding:'utf8',maxBuffer:32e6,...o});
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const git = f => cp.execFileSync('git',['show',commit+':'+f],{maxBuffer:32e6});
const plan = JSON.parse(git(source+'/plan.json'));
const expectedPlan = '1760affd35264781dfca668e424c7d2d1679a56fdc257738ef9693245c87eb3d';
const check = (ok,m) => {if(!ok)throw Error(m);};
const remote = (command,input) => run('ssh',['-q','-o','BatchMode=yes','mohe-server',command],{input});
const save = (name,v) => fs.writeFileSync(path.join(local,name),JSON.stringify(v,null,2)+'\n');
function inspect() {
  let php = git('scripts/member-008-region-data-inspect.php').toString();
  const manifest = JSON.parse(fs.readFileSync('tests/member-store-regions/release-20261024/payload-fingerprint.json'));
  const files = Buffer.from(JSON.stringify(Object.keys(manifest.files))).toString('base64');
  // Only hashes of complete store rows and protected records leave the host.
  // Raw credential/contact fields are neither emitted nor archived locally.
  const extras = `
$digest=static function(array $rows):string{foreach($rows as &$row){ksort($row);foreach($row as &$v)if($v!==null)$v=(string)$v;unset($v);}unset($row);return hash('sha256',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));};
$output['storeRowHashes']=[];foreach($pdo->query('SELECT * FROM eb_system_store ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row)$output['storeRowHashes'][$row['id']]=$digest([$row]);
$output['dictionaryDigest']=$digest($pdo->query('SELECT * FROM eb_city_area ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
$output['protectedDigests']=[];foreach(['eb_diy','eb_system_config'] as $table)$output['protectedDigests'][$table]=$digest($pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
$output['files']=[];foreach(json_decode(base64_decode('${files}'),true) as $f)$output['files'][$f]=is_file($root.'/'.$f)?hash_file('sha256',$root.'/'.$f):null;
$output['expectedRowsAfter']=null;if(is_file('${directory}/before.json')){$b=json_decode(file_get_contents('${directory}/before.json'),true);$output['expectedRowsAfter']=[];foreach($b['storesAfter'] as $r)$output['expectedRowsAfter'][$r['id']]=$digest([$r]);$output['expectedDictionaryDigest']=$b['seedDigest'];}
`;
  php = php.replace("$pdo->exec('ROLLBACK');",extras+"$pdo->exec('ROLLBACK');");
  return JSON.parse(remote('/www/server/php/74/bin/php -d memory_limit=512M',php));
}
async function main() {
  fs.mkdirSync(local,{recursive:true});
  check(sha(git(source+'/plan.json'))===expectedPlan,'Plan differs');
  const mode=process.argv[2];
  if(mode==='resume-preflight') {
    check(!fs.existsSync(path.join(local,'production-applied.json')),'Do not resend an ambiguous write');
    const before=JSON.parse(fs.readFileSync(path.join(local,'production-before.json'))),now=inspect();
    check(now.dictionaryCountAllLevels===0,'Preflight retry dictionary drift');
    for(const k of ['storeRowHashes','files','protectedDigests','latestUpgrade'])check(JSON.stringify(now[k])===JSON.stringify(before[k]),'State changed after failed read-only preflight: '+k);
    const transfer=JSON.parse(fs.readFileSync(path.join(local,'transfer.json')));
    for(const [f,h]of Object.entries(transfer.files))check(remote('sha256sum '+directory+'/'+f).split(/\s/)[0]===h,'Private payload changed');
    remote('test ! -e '+directory+'/before.json');
    const executorCommit='9e0888ed42d64d8003287403d618c7edfc119cec';
    const bytes=cp.execFileSync('git',['show',executorCommit+':'+source+'/run.php']);
    remote('test ! -e '+directory+'/run-preflight-fixed.php && cat > '+directory+'/run-preflight-fixed.php',bytes);
    check(remote('sha256sum '+directory+'/run-preflight-fixed.php').split(/\s/)[0]===sha(bytes),'Executor transfer mismatch');
    const prefix='/www/server/php/74/bin/php -d memory_limit=512M '+directory+'/run-preflight-fixed.php ';
    const preflight=JSON.parse(remote(prefix+'preflight'));save('production-preflight.json',{...preflight,executorCommit});check(preflight.result==='PREFLIGHT_PASS','Fixed preflight failed');
    save('production-applied.json',{status:'ATTEMPT_STARTED',commit,executorCommit,directory,planSha256:expectedPlan});
    const result=JSON.parse(remote(prefix+'apply '+expectedPlan));
    save('production-applied.json',{...result,commit,executorCommit,directory});console.log(JSON.stringify(result));
  } else if(mode==='apply') {
    check(!fs.existsSync(path.join(local,'production-applied.json')),'Already attempted: inspect recorded result, never resend');
    const before=inspect();save('production-before.json',before);
    check(before.dictionaryCountAllLevels===0,'Dictionary changed since approval');
    for(const change of plan.changes){const row=before.stores.find(r=>r.id===change.before.id);for(const [k,v] of Object.entries(change.before))check(row&&String(row[k])===String(v),'Store baseline changed');}
    const files=JSON.parse(fs.readFileSync('tests/member-store-regions/release-20261024/payload-fingerprint.json')).files;
    for(const [f,h]of Object.entries(files))check(before.files[f]===h,'Application baseline changed: '+f);
    remote('test ! -e '+directory+' && mkdir -p '+directory+' && chmod 700 '+directory);
    const transferred={};
    for(const f of ['plan.json','standard-city.json.gz','repair.php','run.php']){
      const b=git(source+'/'+f);remote('cat > '+directory+'/'+f,b);
      check(remote('sha256sum '+directory+'/'+f).split(/\s/)[0]===sha(b),'Transfer mismatch');transferred[f]=sha(b);
    }
    save('transfer.json',{commit,files:transferred,directory});
    const prefix='/www/server/php/74/bin/php -d memory_limit=512M '+directory+'/run.php ';
    const preflight=JSON.parse(remote(prefix+'preflight'));save('production-preflight.json',preflight);check(preflight.result==='PREFLIGHT_PASS','Preflight failed');
    // This marker is persisted before the write command so network ambiguity
    // can never cause a blind second production execution.
    save('production-applied.json',{status:'ATTEMPT_STARTED',commit,directory,planSha256:expectedPlan});
    const result=JSON.parse(remote(prefix+'apply '+expectedPlan));
    save('production-applied.json',{...result,commit,directory});console.log(JSON.stringify(result));
  } else if(mode==='verify') {
    const before=JSON.parse(fs.readFileSync(path.join(local,'production-before.json')));
    const after=inspect();save('production-after.json',after);
    check(after.dictionaryCountAllLevels===44707&&after.dictionaryDigest===after.expectedDictionaryDigest,'Dictionary does not match standard seed');
    for(const [id,h]of Object.entries(before.storeRowHashes))check(after.storeRowHashes[id]===(after.expectedRowsAfter[id]||h),'Unexpected store change: '+id);
    check(Object.keys(after.storeRowHashes).length===Object.keys(before.storeRowHashes).length,'Store count changed');
    for(const k of ['latestUpgrade','protectedDigests','files','backend','tableEngines'])check(JSON.stringify(before[k])===JSON.stringify(after[k]),'Protected state changed: '+k);
    const result={result:'PASS',instance:'008',commit,dictionaryRows:44707,updatedStores:[127,129,130],other127StoreRowsUnchanged:true,protectedApplicationFilesUnchanged:true,configurationAndDecorationUnchanged:true,upgradeLedgerUnchanged:true,backup:directory};
    save('production-verified.json',result);console.log(JSON.stringify(result));
  } else throw Error('Use apply, resume-preflight or verify');
}
main().catch(e=>{console.error(e);process.exitCode=1;});
