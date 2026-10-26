/** Policy projection tests: no data mutation; old field preferences remain usable
 * when debt is re-enabled. Also compile the actual changed SFC templates.
 */
import assert from 'node:assert/strict'
import fs from 'node:fs'
import {createRequire} from 'node:module'
import {orderRecordTypeVisible,ORDER_RECORD_TYPES} from '../../../前端代码/cashier-v3/src/constants/orderRecordTypes.js'
import {orderDebtFields,orderDebtQuery,orderBusinessSourceFields} from '../../../前端代码/cashier-v3/src/services/orderCenterReportFilters.js'
const fields=[{key:'debt_amount'},{key:'receivable_amount'},{key:'source'},{key:'source_type'}]
assert.deepEqual(orderDebtFields(fields,false).map(f=>f.key),['receivable_amount','source','source_type'])
assert.equal(orderDebtFields(fields,true).length,4)
assert.deepEqual(orderBusinessSourceFields(orderDebtFields(fields,false),false).map(f=>f.key),['receivable_amount','source_type'])
assert.deepEqual(ORDER_RECORD_TYPES.filter(t=>orderRecordTypeVisible(t.key,false)).map(t=>t.key),['all','sales','recharge','refund','service','gift','card_operation'])
assert.equal(ORDER_RECORD_TYPES.filter(t=>orderRecordTypeVisible(t.key,true)).length,9)
const saved={topFilters:[{field:'debt_amount',value:1},{field:'business_date',value:'2026-10-25'}],filters:[{key:'debt_amount'}],sorts:[{field_key:'debt_amount'}],visibleFields:['debt_amount','receivable_amount'],export:{fields:['debt_amount','receivable_amount']},querySettings:{quickFields:['debt_amount','phone']}}
const original=JSON.stringify(saved),hidden=orderDebtQuery(saved,false)
assert.equal(JSON.stringify(saved),original)
assert.deepEqual(hidden.visibleFields,['receivable_amount']);assert.deepEqual(hidden.export.fields,['receivable_amount']);assert.deepEqual(hidden.querySettings.quickFields,['phone']);assert.equal(hidden.topFilters.length,1);assert.equal(hidden.filters.length,0);assert.equal(hidden.sorts.length,0);assert.equal(orderDebtQuery(saved,true),saved)
const require=createRequire(new URL('../../../前端代码/cashier-v3/package.json',import.meta.url));const {parse,compileScript,compileTemplate}=require('@vue/compiler-sfc')
for(const file of ['views/OrderCenterView.vue','components/order/SalesOrderDetailOverlay.vue','components/navigation/StoreTopNavigation.vue','layouts/CashierShell.vue']){const {descriptor,errors}=parse(fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/'+file,import.meta.url),'utf8'));assert.equal(errors.length,0);compileScript(descriptor,{id:file});assert.deepEqual(compileTemplate({source:descriptor.template.content,id:file,filename:file}).errors,[])}
console.log('R60_DEBT_SWITCH_PASS menus, metadata, independent source switch, saved filters/export, re-enable and 4 SFCs')
