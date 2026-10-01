import { ref } from 'vue'
import { createCashierV3CommandId, requestCashierV3Action } from '@/services/cashierV3Bridge'
import { cashierV3ResponseEnvelope } from '@/services/cashierV3CheckoutResultContract'

function resultStatus(result) {
  const nested = result?.data && typeof result.data === 'object' ? result.data : null
  const response = result?.result && typeof result.result === 'object'
    ? result
    : nested?.result && typeof nested.result === 'object'
      ? nested
      : nested && typeof nested.status === 'string'
        ? nested
        : result
  return response?.result?.status || response?.status || ''
}

function clonePlain(value = {}) {
  return JSON.parse(JSON.stringify(value && typeof value === 'object' ? value : {}))
}

/**
 * 充值确认只在浏览器中编辑本次快照；服务端仅在最终确认时创建业务事实。
 */
export function useRechargeCheckout() {
  const rechargeCheckout = ref(null)
  let rechargeCheckoutMutationTail = Promise.resolve()
  let originalLocalSubmit = null
  const localMethods = [
    ['unionpay', '银联'], ['wechat', '微信'], ['alipay', '支付宝'],
    ['dianping_voucher', '大众验券'], ['douyin_voucher', '抖音验券'],
    ['partner_collection', '合作方收款'], ['other_collection', '其他收款']
  ]

  function acceptLocalRechargeCheckout(input = {}, session = {}) {
    const memberId = Number(input.memberId || 0)
    const packageItem = (session.rechargeOptions?.packages || [])
      .find((item) => Number(item.id) === Number(input.rechargePackageId))
    const principal = Number(input.rechargeMode === 'package' ? packageItem?.price : input.principalAmount)
    const bonus = Number(input.rechargeMode === 'package' ? packageItem?.bonus : input.bonusAmount)
    const debt = Number(input.debtAmount || 0)
    const due = principal - debt
    if (!Number.isSafeInteger(memberId) || memberId <= 0
      || !Number.isSafeInteger(principal) || principal <= 0
      || !Number.isSafeInteger(bonus) || bonus < 0
      || !Number.isSafeInteger(debt) || debt < 0 || debt > principal
      || (input.rechargeMode === 'package' && !packageItem)) return null
    const localId = `local-recharge-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const selectedLines = due > 0 ? [{
      id: `${localId}-unionpay`, kind: 'bookkeeping_collection', method: 'unionpay',
      name: '银联', amount: due, externalTransactionNo: '', status: 'editing',
      canEdit: true, canRemove: true
    }] : []
    // 后端套餐配置和真实余额在最终提交时重算；这里仅显示操作员刚确认的值。
    rechargeCheckout.value = {
      contractVersion: 'cashier-v3-recharge-checkout-v1', businessType: 'recharge',
      localSnapshotPreview: true, status: 'editing', activeStep: 1,
      member: clonePlain(session.member || { id: memberId }),
      rechargeTerms: {
        memberId, rechargeMode: input.rechargeMode,
        rechargePackageId: Number(input.rechargePackageId || 0),
        principalAmount: String(principal), bonusAmount: String(bonus),
        debtAmount: String(debt), businessDate: String(input.businessDate || ''),
        salespersonAllocations: clonePlain(input.salespersonAllocations || []),
        businessSource: clonePlain(input.businessSource || {})
      },
      businessDate: String(input.businessDate || ''),
      orderLines: [{ id: localId, lineRole: 'recharge',
        name: input.rechargeMode === 'package' ? '充值套餐' : '自定义充值',
        quantity: 1, amount: principal }],
      summary: { selectedCount: 1, originalAmount: principal,
        discountAmount: 0, receivableAmount: due, entitlementActualAmount: 0 },
      composition: { code: 'recharge', lineRoles: ['recharge'], hasSale: false,
        hasEntitlement: false, primaryAction: 'collect_payment', primaryActionLabel: '确认充值',
        steps: [{ key: 'order', number: 1, label: '确认充值' },
          { key: 'payment', number: 2, label: '收款信息' },
          { key: 'final', number: 3, label: '确认充值' },
          { key: 'result', number: 4, label: '处理结果' }] },
      bonusAmount: bonus, debtAmount: debt, cashPerformanceAmount: due,
      balancePaymentAmount: 0, finalChanges: [], sourceEnabled: false,
      sourceSelectable: false, payment: {
        methods: localMethods.map(([id, name]) => ({ id, name, canAdd: id !== 'unionpay' })),
        selectedLines,
        summary: { receivableAmount: due, selectedAmount: due,
          remainingAmount: 0, overpaidAmount: 0 }
      }
    }
    originalLocalSubmit = null
    return rechargeCheckout.value
  }

  function rechargeCheckoutProjection(response) {
    const direct = response?.data?.rechargeCheckout
    const nested = response?.result?.data?.rechargeCheckout
    const legacy = response?.data?.data?.rechargeCheckout
    const projection = direct || nested || legacy
    return projection && typeof projection === 'object' ? clonePlain(projection) : null
  }

  function setRechargeCheckout(response, { allowTerminalOutcome = true } = {}) {
    const projection = rechargeCheckoutProjection(response)
    if (projection) {
      // 最终响应只返回真实充值结果；确认页的行、金额和收款方式仍是
      // 本次浏览器快照，不能用精简结果覆盖后让成功页丢失明细。
      rechargeCheckout.value = rechargeCheckout.value?.localSnapshotPreview === true
        ? { ...rechargeCheckout.value, ...projection }
        : projection
    } else {
      // A command can reach the server but lose its HTTP response (timeout,
      // worker restart, or a proxy disconnect). Keep the same request visible
      // as an outcome instead of leaving the overlay's local submit lock on an
      // editing snapshot forever. The result-query action can then resolve the
      // original request without creating a second recharge.
      const status = String(resultStatus(response) || '').toLowerCase()
      if (allowTerminalOutcome
        && ['result_unknown', 'pending_confirmation', 'processing', 'failed'].includes(status)
        && rechargeCheckout.value) {
        const envelope = cashierV3ResponseEnvelope(response)
        const result = envelope?.result && typeof envelope.result === 'object' ? envelope.result : {}
        rechargeCheckout.value = {
          ...rechargeCheckout.value,
          status,
          failureReason: String(result.message || envelope?.message || ''),
          processingLong: status === 'processing' || status === 'pending_confirmation'
        }
      }
    }
    return projection
  }

  function closeRechargeCheckout() {
    rechargeCheckout.value = null
    originalLocalSubmit = null
  }

  function changeLocalRechargeDate({ businessDate = '', reason = '' } = {}) {
    const current = rechargeCheckout.value
    if (current?.localSnapshotPreview !== true
      || !/^\d{4}-\d{2}-\d{2}$/.test(String(businessDate))) return
    // 日期编辑只改本次浏览器快照；最终服务端再校验日期及历史补单原因。
    rechargeCheckout.value = {
      ...current, businessDate,
      businessDateReason: String(reason || ''),
      rechargeTerms: { ...current.rechargeTerms,
        businessDate, businessDateReason: String(reason || '') }
    }
  }

  function editLocalPayment(action, payload = {}) {
    const current = rechargeCheckout.value
    const lines = clonePlain(current.payment?.selectedLines || [])
    const method = localMethods.find(([id]) => id === String(payload.paymentMethodId || ''))
    if (action === 'add-payment-method') {
      if (!method || lines.some((line) => line.method === method[0])) return null
      lines.push({ id: `local-recharge-payment-${method[0]}`, kind: 'bookkeeping_collection',
        method: method[0], name: method[1], amount: Math.max(0, Number(current.payment.summary.remainingAmount || 0)),
        externalTransactionNo: '', status: 'editing', canEdit: true, canRemove: true })
    } else {
      const index = lines.findIndex((line) => line.id === payload.paymentLineId)
      if (index < 0) return null
      if (action === 'remove-payment-line') lines.splice(index, 1)
      else {
        const amount = Number(payload.amount)
        if (!Number.isSafeInteger(amount) || amount <= 0) return null
        lines[index].amount = amount
        lines[index].externalTransactionNo = String(payload.externalTransactionNo || '')
      }
    }
    const due = Number(current.summary.receivableAmount || 0)
    const selected = lines.reduce((total, line) => total + Number(line.amount || 0), 0)
    rechargeCheckout.value = { ...current, payment: {
      ...current.payment, selectedLines: lines,
      methods: localMethods.map(([id, name]) => ({ id, name, canAdd: !lines.some((line) => line.method === id) })),
      summary: { receivableAmount: due, selectedAmount: selected,
        remainingAmount: Math.max(0, due - selected), overpaidAmount: Math.max(0, selected - due) }
    } }
    return rechargeCheckout.value
  }

  async function requestRechargeCheckoutAction({ action, payload = {} }) {
    const checkout = rechargeCheckout.value
    if (checkout?.localSnapshotPreview === true) {
      if (['add-payment-method', 'update-payment-line', 'remove-payment-line'].includes(action)) {
        const edited = editLocalPayment(action, payload)
        const response = { result: { status: edited ? 'success' : 'failed',
          message: edited ? '收款明细已更新。' : '收款明细无效，请核对后重试。' } }
        window.dispatchEvent(new CustomEvent('cashier-v3:checkout-draft-mutation-result', {
          detail: { action, payload: clonePlain(payload), status: response.result.status,
            message: response.result.message }
        }))
        return response
      }
      if (action === 'query-checkout-result' && originalLocalSubmit) {
        // 查询只能复用原提交内容及幂等键，不能生成第二笔充值。
        const response = await requestCashierV3Action('submit-recharge-checkout', originalLocalSubmit)
        setRechargeCheckout(response)
        return response
      }
      if (action === 'submit-checkout') {
        const snapshot = clonePlain(checkout)
        const submitted = {
          memberId: Number(snapshot.rechargeTerms.memberId),
          checkoutSnapshot: {
            ...clonePlain(snapshot.rechargeTerms),
            businessType: 'recharge',
            paymentLines: snapshot.payment.selectedLines.map((line) => ({
              paymentMethod: line.method, amount: String(line.amount),
              collectionReference: line.externalTransactionNo || ''
            }))
          },
          idempotencyKey: payload.idempotencyKey || createCashierV3CommandId('CHECKOUT')
        }
        originalLocalSubmit = clonePlain(submitted)
        const response = await requestCashierV3Action('submit-recharge-checkout', submitted)
        setRechargeCheckout(response)
        return response
      }
      return { result: { status: 'failed', code: 'RECHARGE_CHECKOUT_ACTION_NOT_ALLOWED',
        message: '该充值结账操作尚未开放。' } }
    }
    return { result: { status: 'failed', code: 'RECHARGE_CHECKOUT_ACTION_NOT_ALLOWED',
      message: '充值确认快照已失效，请重新进入充值。' } }
  }

  async function enqueueRechargeCheckoutAction(event = {}) {
    let result
    if (String(event?.action || '') === 'query-checkout-result') {
      result = await requestRechargeCheckoutAction(event)
      event?.resolve?.(result)
      return result
    }
    rechargeCheckoutMutationTail = rechargeCheckoutMutationTail
      .catch(() => undefined)
      .then(() => requestRechargeCheckoutAction(event))
    result = await rechargeCheckoutMutationTail
    event?.resolve?.(result)
    return result
  }

  return {
    rechargeCheckout,
    rechargeCheckoutProjection,
    acceptLocalRechargeCheckout,
    closeRechargeCheckout,
    changeLocalRechargeDate,
    requestRechargeCheckoutAction,
    enqueueRechargeCheckoutAction
  }
}
