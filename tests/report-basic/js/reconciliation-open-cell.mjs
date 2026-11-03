/** Execute the canonical async click handler, including lexical declarations; catches TDZ failures before requests or navigation. */
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import vm from 'node:vm'
import assert from 'node:assert/strict'
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..')
const require=createRequire(path.join(root,'前端代码/cashier-v3/package.json'))
const {parse}=require('@babel/parser')
const source=readFileSync(process.env.R9_REPORT_SOURCE || path.join(root,'前端代码/cashier-v3/src/components/reports/StoreReconciliationReport.vue'),'utf8').split('<script setup>')[1].split('</script>')[0]
const node=parse(source,{sourceType:'module'}).program.body.find(x=>x.type==='FunctionDeclaration'&&x.id.name==='openCell')
assert.ok(node)
const selection={storeId:8,methodCode:'legacy_1',from:'2026-10-28',to:'2026-10-28'}
const fixture=()=>{
 const calls=[],stored=[],messages=[],details=[]
 const context={props:{platform:true},executed:{value:{query:{start_date:selection.from,end_date:selection.to},storeIds:[],fingerprint:'verified-cell'}},loading:{value:false},detailLoading:{value:false},error:{value:''},label:{value:'全部门店'},dates:{value:{start_date:selection.from,end_date:selection.to}},month:{value:'2026-10'},resultsScroll:{value:{scrollTop:12,scrollLeft:34}},
  cloneUnifiedQuerySnapshot:x=>JSON.parse(JSON.stringify(x)),loadBasicReport:async(...args)=>{calls.push(args);return {navigation_document_nos:['SYNTHETIC-SALE'],navigation_selection:selection,detail_context:{store_id:8,store_name:'海沧店'}}},
  crypto:{randomUUID:()=> '00000000-0000-4000-8000-000000000009'},sessionStorage:{setItem:(...x)=>stored.push(x)},document:{referrer:''},window:{location:{origin:'http://127.0.0.1'},parent:{postMessage:(...x)=>messages.push(x)}},URL,openDetail:async(...x)=>details.push(x)}
 vm.createContext(context);vm.runInContext(source.slice(node.start,node.end),context)
 return {context,calls,stored,messages,details}
}
const row={method_code:'legacy_1',payment_name:'银联扫码'},field={drilldown:{column_key:'store_8'}}
const ok=fixture();await ok.context.openCell(row,field)
assert.equal(ok.context.error.value,'')
assert.equal(ok.calls.length,1)
assert.deepEqual(JSON.parse(JSON.stringify(ok.calls[0][1].detail)),{method_code:'legacy_1',column_key:'store_8'})
assert.equal(ok.calls[0][1].expected_fingerprint,'verified-cell')
assert.equal(ok.stored.length,1)
assert.deepEqual(JSON.parse(ok.stored[0][1]).selection,selection)
assert.equal(ok.messages[0][0].type,'mohe:reconciliation-open-orders')
assert.equal(ok.context.detailLoading.value,false)
const brush=fixture();await brush.context.openCell({...row,method_code:'brush_douyin'},field);assert.equal(brush.calls.length,0);assert.equal(brush.details.length,1)
const failure=fixture();failure.context.loadBasicReport=async()=>{throw Error('合成来源失效')};await failure.context.openCell(row,field);assert.equal(failure.context.error.value,'合成来源失效');assert.equal(failure.context.detailLoading.value,false);assert.equal(failure.stored.length,0);assert.equal(failure.messages.length,0)
console.log('PASS canonical openCell: request, source scope, storage, parent navigation, brush fallback, failure recovery')
