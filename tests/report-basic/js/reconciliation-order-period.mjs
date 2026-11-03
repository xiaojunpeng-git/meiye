/** Canonical date adapter regression: receipt period is not an order business-date predicate. No browser automation. */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import assert from 'node:assert/strict'
const repo=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../../..')
const source=readFileSync(path.join(repo,'前端代码/cashier-v3/src/services/orderCenterReportFilters.js'),'utf8')
const {orderReportDate,buildOrderReportQuery}=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'))
const selection={storeId:8,methodCode:'legacy_1',from:'2026-10-28',to:'2026-10-28'}
const original={pageCode:'order_center_all',reconciliationDrilldown:selection,storeIds:[8],filters:[{field:'member_name',operator:'eq',value:'合成会员'}]}
assert.deepEqual(orderReportDate(original,'2026-11-03'),{min:'2026-10-28',max:'2026-10-28'})
const changed=buildOrderReportQuery(original,{filters:[]},{min:'2026-10-27',max:'2026-10-29'},'normal',20)
assert.deepEqual(changed.reconciliationDrilldown,{...selection,from:'2026-10-27',to:'2026-10-29'})
for(const key of ['dateFrom','dateTo','businessDateFrom','businessDateTo'])assert.equal(Object.hasOwn(changed,key),false)
assert.deepEqual(changed.topFilters,[])
assert.deepEqual(changed.filters,original.filters)
const cleared=buildOrderReportQuery(original,{filters:[]},orderReportDate(original),'all',20,true)
assert.deepEqual(cleared.reconciliationDrilldown,selection)
assert.deepEqual(cleared.filters,[])
assert.equal(cleared.dataScope,'all')
const ordinary=buildOrderReportQuery({}, {filters:[]},{min:'2026-11-01',max:'2026-11-03'},'normal',20)
assert.equal(ordinary.businessDateFrom,'2026-11-01')
assert.deepEqual(ordinary.topFilters,[{field:'business_date',operator:'gte',value:'2026-11-01'},{field:'business_date',operator:'lte',value:'2026-11-03'}])
assert.deepEqual(orderReportDate({},'2026-11-03'),{min:'2026-11-03',max:'2026-11-03'})
console.log('PASS R9 receipt-period date adapter: restore, change, clear, ordinary query isolation')
