function hasValue(value) {
  if (value === 0 || value === false) return true
  if (value === null || value === undefined) return false
  return typeof value !== 'string' || value.trim() !== ''
}

function pickValue(source, keys) {
  if (!source || typeof source !== 'object') return undefined
  for (const key of keys) {
    if (hasValue(source[key])) return source[key]
  }
  return undefined
}

function firstList(source, keys) {
  if (!source || typeof source !== 'object') return []
  for (const key of keys) {
    if (Array.isArray(source[key])) return source[key]
  }
  return []
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function displayText(value, fallback = '—') {
  return hasValue(value) ? String(value) : fallback
}

function displayAmount(value) {
  const amount = Number(value)
  if (!Number.isFinite(amount)) return '—'
  return `¥${amount.toLocaleString('zh-CN', {
    minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
    maximumFractionDigits: 2
  })}`
}

function itemName(item) {
  return pickValue(item, ['name', 'itemName', 'productName', 'projectName', 'title']) || '未命名商品'
}

function itemQuantity(item) {
  return pickValue(item, ['quantity', 'count', 'num', 'quantityText']) || 1
}

function itemAmount(item) {
  return pickValue(item, ['payableAmount', 'receivableAmount', 'amountDue', 'actualReceivedAmount', 'amount'])
}

function paymentName(line) {
  if (line?.isBalancePayment === true || ['balance', 'member_balance', '余额'].includes(String(pickValue(line, ['paymentSource', 'paymentMethodCode', 'methodCode', 'type']) || '').toLowerCase())) {
    return '余额支付'
  }
  return pickValue(line, ['methodName', 'paymentMethodName', 'name', 'paymentMethod', 'label']) || '未命名收款方式'
}

function paymentAmount(line) {
  return pickValue(line, ['amount', 'receivedAmount', 'paidAmount', 'actualReceivedAmount'])
}

function summaryValue(order, keys) {
  const summary = order.amountSummary || order.summary || order.orderSummary || {}
  return pickValue(summary, keys) ?? pickValue(order, keys)
}

function receiptRows(rows) {
  return rows
    .filter((row) => hasValue(row.value))
    .map((row) => `<div class="summary-row"><span>${escapeHtml(row.label)}</span><strong>${escapeHtml(displayAmount(row.value))}</strong></div>`)
    .join('')
}

/**
 * The receipt is deliberately a projection of an already-settled order snapshot.
 * It never queries or changes an order, payment, inventory, or accounting record.
 */
export function buildSalesOrderReceiptHtml(order = {}) {
  const source = order && typeof order === 'object' ? order : {}
  const member = source.member && typeof source.member === 'object' ? source.member : {}
  const items = firstList(source, ['items', 'orderItems', 'lines', 'details'])
  const payments = firstList(source, ['paymentDetails', 'paymentLines', 'receipts', 'payments'])
  const orderNo = pickValue(source, ['salesOrderNo', 'orderNo', 'no'])
  const memberName = pickValue(source, ['memberName']) || pickValue(member, ['name', 'memberName']) || (source.isGuest ? '游客' : '游客')
  const note = pickValue(source, ['orderNote', 'remark', 'note'])
  const itemRows = items.length
    ? items.map((item) => `<tr><td>${escapeHtml(itemName(item))}</td><td class="right">x${escapeHtml(itemQuantity(item))}</td><td class="right">${escapeHtml(displayAmount(itemAmount(item)))}</td></tr>`).join('')
    : '<tr><td colspan="3" class="empty">暂无商品明细</td></tr>'
  const paymentRows = payments.length
    ? payments.map((line) => `<tr><td>${escapeHtml(paymentName(line))}</td><td class="right">${escapeHtml(displayAmount(paymentAmount(line)))}</td></tr>`).join('')
    : '<tr><td colspan="2" class="empty">暂无收款明细</td></tr>'
  const summaryRows = receiptRows([
    { label: '原价合计', value: summaryValue(source, ['originalAmount', 'originalTotalAmount', 'listAmount']) },
    { label: '优惠合计', value: summaryValue(source, ['discountAmount', 'couponDiscountAmount', 'otherDiscountAmount']) },
    { label: '应付金额', value: summaryValue(source, ['payableAmount', 'receivableAmount', 'amountDue']) },
    { label: '实收金额', value: summaryValue(source, ['actualReceivedAmount', 'paidAmount', 'receivedAmount']) }
  ])

  return `<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>销售订单小票</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; padding: 16px; color: #111827; font: 12px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f8fafc; }
  .receipt-toolbar { display: flex; justify-content: flex-end; gap: 8px; width: min(76mm, 100%); margin: 0 auto 12px; }
  .receipt-toolbar button { border: 1px solid #98a2b3; border-radius: 6px; padding: 7px 12px; color: #344054; background: #fff; font: inherit; font-weight: 600; cursor: pointer; }
  .receipt-toolbar button:first-child { border-color: #175cd3; color: #fff; background: #175cd3; }
  .receipt { width: 76mm; max-width: 100%; margin: 0 auto; padding: 4mm; background: #fff; }
  h1 { margin: 0; font-size: 18px; text-align: center; }
  .meta { margin: 10px 0; padding: 8px 0; border-top: 1px dashed #6b7280; border-bottom: 1px dashed #6b7280; }
  .meta div { display: flex; justify-content: space-between; gap: 8px; }
  .meta span:last-child { text-align: right; overflow-wrap: anywhere; }
  h2 { margin: 12px 0 5px; font-size: 13px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 4px 0; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
  th { color: #4b5563; font-weight: 600; }
  .right { text-align: right; }
  .empty { color: #6b7280; text-align: center; }
  .summary { margin-top: 8px; border-top: 1px dashed #6b7280; }
  .summary-row { display: flex; justify-content: space-between; gap: 8px; padding-top: 4px; }
  .note { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #6b7280; white-space: pre-wrap; overflow-wrap: anywhere; }
  .footer { margin-top: 12px; color: #6b7280; text-align: center; font-size: 11px; }
  @page { size: 80mm auto; margin: 0; }
  @media print {
    body { padding: 0; background: #fff; }
    .receipt-toolbar { display: none; }
    .receipt { width: 76mm; max-width: none; margin: 0 auto; }
  }
</style>
</head>
<body>
  <div class="receipt-toolbar" aria-label="小票预览操作">
    <button type="button" onclick="window.print()">打印</button>
    <button type="button" onclick="window.close()">关闭</button>
  </div>
  <main class="receipt">
  <h1>${escapeHtml(displayText(pickValue(source, ['storeName', 'shopName']), '销售小票'))}</h1>
  <div class="meta">
    <div><span>订单号</span><span>${escapeHtml(displayText(orderNo))}</span></div>
    <div><span>顾客</span><span>${escapeHtml(memberName)}</span></div>
    <div><span>业务日期</span><span>${escapeHtml(displayText(pickValue(source, ['businessDate', 'settledAt', 'paymentCompletedAt', 'occurredAt'])))}</span></div>
    <div><span>收银员</span><span>${escapeHtml(displayText(pickValue(source, ['cashierName', 'operatorName', 'operatedBy'])))}</span></div>
  </div>
  <h2>商品明细</h2>
  <table><thead><tr><th>名称</th><th class="right">数量</th><th class="right">金额</th></tr></thead><tbody>${itemRows}</tbody></table>
  <h2>收款明细</h2>
  <table><thead><tr><th>收款方式</th><th class="right">金额</th></tr></thead><tbody>${paymentRows}</tbody></table>
  <div class="summary">${summaryRows || '<div class="empty">暂无金额汇总</div>'}</div>
  ${hasValue(note) ? `<div class="note"><strong>订单备注</strong><br>${escapeHtml(note)}</div>` : ''}
  <div class="footer">本小票仅展示已结账订单快照</div>
  </main>
</body>
</html>`
}

export function salesOrderReceiptFromCheckout(checkout = {}) {
  const source = checkout && typeof checkout === 'object' ? checkout : {}
  const snapshot = source.orderSnapshot && typeof source.orderSnapshot === 'object'
    ? source.orderSnapshot
    : source.snapshot && typeof source.snapshot === 'object'
      ? source.snapshot
      : {}
  const payment = source.payment && typeof source.payment === 'object' ? source.payment : {}
  return {
    ...snapshot,
    salesOrderNo: pickValue(source, ['salesOrderNo', 'orderNo']) || pickValue(snapshot, ['salesOrderNo', 'orderNo']),
    storeName: pickValue(source, ['storeName', 'shopName']) || pickValue(snapshot, ['storeName', 'shopName']),
    member: source.member || snapshot.member,
    memberName: pickValue(source, ['memberName']) || pickValue(snapshot, ['memberName']),
    isGuest: source.isGuest === true || snapshot.isGuest === true || !source.member,
    businessDate: pickValue(source, ['businessDate', 'settledAt', 'paymentCompletedAt']) || pickValue(snapshot, ['businessDate', 'settledAt', 'paymentCompletedAt']),
    cashierName: pickValue(source, ['cashierName', 'operatorName']) || pickValue(snapshot, ['cashierName', 'operatorName']),
    orderNote: pickValue(source, ['orderNote']) || pickValue(snapshot, ['orderNote', 'remark', 'note']),
    items: firstList(snapshot, ['items', 'orderItems', 'lines', 'details']).length
      ? firstList(snapshot, ['items', 'orderItems', 'lines', 'details'])
      : firstList(source, ['orderLines', 'lines']),
    paymentDetails: firstList(payment, ['resultLines', 'selectedLines']).length
      ? firstList(payment, ['resultLines', 'selectedLines'])
      : firstList(snapshot, ['paymentDetails', 'paymentLines', 'receipts', 'payments']),
    amountSummary: source.summary || source.orderSummary || snapshot.summary || snapshot.amountSummary || {}
  }
}

export function openSalesOrderReceiptPrint(order, hostWindow = window) {
  let popup
  try {
    popup = hostWindow.open('', '_blank', 'popup,width=420,height=720')
    if (!popup) return { ok: false, message: '浏览器阻止了打印窗口，请允许弹窗后重试。' }
    popup.document.open()
    popup.document.write(buildSalesOrderReceiptHtml(order))
    popup.document.close()
    popup.focus()
    return { ok: true }
  } catch (error) {
    return { ok: false, message: '小票暂时无法生成，请稍后重试。', error }
  }
}
