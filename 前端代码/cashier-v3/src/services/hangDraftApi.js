import { readStoreV3SessionToken } from './storeV3SessionToken.js'

export async function deleteHangDraft(hangOrderId) {
  const token = readStoreV3SessionToken()
  const response = await fetch('/cashierapi/v3/hang-drafts/delete', {
    method: 'POST',
    credentials: 'omit',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {})
    },
    body: JSON.stringify({ hangOrderId })
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok || Number(body.status || 200) !== 200) {
    throw new Error(String(body.msg || body.message || '挂单删除失败。'))
  }
  return body.data || body
}

export async function saveHangDraft({ stateContextId, idempotencyKey, ...draft }) {
  const token = readStoreV3SessionToken()
  const response = await fetch('/cashierapi/v3/hang-drafts/save', {
    method: 'POST',
    credentials: 'omit',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {})
    },
    body: JSON.stringify({ stateContextId, idempotencyKey, ...draft })
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok || Number(body.status || 200) !== 200) {
    throw new Error(String(body.msg || body.message || '挂单草稿保存失败。'))
  }
  return body.data?.data || body.data || body
}

export async function resumeHangDraft({ hangOrderId, stateContextId }) {
  const token = readStoreV3SessionToken()
  const response = await fetch('/cashierapi/v3/hang-drafts/resume', {
    method: 'POST', credentials: 'omit',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) },
    body: JSON.stringify({ hangOrderId, stateContextId })
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok || Number(body.status || 200) !== 200) throw new Error(String(body.msg || body.message || '提取挂单失败。'))
  return body.data?.data || body.data || body
}

export async function clearCashierDraft(stateContextId) {
  const token = readStoreV3SessionToken()
  const response = await fetch('/cashierapi/v3/cashier-drafts/clear', {
    method: 'POST', credentials: 'omit',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) },
    body: JSON.stringify({ stateContextId })
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok || Number(body.status || 200) !== 200) throw new Error(String(body.msg || body.message || '购物车清空失败。'))
  return body.data?.data || body.data || body
}

export async function discardCashierCheckout(stateContextId) {
  const token = readStoreV3SessionToken()
  const response = await fetch('/cashierapi/v3/cashier-drafts/discard-checkout', {
    method: 'POST', credentials: 'omit',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) },
    body: JSON.stringify({ stateContextId })
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok || Number(body.status || 200) !== 200) throw new Error(String(body.msg || body.message || '结账草稿清理失败。'))
  return body.data?.data || body.data || body
}

export async function validateCashierGuideRound({ stateContextId, checkoutRequestId, checkoutRequestVersion }) {
  const token = readStoreV3SessionToken()
  const response = await fetch('/cashierapi/v3/cashier-drafts/validate-guide-round', {
    method: 'POST', credentials: 'omit',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) },
    body: JSON.stringify({ stateContextId, checkoutRequestId, checkoutRequestVersion })
  })
  const body = await response.json().catch(() => ({}))
  if (!response.ok || Number(body.status || 200) !== 200) {
    throw new Error(String(body.msg || body.message || '导购轮次校验失败，请返回购物车重新选择后再结账。'))
  }
  return body.data?.data || body.data || body
}
