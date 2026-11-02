// Exercise the real selector predicates and click handler. Expiry only relaxes
// extension eligibility; redemption, voided sources and repeat clicks stay gated.
import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
const source = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue', import.meta.url), 'utf8')
const names = ['isSourceAvailable', 'sourceHasRemainingTimes', 'sourceIsExpired', 'isSourceExtendable', 'clonePlain', 'chooseSourceOperation']
const extracted = names.map(name => {
  const start = source.indexOf(`function ${name}(`)
  assert.ok(start >= 0, name)
  const rest = source.slice(start)
  const end = rest.slice(1).search(/\n(?:async )?function |\nconst |\nwatch\(/)
  return end < 0 ? rest.slice(0, rest.indexOf('</script>')) : rest.slice(0, end + 1)
}).join('\n')
const events=[]
const context=vm.createContext({ props:{ operationMode:'card-extension',submitting:false }, selectorReady:{value:true},
  editingSource:{value:null}, operationDate:{value:''}, operationReason:{value:''},
  entitlementCardHolderId:s=>s.entitlementInstanceType==='independent_gift'?0:Number(s.id),
  cardToBalanceUnavailableReason:()=>'',emit:(...args)=>events.push(args) })
vm.runInContext(extracted,context)
const expired={id:12349,entitlementInstanceType:'card_holder',remainingTimes:4,expiryDate:'2020-11-01',selectable:false,disabled:false,statusCode:'unavailable',disabledReason:''}
assert.equal(context.isSourceAvailable(expired),false)
assert.equal(context.isSourceExtendable(expired),true)
for(const variant of [{disabled:true},{disabledReason:'原订单已作废'},{statusCode:'upgraded'},{remainingTimes:0},{entitlementInstanceType:'independent_gift'},{expiryDate:'2099-11-01'},{expiryDate:''}])
  assert.equal(context.isSourceExtendable({...expired,...variant}),false,JSON.stringify(variant))
context.chooseSourceOperation(expired)
assert.equal(context.editingSource.value.id,12349)
assert.equal(context.operationDate.value,'2020-11-01')
context.editingSource.value=null
context.props.operationMode='card-transfer'
context.chooseSourceOperation(expired)
assert.equal(context.editingSource.value,null)
assert.equal(events.at(-1)[0],'contract-error')
context.props.operationMode='card-extension';context.props.submitting=true
context.chooseSourceOperation(expired)
assert.equal(context.editingSource.value,null)
console.log('PASS expired-card extension eligibility, click handler and blocked-state regression')
