"""补齐用户指出的瑞昊preview入口：复用已验收R32完整门店包，备份并保持正式入口、平台包和数据库不变。"""
from pathlib import Path
import subprocess,json,hashlib,base64,tarfile,io
R=Path('tests/cashier-v3/evidence/R32-report-scroll/release');P=R/'preview-fix'
def ssh(cmd,data=None):return subprocess.check_output(['ssh','-q','-o','BatchMode=yes','rh-server',cmd],input=data,stderr=subprocess.PIPE)
def sha(b):return hashlib.sha256(b).hexdigest()
def probe():return json.loads(ssh('/www/server/php/74/bin/php -- rh',Path('scripts/r28-permissions-inspect.php').read_bytes()))
def save(n,v):(P/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
before=json.loads((P/'before.json').read_text());assert probe()==before
info=json.loads((R/'commit.json').read_text());build=json.loads((R/'candidate-cashier-v3-build.json').read_text());assert build['result']=='PASS' and build['sourceStable'];assert build['tree']==info['candidateTree']
# A previously deployed older preview is advanced to the same accepted unified source,
# preserving all accepted earlier features rather than constructing a second editable source.
root='/www/wwwroot/rh.cc3798.com-8080';directory='/www/backups/rh/r32-report-scroll-preview-'+info['commit'][:8]
files={}
for rel,h in build['assets'].items():
 b=(Path(build['output'])/rel).read_bytes();assert sha(b)==h;files['public/view_cashier_v3/'+rel]=b
encoded=base64.b64encode(json.dumps(list(files)).encode()).decode();php="<?php $r='"+root+"';$a=[];foreach(json_decode(base64_decode('"+encoded+"'),true) as $p)$a[$p]=is_file($r.'/'.$p)?hash_file('sha256',$r.'/'.$p):null;echo json_encode($a);"
olds=json.loads(ssh('/www/server/php/74/bin/php',php.encode()));front=next(f for f in before['frontends'] if f['root']==root and f['kind']=='cashier');assert olds[front['entry']]==front['sha256']
manifest={'site':'rh','commit':info['commit'],'sourceTree':build['tree'],'root':root,'target':'preview-8080 cashier only','targets':dict(sorted({p:{'before':olds[p],'after':sha(b)}for p,b in files.items()}.items(),key=lambda x:(x[0].endswith('index.html'),x[0])))}
installer=Path('scripts/r32-report-scroll-install.php').read_text().replace('/r32-report-scroll-','/r32-report-scroll-preview-').replace("$root='/www/wwwroot/'.$domain;","$root='/www/wwwroot/rh.cc3798.com-8080';if($site!=='rh')throw new RuntimeException('RH_PREVIEW_ONLY');")
# Only generated installer parameters change; each payload target is still restricted to cashier files.
installer=installer.replace("$dst=$root.'/'.$rel;$src=", "if(strpos($rel,'public/view_cashier_v3/')!==0)throw new RuntimeException('CASHIER_ONLY');$dst=$root.'/'.$rel;$src=")
archive=io.BytesIO()
with tarfile.open(fileobj=archive,mode='w:gz') as tf:
 for name,b in [('payload/'+p,b)for p,b in files.items()]+[('payload/manifest.json',json.dumps(manifest).encode()),('install.php',installer.encode())]:
  item=tarfile.TarInfo(name);item.size=len(b);item.mode=0o600;tf.addfile(item,io.BytesIO(b))
ssh("test ! -e '"+directory+"' && mkdir -m 700 -p '"+directory+"'");ssh('tar -xzf - -C '+directory,archive.getvalue());assert probe()==before
(P/'deployment.log').write_bytes(ssh('/www/server/php/74/bin/php '+directory+'/install.php '+directory+' install'))
actual=json.loads(ssh('/www/server/php/74/bin/php',php.encode()));assert actual=={p:x['after']for p,x in manifest['targets'].items()}
after=probe()
for k in before:
 if k!='frontends':assert before[k]==after[k],k
for old,new in zip(before['frontends'],after['frontends']):
 if old['root']==root and old['kind']=='cashier':
  assert new['sha256']==sha(files[new['entry']]);assert all(sha(files['public/'+p.lstrip('/')])==h for p,h in new['assets'].items())
 else:assert old==new,'Protected entry changed'
save('manifest.json',manifest);save('after.json',after);save('verified.json',{'result':'PASS','commit':info['commit'],'backup':directory,'root':root,'count':len(files),'formal':'unchanged','platform':'unchanged','database':'no writes','previewUpgrade':'accepted R51 frontend advanced to accepted unified R32, including accepted R31 unit price validation','userAuthorization':'user identified this exact preview URL as still broken; correction of same accepted release'})
print('RH PREVIEW DEPLOY VERIFIED',info['commit'])
