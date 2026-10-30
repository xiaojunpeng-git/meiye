"""第12轮逐实例只读基线探测；发布与备份写操作不在此脚本内。"""
from pathlib import Path
import json,base64,hashlib,subprocess,shlex,concurrent.futures
ROOT=Path(__file__).resolve().parents[2];E=ROOT/'tests/customer-010/evidence/round12-release';HOSTS={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
def probe(site):
 state=json.loads((ROOT/'tests/customer-010/evidence/round12-member-survey/development-state.json').read_text())
 files=[p['path'].removeprefix('后端代码/')for p in state['owned_files']if p['path'].startswith('后端代码/')]+['.env','public/system.html','public/index.html']
 code=base64.b64encode((ROOT/'scripts/member-survey/release-preflight.php').read_bytes()).decode()
 cmd='/www/server/php/74/bin/php -r '+shlex.quote('eval(substr(base64_decode("'+code+'"),5));')+' '+site
 result=subprocess.run(['ssh','-q','-o','BatchMode=yes',HOSTS[site],cmd],input=json.dumps({'files':files}).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=150)
 if result.returncode:raise RuntimeError(site+': '+result.stdout.decode()[-1800:]+result.stderr.decode()[-1000:])
 data=json.loads(result.stdout);assert data['site']==site and data['read_only'];(E/(site+'-preflight.json')).write_text(json.dumps(data,ensure_ascii=False,indent=2));return {'site':site,'tables':data['survey_tables'],'dependencies':data['dependencies'],'assets':len(data['frontend_assets'])}
if __name__=='__main__':
 with concurrent.futures.ThreadPoolExecutor(max_workers=3)as pool:
  for result in pool.map(probe,HOSTS):print(json.dumps(result,ensure_ascii=False))
