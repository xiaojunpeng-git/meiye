#!/usr/bin/env python3
"""在三个已授权实例的独立只读 PHP 进程核对资产候选；不写服务器文件或业务库。"""
from pathlib import Path
import argparse, subprocess, base64, hashlib, json, shlex, concurrent.futures
ROOT = Path(__file__).resolve().parents[3]
BASE = '6359f9c089a9184f31319167876fc9fd473a953f'
p = argparse.ArgumentParser()
p.add_argument('--probe', choices=['daily', 'host', 'assets', 'balance'], required=True)
p.add_argument('--tag', required=True)
a = p.parse_args()
if not a.tag.replace('-', '').isalnum(): raise SystemExit('Invalid evidence tag')
probe = ROOT / 'tests/report-basic/php' / {'daily':'round4-daily-business-date-readonly.php','host':'round4-release-readonly.php','assets':'round4-gap-assets-readonly.php','balance':'round4-balance-source-readonly.php'}[a.probe]
paths = ['config/unified_query.php', 'app/services/report/BasicReportCatalogServices.php', 'app/services/report/BasicBusinessReportServices.php',
         'app/services/report/BasicEntitlementReportServices.php', 'app/services/report/OtherReports007ReadServices.php',
         'app/services/report/Sidebar007UnconsumedV3ReadServices.php', 'app/services/report/StoreReconciliationReceiptReader.php', 'app/services/report/ReportCardTransferProofServices.php', 'app/services/report/HistoricalCardService007ReadServices.php', 'app/services/report/ReportLegacyArchiveProofServices.php', 'app/services/report/BalanceOrder007ReadServices.php']
sources = {}
for i, path in enumerate(paths):
    data = subprocess.check_output(['git', 'show', BASE + ':后端代码/' + path], cwd=ROOT) if i < 3 else (ROOT / '后端代码' / path).read_bytes()
    sources[path] = {'base64': base64.b64encode(data).decode(), 'sha256': hashlib.sha256(data).hexdigest()}
code = 'eval(substr(base64_decode("' + base64.b64encode(probe.read_bytes()).decode() + '"),5));'
def run(site):
    payload = {'site': site, 'sources': sources}
    if a.probe == 'host': payload['codes'] = ['basic_daily_reconciliation', 'basic_store_reconciliation', 'basic_balance_order_detail', 'basic_balance_order_summary', 'basic_unconsumed_detail', 'basic_unconsumed_as_of_detail', 'basic_unconsumed_summary', 'basic_customer_entitlements']
    result = subprocess.run(['ssh', '-o', 'BatchMode=yes', '-o', 'LogLevel=ERROR', 'rh-server' if site == 'rh' else 'mohe-server',
                             'cd /www/wwwroot/' + site + '.cc3798.com && /www/server/php/74/bin/php -r ' + shlex.quote(code)],
                            input=json.dumps(payload).encode(), capture_output=True, timeout=180)
    output = ROOT / 'tests/report-basic/evidence/010-round4-gap-audit' / (site + '-' + a.tag + '.json')
    if output.exists(): raise RuntimeError('Do not overwrite audit evidence')
    output.write_bytes(result.stdout)
    data = json.loads(result.stdout)
    print(site, result.returncode, {k:(v.get('status'),v.get('rows'),v.get('complete')) for k,v in data.get('reports',{}).items()} or data.get('total_cents'))
with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
    list(pool.map(run, ['rh', '008', '010']))
