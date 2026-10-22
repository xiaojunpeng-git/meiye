const http = require('http');
const fs = require('fs');
const path = require('path');
// 本地生产包验收入口：只服务固定候选资源，API转发至本地18093，不连接客户实例。
const root = '/private/tmp/mohe-r26-member-transfer-20261022/admin-candidate';
http.createServer((req, res) => {
  if (req.url.startsWith('/adminapi')) {
    const proxy = http.request({hostname:'127.0.0.1',port:18093,path:req.url,method:req.method,headers:req.headers}, reply => {
      res.writeHead(reply.statusCode, reply.headers); reply.pipe(res);
    });
    proxy.on('error', () => { res.writeHead(502); res.end('Local API unavailable'); }); req.pipe(proxy); return;
  }
  const url = new URL(req.url, 'http://127.0.0.1');
  const relative = decodeURIComponent(url.pathname).replace(/^\//,'');
  const file = path.resolve(root, relative.startsWith('admin') ? 'system.html' : relative || 'system.html');
  if (!file.startsWith(root+'/') || !fs.existsSync(file) || !fs.statSync(file).isFile()) {res.writeHead(404);res.end();return;}
  const type = {'.html':'text/html','.js':'application/javascript','.css':'text/css','.json':'application/json','.svg':'image/svg+xml','.woff':'font/woff','.woff2':'font/woff2','.png':'image/png'}[path.extname(file)] || 'application/octet-stream';
  res.writeHead(200,{'Content-Type':type,'Cache-Control':'no-store'});fs.createReadStream(file).pipe(res);
}).listen(18096,'127.0.0.1',()=>console.log('R26_FIXED_PRODUCTION_PREVIEW=18096'));
