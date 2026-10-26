/** 真实共用Vue报表组件/外壳CSS，合成宽表接口仅验证布局；不写业务、不冒充实际报表数据验收。 */
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict')
const {chromium}=require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright')
const evidence=path.resolve(__dirname,'../evidence/R32-report-scroll'),phase=process.argv[2]||'after'
const codes=['member_consumption_detail','partner_item_detail','market_detail']
const columns=Array.from({length:22},(_,i)=>({key:'c'+i,label:i===0?'门店':'列'+i,width:i===0?160:130,fixed:i===0?'left':undefined}))
let html=`<html><head><meta charset="utf-8"></head><body><div id="app"></div><script type="module">
import {createApp,h} from '/view_cashier_v3/node_modules/.vite/deps/vue.js';import {createRouter,createWebHashHistory,RouterView} from '/view_cashier_v3/node_modules/.vite/deps/vue-router.js';
import Report from '/view_cashier_v3/src/views/StoreBusinessReportView.vue';import '/view_cashier_v3/src/styles/base.css';
const router=createRouter({history:createWebHashHistory(),routes:[{name:'cashier-v3-store-business-reports',path:'/data/reports/:report',component:Report}]});
createApp({render:()=>h('div',{class:'cashier-shell cashier-shell--top-navigation'},[h('header',{class:'store-navigation',style:'flex:none;height:90px'},'报表布局测试'),h('section',{class:'cashier-main cashier-main--headerless'},[h('main',{class:'cashier-main__content'},[h('div',{class:'cashier-main__view'},[h(RouterView)])])])])}).use(router).mount('#app');</script></body></html>`
;(async()=>{const source=await(await fetch('http://127.0.0.1:18091/view_cashier_v3/src/views/StoreBusinessReportView.vue')).text();for(const name of ['vue','vue-router']){const prefix='/view_cashier_v3/node_modules/.vite/deps/'+name+'.js';const url=source.split('\"').find(x=>x.startsWith(prefix+'?'));assert.ok(url);html=html.replace(prefix,url)}const browser=await chromium.launch({channel:'chrome',headless:true,ignoreDefaultArgs:['--hide-scrollbars']});const results=[];try{
for(const viewport of [{width:1515,height:1009},{width:960,height:720}]){
 const context=await browser.newContext({viewport});let count=20;const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message))
 await page.route('**/r32-scroll-fixture.html',r=>r.fulfill({contentType:'text/html',body:html}))
 await page.route('**/cashierapi/v3/report/unified/**',r=>{const url=new URL(r.request().url());let data;if(url.pathname.endsWith('/catalog'))data=codes.map(code=>({code,name:code}));else if(url.pathname.endsWith('/scope'))data={allowed_store_ids:[1],tree:[]};else data={columns,records:Array.from({length:count},(_,i)=>Object.fromEntries(columns.map(c=>[c.key,`测试${i+1}`]))),summary_row:{c0:'合计'},page_size:20,total:count?42:0,table_layout:{fixed:true}};return r.fulfill({contentType:'application/json',body:JSON.stringify({status:200,data})})})
 for(const code of codes){
  await page.goto(`http://127.0.0.1:18091/r32-scroll-fixture.html#/data/reports/${code}`);await page.locator('.store-business-report tbody tr').first().waitFor({timeout:10000}).catch(async e=>{console.error(JSON.stringify({errors,body:(await page.locator('body').innerText()).slice(0,1000)}));throw e})
  const scroll=page.locator('.store-business-report__table-scroll');const dimensions=await scroll.evaluate(el=>({overflow:getComputedStyle(el).overflow,width:el.clientWidth,scrollWidth:el.scrollWidth,height:el.clientHeight,scrollHeight:el.scrollHeight,gutter:getComputedStyle(el).scrollbarGutter}))
  if(phase==='after'){
   assert.ok(['auto','scroll'].includes(dimensions.overflow));assert.ok(dimensions.scrollWidth>dimensions.width);assert.ok(dimensions.scrollHeight>dimensions.height)
   const box=await scroll.boundingBox()
   await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await page.mouse.wheel(450,0);await page.waitForTimeout(120)
   assert.ok(await scroll.evaluate(el=>el.scrollLeft>0),'horizontal mouse wheel scrolls wide result')
   await page.mouse.wheel(0,180);await page.waitForTimeout(120)
   assert.ok(await scroll.evaluate(el=>el.scrollTop>0),'vertical wheel scroll reaches body')
   await scroll.evaluate(el=>{el.scrollLeft=0;el.scrollTop=0})
   const query=await page.locator('.store-business-report__filters').first().boundingBox(),footer=await page.locator('.store-business-report__pagination').boundingBox(),head=await page.locator('thead th').first().boundingBox(),summary=await page.locator('.store-business-report__summary-row td').first().boundingBox()
   await scroll.evaluate(el=>{el.scrollTop=300;el.scrollLeft=900});await page.waitForTimeout(100)
   const offsets=await scroll.evaluate(el=>[el.scrollTop,el.scrollLeft]);assert.ok(offsets.every(x=>x>0))
   const headAfter=await page.locator('thead th').first().boundingBox(),summaryAfter=await page.locator('.store-business-report__summary-row td').first().boundingBox()
   assert.ok(Math.abs(head.y-headAfter.y)<2);assert.ok(Math.abs(head.x-headAfter.x)<2);assert.ok(Math.abs(summary.y-summaryAfter.y)<2)
   assert.deepEqual(await page.locator('.store-business-report__filters').first().boundingBox(),query);assert.deepEqual(await page.locator('.store-business-report__pagination').boundingBox(),footer);assert.ok(footer.y+footer.height<=viewport.height)
   await scroll.evaluate(el=>{el.scrollLeft=el.scrollWidth;el.scrollTop=el.scrollHeight});assert.ok((await page.locator('tbody tr').last().boundingBox()).y<viewport.height)
  }
  if(code==='member_consumption_detail')await page.screenshot({path:path.join(evidence,`${phase}-${viewport.width}.png`)})
  results.push({code,viewport,dimensions,errors:[...errors]});assert.deepEqual(errors,[])
 }
 if(phase==='after') {count=0;await page.getByRole('button',{name:'查询',exact:true}).click();await page.locator('.store-business-report__empty-cell').waitFor();assert.ok((await page.locator('.store-business-report__table-scroll').boundingBox()).height>0)}
 await context.close()
}
fs.writeFileSync(path.join(evidence,phase+'-browser.json'),JSON.stringify(results,null,2));console.log(phase==='after'?'R32 REPORT SCROLL PASS':'R32 BEFORE MEASURED')
}finally{await browser.close()}})().catch(e=>{console.error(e);process.exitCode=1})
