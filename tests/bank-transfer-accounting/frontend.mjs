import assert from 'node:assert/strict'
import fs from 'node:fs'
import { runInNewContext } from 'node:vm'
import { configuredActualAccountingMethods, filterConfiguredAccountingMethods } from '../../前端代码/cashier-v3/src/services/configuredAccountingMethods.js'
const bankTransferAccounting = (catalog) => configuredActualAccountingMethods(catalog).find((method) => method.id === 'bank_transfer') || null
const enabled = { accountingMethods: [{code: 'bank_transfer', status: 1, displayName: '银行转账'}] }
assert.equal(bankTransferAccounting({}), null)
assert.equal(bankTransferAccounting({ accountingMethods: [{code:'bank_transfer',status:0}] }), null)
assert.equal(bankTransferAccounting(enabled).cashPerformanceEligible, true)
const source = fs.readFileSync(new URL('../../前端代码/cashier-v3/src/composables/useRechargeCheckout.js',import.meta.url),'utf8')
const pending=[]
const factory=runInNewContext(source.replace(/^import .*\n/gm,'').replace('export function useRechargeCheckout()','function useRechargeCheckout()')+'\nuseRechargeCheckout',{
  ref:value=>({value}),configuredActualAccountingMethods,
  window:{dispatchEvent:()=>{}}, CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail}},
  loadCheckoutBusinessCatalog:()=>new Promise((resolve,reject)=>pending.push({resolve,reject})),
  createCashierV3CommandId:()=> 'bank-test',requestCashierV3Action:async()=>({result:{status:'success'}}),
  cashierV3ResponseEnvelope:r=>r
})
const api=factory()
const open=()=>api.acceptLocalRechargeCheckout({memberId:1,rechargeMode:'custom',principalAmount:'85',bonusAmount:'0',debtAmount:'0'}, {member:{id:1}})
const flush=()=>new Promise(resolve=>setTimeout(resolve,0))
open();await flush()
assert(!api.rechargeCheckout.value.payment.methods.some(m=>m.id==='bank_transfer'))
pending[0].resolve(enabled);await flush()
assert.equal(api.rechargeCheckout.value.payment.methods.filter(m=>m.id==='bank_transfer').length,1)
await api.requestRechargeCheckoutAction({action:'add-payment-method',payload:{paymentMethodId:'bank_transfer'}})
assert(api.rechargeCheckout.value.payment.selectedLines.some(m=>m.method==='bank_transfer'))
open();await flush();open();await flush()
pending[1].resolve(enabled);await flush()
assert(!api.rechargeCheckout.value.payment.methods.some(m=>m.id==='bank_transfer'),'old popup response cannot enable a newer popup')
pending[2].reject(new Error('catalog unavailable'));await flush()
assert(!api.rechargeCheckout.value.payment.methods.some(m=>m.id==='bank_transfer'))
open();await flush();pending[3].resolve({accountingMethods:[]});await flush()
assert(!api.rechargeCheckout.value.payment.methods.some(m=>m.id==='bank_transfer'),'unconfigured client does not gain bank method')
console.log('PASS 9 bank config, recharge selection, failure and popup race checks')

const incomeCatalog={accountingMethods:[{code:'other_income',status:1,displayName:'其他收入'},{code:'other_collection',status:0,displayName:'其他收款'}]}
assert.equal(configuredActualAccountingMethods(incomeCatalog)[0].id,'other_income')
assert.equal(configuredActualAccountingMethods(incomeCatalog)[0].cashPerformanceEligible,true)
assert.deepEqual(filterConfiguredAccountingMethods([{id:'other_income'},{id:'other_collection'}],incomeCatalog).map(m=>m.id),['other_income'])
assert.equal(filterConfiguredAccountingMethods([{id:'other_collection'}]).length,0,'unknown config never exposes hidden noncash method')
console.log('PASS other income is cash eligible and disabled other collection is hidden')

const workbench=fs.readFileSync(new URL('../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue',import.meta.url),'utf8')
const begin=workbench.indexOf('function localCheckoutPaymentMethods(')
const finish=workbench.indexOf('\nfunction localCheckoutPreviewSnapshot(',begin)
const methodFactory=runInNewContext(workbench.slice(begin,finish)+'\nlocalCheckoutPaymentMethods',{
  configuredActualAccountingMethods,filterConfiguredAccountingMethods,
  cartLineRole:()=> 'sale',canUseCashierV3Operation:()=>false
})
const incomeOptions=methodFactory([],false,incomeCatalog)
assert.deepEqual(Array.from(incomeOptions,m=>m.id),['other_income'])
const otherClient={accountingMethods:[{code:'other_collection',status:1,displayName:'其他收款'}]}
assert.deepEqual(Array.from(methodFactory([],false,otherClient),m=>m.id),['other_collection'],'another instance retains its enabled noncash option')
assert(!methodFactory().some(m=>m.id==='other_collection'),'unconfirmed config does not flash a hidden option')
console.log('PASS actual checkout method projection follows per-instance hide/enable configuration')
