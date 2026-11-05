"""Read-only page preview: fixed H5 assets, public 010 catalogue GETs, local converter POSTs.
Never forwards a member token, write command, or cookie to the customer site.
"""
import http.server,urllib.request,urllib.error,sys,pathlib,socket
root=pathlib.Path(sys.argv[1]).resolve()
class Preview(http.server.BaseHTTPRequestHandler):
 def do_GET(self):
  path=self.path.split('?')[0]
  file=root/path.lstrip('/')
  if file.is_file():
   import mimetypes
   self.send_response(200);self.send_header('Content-Type',mimetypes.guess_type(str(file))[0] or 'application/octet-stream');self.end_headers();self.wfile.write(file.read_bytes());return
  if path.startswith(('/api/','/uploads/','/statics/','/static/html/')):return self.proxy('https://010.cc3798.com'+self.path)
  self.send_response(200);self.send_header('Content-Type','text/html');self.end_headers();self.wfile.write((root/'index.html').read_bytes())
 def do_POST(self):
  if self.path!='/api/image_base64':self.send_error(405);return
  body=self.rfile.read(int(self.headers.get('Content-Length',0)))
  self.proxy('http://127.0.0.1:8080'+self.path,body)
 def proxy(self,url,body=None):
  try:
   request=urllib.request.Request(url,data=body,headers={'Content-Type':'application/json','Form-type':'h5'})
   with urllib.request.urlopen(request,timeout=25) as r:
    self.send_response(r.status);self.send_header('Content-Type',r.headers.get('Content-Type','application/octet-stream'));self.end_headers();self.wfile.write(r.read())
  except urllib.error.HTTPError as e:
   self.send_response(e.code);self.end_headers();self.wfile.write(e.read())
 def log_message(self,*args):pass
class Server(http.server.ThreadingHTTPServer): address_family=socket.AF_INET6
Server(('::1',8081),Preview).serve_forever()
