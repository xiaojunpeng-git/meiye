"""加载唯一工作树类，随机本机库执行后自动删除；浏览器只保存匿名夹具。"""
from pathlib import Path
import base64,json,subprocess
from round9_test_runtime import prelude
root=Path(__file__).resolve().parents[2];e=root/'tests/customer-010/evidence/round10-employee-summary'
boot=prelude(root)
old=subprocess.check_output(['git','show','42709bddc562058c6c7b24ed4563c43046299340:后端代码/app/services/report/BasicActivityReportServices.php'],cwd=root).replace(b'final class BasicActivityReportServices',b'final class Round10BaselineActivityReportServices')
boot+="eval(substr(base64_decode('"+base64.b64encode(old).decode()+"'),5));\n"
boot+="$GLOBALS['fixed_query_config']=eval(substr(base64_decode('"+base64.b64encode((root/'后端代码/config/unified_query.php').read_bytes()).decode()+"'),5));\n"
code=(root/'tests/customer-010/round10-employee-summary-local.php').read_text().split('<?php',1)[1]
p=subprocess.run(['docker','exec','-i','mohe-app','php','-d','memory_limit=768M'],input=boot+code,text=True,capture_output=True)
if p.returncode==0:
 d=json.loads(p.stdout);fixture=d.pop('browser_fixture');(e/'browser-fixture.json').write_text(json.dumps(fixture,ensure_ascii=False,indent=2));p.stdout=json.dumps(d,ensure_ascii=False)
out={'exit_code':p.returncode,'output':p.stdout[-3000:],'error':p.stderr[-3000:]};(e/'local-integration.json').write_text(json.dumps(out,ensure_ascii=False,indent=2));print(json.dumps(out,ensure_ascii=False));raise SystemExit(p.returncode)
