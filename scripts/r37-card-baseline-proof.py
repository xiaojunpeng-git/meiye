# coding: utf-8
"""核对现网R68留存的只读输入/输出与唯一Git正本，不把旧包或服务器文件作为源码。"""
from pathlib import Path
import subprocess,json,hashlib
R=Path('tests/cashier-v3/evidence/R37-card-labor-followup/release');plan=json.loads((R/'trees.json').read_text());record=Path('/private/tmp/r68-final-release-20261027/admin-candidate-input.json');old=json.loads(record.read_text());tree=old['tree'];output=Path(old['output']);source=Path(old['input']).parents[1]
def g(*a):return subprocess.check_output(['git',*a])
def sha(b):return hashlib.sha256(b).hexdigest()
for package in ['前端代码/admin','前端代码/shared']:
 assert g('rev-parse',tree+':'+package)==g('rev-parse',plan['plans']['standard']['baselineTree']+':'+package)
verified={}
for row in g('ls-tree','-rz',tree,'--','前端代码/admin','前端代码/shared').split(b'\0'):
 if not row:continue
 meta,path=row.split(b'\t',1);mode,kind,blob=meta.split();assert kind==b'blob';n=path.decode();p=source/n;b=p.read_bytes();assert hashlib.sha1(('blob '+str(len(b))+'\0').encode()+b).hexdigest()==blob.decode();assert p.stat().st_mode&0o222==0;verified[n]=sha(b)
assets={str(p.relative_to(output)):sha(p.read_bytes())for p in output.rglob('*')if p.is_file()}
for site in ['rh','008']:
 live=json.loads((R/(site+'-before.json')).read_text());front=next(f for f in live['frontends']if f['kind']=='admin'and f['root']==live['root']);assert assets['system.html']==front['sha256'];assert all(assets[n.lstrip('/')]==h for n,h in front['assets'].items())
(R/'canonical-runtime-baseline.json').write_text(json.dumps({'result':'PASS','sourceCommit':old['commit'],'sourceTree':tree,'buildRecordSHA256':sha(record.read_bytes()),'sourceInputs':verified,'output':str(output),'assets':assets,'proof':'留存已验收R68构建完整只读输入与当前基线admin/shared Git子树一致；产物主HTML/JS/CSS与瑞昊008现网完全一致。新候选仍由正本固定树全新构建，不复用旧包发布。'},ensure_ascii=False,indent=2));print('R68 canonical 1512 inputs and live platform artifacts verified')
