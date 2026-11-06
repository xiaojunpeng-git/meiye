import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import { CASHIER_V3_ACTION_MANIFEST } from '../../../前端代码/cashier-v3/src/services/cashierV3ActionManifest.js'
import { platformDocumentActionAllowed } from '../../../前端代码/cashier-v3/src/services/platformDocumentEditPolicy.js'
for (const [action, d] of Object.entries(CASHIER_V3_ACTION_MANIFEST)) {
  assert.equal(platformDocumentActionAllowed(action, false), d.type === 'projection' || ['print-sales-order-receipt', 'create-unified-query-export'].includes(d.canonical), action)
  assert.equal(platformDocumentActionAllowed(action, true), true)
}
assert.equal(platformDocumentActionAllowed('unknown-write', false), false)
const source=fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue',import.meta.url),'utf8')
// Execute the real request wrapper: closed stale dialogs never reach transport; query/export/print still do.
const wrapper=source.slice(source.indexOf('function requestAction('),source.indexOf('\nfunction openImportedCardRefundMemberSelector'))
let calls=[];const context={documentEditable:{value:false},platformDocumentActionAllowed,PLATFORM_DOCUMENT_READ_ONLY_MESSAGE:'closed',requestCashierV3Action:(...args)=>{calls.push(args);return Promise.resolve({result:{status:'success'}})}};vm.createContext(context);vm.runInContext(wrapper,context)
for(const a of ['adjust-service-record-craftsmen','adjust-sales-order-personnel','void-sales-order','update-sales-order-note','refund-recharge-order'])assert.equal((await context.requestAction(a)).result.code,'PLATFORM_DOCUMENT_EDIT_DISABLED')
assert.equal(calls.length,0)
for(const a of ['query-sales-orders','open-sales-order-detail','create-unified-query-export','print-sales-order-receipt'])await context.requestAction(a)
assert.equal(calls.length,4);context.documentEditable.value=true;await context.requestAction('update-sales-order-note');assert.equal(calls.length,5)
console.log(`PASS ${Object.keys(CASHIER_V3_ACTION_MANIFEST).length} manifest entries, stale dialog protection, read/print/export and enabled behavior`)
