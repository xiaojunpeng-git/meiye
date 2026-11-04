import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

const component = readFileSync(new URL('../../../前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue', import.meta.url), 'utf8')
const workbench = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
const closeStart = workbench.indexOf('async function closeCheckoutOverlay(options = {}) {')
const closeEnd = workbench.indexOf('\nasync function closeSucceededCheckoutAndRefreshWorkbench', closeStart)
assert.ok(closeStart >= 0 && closeEnd > closeStart, 'locate the checkout return handler')
assert.match(component, /returnToDebt:\s*isDebtRepayment\.value/, 'only debt checkout asks to return to debt details')

function runReturn({ debt, succeeded, refreshFails = false }) {
  const calls = []
  const ref = (value) => ({ value })
  const context = {
    isDebtRepaymentCheckout: ref(debt),
    currentCheckoutSnapshot: ref(debt ? { memberId: 1136104 } : {}),
    checkoutRequiresRootReload: ref(succeeded),
    isCheckoutOpen: ref(true),
    checkoutPreparationId: ref('prepared'),
    checkoutSession: ref({}),
    checkoutRecoveryActiveStep: ref('final'),
    checkoutLocalOutcome: ref({ status: succeeded ? 'succeeded' : 'editing' }),
    localCheckoutPreview: ref({ memberId: 1136104 }),
    requestAction: async (action) => {
      calls.push(['refresh', action])
      if (refreshFails) throw new Error('local refresh unavailable')
      return { result: { status: 'succeeded' } }
    },
    confirmClearCart: () => { throw new Error('return must not clear the cart') },
    console: { warn: () => {} },
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail } },
    window: {
      location: { reload: () => calls.push(['reload']) },
      dispatchEvent: (event) => calls.push(['event', event.type, event.detail.memberId])
    }
  }
  const close = runInNewContext(`${workbench.slice(closeStart, closeEnd)}\ncloseCheckoutOverlay`, context)
  return close({ returnToDebt: debt }).then(() => ({ calls, context }))
}

const paidDebt = await runReturn({ debt: true, succeeded: true })
assert.deepEqual(paidDebt.calls, [
  ['refresh', 'open-cashier-workbench'],
  ['event', 'cashier-v3:open-member-debt-repayment', 1136104]
], 'successful repayment refreshes the root before opening the same member debt')
assert.equal(paidDebt.context.localCheckoutPreview.value, null, 'completed repayment preview is released')

const editingDebt = await runReturn({ debt: true, succeeded: false })
assert.deepEqual(editingDebt.calls, [
  ['event', 'cashier-v3:open-member-debt-repayment', 1136104]
], 'unsubmitted repayment returns without a root reload')

const refreshFailure = await runReturn({ debt: true, succeeded: true, refreshFails: true })
assert.deepEqual(refreshFailure.calls, [
  ['refresh', 'open-cashier-workbench'],
  ['event', 'cashier-v3:open-member-debt-repayment', 1136104]
], 'a failed root refresh still opens the independently read debt details')

const ordinary = await runReturn({ debt: false, succeeded: true })
assert.deepEqual(ordinary.calls, [['reload']], 'ordinary checkout retains its existing return behavior')
console.log('CHECKOUT_RETURN_TO_DEBT=PASS')
