/** 真实 Vue 组件与后端隔离库投影；接口拦截仅用于页面显隐验证，不代替登录/线上验收。 */
import { createRequire } from 'node:module'
import { readFileSync,writeFileSync } from 'node:fs'
import os from 'node:os'
import path from 'node:path'
const require=createRequire(import.meta.url)
const {chromium}=require(path.join(os.homedir(),'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright'))
const root=path.resolve(new URL('../..',import.meta.url).pathname)
const evidence=path.join(root,'tests/customer-010/evidence/round9-salary-reconciliation')
const fixture=JSON.parse(readFileSync(path.join(evidence,'private/browser-projection.json')))
const browser=await chromium.launch({headless:true,channel:'chrome'})
const page=await browser.newPage({viewport:{width:1475,height:996}})
let checks=0;const check=(ok,label)=>{if(!ok)throw Error(label);checks++}
try{
 await page.route('**/r9-fixture.html',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta charset="utf-8"><style>html,body,#app{margin:0;height:100%}</style></head><body><div id="app"></div><script type="module" src="/view_cashier_v3/src/dev/r51BasicReportFixture.js"></script></body></html>'}))
 await page.route('**/adminapi/report/basic/**',async route=>{
  const action=new URL(route.request().url()).pathname.split('/basic/')[1]
  let data=action==='catalog'?fixture.catalog:action==='scope'?{tree:[{id:11,store_id:11,node_type:'store',title:'湾悦城店',children:[]}],allowed_store_ids:[11],authorization_mode:'stores'}:fixture.page
  await route.fulfill({contentType:'application/json',body:JSON.stringify({status:200,data})})
 })
 await page.goto('http://127.0.0.1:18118/r9-fixture.html')
 await page.getByRole('button',{name:'工资表',exact:true}).click()
 await page.locator('tbody tr').first().waitFor()
 await page.getByLabel('工资月份',{exact:true}).fill('2026-09')
 const response=page.waitForResponse(r=>r.url().includes('/basic/query'))
 await page.getByRole('button',{name:'查询',exact:true}).click()
 await response
 check(await page.getByRole('button',{name:'编辑',exact:true}).count()===0,'Historical edit hidden')
 check(await page.getByRole('button',{name:'冻结',exact:true}).count()===0,'Historical freeze hidden')
 check((await page.locator('.basic-reports__salary-summary').innerText()).includes('27,752'),'Saved payroll summary displayed')
 check((await page.locator('.basic-reports__snapshot').innerText()).includes('仅查询、导出'),'Historical source visible')
 await page.getByRole('button',{name:'列名取值来源',exact:true}).click()
 check((await page.getByRole('dialog').innerText()).includes('读取当月已保存'),'Field source guide uses saved amounts')
 await page.getByRole('button',{name:'关闭',exact:true}).click()
 await page.screenshot({path:path.join(evidence,'history-readonly.png'),fullPage:true})
 writeFileSync(path.join(evidence,'browser-tests.json'),JSON.stringify({result:'PASS',checks,source:'real Vue + anonymized real backend fixture projection',online_test:false},null,2))
 console.log(JSON.stringify({result:'PASS',checks}))
}finally{await browser.close()}
