// Loopback-only visual fixture. Compile canonical sources in memory; no copies,
// customer API calls, production writes or dependency installation are needed.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { root, readComponent, config } = require('./source-harness.cjs');
const port = Number(process.env.PORT || 19208);
http.createServer((req, res) => {
  try {
    if (req.url === '/' || req.url === '/fixture.html') {
      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.end(fs.readFileSync(path.join(__dirname, 'fixture.html')));
    } else if (req.url === '/vue.js') {
      res.setHeader('Content-Type', 'text/javascript; charset=utf-8');
      res.end(fs.readFileSync(path.join(root, '前端代码/admin/node_modules/vue/dist/vue.min.js')));
    } else if (req.url === '/source.js') {
      const source = {};
      for (const key of ['preview','member','settings']) {const c=readComponent(key);source[key]={script:c.script,template:c.template};}
      source.config = config();
      res.setHeader('Content-Type', 'text/javascript; charset=utf-8');
      res.end('const fixtureSources='+JSON.stringify(source)+';const style=document.createElement("style");style.textContent='+JSON.stringify(readComponent('preview').css)+';document.head.appendChild(style);');
    } else if (/^\/asset\/(rank[123]_icon|presale)\.png$/.test(req.url)) {
      res.setHeader('Content-Type', 'image/png');
      res.end(fs.readFileSync(path.join(root, '前端代码/uniapp/static/img', path.basename(req.url))));
    } else {res.writeHead(404);res.end('Not found');}
  } catch (e) {res.writeHead(500);res.end(e.message);}
}).listen(port, '127.0.0.1', () => console.log('Fixture: http://127.0.0.1:'+port));
