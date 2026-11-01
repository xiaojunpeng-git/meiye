#!/usr/bin/env python3
"""Release the fixed shared order-center candidate to one explicitly selected site."""
import pathlib,json,subprocess,hashlib,tarfile,gzip,shutil,sys,shlex
out=pathlib.Path(sys.argv[1]); site=sys.argv[2]; assert site in ('rh','008','010')
host='rh-server' if site=='rh' else 'mohe-server'; root='/www/wwwroot/'+site+'.cc3798.com'
def run(args,body=None):return subprocess.check_output(args,input=body,stderr=subprocess.PIPE)
def ssh(body):return run(['ssh','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes',host,'bash -s'],body.encode()).decode()
def sha(b):return hashlib.sha256(b).hexdigest()
m=json.loads((out/'manifest.json').read_text());commit=json.loads((out/'commits.json').read_text())['round'];assert run(['git','rev-parse',commit+'^{tree}']).decode().strip()==m['candidateTree']
payload=out/'payload';payload.mkdir(exist_ok=True)
files=[x['path'] for x in m['files'] if x['path'].startswith('后端代码/')]+['后端代码/app/services/cashier/v3/order/CashierV3HistoricalRechargeDate.php']
for f in files:
 data=run(['git','show',commit+':'+f]);p=payload/f.removeprefix('后端代码/');p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data)
# Build outputs come from the same fixed tree; preserve old hashed assets on the server.
shutil.copytree(out/'release-cashier',payload/'public/view_cashier_v3',dirs_exist_ok=True)
admin=out/'release-admin';shutil.copytree(admin/'view_admin',payload/'public/view_admin',dirs_exist_ok=True)
shutil.copy2(admin/'system.html',payload/'public/system.html')
for f in ('public/system.html','public/view_cashier_v3/index.html'):
 (payload/(f+'.gz')).write_bytes(gzip.compress((payload/f).read_bytes(),mtime=0))
expected=json.loads((out/(site+'-before.json')).read_text()); new={str(p.relative_to(payload)):sha(p.read_bytes()) for p in payload.rglob('*') if p.is_file()}
manifest={'commit':commit,'tree':m['candidateTree'],'files':new};(payload/'release-manifest.json').write_text(json.dumps(manifest,indent=2))
archive=out/'payload.tar.gz'
with tarfile.open(archive,'w:gz') as t:
 for f in payload.iterdir():t.add(f,arcname=f.name)
backup='/www/backups/'+site+'.cc3798.com/20261101-order-r66-'+commit[:10]
q=shlex.quote
check='\n'.join(('test "$(sha256sum '+q(root+'/'+f)+" | cut -d' ' -f1)\" = "+q(h)) if h else 'test ! -e '+q(root+'/'+f) for f,h in expected.items())
existing=[f for f,h in expected.items() if h]
script='set -euo pipefail\n'+check+'\nmkdir -p '+q(backup+'/stage')+'\ntest ! -e '+q(backup+'/before.tar.gz')+'\ntar -czf '+q(backup+'/before.tar.gz')+' -C '+q(root)+' '+' '.join(map(q,existing))+'\ngzip -t '+q(backup+'/before.tar.gz')+'\n'
ssh(script)
run(['scp','-q',str(archive),host+':'+backup+'/payload.tar.gz'])
# Apply only access indexes with the instance's own database connection.
php='''<?php
$root=$argv[1];$sql=$argv[2];require $root.'/vendor/autoload.php';(new think\\App($root.'/'))->initialize();
$expected=$argv[3];if(think\\facade\\Db::query('SELECT DATABASE() d')[0]['d']!==$expected)throw new RuntimeException('database mismatch');
$body=preg_replace('/^--.*$/m','',file_get_contents($sql));foreach(explode(';',$body)as$s)if(trim($s)!=='')think\\facade\\Db::execute(trim($s));echo "INDEXES_OK\\n";
'''
# stdin PHP script remains outside web root and is deleted after execution.
run(['ssh','-o','BatchMode=yes',host,'cat > '+q(backup+'/indexes.php')],php.encode())
restart='/etc/init.d/ruihao_swoole restart' if site=='rh' else 'systemctl restart mohe-'+site+'-swoole.service'
health="ss -lnt | grep -q ':20800 '" if site=='rh' else 'systemctl is-active --quiet mohe-'+site+'-swoole.service'
stage=backup+'/stage';db={'rh':'ruihao','008':'mohe_008','010':'mohe_010_app'}[site]
checks='\n'.join('test "$(sha256sum '+q(stage+'/'+f)+" | cut -d' ' -f1)\" = "+q(h) for f,h in new.items())
backend=[f for f in new if not f.startswith('public/')]
newpaths=[f for f in backend+['public/system.html.gz','public/view_cashier_v3/index.html.gz'] if expected.get(f) is None]
rollback='rollback(){ set +e; tar -xzf '+q(backup+'/before.tar.gz')+' -C '+q(root)+'; '+'; '.join('rm -f '+q(root+'/'+f) for f in newpaths)+'; '+restart+'; }; trap rollback ERR\n'
commands='set -euo pipefail\n'+check+'\ntest "$(sha256sum '+q(backup+'/payload.tar.gz')+" | cut -d' ' -f1)\" = "+q(sha(archive.read_bytes()))+'\ntar -xzf '+q(backup+'/payload.tar.gz')+' -C '+q(stage)+'\n'+checks+'\n'
commands+='\n'.join('/www/server/php/74/bin/php -l '+q(stage+'/'+f)+' >/dev/null' for f in backend if f.endswith('.php'))+'\n'
commands+='/www/server/php/74/bin/php '+q(backup+'/indexes.php')+' '+q(root)+' '+q(stage+'/database/upgrades/20261101_r63_order_center_global_date_index.sql')+' '+q(db)+'\n'+rollback
# Assets first, PHP next, HTML entry points last. No customer configuration or data payload.
ordered=sorted(new,key=lambda f:2 if f in ('public/system.html','public/system.html.gz','public/view_cashier_v3/index.html','public/view_cashier_v3/index.html.gz') else (0 if f.startswith('public/') else 1))
for f in ordered:
 commands+='mkdir -p '+q(str(pathlib.PurePosixPath(root+'/'+f).parent))+'\ninstall -m 0644 '+q(stage+'/'+f)+' '+q(root+'/'+f+'.r66-new')+'\nchown www:www '+q(root+'/'+f+'.r66-new')+'\nmv -f '+q(root+'/'+f+'.r66-new')+' '+q(root+'/'+f)+'\n'
commands+=restart+'\n'+health+'\ntrap - ERR\necho SWITCH_OK\n'
result=ssh(commands)
(out/(site+'-deploy.json')).write_text(json.dumps({'site':site,'commit':commit,'backup':backup,'files':len(new),'result':result},ensure_ascii=False,indent=2))
print(json.dumps({'site':site,'commit':commit,'backup':backup,'result':result[-500:]},ensure_ascii=False))
