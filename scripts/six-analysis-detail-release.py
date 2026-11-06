"""R53 per-instance release: read-only preflight/smoke, backups, guarded switch.

No schema/config/business mutations. Deployment requires an accepted manifest
and recorded local commits; unrelated files and all prior assets are preserved.
"""
import base64, hashlib, io, json, pathlib, shlex, subprocess, sys, tarfile, urllib.request

R = pathlib.Path(__file__).resolve().parents[1]
E = R.parent/'任务管理/第53轮-六维分析数值明细-20261107/release'
S = {'rh':('rh-server','ruihao','/etc/init.d/ruihao_swoole restart'),
     '008':('mohe-server','mohe_008','systemctl restart mohe-008-swoole.service'),
     '010':('mohe-server','mohe_010_app','systemctl restart mohe-010-swoole.service')}

def load(name): return json.loads((E/name).read_text())
def save(name,x): (E/name).write_text(json.dumps(x,ensure_ascii=False,indent=2)+'\n')
def sha(b): return hashlib.sha256(b).hexdigest()
def git(*args): return subprocess.check_output(['git',*args],cwd=R)

def php(site, body, app=True):
    host,db,_=S[site]
    code='<?php chdir('+json.dumps('/www/wwwroot/'+site+'.cc3798.com')+');'
    if app: code+="require 'vendor/autoload.php';(new think\\App())->initialize();if(config('database.connections.mysql.database')!=="+json.dumps(db)+")throw new Exception('WRONG_INSTANCE');"
    result=subprocess.run(['ssh',host,'sudo -u www php'],input=code+body,text=True,capture_output=True,timeout=180)
    if result.returncode: raise RuntimeError(result.stderr+result.stdout)
    try: return json.loads(result.stdout)
    except Exception: raise RuntimeError('INVALID_PROBE_RESPONSE '+result.stdout[:1000])

def probe(site, paths):
    return php(site,'$out=[];foreach('+json.dumps(sorted(paths))+" as $p)$out[$p]=is_file($p)?hash_file('sha256',$p):null;echo json_encode(['files'=>$out,'environment'=>hash_file('sha256','.env')]);",False)

def smoke(site, mode):
    c=load('candidates.json')['sites'][site]
    body=r"""
ini_set('memory_limit','512M');
think\facade\Db::execute('SET TRANSACTION READ ONLY');think\facade\Db::startTrans();
try {
 $range=['start'=>'2026-10-01','end'=>'2026-10-31'];
 $stores=array_map('intval',think\facade\Db::name('cashier_v3_entitlement_service_fact')->where('service_status','completed')->whereBetween('business_date',[$range['start'],$range['end']])->group('store_id')->order('store_id')->limit(2)->column('store_id'));
 if(!$stores)$stores=array_map('intval',think\facade\Db::name('system_store')->where('is_del',0)->order('id')->limit(2)->column('id'));
 $report='six_dimension_analysis';$input=['_internal_all'=>true];$started=microtime(true);
 $actual=(new app\services\report\StoreUnifiedReportPhaseFourServices())->query($report,$stores,$range,$input);
 // Empty sample stores cannot validate real contributions; select populated
 // parent rows using the same server report, then requery only those stores.
 if(!$actual['records']){$all=array_map('intval',think\facade\Db::name('system_store')->where('is_del',0)->column('id'));$scan=(new app\services\report\StoreUnifiedReportPhaseFourServices())->query($report,$all,$range,$input);$populated=array_slice(array_values(array_unique(array_map('intval',array_column($scan['records'],'store_id')))),0,2);if($populated){$stores=$populated;$actual=(new app\services\report\StoreUnifiedReportPhaseFourServices())->query($report,$stores,$range,$input);}}
 $normalize=static function($v){foreach($v as &$r){unset($r['detail_store_key'],$r['refund_amount']);}unset($r);return $v;};
 $existing=hash('sha256',json_encode([$normalize($actual['records']),$normalize([$actual['summary_row']])]));
"""
    if mode=='preflight':
        for p in ['SixDimensionAnalysisDetail','StoreUnifiedReportPhaseFourServices']:
            source=git('show',c['tree']+':后端代码/app/services/report/'+p+'.php')
            if p=='StoreUnifiedReportPhaseFourServices':source=source.replace(b'final class StoreUnifiedReportPhaseFourServices',b'final class R53CandidatePhaseFourServices',1)
            body+='eval(substr(base64_decode('+json.dumps(base64.b64encode(source).decode())+'),5));'
        body+="$service=new app\\services\\report\\R53CandidatePhaseFourServices();"
    else:body+="$service=new app\\services\\report\\StoreUnifiedReportPhaseFourServices();"
    body+=r"""
 $candidate=$service->query($report,$stores,$range,$input);
 if($normalize($candidate['records'])!==$normalize($actual['records'])||$normalize([$candidate['summary_row']])!==$normalize([$actual['summary_row']]))throw new Exception('SUMMARY_CHANGED');
 $checks=0;$detailHashes=[];$exports=0;
 foreach(array_merge($candidate['records'],[$candidate['summary_row']]) as $row){
  foreach($candidate['columns'] as $col){$key=$col['key'];if(in_array($key,['company_name','store_name'],true))continue;
   if(($col['drilldown']['report']??'')!==$report)throw new Exception('DRILL_NOT_DECLARED '.$key);
   if(($row[$key]??'-')==='-')continue;
   $detailInput=$input+['metric_code'=>$key,'detail_store_key'=>$row['detail_store_key']??'all'];
   $detail=$service->query($report,$stores,$range,$detailInput);
   if(is_numeric($row[$key])?abs((float)$detail['metric_value']-(float)$row[$key])>0.001:$detail['metric_value']!==$row[$key])throw new Exception('DETAIL_MISMATCH '.$key);
   if(count($detail['records'])!==$detail['total'])throw new Exception('EXPORT_INCOMPLETE');
   $detailHashes[]=hash('sha256',json_encode([$detail['metric_value'],$detail['records']]));$checks++;
   if($exports===0){$xlsx=(new app\services\report\StoreReportXlsxWriter())->render($detail);if(substr($xlsx,0,2)!=='PK')throw new Exception('XLSX_FAILED');$exports++;}
  }
 }
 foreach([['metric_code'=>'invalid','detail_store_key'=>'all'],['metric_code'=>'debt','detail_store_key'=>'forged'],['metric_code'=>'debt','detail_store_key'=>'all','_report_scope'=>['mode'=>'none']]] as $bad){
  try{$service->query($report,$stores,$range,$bad);throw new Exception('INVALID_SCOPE_ACCEPTED');}catch(\InvalidArgumentException $expected){}
 }
 $result=['database'=>config('database.connections.mysql.database'),'stores'=>$stores,'range'=>$range,'existingHash'=>$existing,'detailHash'=>hash('sha256',json_encode($detailHashes)),'detailQueries'=>$checks,'xlsxChecks'=>$exports,'rows'=>count($candidate['records']),'summary'=>$candidate['summary_row'],'seconds'=>round(microtime(true)-$started,3)];
 echo json_encode($result,JSON_UNESCAPED_UNICODE);
} catch (\Throwable $error) {echo json_encode(['probeError'=>get_class($error),'message'=>$error->getMessage(),'file'=>$error->getFile(),'line'=>$error->getLine()]);} finally {think\facade\Db::rollback();}
"""
    result=php(site,body);save(site+'-smoke-'+mode+'.json',result)
    assert 'probeError' not in result,result
    assert result['detailQueries']>0,result
    if mode=='after':
        before=load(site+'-smoke-preflight.json')
        assert result['existingHash']==before['existingHash'],'EXISTING_REPORT_CHANGED_AFTER_DEPLOY'
        assert result['detailHash']==before['detailHash'],'DETAIL_CHANGED_AFTER_DEPLOY'
    return result

def payload(site):
    c=load('candidates.json')['sites'][site]
    out={}
    for p in c['files']:
        if p.startswith('后端代码/'):out[p.removeprefix('后端代码/')]=git('show',c['tree']+':'+p)
    for p in (E/'build'/site/'candidate').rglob('*'):
        if p.is_file():out['public/view_cashier_v3/'+p.relative_to(E/'build'/site/'candidate').as_posix()]=p.read_bytes()
    return out

def prepare(site):
    candidate=payload(site);before=load(site+'-before.json');now=probe(site,set(candidate)|set(before['files']))
    assert now['environment']==before['environment'],'CONFIG_CHANGED'
    for p,h in before['files'].items():assert now['files'][p]==h,('LIVE_CHANGED',site,p)
    selected={p:b for p,b in candidate.items() if now['files'][p]!=sha(b)}
    for p in selected:
        if p.startswith('public/') and p!='public/view_cashier_v3/index.html':assert now['files'][p] is None,('ASSET_COLLISION',site,p)
    save(site+'-preinstall.json',now);save(site+'-manifest.json',{p:sha(b) for p,b in selected.items()})
    with tarfile.open(E/(site+'-payload.tar.gz'),'w:gz') as archive:
        for p,b in selected.items():
            info=tarfile.TarInfo(p);info.size=len(b);info.mode=0o644;archive.addfile(info,io.BytesIO(b))
    result=smoke(site,'preflight')
    return {'site':site,'files':len(selected),'readOnlySmoke':'PASS','sample':result['stores']}

def install(site):
    approval=load('accepted-release.json');c=load('candidates.json')['sites'][site]
    assert approval['trees'][site]==c['tree'],'FIXED_CANDIDATE_NOT_ACCEPTED'
    commit=load('commits.json')['sites'][site]['commit'];assert git('rev-parse',commit+'^{tree}').decode().strip()==c['tree']
    host,_,restart=S[site];before=load(site+'-preinstall.json');assert probe(site,before['files'])==before,'LIVE_CHANGED_BEFORE_SWITCH'
    manifest=load(site+'-manifest.json');root='/www/wwwroot/'+site+'.cc3798.com'
    backup='/www/backup/r53-six-detail-'+commit[:8]+'-'+site;remote='/tmp/r53-six-detail-'+site+'.tar.gz'
    subprocess.run(['scp',str(E/(site+'-payload.tar.gz')),host+':'+remote],check=True,capture_output=True)
    existing=[p for p in manifest if before['files'][p] is not None]
    stage='set -e\numask 077\nmkdir '+shlex.quote(backup)+'\ncd '+shlex.quote(root)+'\ntar -czf '+backup+'/before.tar.gz -- '+' '.join(map(shlex.quote,existing))+'\ntest -s '+backup+'/before.tar.gz\nmkdir '+backup+'/payload\ntar -xzf '+remote+' -C '+backup+'/payload\n'
    for p in manifest:
        if p.endswith('.php'):stage+='php -l '+shlex.quote(backup+'/payload/'+p)+'\n'
    rollback='set -e\ncd '+shlex.quote(root)+'\ntar -xzf '+backup+'/before.tar.gz\n'
    for p in manifest:
        if before['files'][p] is None and p.startswith('app/'):rollback+='rm -f -- '+shlex.quote(root+'/'+p)+'\n'
    rollback+=restart+'\n';(E/(site+'-rollback.sh')).write_text(rollback)
    result=subprocess.run(['ssh',host,'bash','-s'],input=stage,text=True,capture_output=True);(E/(site+'-stage.log')).write_text(result.stdout+result.stderr);assert result.returncode==0,'STAGE_FAILED'
    assert probe(site,before['files'])==before,'LIVE_CHANGED_DURING_STAGE'
    switch='set -e\n'
    for p in sorted(manifest,key=lambda p:p=='public/view_cashier_v3/index.html'):
        dest=root+'/'+p;switch+='mkdir -p '+shlex.quote(str(pathlib.PurePosixPath(dest).parent))+'\ncp '+shlex.quote(backup+'/payload/'+p)+' '+shlex.quote(dest+'.r53-new')+'\nchown www:www '+shlex.quote(dest+'.r53-new')+'\nchmod 644 '+shlex.quote(dest+'.r53-new')+'\nmv '+shlex.quote(dest+'.r53-new')+' '+shlex.quote(dest)+'\n'
    switch+=restart+'\n'
    result=subprocess.run(['ssh',host,'bash','-s'],input=switch,text=True,capture_output=True);(E/(site+'-install.log')).write_text(result.stdout+result.stderr)
    try:
        assert result.returncode==0,'SWITCH_FAILED'
        after=probe(site,before['files']);save(site+'-after.json',after)
        for p,h in manifest.items():assert after['files'][p]==h,('FILE_MISMATCH',p)
        for p,h in before['files'].items():
            if p not in manifest:assert after['files'][p]==h,('PROTECTED_FILE_CHANGED',p)
        assert after['environment']==before['environment'],'ENV_CHANGED'
        smoke(site,'after')
        public={}
        for p,h in load(site+'-candidate-assets.json').items():
            body=urllib.request.urlopen('https://'+site+'.cc3798.com/view_cashier_v3/'+p,timeout=30).read()
            assert sha(body)==h,('PUBLIC_RESOURCE_MISMATCH',p);public[p]=sha(body)
        save(site+'-public.json',public)
    except Exception:
        recovery=subprocess.run(['ssh',host,'bash','-s'],input=rollback,text=True,capture_output=True)
        (E/(site+'-rollback-result.log')).write_text(recovery.stdout+recovery.stderr)
        raise
    result={'site':site,'commit':commit,'tree':c['tree'],'backup':backup,'files':manifest,'businessSmoke':'PASS','publicResources':'PASS','databaseMigration':False,'configChanged':False}
    save(site+'-installed.json',result);return result

if __name__=='__main__':
    action=sys.argv[1];site=sys.argv[2]
    print(json.dumps((prepare(site) if action=='prepare' else install(site)),ensure_ascii=False))
