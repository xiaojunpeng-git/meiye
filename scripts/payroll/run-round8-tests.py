"""第八轮只在一次性本地库运行真实SQL/手机服务，并输出组件验证的非真实员工夹具。"""
from pathlib import Path
from round7_test_runtime import prelude
import base64, subprocess, json

root=Path(__file__).resolve().parents[2]
e=root/'tests/customer-010/evidence/round8-mobile-fee-project-count'
e.mkdir(parents=True,exist_ok=True)
bootstrap=prelude(root)
reader=(root/'后端代码/app/services/query/metric/RegisteredMetricReadServices.php').read_bytes()
bootstrap+="eval(substr(base64_decode('"+base64.b64encode(reader).decode()+"'),5));\n"
code=(root/'tests/customer-010/payroll-local.php').read_text().split('<?php',1)[1]
addon=(root/'tests/customer-010/round8-mobile-detail.php').read_text().split('<?php',1)[1]
code=code.replace(" echo json_encode(['result'=>'PASS'",addon+"\n echo json_encode(['result'=>'PASS'")
bootstrap+="$GLOBALS['round8_projection_output']='/tmp/round8-mobile-projections.json';\n"
result=subprocess.run(['docker','exec','-i','mohe-app','php'],input=bootstrap+code,text=True,capture_output=True)
record={'test':'round8-mobile-detail','exit_code':result.returncode,'output':result.stdout[-3000:],'error':result.stderr[-2000:],'isolated_database':True,'remote_access':False}
(e/'backend-test.json').write_text(json.dumps(record,ensure_ascii=False,indent=2))
print(json.dumps(record,ensure_ascii=False))
if result.returncode:raise SystemExit(result.returncode)
payload=subprocess.check_output(['docker','exec','mohe-app','cat','/tmp/round8-mobile-projections.json'])
(e/'fixture-projections.json').write_bytes(payload)
