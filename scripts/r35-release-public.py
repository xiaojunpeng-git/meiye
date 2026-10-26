"""公网只读核验正式入口、原静态资源及退役路径；不使用生产登录会话。"""
from pathlib import Path
import urllib.request,urllib.error,json,hashlib,sys
R=Path('tests/organization-store-v3/evidence/R35-organization-edit/release');s=sys.argv[1];assert s in ['rh','008','010'];v=json.loads((R/(s+'-after.json')).read_text());domain='rh.cc3798.com'if s=='rh'else s+'.cc3798.com'
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**kw):return None
op=urllib.request.build_opener(NoRedirect);checks=[]
def get(path):
 try:return op.open('https://'+domain+path,timeout=30)
 except urllib.error.HTTPError as e:return e
for f in v['frontends']:
 if f['root'].endswith('-8080'):continue
 entry='/view_cashier_v3/'if f['kind']=='cashier'else'/admin/login';r=get(entry);assert r.code==200,(entry,r.code);checks.append({'path':entry,'status':200})
 for p,h in f['assets'].items():
  r=get(p);assert r.code==200 and hashlib.sha256(r.read()).hexdigest()==h,p;checks.append({'path':p,'status':200,'sha256':h})
if s=='rh':
 for p in ['/preview-8080/view_cashier_v3/','/store-v3/']:
  r=get(p);assert r.code==410 and not r.headers.get('Location'),p;checks.append({'path':p,'status':410})
(R/(s+'-public.json')).write_text(json.dumps({'site':s,'result':'PASS','checks':checks,'sourceCommit':json.loads((R/'commit.json').read_text())['commit']},ensure_ascii=False,indent=2));print(s,'PUBLIC PASS',len(checks))
