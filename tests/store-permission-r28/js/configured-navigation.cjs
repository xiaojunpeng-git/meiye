/** 真实本地配置保存→新工作台：验证改名、排序、归属及停用；finally恢复原目录，不写业务事实。 */
const fs=require('fs'),assert=require('assert/strict');
const {chromium}=require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const session=JSON.parse(fs.readFileSync('/tmp/r28-browser-sessions.json'));
const url='http://127.0.0.1:18081/adminapi/setting/store-permissions';
async function api(body){const response=await fetch(url,{method:body?'POST':'GET',headers:{'Authori-zation':'Bearer '+session.admin,'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});const out=await response.json();assert.equal(out.status,200,out.msg);return out.data;}
(async()=>{
 let browser;const before=await api();
 try{
  const input=structuredClone(before);const find=id=>input.rules.find(r=>r.id===id);
  find(-2801).title='运营测试菜单';find(-2801).sort=99;find(301009).parent_id=-2806;find(-2804).enabled=0;
  await api(input);
  browser=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
  const c=await browser.newContext({viewport:{width:1515,height:1009}});await c.addInitScript(token=>sessionStorage.setItem('cashier-v3:session-token',token),session.cashier);
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.goto('http://127.0.0.1:18091/view_cashier_v3/#/entry');await p.locator('.store-quickbar').waitFor();
  const nav=p.locator('#cashier-primary-nav');assert.deepEqual(await nav.locator('button').allTextContents(),['订单管理','库存管理','基础设置','运营测试菜单']);
  await nav.getByRole('button',{name:'运营测试菜单',exact:true}).click();assert.equal(await p.locator('.store-menu').getByRole('button',{name:'费用',exact:true}).count(),0);await p.keyboard.press('Escape');await nav.getByRole('button',{name:'订单管理',exact:true}).click();await p.locator('.store-menu').getByRole('button',{name:'费用',exact:true}).waitFor();
  console.log('PASS saved category rename/order and moved fund entry reflected in fresh store bootstrap; empty finance category hidden');
  assert.equal(await nav.getByRole('button',{name:'数据中心',exact:true}).count(),0);
  await p.goto('http://127.0.0.1:18091/view_cashier_v3/#/data/reports');await p.waitForURL(u=>!u.hash.startsWith('#/data/reports'));console.log('PASS disabled data category removes menu and denies direct report route');
  const accountDisabled=await api();accountDisabled.rules.find(row=>row.id===-2805).enabled=0;await api(accountDisabled);
  await p.reload();await p.locator('.store-quickbar').waitFor();
  await p.getByRole('button',{name:'查看操作员信息',exact:true}).click();await p.getByRole('button',{name:'退出系统',exact:true}).waitFor();
  console.log('PASS disabled account category retains reachable password/logout controls');
  assert.deepEqual(errors,[]);
  await p.screenshot({path:'tests/store-permission-r28/evidence/top-navigation/configured-navigation.png'});
 }finally{
  const current=await api();await api({revision:current.revision,rules:before.rules});assert.deepEqual((await api()).rules,before.rules);if(browser)await browser.close();console.log('PASS original local catalog restored after configuration test');
 }
 console.log('R28 CONFIGURED NAVIGATION PASS');
})().catch(e=>{console.error(e.stack);process.exitCode=1});
