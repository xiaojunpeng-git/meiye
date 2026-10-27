"""通过stdin执行固定只读预检；不下载服务器代码，不输出身份密钥。"""
from pathlib import Path
import subprocess,json,base64,shlex
r=Path(__file__).resolve().parents[2];e=r/'tests/customer-010/evidence/round6-payroll-release'
files=[p[len('后端代码/'):] for p in json.loads((e/'backend-source-list.json').read_text())]+['config/basic_report.php','config/payroll.php','public/system.html','public/view_cashier_v3/index.html','public/view_mobile_v3/index.html']
code=base64.b64encode((r/'scripts/payroll/release-inspect.php').read_bytes()).decode();command='/www/server/php/74/bin/php -r '+shlex.quote('eval(substr(base64_decode("'+code+'"),5));')
for site,host in [('rh','rh-server'),('008','mohe-server'),('010','mohe-server')]:
 result=subprocess.run(['ssh','-q','-o','BatchMode=yes',host,command+' '+site],input=json.dumps(files),text=True,capture_output=True,timeout=90)
 if result.returncode:raise RuntimeError(result.stderr[-2000:])
 d=json.loads(result.stdout);(e/(site+'-full-preflight.json')).write_text(json.dumps(d,ensure_ascii=False,indent=2));p=d['defaults_plan'];print(json.dumps({'site':site,'mode':d['mode'],'default_projects':len(p['projects']),'default_card_rows':len(p['cards']),'preserved_projects':p['preserved_projects'],'preserved_cards':p['preserved_cards'],'excluded_sources':p['excluded_sources'],'plan':p['sha256'],'files':len(files)}))
