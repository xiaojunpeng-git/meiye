"""在现有本地PHP容器内注入固定候选类；仅测试夹具库允许写入，原工作区不复制或改写。"""
from pathlib import Path
import subprocess,json,sys
root=Path(__file__).resolve().parents[2]
from round7_test_runtime import prelude as make_prelude
prelude=make_prelude(root)
results=[]
for relative in sys.argv[1:] or ['tests/report-basic/php/salary-local.php','tests/report-basic/php/activity-local.php']:
 code=(root/relative).read_text();code=code[code.index('<?php')+5:].replace('declare(strict_types=1);','')
 if relative.endswith('payroll-local.php'):
  addon=(root/'tests/customer-010/payroll-manual-integration.php').read_text().split('<?php',1)[1]
  code=code.replace(" echo json_encode(['result'=>'PASS'",addon+"\n echo json_encode(['result'=>'PASS'")
 run=subprocess.run(['docker','exec','-i','-e','R51_LOCAL_TEST=1','mohe-app','php'],input=prelude+code,text=True,capture_output=True)
 results.append({'test':relative,'exit_code':run.returncode,'output':run.stdout[-4500:],'error':run.stderr[-2000:]})
 print(json.dumps(results[-1],ensure_ascii=False),flush=True)
 if run.returncode:sys.exit(run.returncode)
e=root/'tests/customer-010/evidence/round7-salary-edit-freeze';e.mkdir(parents=True,exist_ok=True)
(e/'local-integration.json').write_text(json.dumps(results,ensure_ascii=False,indent=2))
