// Exercise the actual parent search handler with deliberately reversed responses.
import fs from 'node:fs'
import assert from 'node:assert/strict'
const src=fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue',import.meta.url),'utf8')
const start=src.indexOf('async function searchPersonnelOverlay('),end=src.indexOf('\nasync function openCartLineCraftsmen',start)
const state={value:{line:{id:'test-line'},requestKey:'one'}},pending=[]
const handler=new Function('personnelOverlay','requestAction','resultStatus','responseDataBlock','queryPersonnelCandidates',`const otherCraftsmanQueryEpoch={cashier_other_craftsmen:0,cashier_other_salespeople:0};${src.slice(start,end)};return searchPersonnelOverlay`)(state,()=>new Promise((resolve,reject)=>pending.push({resolve,reject})),r=>r.result.status,r=>r.data,()=>{throw Error('wrong query path')})
const query=(id)=>handler({scope:'cashier_other_craftsmen',target:'otherCraftsmen',organizationNode:{type:'store',id}})
const old=query(1),latest=query(2)
pending[1].resolve({result:{status:'success'},data:{records:[{id:2,name:'new'}],total:1,page:1}});await latest
pending[0].resolve({result:{status:'success'},data:{records:[{id:1,name:'stale'}],total:1,page:1}});await old
assert.equal(state.value.otherCraftsmanCandidates[0].id,2,'old node cannot overwrite latest node')
const failed=query(3);pending[2].reject(Error('fixture error'));await failed
assert.deepEqual(state.value.otherCraftsmanCandidates,[]);assert.equal(state.value.otherCraftsmanError,'fixture error');assert.equal(state.value.otherCraftsmanLoading,false)
const sale=handler({scope:'cashier_other_salespeople',organizationNode:{type:'org',id:1}})
pending[3].resolve({result:{status:'success'},data:{records:[{id:9}],total:1,page:1}});await sale
assert.equal(state.value.otherSalespersonCandidates[0].id,9)
assert.deepEqual(state.value.otherCraftsmanCandidates,[],'role results stay separate')
const closed=query(4);state.value=null;pending[4].resolve({result:{status:'success'},data:{records:[{id:4}]}});await closed;assert.equal(state.value,null,'closed overlay cannot be restored by a response')
console.log('PASS actual search handler: reversed node responses, error clears candidates, closed overlay stays closed')

const overlay=fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue',import.meta.url),'utf8')
const a=overlay.indexOf('function otherCraftsmanSelected('),b=overlay.indexOf('\nfunction addOtherCraftsman',a)
const selected={value:[{id:98,employeeId:98,selected:true}]}
const isSelected=new Function('craftsmen',`${overlay.slice(a,b)};return otherCraftsmanSelected`)(selected)
assert.equal(isSelected({id:1000000098,employeeId:98}),true,'store and virtual IDs cannot duplicate one employee')
assert.equal(isSelected({id:1000000099,employeeId:99}),false,'other employee stays selectable')
console.log('PASS same employee cannot be added twice through different identity types')
