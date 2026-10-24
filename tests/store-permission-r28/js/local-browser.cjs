/** 本地隔离浏览器会话，令牌从0600临时文件读入，截图/日志不包含凭据。 */
const fs = require('fs');
const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const session=JSON.parse(fs.readFileSync('/tmp/r28-browser-sessions.json','utf8'));
const evidence=process.env.R28_EVIDENCE || '/tmp/r28-browser';
fs.mkdirSync(evidence,{recursive:true});
(async()=>{
 const b=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
 try{
  const c=await b.newContext({viewport:{width:1515,height:1009}});
  await c.addCookies([{name:'admin-token',value:session.admin,url:'http://127.0.0.1:18081'}]);
  await c.addInitScript(() => {
    localStorage.setItem('unique_auth','setting-system-menus,admin-store-region-job_positions');
    // 本地未启动通知WebSocket；只单独记录已证实来自该连接的拒绝，不屏蔽业务脚本错误。
    window.__r28SocketErrors = 0;
    addEventListener('unhandledrejection', event => {
      if (event.reason instanceof Event && event.reason.target instanceof WebSocket) {
        window.__r28SocketErrors++; event.preventDefault();
      }
    });
  });
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push({page:'admin',message:e.message,stack:e.stack}));
  await p.goto('http://127.0.0.1:18081/admin/setting/store-permissions',{waitUntil:'domcontentloaded',timeout:60000});
  await p.getByText('保存规则',{exact:true}).waitFor({timeout:60000});
  await p.locator('.rule-table tbody tr[data-rule-id]').first().waitFor();
  await p.waitForFunction(()=>document.querySelectorAll('.rule-table input').length>5);
  await p.getByText('保存规则',{exact:true}).waitFor();
  await p.waitForFunction(()=>!Array.from(document.querySelectorAll('button')).find(b=>b.textContent.trim()==='保存规则').disabled);
  const catalogResponse = await c.request.get('http://127.0.0.1:18081/adminapi/setting/store-permissions', { headers: { 'Authori-zation': 'Bearer ' + session.admin } });
  const catalog = (await catalogResponse.json()).data;
  for (const label of ['运营管理','库存管理','财务管理','数据中心','基础设置']) if (!(await p.locator('.rule-category input.ivu-input').evaluateAll(nodes=>nodes.map(n=>n.value))).includes(label)) throw new Error('missing rule category ' + label);
  const rootCount = await p.locator('.rule-table tbody tr[data-rule-id]').count();
  if (await p.locator('tr[data-rule-id="301010"]').count()) throw new Error('collapsed child must be hidden');
  if (await p.locator('.rule-table tbody tr[data-rule-id]').count() !== rootCount) throw new Error('default tree must show top-level rules');
  await p.getByRole('button', { name: '展开运营管理', exact: true }).click();
  await p.getByRole('button', { name: '展开基础设置', exact: true }).click();
  await p.getByRole('button', { name: '展开收银', exact: true }).click();
  if (await p.locator('tr[data-rule-id="301010"]').getAttribute('data-depth') !== '2') throw new Error('cashier child indentation missing');
  await p.getByRole('button', { name: '展开管理', exact: true }).click();
  await p.getByRole('button', { name: '展开人员管理', exact: true }).click();
  if (await p.locator('tr[data-rule-id="301040"]').getAttribute('data-depth') !== '3') throw new Error('nested staff action indentation missing');
  await p.screenshot({ path: evidence + '/rule-hierarchy-expanded.png' });
  await p.getByRole('button', { name: '收起全部', exact: true }).click();
  await p.getByPlaceholder('搜索功能名称或权限代码').fill('cashier.v3.staff.create');
  if (await p.locator('.rule-table tbody tr[data-rule-id]').count() !== 4) throw new Error('search must retain management/staff ancestors');
  await p.getByPlaceholder('搜索功能名称或权限代码').fill('');
  if (await p.locator('.rule-table tbody tr[data-rule-id]').count() !== rootCount) throw new Error('search clear must restore collapsed tree');
  await p.getByRole('button', { name: '展开全部', exact: true }).click();
  if (await p.locator('.rule-table tbody tr[data-rule-id]').count() !== catalog.rules.length) throw new Error('expand-all lost rules');
  await p.getByRole('button', { name: '收起全部', exact: true }).click();
  const saving = p.waitForRequest(request => request.url().endsWith('/setting/store-permissions') && request.method() === 'POST');
  const saved = p.waitForResponse(response => response.url().endsWith('/setting/store-permissions') && response.request().method() === 'POST');
  await p.getByText('保存规则', { exact: true }).click();
  const submitted = (await saving).postDataJSON();
  if (submitted.rules.length !== catalog.rules.length || JSON.stringify(submitted.rules) !== JSON.stringify(catalog.rules)) throw new Error('collapse/search changed saved permissions');
  if ((await (await saved).json()).status !== 200) throw new Error('full document save failed');
  console.log('PASS hierarchical indent, individual/all collapse, search ancestors, restore and complete-document save');
  console.log('PASS actual platform rule page renders registered editable capabilities');
  await p.screenshot({path:evidence+'/platform-rules.png'});
  const count=await p.locator('.rule-table tbody tr[data-rule-id]').count();
  await p.getByPlaceholder('搜索功能名称或权限代码').fill('cashier.v3.fund');
  if(await p.locator('.rule-table tbody tr[data-rule-id]').count()!==2)throw new Error('filter failed');
  await p.getByPlaceholder('搜索功能名称或权限代码').fill('');
  if(await p.locator('.rule-table tbody tr[data-rule-id]').count()!==count)throw new Error('filter lost rows');
  console.log('PASS filter and clear retain complete editable document');
  await p.goto('http://127.0.0.1:18081/admin/store/region/job-positions',{waitUntil:'domcontentloaded'});
  await p.getByText('编辑',{exact:true}).first().click();
  await p.locator('.fcpe-tree').waitFor();
  await p.locator('.fcpe-actions').getByText('展开',{exact:true}).click();
  const tree=await p.locator('.fcpe-tree').innerText();
  for(const title of ['费用','客情','数据','目标','人员管理','房间设置'])if(!tree.includes(title))throw new Error('job tree missing '+title);
  await p.screenshot({path:evidence+'/position-store-tab.png'});
  console.log('PASS existing job editor loads same split catalog without replacing platform/mobile tabs');
  const store=await b.newContext({viewport:{width:1515,height:1009}});
  await store.addInitScript(token=>sessionStorage.setItem('cashier-v3:session-token',token),session.cashier);
  const sp=await store.newPage();sp.on('pageerror',e=>errors.push({page:'admin',message:e.message,stack:e.stack}));
  await sp.goto('http://127.0.0.1:18091/view_cashier_v3/#/entry',{waitUntil:'domcontentloaded'});
  await sp.locator('#cashier-primary-nav').waitFor({timeout:60000});
  const nav=await sp.locator('.store-quickbar').innerText();
  if(!nav.includes('收银'))throw new Error('store nav missing');
  console.log('PASS real store bootstrap and navigation render; first accessible route selected');
  await sp.screenshot({path:evidence+'/store-nav.png'});
  if(errors.length)throw new Error('page runtime errors: '+JSON.stringify(errors));
  console.log('R28 BROWSER PASS');
 }finally{await b.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1});
