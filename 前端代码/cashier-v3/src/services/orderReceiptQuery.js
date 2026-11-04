import { salesOrderProjectionFromResult } from './cashierV3OrderProjectionContract.js'

/** 结账成功回执只提供已落账身份，不再从购物车拼造小票；纯权益组沿用全部订单的 service: 身份。 */
export function checkoutReceiptOrderId(checkout = {}, response = {}) {
  const envelope = response?.result ? response : (response?.data || response)
  const submission = envelope?.data?.checkoutSubmission || envelope?.data?.checkoutResult || {}
  const status = String(checkout.status || envelope?.result?.status || '')
  if (!['success', 'succeeded'].includes(status)) return ''
  const orderId = String(submission.salesOrder?.orderId || checkout.salesOrderId || '').trim()
  if (orderId) return orderId
  const requestId = String(submission.checkoutRequestId || submission.checkoutRequest?.requestId
    || checkout.checkoutRequestId || '').trim()
  const entitlementOnly = submission.entitlementCompletion
    || checkout.composition?.hasEntitlement === true && checkout.composition?.hasSale !== true
  return entitlementOnly && /^[A-Za-z0-9_-]{1,64}$/.test(requestId) ? `service:${requestId}` : ''
}

/** 两个打印入口共用同一只读详情查询及身份校验；失败不回退到结账前余次、人员或金额。 */
export async function readOrderReceipt(requestAction, orderId) {
  const id = String(orderId || '').trim()
  if (!id) throw new Error('已完成单据标识尚未返回，暂时无法打印，请从全部订单重试。')
  const response = await requestAction('open-sales-order-detail', { orderId: id })
  const envelope = response?.result ? response : response?.data
  const detail = salesOrderProjectionFromResult(response)?.salesOrderDetail
  // 拒绝失败信封中残留的同单详情，权限拒绝或查询未知时不能误打印旧数据。
  if (!['success', 'succeeded'].includes(String(envelope?.result?.status || ''))
    || !detail || String(detail.id || detail.orderId || detail.salesOrderId || '') !== id) {
    throw new Error(envelope?.result?.message || envelope?.message || '单据详情尚未完整返回或当前无权读取，请稍后重试。')
  }
  return detail
}
