const fs = require('fs');
const http = require('http');
const path = require('path');
const {root, sfc, script} = require('./recharge-style-contract.cjs');
const sass = require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/compile-dart-sass/node_modules/sass');
const postcss = require(path.join(root, '前端代码/admin/node_modules/postcss'));
const values = require(path.join(root, '前端代码/admin/node_modules/postcss-value-parser'));

// Compile the canonical SFC, not a hand-drawn lookalike. Only host tags/units
// and uni radio events are adapted for the browser; all APIs/navigation are fake.
const template = sfc.template.content.replace(/<image\b([^>]*?)\/>/g, '<img$1>')
  .replace(/<(\/?)(view|text)\b/g, (_, slash, tag) => '<' + slash + (tag === 'view' ? 'div' : 'span'))
  .replace(/<radio(?=[\s/>])/g, '<input type="radio" name="recharge-package"');
const css = postcss.parse(sass.renderSync({data: sfc.styles[0].content}).css.toString());
css.walkDecls(d => {
  const parsed = values(d.value);
  parsed.walk(n => {if (n.type === 'word' && /^[-\d.]+rpx$/.test(n.value)) n.value = `calc(${parseFloat(n.value)} * var(--rpx))`;});
  d.value = parsed.toString();
});
const html = `<!doctype html><meta charset="utf-8"><title>充值原版样式 · 本地候选</title>
<style>body{margin:0;background:#eee}iframe{display:block;border:0;width:375px;height:750px;margin:auto}</style>
<iframe id="phone" src="/phone?w=375&theme=red"></iframe>`;
const phone = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>*{box-sizing:border-box}body{margin:0;font-family:Arial,"PingFang SC",sans-serif;letter-spacing:0}button,input{font:inherit}button{display:block;border:0}input{border:0;background:transparent;outline:0}input[type=radio]{margin:0}@font-face{font-family:SemiBold;src:url(/semibold.ttf)}${css.toString()}</style>
<div id="app"></div><output id="navigation-log" hidden></output><script src="/vue.js"></script><script>
const params=new URLSearchParams(location.search),width=Number(params.get('w'))||375;
document.documentElement.style.setProperty('--rpx',width/750+'px');
const palettes={red:['#e93323','#ff7931','rgba(233,51,35,.1)'],wine:['#7b2941','#a45b6d','rgba(123,41,65,.1)'],future:['#146c63','#2f8a73','rgba(20,108,99,.1)']};
const palette=palettes[params.get('theme')]||palettes.red;
const payload={user_now_money:13692,recharge_quota:[1000,5000,3000,2000].map((price,i)=>({id:i+1,price,give_money:i===0?100:0})),recharge_attention:['充值说明来自原接口，不改变套餐及赠送规则。']};
if(params.has('long'))payload.recharge_quota.push({id:9,price:123456789,give_money:123456789});
if(params.has('empty'))payload.recharge_quota=[];
window.navigations=[];
const options=new Function('colors','getRechargeApi','toLogin','uni',${JSON.stringify(script)})({},()=>Promise.resolve({data:payload}),()=>{}, {navigateTo:value=>{window.navigations.push(value.url);document.getElementById('navigation-log').textContent=JSON.stringify(window.navigations)}});
options.template=${JSON.stringify(template)};
options.components={'radio-group':{render(h){return h('div',{attrs:{class:'package-grid','aria-label':'充值金额'},on:{change:e=>this.$emit('change',{detail:{value:e.target.value}})}},this.$slots.default)}}};
options.beforeCreate=function(){this.$store={getters:{isLogin:true}}};
options.created=function(){this.colorStyle='--view-theme:'+palette[0]+';--view-gradient:'+palette[1]+';--view-minorColorT:'+palette[2]+';';options.onLoad.call(this)};
window.vm=new Vue(options).$mount('#app');
</script>`;
http.createServer((req, res) => {
  const u = new URL(req.url, 'http://127.0.0.1');
  if (req.method !== 'GET') {res.writeHead(405).end(); return;}
  let body, type = 'text/html; charset=utf-8';
  if (u.pathname === '/') body = html;
  else if (u.pathname === '/phone') body = phone;
  else if (u.pathname === '/vue.js') {type = 'application/javascript'; body = fs.readFileSync(path.join(root, '前端代码/admin/node_modules/vue/dist/vue.js'));}
  else if (u.pathname === '/semibold.ttf') {type = 'font/ttf'; body = fs.readFileSync(path.join(root, '前端代码/uniapp/static/fonts/D-DIN-PRO-600-SemiBold.ttf'));}
  else if (u.pathname === '/static/chongzhi.png') {type = 'image/png'; body = fs.readFileSync(path.join(root, '前端代码/uniapp/pages/users/static/chongzhi.png'));}
  else {res.writeHead(404).end(); return;}
  res.writeHead(200, {'Content-Type': type, 'Cache-Control': 'no-store'});
  res.end(body);
}).listen(19229, '127.0.0.1', () => console.log('Canonical recharge fixture: http://127.0.0.1:19229/ (mock API, no order creation)'));
