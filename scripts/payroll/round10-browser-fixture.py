"""仅本机的只读页面夹具：返回已由真实PHP/SQL/Host算出的匿名结果，不模拟业务写入。"""
from pathlib import Path
from http.server import BaseHTTPRequestHandler,HTTPServer
from urllib.parse import urlparse,parse_qs
import json,copy
root=Path(__file__).resolve().parents[2];e=root/'tests/customer-010/evidence/round10-employee-summary';fixture=json.loads((e/'browser-fixture.json').read_text())
class Handler(BaseHTTPRequestHandler):
 def do_GET(self):
  global fixture
  fixture=json.loads((e/'browser-fixture.json').read_text())
  path=urlparse(self.path)
  if path.path=='/':
   value=b'<!doctype html><html><head><meta charset="utf-8"><style>html,body,#app{margin:0;height:100%;width:100%}*{box-sizing:border-box}</style></head><body><div id="app"></div><script type="module" src="http://127.0.0.1:18118/view_cashier_v3/src/dev/r51BasicReportFixture.js"></script></body></html>';kind='text/html;charset=utf-8'
  elif path.path.startswith('/adminapi/report/basic/'):
   action=path.path.split('/basic/')[1]
   if action=='catalog':data=fixture['catalog']
   elif action=='scope':data={'tree':[{'id':901,'store_id':901,'node_type':'store','title':'夹具门店901','children':[]}],'allowed_store_ids':[901],'authorization_mode':'stores'}
   elif action=='query':
    query=json.loads(parse_qs(path.query).get('query',['{}'])[0]);page=max(1,int(query.get('page',1)));data=copy.deepcopy(fixture['page']);data['rows']=fixture['full_rows'][(page-1)*20:page*20];data['pagination']['page']=page
   else:self.send_error(404);return
   value=json.dumps({'status':200,'data':data},ensure_ascii=False).encode();kind='application/json;charset=utf-8'
  else:self.send_error(404);return
  self.send_response(200);self.send_header('Content-Type',kind);self.end_headers();self.wfile.write(value)
 def log_message(self,*args):pass
server=HTTPServer(('127.0.0.1',18121),Handler)
print('http://127.0.0.1:18121/',flush=True)
try:server.serve_forever()
except KeyboardInterrupt:pass
finally:server.server_close()
