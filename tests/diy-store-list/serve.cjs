// Local-only component verification. No production API, credentials or writes.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { root, deps, readComponent, upgradeScript, config, stores } = require('./source-harness.cjs');
const port = Number(process.env.PORT || 19209);
http.createServer((req, res) => {
  try {
    if (req.url === '/' || req.url === '/fixture.html') {
      res.setHeader('Content-Type', 'text/html; charset=utf-8');res.end(fs.readFileSync(path.join(__dirname, 'fixture.html')));
    } else if (req.url === '/vue.js' || req.url === '/iview.js' || req.url === '/iview.css') {
      const files = { '/vue.js': 'vue/dist/vue.js', '/iview.js': 'view-design/dist/iview.min.js', '/iview.css': 'view-design/dist/styles/iview.css' };
      res.setHeader('Content-Type', req.url.endsWith('.css') ? 'text/css' : 'text/javascript');res.end(fs.readFileSync(path.join(deps, files[req.url])));
    } else if (req.url === '/source.js') {
      const source = {}, css = [];
      for (const key of ['preview','member','settings','checkbox','radio']) { const c=readComponent(key);source[key]={script:c.script,template:c.template};css.push(c.css); }
      source.config=config();source.stores=stores();source.upgrade=upgradeScript;
      res.setHeader('Content-Type', 'text/javascript; charset=utf-8');
      res.end('const fixtureSources='+JSON.stringify(source)+';const sourceStyle=document.createElement("style");sourceStyle.textContent='+JSON.stringify(css.join('\n'))+';document.head.appendChild(sourceStyle);');
    } else if (req.url === '/utilities.css') {
      // Scope the project's actual utility CSS to each terminal, converting
      // only uni-app's rpx unit for this browser fixture.
      const postcss=require(path.join(deps,'postcss'));
      const scoped=(relative,prefix)=>{
        const parsed=postcss.parse(fs.readFileSync(path.join(root,relative),'utf8').replace(/(-?[\d.]+)rpx/g,(_,n)=>'calc('+n+' * var(--rpx))'));
        parsed.walkAtRules('import',rule=>rule.remove());
        parsed.walkRules(rule=>{if(!rule.parent.name || !/keyframes/i.test(rule.parent.name))rule.selectors=rule.selectors.map(s=>prefix+' '+s);});return parsed.toString();
      };
      res.setHeader('Content-Type','text/css');res.end(scoped('前端代码/admin/src/styles/style.css','.editor-pane')+scoped('前端代码/uniapp/static/css/unocss.css','.member-pane')+scoped('前端代码/uniapp/static/css/base.css','.member-pane'));
    } else if (/^\/asset\/(store|store01|store02|storeBg|shan)\.png$/.test(req.url)) {
      res.setHeader('Content-Type','image/png');res.end(fs.readFileSync(path.join(root,'前端代码/admin/src/assets/images',path.basename(req.url))));
    } else { res.writeHead(404);res.end('Not found'); }
  } catch (error) { res.writeHead(500);res.end(error.stack); }
}).listen(port,'127.0.0.1',()=>console.log('Fixture: http://127.0.0.1:'+port+'/fixture.html'));
