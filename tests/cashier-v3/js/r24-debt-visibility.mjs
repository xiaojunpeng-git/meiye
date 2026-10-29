/** Render the real summary row under both switches; member tab tests use Vue reactivity, without business writes. */
import fs from 'node:fs'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { guideRoundText } from '../../../前端代码/cashier-v3/src/services/orderCenterPersonnelDisplay.js'
import { orderDebtFields } from '../../../前端代码/cashier-v3/src/services/orderCenterReportFilters.js'
const require=createRequire(new URL('../../../前端代码/cashier-v3/package.json',import.meta.url))
const Vue=require('vue'),{renderToString}=require('@vue/server-renderer'),{compile}=require('@vue/compiler-dom')
const {parse,compileScript,compileTemplate}=require('@vue/compiler-sfc')
const read=p=>fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/'+p,import.meta.url),'utf8')
const member=read('components/member/MemberDetailOverlay.vue'),orders=read('views/OrderCenterView.vue'),shell=read('layouts/CashierShell.vue')
for(const file of ['components/member/MemberDetailOverlay.vue','layouts/CashierShell.vue','views/OrderCenterView.vue','views/MemberListView.vue']){
 const {descriptor,errors}=parse(read(file));assert.deepEqual(errors,[]);compileScript(descriptor,{id:file});assert.deepEqual(compileTemplate({source:descriptor.template.content,id:file,filename:file}).errors,[])
}
const defaults=JSON.parse(member.match(/const DEFAULT_TABS = (\[[\s\S]*?\n\])/)[1].replace(/'/g,'"').replace(/,\s*\]/g,']').replace(/\b(key|label):/g,'"$1":'))
const props=Vue.reactive({debtPaymentEnabled:false,initialTab:'debt'})
const expression=member.match(/const activeTab = ref\(([^\n]+)\)/)[1]
const activeTab=Vue.ref(new Function('DEFAULT_TABS','props',`return (${expression})`)(defaults,props));assert.equal(activeTab.value,'profile')
const tabExpression=member.match(/const tabs = computed\(([^\n]+)\)/)[1]
const tabs=new Function('DEFAULT_TABS','props','computed',`return computed(${tabExpression})`)(defaults,props,Vue.computed)
assert.equal(tabs.value.some(t=>t.key==='debt'),false);props.debtPaymentEnabled=true;assert.equal(tabs.value.some(t=>t.key==='debt'),true)
const calls=[];const stop=new Function('watch','props','activeTab','switchTab',member.match(/watch\(\(\) => props.debtPaymentEnabled,[\s\S]*?\n\}\)/)[0]+';return undefined')(Vue.watch,props,activeTab,t=>calls.push(t.key))
activeTab.value='debt';props.debtPaymentEnabled=false;await Vue.nextTick();assert.deepEqual(calls,['profile'])
assert.match(shell,/:debt-payment-enabled="state.cashier\?\.debtPaymentEnabled !== false"/)
const statusFunction=orders.slice(orders.indexOf('function statusClass('),orders.indexOf('\nfunction tableCellClass('))
const classifyStatus=new Function(statusFunction+';return statusClass')()
const row=orders.match(/<tr v-if="recordType\(record\) !== 'sales'" class="order-business-summary-row">[\s\S]*?<\/tr>/)[0]
const render=new Function('Vue',compile('<table><tbody>'+row+'</tbody></table>',{mode:'function',prefixIdentifiers:true}).code)(Vue)
const keys=JSON.parse(orders.match(/const salesOrderListColumnKeys = (\[[^\n]+\])/)[1].replace(/'/g,'"'))
for(const type of ['recharge','refund','supplement'])for(const debt of [true,false])for(const attribution of [true,false]){
 const context={statusClass:classifyStatus,record:{},activeTabKey:'all',debtPaymentEnabled:debt,salesAttributionEnabled:attribution,recordType:()=>type,displayRecordField:(_,k)=>k,displayMoneyField:(_,k)=>k,recordFieldValue:()=>'',canEditRecordPersonnel:()=>false,canEditRecordRoles:()=>false}
 const html=await renderToString(Vue.createSSRApp({render,setup:()=>context}))
 const cells=[...html.matchAll(/<td(?:\s[^>]*)?>([\s\S]*?)<\/td>/g)].map(m=>m[1])
 const columns=orderDebtFields(keys.map(key=>({key})),debt).filter(f=>attribution||!['sales_manager','guide'].includes(f.key)).map(f=>f.key)
 assert.equal(cells.length,columns.length,`${type}/${debt}/${attribution}: column count`)
 assert.match(cells[columns.indexOf('store')],/>?store/)
 assert.match(cells[columns.indexOf('order_status')],/order-status/)
 assert.match(cells[columns.indexOf('payment_method')],new RegExp(type==='refund'?'refund_method':'payment_method'))
 if(type==='recharge')assert.match(cells[columns.indexOf('actual_received_amount')],/actual_received_amount/)
}
console.log('PASS: member tab hide/re-enable/active-panel fallback; 12 real summary-row renders align with header fields; 4 SFCs compile')

// Column visibility must not erase a saved preference, and platform-adapted lists keep their independent settings.
const memberList=read('views/MemberListView.vue')
const listProps=Vue.reactive({platformAdapter:null}),listState=Vue.reactive({cashier:{debtPaymentEnabled:false}})
const listDebt=Vue.computed(new Function('props','state',`return () => (${memberList.match(/const debtPaymentEnabled = computed\(\(\) => ([^\n]+)\)/)[1]})`)(listProps,listState))
const saved=Vue.ref(['member_name','debt_amount','account_balance']),fieldMap=Vue.computed(()=>new Map(saved.value.map(key=>[key,{key}])))
const listExpression=memberList.slice(memberList.indexOf('const visibleFields = computed('),memberList.indexOf('const summaryEntries = computed(')).replace('const visibleFields = ','return ')
const shown=new Function('computed','configuredVisibleFieldKeys','queryFieldMap','debtPaymentEnabled',listExpression)(Vue.computed,saved,fieldMap,listDebt)
assert.deepEqual(shown.value.map(f=>f.key),['member_name','account_balance'])
assert.deepEqual(Array.from(saved.value),['member_name','debt_amount','account_balance'])
listState.cashier.debtPaymentEnabled=true;assert.ok(shown.value.some(f=>f.key==='debt_amount'))
saved.value=['member_name','account_balance'];assert.equal(shown.value.some(f=>f.key==='debt_amount'),false)
saved.value=['member_name','debt_amount'];listState.cashier.debtPaymentEnabled=false;listProps.platformAdapter={}
assert.ok(shown.value.some(f=>f.key==='debt_amount'))
assert.match(memberList,/<th v-for="field in visibleFields"/)
assert.match(memberList,/<td v-for="field in visibleFields"/)
console.log('PASS: member list hide/re-enable/saved preferences/platform isolation; header and row share visible fields')

// The same real display helper serves the all-orders and recharge tabs; names and role applicability stay unchanged.
const displayFunction=orders.slice(orders.indexOf('function displayRecordField('),orders.indexOf('\nfunction displayMoneyField('))
const tab=Vue.ref('all')
const display=new Function('recordFieldValue','recordType','activeTabKey',displayFunction+';return displayRecordField')((r,k)=>r[k],r=>tab.value==='all'?r.recordType:tab.value,tab)
for(const missing of [undefined,null,'',[]])assert.equal(display({recordType:'recharge',salesperson:missing},'salesperson'),'未分配')
assert.equal(display({recordType:'recharge',salesperson:'王盼盼（99）'},'salesperson'),'王盼盼（99）')
assert.equal(display({recordType:'recharge',salesperson:['甲','乙']},'salesperson'),'甲、乙')
assert.equal(display({recordType:'recharge'},'sales_manager'),'—')
assert.equal(display({recordType:'recharge'},'guide'),'—')
tab.value='recharge';assert.equal(display({},'salesperson'),'未分配')
assert.match(orders,/title="修改销售人"[\s\S]*?@click="openRecordPersonnelEditor\(record\)"/)
console.log('PASS: recharge salesperson empty label, existing names, all/recharge tabs and unchanged assignment entry')

// Supplement placeholders depend on the backend applicability fact, not edit permission or the original sale.
tab.value='all'
for(const key of ['salesperson','sales_manager','guide','supplement_attribution']){
 assert.equal(display({recordType:'supplement',attributionEditable:true},key),'未分配')
 assert.equal(display({recordType:'supplement',attributionEditable:false},key),'—')
 assert.equal(display({recordType:'supplement',attributionEditable:true,[key]:'已分配人员'},key),'已分配人员')
}
tab.value='supplement';assert.equal(display({attributionEditable:true},'salesperson'),'未分配')
const fieldFunction=orders.slice(orders.indexOf('function recordFieldValue('),orders.indexOf('\nfunction displayRecordField('))
const fieldValue=new Function('recordType','guideRoundText','activeTabKey','firstValue','FIELD_ALIASES',fieldFunction+';return recordFieldValue')(r=>r.recordType,guideRoundText,tab,(r,keys)=>keys.map(k=>r?.[k]).find(v=>v!==undefined&&v!==null&&v!==''),{})
const assignedSupplement={recordType:'supplement',attributionPeople:[{role:'guide',employee_name_snapshot:'历史导购',guide_round_no:2},{role:'sales_manager',employee_name_snapshot:'历史经理'}]}
assert.equal(fieldValue(assignedSupplement,'guide'),'历史导购（第2轮）')
assert.equal(fieldValue(assignedSupplement,'sales_manager'),'历史经理')
assert.equal(fieldValue({...assignedSupplement,attributionPeople:[]},'guide'),'')
console.log('PASS: supplement unassigned/applicability/assigned names/all and supplement tabs; frozen manager and guide snapshots')

// Render statuses through the same existing mapping as sales lines; labels remain unchanged.
for(const [type,key,label,css] of [['recharge','order_status','正常','paid'],['supplement','payment_status','补交成功','paid'],['recharge','order_status','已作废','voided'],['supplement','payment_status','待补交','debt'],['refund','refund_status','已退款','neutral']]){
 const context={statusClass:classifyStatus,record:{[key]:label},activeTabKey:'all',debtPaymentEnabled:false,salesAttributionEnabled:false,recordType:()=>type,displayRecordField:(r,k)=>r[k]||'—',recordFieldValue:(r,k)=>r[k],displayMoneyField:()=> '—',canEditRecordPersonnel:()=>false,canEditRecordRoles:()=>false}
 const html=await renderToString(Vue.createSSRApp({render,setup:()=>context}))
 assert.match(html,new RegExp(`class="order-status order-status--${css}"`))
 assert.ok(html.includes(`>${label}</span>`))
}
console.log('PASS: summary status badges share sales mapping; success/void/debt/neutral and original status labels')
