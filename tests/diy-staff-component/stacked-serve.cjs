// Loopback-only fixture server compiles the sole source in memory, not a build.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { root, deps, source } = require('./stacked-harness.cjs');
http.createServer((req,res) => {
  try {
    if (req.url === '/' || req.url === '/fixture.html') {
      res.setHeader('Content-Type','text/html; charset=utf-8'); res.end(fs.readFileSync(path.join(__dirname,'stacked-fixture.html')));
    } else if (req.url === '/source.js') {
      res.setHeader('Content-Type','text/javascript'); res.end('const fixtureSources='+JSON.stringify(source())+';');
    } else if (req.url === '/vue.js') {
      res.setHeader('Content-Type','text/javascript'); res.end(fs.readFileSync(path.join(deps,'vue/dist/vue.js')));
    } else if (req.url === '/avatar.png') {
      res.setHeader('Content-Type','image/png'); res.end(fs.readFileSync(path.join(root,'前端代码/admin/src/assets/images/mobilehead.png')));
    } else { res.writeHead(404); res.end('Not found'); }
  } catch (e) { res.writeHead(500); res.end(e.stack); }
}).listen(19210,'127.0.0.1',()=>console.log('Staff fixture: http://127.0.0.1:19210/fixture.html'));
