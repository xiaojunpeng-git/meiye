export const SALES_ORDER_RECEIPT_PRINT_SETTINGS_KEY = 'cashier-v3:sales-order-receipt-print-settings:v1'

export const SALES_ORDER_RECEIPT_PAPER_PROFILES = Object.freeze([
  Object.freeze({ key: '58', label: '58mm', paperWidthMm: 58, contentWidthMm: 44, fontSizePx: 12, titleSizePx: 17 }),
  // Common 76mm impact-printer drivers expose a 63mm printable form.
  Object.freeze({ key: '76', label: '76mm', paperWidthMm: 63, contentWidthMm: 56, fixedPageHeightMm: 296, fontSizePx: 15, titleSizePx: 20 }),
  Object.freeze({ key: '80', label: '80mm', paperWidthMm: 80, contentWidthMm: 60, fontSizePx: 13, titleSizePx: 18 })
])

const DEFAULT_RECEIPT_PAPER_KEY = '76'
// Fifteen 1/6-inch impact-printer lines, now owned by the receipt instead of the driver.
const RECEIPT_TEAR_GUIDE_MM = 64
const RECEIPT_TRAILING_SAFETY_MM = 4

function resolveReceiptPageHeightMm(paperProfile, measuredHeightPx) {
  if (Number.isFinite(paperProfile?.fixedPageHeightMm)) return paperProfile.fixedPageHeightMm
  return Math.max(40, Math.ceil((measuredHeightPx * 25.4) / 96 + RECEIPT_TRAILING_SAFETY_MM))
}

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

export function resolveSalesOrderReceiptPaperProfile(paperSize) {
  const normalized = String(paperSize || '').trim()
  return SALES_ORDER_RECEIPT_PAPER_PROFILES.find((profile) => profile.key === normalized)
    || SALES_ORDER_RECEIPT_PAPER_PROFILES.find((profile) => profile.key === DEFAULT_RECEIPT_PAPER_KEY)
}

export function loadSalesOrderReceiptPrintSettings(storageRef) {
  try {
    const storage = storageRef || globalThis?.localStorage
    const parsed = JSON.parse(storage?.getItem?.(SALES_ORDER_RECEIPT_PRINT_SETTINGS_KEY) || '{}')
    return { paperSize: resolveSalesOrderReceiptPaperProfile(parsed?.paperSize).key }
  } catch {
    return { paperSize: DEFAULT_RECEIPT_PAPER_KEY }
  }
}

export function saveSalesOrderReceiptPrintSettings(settings = {}, storageRef) {
  const normalized = { paperSize: resolveSalesOrderReceiptPaperProfile(settings.paperSize).key }
  try {
    const storage = storageRef || globalThis?.localStorage
    storage?.setItem?.(SALES_ORDER_RECEIPT_PRINT_SETTINGS_KEY, JSON.stringify(normalized))
  } catch {
    // Printing still works when browser privacy settings block local storage.
  }
  return normalized
}

export function setSalesOrderReceiptPageSize(documentRef, paperSize = DEFAULT_RECEIPT_PAPER_KEY) {
  const receipt = documentRef?.querySelector?.('.receipt')
  if (!receipt) return null

  const measuredHeight = Math.max(
    Number(receipt.scrollHeight) || 0,
    Number(receipt.getBoundingClientRect?.().height) || 0
  )
  if (measuredHeight <= 0) return null

  const paperProfile = resolveSalesOrderReceiptPaperProfile(paperSize)
  const pageHeightMm = resolveReceiptPageHeightMm(paperProfile, measuredHeight)
  let style = documentRef.getElementById?.('sales-order-receipt-page-size')
  if (!style) {
    style = documentRef.createElement?.('style')
    if (!style) return null
    style.id = 'sales-order-receipt-page-size'
    documentRef.head?.appendChild(style)
  }
  style.textContent = `@page { size: ${paperProfile.paperWidthMm}mm ${pageHeightMm}mm; margin: 0; }`
  return pageHeightMm
}

function localDateParts(dateValue) {
  const value = dateValue instanceof Date ? dateValue : new Date(dateValue)
  const valid = Number.isFinite(value.getTime()) ? value : new Date()
  const pad = (part) => String(part).padStart(2, '0')
  return {
    date: `${valid.getFullYear()}-${pad(valid.getMonth() + 1)}-${pad(valid.getDate())}`,
    compact: `${valid.getFullYear()}${pad(valid.getMonth() + 1)}${pad(valid.getDate())}-${pad(valid.getHours())}${pad(valid.getMinutes())}${pad(valid.getSeconds())}`
  }
}

export function buildSalesOrderReceiptTestOrder(options = {}) {
  const parts = localDateParts(options.now || new Date())
  return {
    isPrintTest: true,
    salesOrderNo: `TEST-${parts.compact}`,
    storeName: options.storeName || '打印测试门店',
    memberName: '测试顾客',
    businessDate: parts.date,
    cashierName: options.cashierName || '测试操作员',
    items: [
      { name: '中文项目名称换行测试', quantity: 1, payableAmount: 129 },
      { name: '产品金额对齐测试', quantity: 2, payableAmount: 499 }
    ],
    paymentDetails: [
      { methodName: '微信', amount: 300 },
      { methodName: '银联', amount: 328 }
    ],
    amountSummary: {
      originalAmount: 628,
      discountAmount: 0,
      payableAmount: 628,
      actualReceivedAmount: 628
    },
    orderNote: '测试小票底部内容完整。'
  }
}

/**
 * The receipt is deliberately a projection of an already-settled order snapshot.
 * It never queries or changes an order, payment, inventory, or accounting record.
 */
export function buildSalesOrderReceiptHtml(order = {}, options = {}) {
  const source = order && typeof order === 'object' ? order : {}
  const paperProfile = resolveSalesOrderReceiptPaperProfile(options.paperSize)
  const paperSideMarginMm = Math.max(0, (paperProfile.paperWidthMm - paperProfile.contentWidthMm) / 2)
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
<title>${source.isPrintTest ? '打印测试小票' : '销售订单小票'}</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; padding: 16px; color: #000; font: ${paperProfile.fontSizePx}px/1.5 "PingFang SC", "Microsoft YaHei", Arial, sans-serif; font-weight: 500; background: #f8fafc; }
  .receipt-toolbar { display: flex; justify-content: flex-end; gap: 8px; width: min(${paperProfile.contentWidthMm}mm, 100%); margin: 0 auto 12px; }
  .receipt-toolbar button { border: 1px solid #98a2b3; border-radius: 6px; padding: 7px 12px; color: #344054; background: #fff; font: inherit; font-weight: 600; cursor: pointer; }
  .receipt-toolbar button:first-child { border-color: #175cd3; color: #fff; background: #175cd3; }
  .receipt { width: ${paperProfile.contentWidthMm}mm; max-width: 100%; margin: 0 auto; padding: 3mm; background: #fff; }
  h1 { margin: 0; font-size: ${paperProfile.titleSizePx}px; font-weight: 700; text-align: center; }
  .test-label { margin-top: 3px; text-align: center; font-weight: 700; }
  .meta { margin: 10px 0; padding: 8px 0; border-top: 1px dashed #6b7280; border-bottom: 1px dashed #6b7280; }
  .meta div { display: flex; justify-content: space-between; gap: 8px; }
  .meta span:first-child { flex: none; white-space: nowrap; }
  .meta span:last-child { text-align: right; overflow-wrap: anywhere; }
  h2 { margin: 12px 0 5px; font-size: ${paperProfile.fontSizePx}px; font-weight: 700; text-align: center; }
  table { width: 100%; table-layout: fixed; border-collapse: collapse; }
  th, td { padding: 4px 0; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
  th { color: #111; font-weight: 700; }
  td:first-child { padding-right: 4px; overflow-wrap: anywhere; }
  .right { padding-left: 2px; text-align: right; white-space: nowrap; }
  .items-table th:nth-child(1), .items-table td:nth-child(1) { width: 57%; }
  .items-table th:nth-child(2), .items-table td:nth-child(2) { width: 15%; }
  .items-table th:nth-child(3), .items-table td:nth-child(3) { width: 28%; }
  .payments-table th:nth-child(1), .payments-table td:nth-child(1) { width: 68%; }
  .payments-table th:nth-child(2), .payments-table td:nth-child(2) { width: 32%; }
  .empty { color: #6b7280; text-align: center; }
  .summary { margin-top: 8px; border-top: 1px dashed #6b7280; }
  .summary-row { display: flex; justify-content: space-between; gap: 8px; padding-top: 4px; }
  .note { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #6b7280; white-space: pre-wrap; overflow-wrap: anywhere; }
  .footer { margin-top: 12px; color: #374151; text-align: center; font-size: ${paperProfile.fontSizePx - 1}px; }
  .tear-guide { display: flex; height: ${RECEIPT_TEAR_GUIDE_MM}mm; align-items: flex-end; justify-content: center; border-bottom: 1px dashed #6b7280; color: #4b5563; font-size: 10px; }
  @page { size: ${paperProfile.paperWidthMm}mm 297mm; margin: 0; }
  @media print {
    body { padding: 0; background: #fff; }
    .receipt-toolbar { display: none; }
    .receipt { width: ${paperProfile.contentWidthMm}mm; max-width: none; margin: 0 0 0 ${paperSideMarginMm}mm; }
    .meta, tr, .summary, .note, .footer, .tear-guide { break-inside: avoid; page-break-inside: avoid; }
    h2 { break-after: avoid; page-break-after: avoid; }
  }
</style>
</head>
<body>
  ${options.hideToolbar ? '' : `<div class="receipt-toolbar" aria-label="小票预览操作">
    <button type="button" onclick="window.prepareSalesOrderReceiptPrint(); window.print()">打印</button>
    <button type="button" onclick="window.parent !== window ? window.parent.postMessage({ type: 'cashier-v3:close-receipt-preview' }, '*') : window.close()">关闭</button>
  </div>`}
  <main class="receipt">
  <h1>${escapeHtml(displayText(pickValue(source, ['storeName', 'shopName']), '销售小票'))}</h1>
  ${source.isPrintTest ? '<div class="test-label">打印测试小票 · 非业务凭证</div>' : ''}
  <div class="meta">
    <div><span>订单号</span><span>${escapeHtml(displayText(orderNo))}</span></div>
    <div><span>顾客</span><span>${escapeHtml(memberName)}</span></div>
    <div><span>业务日期</span><span>${escapeHtml(displayText(pickValue(source, ['businessDate', 'settledAt', 'paymentCompletedAt', 'occurredAt'])))}</span></div>
    <div><span>收银员</span><span>${escapeHtml(displayText(pickValue(source, ['cashierName', 'operatorName', 'operatedBy'])))}</span></div>
  </div>
  <h2>商品明细</h2>
  <table class="items-table"><thead><tr><th>名称</th><th class="right">数量</th><th class="right">金额</th></tr></thead><tbody>${itemRows}</tbody></table>
  <h2>收款明细</h2>
  <table class="payments-table"><thead><tr><th>收款方式</th><th class="right">金额</th></tr></thead><tbody>${paymentRows}</tbody></table>
  <div class="summary">${summaryRows || '<div class="empty">暂无金额汇总</div>'}</div>
  ${hasValue(note) ? `<div class="note"><strong>订单备注</strong><br>${escapeHtml(note)}</div>` : ''}
  <div class="footer">本小票仅展示已结账订单快照</div>
  <div class="tear-guide">撕纸处</div>
  </main>
  <script>
    (() => {
      const prepare = () => {
        const receipt = document.querySelector('.receipt')
        if (!receipt) return
        const measuredHeight = Math.max(receipt.scrollHeight || 0, receipt.getBoundingClientRect().height || 0)
        if (measuredHeight <= 0) return
        const fixedPageHeightMm = ${Number.isFinite(paperProfile.fixedPageHeightMm) ? paperProfile.fixedPageHeightMm : 'null'}
        const pageHeightMm = fixedPageHeightMm || Math.max(40, Math.ceil((measuredHeight * 25.4) / 96 + ${RECEIPT_TRAILING_SAFETY_MM}))
        let style = document.getElementById('sales-order-receipt-page-size')
        if (!style) {
          style = document.createElement('style')
          style.id = 'sales-order-receipt-page-size'
          document.head.appendChild(style)
        }
        style.textContent = '@page { size: ${paperProfile.paperWidthMm}mm ' + pageHeightMm + 'mm; margin: 0; }'
      }
      window.prepareSalesOrderReceiptPrint = prepare
      window.addEventListener('load', prepare)
      window.addEventListener('beforeprint', prepare)
    })()
  </script>
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

export function mountSalesOrderReceiptPreview(order, hostWindow = window, options = {}) {
  const documentRef = hostWindow?.document
  if (!documentRef?.createElement || !documentRef?.body) return null

  documentRef.querySelector?.('.sales-order-receipt-preview-overlay')?.remove?.()
  const overlay = documentRef.createElement('section')
  const panel = documentRef.createElement('div')
  const toolbar = documentRef.createElement('div')
  const printButton = documentRef.createElement('button')
  const closeButton = documentRef.createElement('button')
  const status = documentRef.createElement('span')
  const frame = documentRef.createElement('iframe')
  overlay.className = 'sales-order-receipt-preview-overlay'
  overlay.setAttribute('role', 'dialog')
  overlay.setAttribute('aria-modal', 'true')
  overlay.setAttribute('aria-label', '小票预览')
  overlay.style.cssText = 'position:fixed;inset:0;z-index:10000;display:grid;place-items:center;padding:24px;background:rgba(15,23,42,.48)'
  panel.style.cssText = 'display:flex;width:min(460px,100%);height:min(820px,100%);flex-direction:column;overflow:hidden;border-radius:8px;background:#fff;box-shadow:0 24px 64px rgba(15,23,42,.28)'
  toolbar.style.cssText = 'display:flex;min-height:46px;align-items:center;justify-content:flex-end;gap:8px;padding:6px 10px;border-bottom:1px solid #e5e7eb;background:#fff'
  printButton.type = 'button'
  printButton.textContent = '打印'
  printButton.style.cssText = 'min-height:32px;padding:0 14px;border:1px solid #175cd3;border-radius:6px;background:#175cd3;color:#fff;font-weight:600;cursor:pointer'
  closeButton.type = 'button'
  closeButton.textContent = '关闭'
  closeButton.style.cssText = 'min-height:32px;padding:0 14px;border:1px solid #98a2b3;border-radius:6px;background:#fff;color:#344054;font-weight:600;cursor:pointer'
  status.setAttribute('role', 'status')
  status.style.cssText = 'margin-right:auto;color:#b42318;font-size:12px'
  frame.title = '小票预览'
  frame.style.cssText = 'display:block;width:100%;min-height:0;flex:1;border:0;background:#fff'
  const receiptHtml = buildSalesOrderReceiptHtml(order, { ...options, hideToolbar: true })
  frame.srcdoc = receiptHtml
  toolbar.appendChild(status)
  toolbar.appendChild(printButton)
  toolbar.appendChild(closeButton)
  panel.appendChild(toolbar)
  panel.appendChild(frame)
  overlay.appendChild(panel)

  let printHost = null
  let printStyle = null
  let printCapabilityTimer = null
  let printLifecycleStarted = false

  const handleBeforePrint = () => {
    printLifecycleStarted = true
    status.textContent = ''
  }

  const handleAfterPrint = () => {
    status.textContent = ''
    printButton.disabled = false
  }

  const ensureTopLevelPrintDocument = () => {
    if (printHost && printStyle) return true
    if (typeof hostWindow?.DOMParser !== 'function') return false
    const parsed = new hostWindow.DOMParser().parseFromString(receiptHtml, 'text/html')
    const receipt = parsed.querySelector?.('.receipt')
    if (!receipt) return false

    const profile = resolveSalesOrderReceiptPaperProfile(options.paperSize)
    const profileSideMarginMm = Math.max(0, (profile.paperWidthMm - profile.contentWidthMm) / 2)
    printHost = documentRef.createElement('main')
    printHost.id = 'sales-order-receipt-print-document-v1'
    printHost.innerHTML = receipt.innerHTML
    printStyle = documentRef.createElement('style')
    const root = '#sales-order-receipt-print-document-v1'
    const buildPrintCss = (pageHeightMm) => `
      ${root} { position: fixed; left: -10000px; top: 0; box-sizing: border-box; width: ${profile.contentWidthMm}mm; padding: 3mm; color: #000; background: #fff; font: ${profile.fontSizePx}px/1.5 "PingFang SC", "Microsoft YaHei", Arial, sans-serif; font-weight: 500; }
      ${root} *, ${root} *::before, ${root} *::after { box-sizing: border-box; }
      ${root} h1 { margin: 0; font-size: ${profile.titleSizePx}px; font-weight: 700; text-align: center; }
      ${root} .test-label { margin-top: 3px; text-align: center; font-weight: 700; }
      ${root} .meta { margin: 10px 0; padding: 8px 0; border-top: 1px dashed #6b7280; border-bottom: 1px dashed #6b7280; }
      ${root} .meta div { display: flex; justify-content: space-between; gap: 8px; }
      ${root} .meta span:first-child { flex: none; white-space: nowrap; }
      ${root} .meta span:last-child { text-align: right; overflow-wrap: anywhere; }
      ${root} h2 { margin: 12px 0 5px; font-size: ${profile.fontSizePx}px; font-weight: 700; text-align: center; }
      ${root} table { width: 100%; table-layout: fixed; border-collapse: collapse; }
      ${root} th, ${root} td { padding: 4px 0; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
      ${root} th { color: #111; font-weight: 700; }
      ${root} td:first-child { padding-right: 4px; overflow-wrap: anywhere; }
      ${root} .right { padding-left: 2px; text-align: right; white-space: nowrap; }
      ${root} .items-table th:nth-child(1), ${root} .items-table td:nth-child(1) { width: 57%; }
      ${root} .items-table th:nth-child(2), ${root} .items-table td:nth-child(2) { width: 15%; }
      ${root} .items-table th:nth-child(3), ${root} .items-table td:nth-child(3) { width: 28%; }
      ${root} .payments-table th:nth-child(1), ${root} .payments-table td:nth-child(1) { width: 68%; }
      ${root} .payments-table th:nth-child(2), ${root} .payments-table td:nth-child(2) { width: 32%; }
      ${root} .empty { color: #6b7280; text-align: center; }
      ${root} .summary { margin-top: 8px; border-top: 1px dashed #6b7280; }
      ${root} .summary-row { display: flex; justify-content: space-between; gap: 8px; padding-top: 4px; }
      ${root} .note { margin-top: 10px; padding-top: 8px; border-top: 1px dashed #6b7280; white-space: pre-wrap; overflow-wrap: anywhere; }
      ${root} .footer { margin-top: 12px; color: #374151; text-align: center; font-size: ${profile.fontSizePx - 1}px; }
      ${root} .tear-guide { display: flex; height: ${RECEIPT_TEAR_GUIDE_MM}mm; align-items: flex-end; justify-content: center; border-bottom: 1px dashed #6b7280; color: #4b5563; font-size: 10px; }
      @page { size: ${profile.paperWidthMm}mm ${pageHeightMm}mm; margin: 0; }
      @media print {
        html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
        body > * { display: none !important; }
        body > ${root} { position: static !important; display: block !important; width: ${profile.contentWidthMm}mm !important; margin: 0 0 0 ${profileSideMarginMm}mm !important; }
        ${root} .meta, ${root} tr, ${root} .summary, ${root} .note, ${root} .footer, ${root} .tear-guide { break-inside: avoid; page-break-inside: avoid; }
        ${root} h2 { break-after: avoid; page-break-after: avoid; }
      }
    `
    documentRef.body.appendChild(printHost)
    documentRef.head?.appendChild(printStyle)

    const previewReceipt = frame.contentDocument?.querySelector?.('.receipt')
    const measurementTarget = previewReceipt || printHost
    const measuredHeight = Math.max(
      Number(measurementTarget.scrollHeight) || 0,
      Number(measurementTarget.getBoundingClientRect?.().height) || 0
    )
    const pageHeightMm = resolveReceiptPageHeightMm(profile, measuredHeight)
    printStyle.textContent = buildPrintCss(pageHeightMm)
    return true
  }

  const disposePreview = () => {
    hostWindow.removeEventListener?.('message', closePreview)
    hostWindow.removeEventListener?.('beforeprint', handleBeforePrint)
    hostWindow.removeEventListener?.('afterprint', handleAfterPrint)
    if (printCapabilityTimer !== null) hostWindow.clearTimeout?.(printCapabilityTimer)
    printHost?.remove?.()
    printStyle?.remove?.()
    overlay.remove()
  }
  const closePreview = (event) => {
    if (event?.source !== frame.contentWindow || event?.data?.type !== 'cashier-v3:close-receipt-preview') return
    disposePreview()
  }
  hostWindow.addEventListener?.('message', closePreview)
  printButton.addEventListener?.('click', () => {
    status.textContent = '正在打开系统打印窗口...'
    if (!ensureTopLevelPrintDocument() || typeof hostWindow?.print !== 'function') {
      status.textContent = '当前浏览器无法打开系统打印窗口。'
      return
    }
    printLifecycleStarted = false
    printButton.disabled = true
    hostWindow.addEventListener?.('beforeprint', handleBeforePrint, { once: true })
    hostWindow.addEventListener?.('afterprint', handleAfterPrint, { once: true })
    hostWindow.print()
    printCapabilityTimer = hostWindow.setTimeout?.(() => {
      printCapabilityTimer = null
      printButton.disabled = false
      if (!printLifecycleStarted) status.textContent = '当前内置浏览器不支持系统打印，请使用 Chrome 或 Edge。'
    }, 800) ?? null
  })
  closeButton.addEventListener?.('click', disposePreview)
  overlay.addEventListener?.('click', (event) => {
    if (event.target !== overlay) return
    disposePreview()
  })
  documentRef.body.appendChild(overlay)
  return { overlay, panel, frame, printButton, closeButton }
}

export function openSalesOrderReceiptPrint(order, hostWindow = window, options = {}) {
  try {
    let storage
    try {
      storage = hostWindow?.localStorage
    } catch {
      storage = null
    }
    const printSettings = options.paperSize
      ? { paperSize: resolveSalesOrderReceiptPaperProfile(options.paperSize).key }
      : loadSalesOrderReceiptPrintSettings(storage)
    const preview = mountSalesOrderReceiptPreview(order, hostWindow, printSettings)
    if (!preview) return { ok: false, message: '当前浏览器无法显示小票预览，请更换浏览器后重试。' }
    return { ok: true, preview }
  } catch (error) {
    return { ok: false, message: '小票暂时无法生成，请稍后重试。', error }
  }
}
