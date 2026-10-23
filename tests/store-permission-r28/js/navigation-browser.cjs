/** 真实本地导航回归：菜单/快捷入口、原业务路由、报表目录、草稿与视口，不提交业务命令。 */
const fs=require('fs'),assert=require('assert/strict');
const {chromium}=require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const session=JSON.parse(fs.readFileSync('/tmp/r28-browser-sessions.json'));
const evidence=process.env.R28_EVIDENCE||'/tmp/r28-navigation';fs.mkdirSync(evidence,{recursive:true});
(async()=>{
 const b=await chromium.launch({headless:true,executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
 try{
  const c=await b.newContext({viewport:{width:1515,height:1009}});await c.addInitScript(token=>sessionStorage.setItem('cashier-v3:session-token',token),session.cashier);
  const p=await c.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.goto('http://127.0.0.1:18091/view_cashier_v3/#/entry');await p.locator('.store-quickbar').waitFor();
  const top=p.locator('#cashier-primary-nav'),quick=p.locator('.store-quickbar');
  assert.deepEqual(await top.locator('button').allTextContents(),['运营管理','订单管理','库存管理','数据中心','基础设置']);
  assert.deepEqual((await quick.locator('button').allTextContents()).map(t=>t.trim()),['收银','挂单','房间','预约','会员','费用']);
  assert.equal(await p.locator('.cashier-sidebar').count(),0);console.log('PASS five top categories, exact six shortcuts, sidebar removed');
  const topBox=await p.locator('.store-topbar').boundingBox();assert.equal(topBox.height,40);
  const brand=await p.locator('.store-contextbar .store-topbar__brand').boundingBox(),cashier=await quick.getByRole('button',{name:'收银',exact:true}).boundingBox(),account=await p.locator('.store-contextbar .store-topbar__account').boundingBox();
  assert.ok(brand.x+brand.width<=cashier.x&&brand.y>=40&&account.x>cashier.x+cashier.width);assert.ok(account.x+account.width>=1490);
  console.log('PASS compact 40px top row; store left of cashier and account right in second row');
  const snapshot=()=>p.evaluate(async()=>JSON.stringify((await import('/view_cashier_v3/src/services/cashierV3Bridge.js')).useCashierV3State().cashier));
  const before=await snapshot();const actions=[];const requests=request=>{if(request.method()==='POST'&&request.url().endsWith('/workbenches/actions'))actions.push(request.postDataJSON()?.action);};p.on('request',requests);
  const categories={运营管理:['收银','挂单','房间','预约','会员','客情','费用'],订单管理:['全部订单','消费订单','充值订单','退款记录','欠款管理','服务记录','补交记录','赠送记录','卡操作记录'],库存管理:['库存','入库','出库','库存查询','盘点','库存统计','请货','调拨','院装','导入','会员领用'],基础设置:['人员管理','房间设置','修改密码','退出系统']};
  for(const [label,names]of Object.entries(categories)){
   await top.getByRole('button',{name:label,exact:true}).click();const menu=p.locator('.store-menu');for(const name of names)await menu.getByRole('button',{name,exact:true}).waitFor();
   await p.keyboard.press('Escape');await menu.waitFor({state:'hidden'});
  }
  await top.getByRole('button',{name:'数据中心',exact:true}).click();await p.locator('.store-menu button[data-rule-id^="-29"]').first().waitFor();
  const dataMenu=p.locator('.store-menu');for(const name of ['经营看板','目标','数据','合作方品项汇总','六维数据分析表'])await dataMenu.getByRole('button',{name,exact:true}).waitFor();
  assert.ok(await p.locator('.store-menu button[data-rule-id^="-29"]').count()>20);await p.screenshot({path:evidence+'/data-menu.png'});
  await p.keyboard.press('Escape');assert.equal(await snapshot(),before);assert.deepEqual(actions,[]);p.off('request',requests);
  console.log('PASS all former menu entries and report list preserved; opening menus does not change cashier draft or submit actions');
  await top.getByRole('button',{name:'库存管理',exact:true}).click();await p.locator('.store-menu').getByRole('button',{name:'库存查询',exact:true}).click();await p.locator('.cashier-inventory-workspace').waitFor();
  assert.equal(await top.locator('.is-active').innerText(),'库存管理');await quick.getByRole('button',{name:'收银',exact:true}).click();await p.locator('.cashier-inventory-workspace').waitFor({state:'hidden'});assert.equal(await snapshot(),before);console.log('PASS original inventory workspace opens and returns to same cashier draft');
  for(const [name,hash]of [['挂单','/hang'],['房间','/room'],['预约','/reservation'],['会员','/member'],['费用','/fund']]){
   await quick.getByRole('button',{name,exact:true}).click();await p.waitForURL(url=>url.hash.includes(hash));assert.equal((await quick.locator('[aria-current="page"]').innerText()).trim(),name);
  }
  console.log('PASS six shortcuts reuse original routes and highlight current page');
  await top.getByRole('button',{name:'数据中心',exact:true}).click();await p.locator('.store-menu').getByRole('button',{name:'合作方品项汇总',exact:true}).click();await p.waitForURL(url=>url.hash.includes('/data/reports/partner_item_summary'));await p.locator('.store-business-report').waitFor();assert.equal(await p.locator('.store-business-report__tabs-shell').count(),0);await p.getByRole('button',{name:'查询',exact:true}).waitFor();await p.screenshot({path:evidence+'/report-no-tabs.png'});console.log('PASS duplicate report tabs removed; original report query remains');
  await top.getByRole('button',{name:'基础设置',exact:true}).click();await p.locator('.store-menu').getByRole('button',{name:'房间设置',exact:true}).click();await p.waitForURL(url=>url.hash.includes('/management-center/room-settings'));await p.getByRole('button',{name:'服务名称',exact:true}).waitFor();
  await top.getByRole('button',{name:'运营管理',exact:true}).click();await p.locator('.store-menu').getByRole('button',{name:'费用',exact:true}).click();await p.locator('.fund-frame').waitFor();
  console.log('PASS report deep link, room/service-name settings, and original fund workspace remain accessible');
  await top.getByRole('button',{name:'基础设置',exact:true}).click();await p.locator('.store-menu').getByRole('button',{name:'人员管理',exact:true}).click();await p.waitForURL(url=>url.hash.includes('/management-center/staff'));
  await p.getByRole('button',{name:'新增员工',exact:true}).waitFor();await p.screenshot({path:evidence+'/staff-top-navigation.png'});
  await top.getByRole('button',{name:'基础设置',exact:true}).click();await p.locator('.store-menu').getByRole('button',{name:'修改密码',exact:true}).click();await p.locator('form[aria-label="修改登录账号和密码"]').waitFor();await p.locator('form[aria-label="修改登录账号和密码"]').getByRole('button',{name:'取消',exact:true}).click();
  console.log('PASS original staff and password entry functions remain accessible');
  await p.setViewportSize({width:930,height:740});await top.getByRole('button',{name:'数据中心',exact:true}).click();const box=await p.locator('.store-menu').boundingBox();assert.ok(box.x>=0&&box.x+box.width<=930);await p.keyboard.press('Escape');
  const main=await p.locator('.cashier-main').boundingBox();assert.ok(main.y>=90&&main.y+main.height<=741);const footer=await p.locator('.staff-list-page .table-pagination').boundingBox();assert.ok(footer && footer.y+footer.height<=741);await p.screenshot({path:evidence+'/compact-top-navigation.png'});console.log('PASS compact desktop keeps menu and remaining main region inside viewport');
  assert.deepEqual(errors,[]);console.log('R28 TOP NAVIGATION PASS');
 }finally{await b.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1});
