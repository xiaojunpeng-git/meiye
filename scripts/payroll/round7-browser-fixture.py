"""仅127.0.0.1的工资页面验证器：真实服务/SQL和独立夹具库，关闭时删除夹具库。

不模拟保存结果，不使用客户账号；端到端身份鉴权仍须目标实例获准部署后单独验证。
"""
from pathlib import Path
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs
from round7_test_runtime import prelude
import base64
import json
import subprocess

ROOT = Path(__file__).resolve().parents[2]
EVIDENCE = ROOT / 'tests/customer-010/evidence/round7-salary-edit-freeze'
EVIDENCE.mkdir(parents=True, exist_ok=True)


def php(code):
    result = subprocess.run(['docker', 'exec', '-i', 'mohe-app', 'php'],
                            input=prelude(ROOT) + code, capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError(result.stderr[-3000:] + result.stdout[-3000:])
    return json.loads(result.stdout)


# 复用本轮已经验证的DDL与业务夹具，不复制客户数据；只在测试成功后保留数据库给页面操作。
fixture = (ROOT / 'tests/customer-010/payroll-local.php').read_text().split('<?php', 1)[1]
fixture = fixture.replace(" echo json_encode(['result'=>'PASS'", """
 fp('yeji_commission',['id'=>1,'product_id'=>100,'commission_json'=>json_encode(['0-10000'=>25,'10001-20000'=>27,'20001-25000'=>28,'25001-30000'=>29,'30000-9999999'=>30])]);
 Db::name('salary_field')->where('key','yingfagongzi')->update(['info'=>'work_get+labor_fee+xiao_money+canbu+jiaban']);
 $GLOBALS['round7_keep_fixture']=true;
 echo json_encode(['database'=>$database]);
 /* echo json_encode(['result'=>'PASS'""")
fixture = fixture.replace('} finally {', '*/ } finally {')
fixture = fixture.replace('if($created)$original', "if($created && empty($GLOBALS['round7_keep_fixture']))$original")
database = php(fixture)['database']
if not database.startswith('payroll_test_') or not database.replace('_', '').isalnum():
    raise RuntimeError('Refusing non-fixture schema')

# 新查询Provider也从当前唯一Git源码读取；应用的注册器随后按这些类初始化。
provider = (ROOT / '后端代码/app/services/report/query/BasicBusinessQueryProvider.php').read_bytes()
provider_load = "eval(substr(base64_decode('" + base64.b64encode(provider).decode() + "'),5));\n"
setup = r"""
(new think\App())->initialize();
$cfg=config('database');$cfg['connections']['round7_browser']=array_replace($cfg['connections']['mysql'],['database'=>DBNAME]);$cfg['default']='round7_browser';config($cfg,'database');
config(['position_model'=>'independent'],'payroll');$GLOBALS['payroll_test_mode']=1;
$context=app\services\report\BasicReportHostServices::context([901],'stores',1,'platform',false);
$context['salary_edit']=true;$context['salary_freeze']=true;
""".replace('DBNAME', json.dumps(database))
requests = []


class FixtureHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.handle_request()

    def do_POST(self):
        self.handle_request()

    def handle_request(self):
        path = urlparse(self.path)
        if path.path == '/':
            html = '<!doctype html><html><head><meta charset="utf-8"><style>html,body,#app{margin:0;height:100%;width:100%}*{box-sizing:border-box}</style></head><body><div id="app"></div><script type="module" src="http://127.0.0.1:18118/view_cashier_v3/src/dev/r51BasicReportFixture.js"></script></body></html>'
            self.send_response(200)
            self.send_header('Content-Type', 'text/html;charset=utf-8')
            self.end_headers()
            self.wfile.write(html.encode())
            return
        if not path.path.startswith('/adminapi/report/basic/'):
            self.send_error(404)
            return
        action = path.path[len('/adminapi/report/basic/'):]
        body = self.rfile.read(int(self.headers.get('Content-Length', 0))) if self.command == 'POST' else b'{}'
        query = parse_qs(path.query)
        payload = body if self.command == 'POST' else query.get('query', ['{}'])[0].encode()
        payload_load = "$payload=json_decode(base64_decode('" + base64.b64encode(payload).decode() + "'),true,64,JSON_THROW_ON_ERROR);\n"
        operations = {
            'catalog': "$result=array_values(array_filter(app\\services\\report\\BasicReportHostServices::catalog(),function($r){return $r['code']==='basic_salary_realtime';}));",
            'scope': "$result=['tree'=>[['id'=>901,'store_id'=>901,'node_type'=>'store','title'=>'测试门店901','children'=>[]]],'allowed_store_ids'=>[901],'authorization_mode'=>'stores'];",
            'query': "$result=app\\services\\report\\BasicReportHostServices::query($context,$payload);",
            'salary/save': "$result=(new app\\services\\report\\BasicSalaryManualServices())->save($context,$payload);",
            'salary/freeze': "$result=(new app\\services\\report\\BasicSalaryManualServices())->save($context,$payload,true);",
        }
        if action not in operations:
            self.send_error(404)
            return
        code = provider_load + setup + payload_load + "try {" + operations[action] + "echo json_encode(['status'=>200,'data'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}catch(Throwable $e){echo json_encode(['status'=>400,'msg'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}"
        result = php(code)
        requests.append({'action': action, 'payload': json.loads(payload), 'response': result})
        (EVIDENCE / 'browser-requests.json').write_text(json.dumps(requests, ensure_ascii=False, indent=2))
        self.send_response(200)
        self.send_header('Content-Type', 'application/json;charset=utf-8')
        self.end_headers()
        self.wfile.write(json.dumps(result, ensure_ascii=False).encode())


server = None
try:
    server = HTTPServer(('127.0.0.1', 18117), FixtureHandler)
    print(json.dumps({'fixture_url': 'http://127.0.0.1:18117/', 'database': database}), flush=True)
    server.serve_forever()
except KeyboardInterrupt:
    pass
finally:
    if server:
        server.server_close()
    php("(new think\\App())->initialize();think\\facade\\Db::execute('DROP DATABASE `" + database + "`');echo '{}';")
