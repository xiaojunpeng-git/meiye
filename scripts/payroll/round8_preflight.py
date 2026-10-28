"""只读逐站记录线上运行摘要，并阻止覆盖任何未知后端或手机包基线。"""
from pathlib import Path
import subprocess,json,base64,shlex,hashlib,sys
ROOT=Path(__file__).resolve().parents[2]
SUPPLEMENT='--remove-net' in sys.argv
E=ROOT/('tests/customer-010/evidence/round8-remove-net-release' if SUPPLEMENT else 'tests/customer-010/evidence/round8-release')
HOSTS={'rh':'rh-server','008':'mohe-server','010':'mohe-server'}
FILES=['app/services/mobile/warehouse/MobileWarehouseServices.php','app/services/query/metric/RegisteredMetricReadServices.php','public/system.html','public/view_cashier_v3/index.html','public/view_mobile_v3/index.html','config/payroll.php','config/basic_report.php']
def inspect(site,request):
 code=base64.b64encode((ROOT/'scripts/payroll/round8-release-inspect.php').read_bytes()).decode()
 command='/www/server/php/74/bin/php -r '+shlex.quote('eval(substr(base64_decode("'+code+'"),5));')
 result=subprocess.run(['ssh','-q','-o','BatchMode=yes',HOSTS[site],command+' '+site],input=json.dumps(request),text=True,capture_output=True,timeout=90)
 if result.returncode:raise RuntimeError(site+': '+result.stderr[-2000:])
 return json.loads(result.stdout)
if __name__=='__main__':
 E.mkdir(parents=True,exist_ok=True)
 for site in HOSTS:
  d=inspect(site,{'files':FILES});(E/(site+'-preflight.json')).write_text(json.dumps(d,ensure_ascii=False,indent=2))
  source='872b22e3' if SUPPLEMENT else 'a9572429'
  baseline={p:hashlib.sha256(subprocess.check_output(['git','show',source+':后端代码/'+p],cwd=ROOT)).hexdigest() for p in FILES if p.startswith('app/')}
  mismatches=[p for p,w in baseline.items() if d['files'][p]!=w]
  old=json.loads((ROOT/('tests/customer-010/evidence/round8-release/h5-build.json' if SUPPLEMENT else 'tests/customer-010/evidence/round6-payroll-algorithm/candidate-mobile-vue3-build.json')).read_text())
  drift=[p for p,w in old['assets'].items() if d['mobile_assets'].get('public/view_mobile_v3/'+p)!=w]
  record={'site':site,'mode':d['mode'],'position_model':d['position_model'],'backend_drift':mismatches,'mobile_asset_drift':drift,'baseline_result':'PASS' if not mismatches and not drift else 'STOP'}
  (E/(site+'-baseline-check.json')).write_text(json.dumps(record,ensure_ascii=False,indent=2))
  print(json.dumps(record,ensure_ascii=False),flush=True)
  assert not mismatches and not drift,'Unknown live baseline: '+site
