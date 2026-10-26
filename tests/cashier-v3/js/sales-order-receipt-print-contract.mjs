import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import {
  SALES_ORDER_RECEIPT_PRINT_SETTINGS_KEY,
  buildSalesOrderReceiptHtml,
  buildSalesOrderReceiptTestOrder,
  loadSalesOrderReceiptPrintSettings,
  mountSalesOrderReceiptPreview,
  openSalesOrderReceiptPrint,
  saveSalesOrderReceiptPrintSettings,
  salesOrderReceiptFromCheckout,
  setSalesOrderReceiptPageSize
} from '../../../前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const orderOverlay = readFileSync(path.join(root, '前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue'), 'utf8')
const checkoutOverlay = readFileSync(path.join(root, '前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue'), 'utf8')
const printButton = readFileSync(path.join(root, '前端代码/cashier-v3/src/components/order/SalesOrderReceiptPrintButton.vue'), 'utf8')
const receiptPrint = readFileSync(path.join(root, '前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js'), 'utf8')
const printerSetup = readFileSync(path.join(root, '前端代码/cashier-v3/src/components/order/ReceiptPrinterSetupOverlay.vue'), 'utf8')
const orderCenter = readFileSync(path.join(root, '前端代码/cashier-v3/src/views/OrderCenterView.vue'), 'utf8')
const cashierWorkbench = readFileSync(path.join(root, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'), 'utf8')

let passed = 0
function ok(label, callback) {
  callback()
  passed += 1
  process.stdout.write(`[PASS] ${label}\n`)
}

ok('长小票在原生窗口打开前解除根容器裁剪，结束、取消、异常和关闭后精确恢复', () => {
  // Model CSSOM values and !important priorities, not just the presence of CSS
  // text: native hosts can snapshot before @media print takes effect.
  for (const outcome of ['afterprint', 'focus', 'throw', 'fallback', 'close']) {
    const makeStyle = () => {
      const values = new Map([['height', ['100%', 'important']], ['width', ['960px', '']], ['min-width', ['1120px', 'important']], ['overflow-x', ['auto', '']], ['overflow-y', ['hidden', '']]])
      return {
        values,
        getPropertyValue(key) { return values.get(key)?.[0] || '' },
        getPropertyPriority(key) { return values.get(key)?.[1] || '' },
        setProperty(key, value, priority = '') { values.set(key, [value, priority]) },
        removeProperty(key) { values.delete(key) }
      }
    }
    const roots = [{ style: makeStyle() }, { style: makeStyle(), children: [], appendChild(child) { this.children.push(child) } }]
    const original = roots.map((root) => [...root.style.values])
    const listeners = new Map()
    let timeout
    let printCss
    const host = {
      document: {
        documentElement: roots[0], body: roots[1], hasFocus() { return true },
        head: { appendChild(style) { printCss = style } },
        createElement(tagName) {
          return { style: {}, children: [], handlers: {}, setAttribute() {},
            appendChild(child) { this.children.push(child) },
            addEventListener(event, callback) { this.handlers[event] = callback }, remove() {} }
        }
      },
      DOMParser: class { parseFromString(html) { return { querySelector() { return { innerHTML: html.match(/<main class="receipt">([\s\S]*?)<\/main>/)[1] } } } } },
      addEventListener(event, callback) { listeners.set(event, callback) },
      removeEventListener(event) { listeners.delete(event) },
      setTimeout(callback) { timeout = callback; return 1 }, clearTimeout() {},
      print() {
        for (const root of roots) {
          assert.equal(root.style.getPropertyValue('height'), 'auto')
          assert.equal(root.style.getPropertyValue('min-height'), '0')
          assert.equal(root.style.getPropertyValue('max-height'), 'none')
          // Preserve sizing, but remove both overflow axes: retaining x:auto
          // would compute y:visible back to auto in a real browser.
          assert.equal(root.style.getPropertyValue('width'), '960px')
          assert.equal(root.style.getPropertyValue('min-width'), '1120px')
          assert.equal(root.style.getPropertyPriority('min-width'), 'important')
          assert.equal(root.style.getPropertyValue('overflow-x'), 'visible')
          assert.equal(root.style.getPropertyPriority('overflow-x'), 'important')
          assert.equal(root.style.getPropertyValue('overflow-y'), 'visible')
          assert.equal(root.style.getPropertyPriority('overflow-y'), 'important')
        }
        if (outcome === 'throw') throw new Error('native dialog failed')
        if (outcome === 'afterprint' || outcome === 'focus') {
          listeners.get('beforeprint')()
          listeners.get(outcome)()
        }
      }
    }
    const preview = mountSalesOrderReceiptPreview({
      items: [{ name: '购买商品', quantity: 1 }, { name: '权益项目', businessTag: '权益', quantity: 1 }]
    }, host)
    preview.printButton.handlers.click()
    if (outcome === 'fallback') timeout()
    if (outcome === 'close') preview.closeButton.handlers.click()
    roots.forEach((root, index) => assert.deepEqual([...root.style.values], original[index], outcome))
    assert.match(printCss.textContent, /html, body \{[^}]*height: auto !important;[^}]*overflow-y: visible !important;/)
    const rootCss = printCss.textContent.match(/html, body \{([^}]+)\}/)[1]
    assert.match(rootCss, /overflow-x: visible !important;/)
    assert.doesNotMatch(rootCss, /(?:^|;)\s*(?:width|min-width):/)
    assert.match(printCss.textContent, /@page \{ size: 63mm 210mm;/)
  }
  for (const paperSize of ['58', '76', '80']) {
    const html = buildSalesOrderReceiptHtml({}, { paperSize })
    assert.match(html, /html, body \{[^}]*height: auto !important;[^}]*overflow-y: visible !important;/)
    assert.match(html.match(/html, body \{([^}]+)\}/)[1], /overflow-x: visible !important;/)
    assert.doesNotMatch(html.match(/html, body \{([^}]+)\}/)[1], /(?:^|;)\s*(?:width|min-width):/)
  }
})

ok('门店地址在实收金额之后，转义并换行，缺失地址不虚构', () => {
  for (const paperSize of ['58', '76', '80']) {
    const html = buildSalesOrderReceiptHtml({
      storeAddress: '河南省许昌市禹州市\n禹王大道<测试>门店 & 2楼',
      amountSummary: { payableAmount: 0, actualReceivedAmount: 0 },
      orderNote: '备注'
    }, { paperSize })
    assert.ok(html.indexOf('<div class="store-address">') > html.indexOf('实收金额'))
    assert.ok(html.indexOf('<div class="store-address">') < html.indexOf('<div class="note">'))
    assert.match(html, /门店地址：/)
    assert.match(html, /禹王大道&lt;测试&gt;门店 &amp; 2楼/)
    assert.match(html, /\.store-address \{[^}]*overflow-wrap: anywhere/)
    assert.match(html, /\.store-address \{[^}]*font-size: \.9em/)
  }
  for (const storeAddress of ['', '  ', null, undefined]) {
    assert.doesNotMatch(buildSalesOrderReceiptHtml({ storeAddress }), /<div class="store-address">/)
  }
  assert.equal(salesOrderReceiptFromCheckout({ storeAddress: '订单门店地址' }).storeAddress, '订单门店地址')
  // 独立的顶层打印样式必须与 iframe 预览同样缩小地址字号。
  assert.match(receiptPrint, /\$\{root\} \.store-address \{[^}]*font-size: \.9em/)
})

ok('小票只展示订单冻结快照且转义动态文本', () => {
  const html = buildSalesOrderReceiptHtml({
    salesOrderNo: 'SO-1001',
    storeName: '<门店>',
    isGuest: true,
    orderNote: '<script>alert(1)</script>',
    items: [{ name: '<项目>', quantity: 1, payableAmount: 99 }],
    paymentDetails: [{ methodName: '微信', amount: 99 }],
    amountSummary: { payableAmount: 99, actualReceivedAmount: 99 }
  })
  assert.match(html, /SO-1001/)
  assert.match(html, /<span>单号<\/span>/)
  assert.match(html, /<span>日期<\/span>/)
  assert.doesNotMatch(html, /<span>订单号<\/span>|<span>业务日期<\/span>/)
  assert.match(html, /&lt;门店&gt;/)
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/)
  assert.doesNotMatch(html, /<script>alert\(1\)<\/script>/)
  assert.match(html, /游客/)
  assert.match(html, /@page \{ size: 63mm 210mm; margin: 0; \}/)
  assert.match(html, /\.receipt \{ width: 60mm; max-width: 100%; margin: 0 auto; padding: 1mm;/)
  assert.match(html, /table-layout: fixed/)
  assert.match(html, /\.items-table th:nth-child\(3\).*width: 31%/)
  assert.match(html, /font: 18px\/1\.5/)
  assert.match(html, /h1 .*font-size: 24px/)
  assert.match(html, /h2 .*text-align: center/)
  assert.match(html, /\.meta div \{ display: flex; justify-content: space-between; gap: 2px; \}/)
  assert.doesNotMatch(html, /本小票仅展示已结账订单快照/)
  assert.match(html, /\.receipt \{ width: 63mm; max-width: none; margin: 0; padding: \.5mm; font-size: 21px; \}/)
  assert.match(html, /\.receipt \.meta div:first-child span:last-child \{ font-size: \.78em; \}/)
  assert.match(html, /\.tear-guide .*height: 64mm/)
  assert.match(html, /<div class="tear-guide" aria-hidden="true"><\/div>/)
  assert.doesNotMatch(html, /撕纸处/)
  assert.match(html, /beforeprint/)
  assert.match(html, /prepareSalesOrderReceiptPrint/)
})

ok('76mm 打印匹配 XP-76 默认 210mm 页长，避免驱动整页缩放', () => {
  let appendedStyle
  const documentRef = {
    querySelector(selector) {
      assert.equal(selector, '.receipt')
      return {
        scrollHeight: 680,
        getBoundingClientRect() { return { height: 672 } }
      }
    },
    getElementById() { return null },
    createElement(tagName) {
      assert.equal(tagName, 'style')
      return {}
    },
    head: {
      appendChild(style) { appendedStyle = style }
    }
  }
  const height = setSalesOrderReceiptPageSize(documentRef)
  assert.equal(height, 210)
  assert.equal(appendedStyle.id, 'sales-order-receipt-page-size')
  assert.equal(appendedStyle.textContent, '@page { size: 63mm 210mm; margin: 0; }')

  const adaptiveHeight = setSalesOrderReceiptPageSize(documentRef, '58')
  assert.equal(adaptiveHeight, 184)
  assert.equal(appendedStyle.textContent, '@page { size: 58mm 184mm; margin: 0; }')
})

ok('纸宽配置只保存在当前浏览器并兼容非法缓存', () => {
  const values = new Map()
  const storage = {
    getItem(key) { return values.get(key) || null },
    setItem(key, value) { values.set(key, value) }
  }
  assert.deepEqual(loadSalesOrderReceiptPrintSettings(storage), { paperSize: '76' })
  assert.deepEqual(saveSalesOrderReceiptPrintSettings({ paperSize: '58' }, storage), { paperSize: '58' })
  assert.equal(JSON.parse(values.get(SALES_ORDER_RECEIPT_PRINT_SETTINGS_KEY)).paperSize, '58')
  values.set(SALES_ORDER_RECEIPT_PRINT_SETTINGS_KEY, '{broken')
  assert.deepEqual(loadSalesOrderReceiptPrintSettings(storage), { paperSize: '76' })
  const blockedStorage = {}
  Object.defineProperty(blockedStorage, 'getItem', { get() { throw new Error('blocked') } })
  Object.defineProperty(blockedStorage, 'setItem', { get() { throw new Error('blocked') } })
  assert.deepEqual(loadSalesOrderReceiptPrintSettings(blockedStorage), { paperSize: '76' })
  assert.deepEqual(saveSalesOrderReceiptPrintSettings({ paperSize: '80' }, blockedStorage), { paperSize: '80' })
})

ok('测试小票包含中文金额和底部完整性内容且不冒充业务订单', () => {
  const order = buildSalesOrderReceiptTestOrder({
    storeName: '测试门店',
    now: new Date('2026-08-09T10:11:12+08:00')
  })
  const html = buildSalesOrderReceiptHtml(order, { paperSize: '58' })
  assert.equal(order.salesOrderNo, 'TEST-20260809-101112')
  assert.match(html, /打印测试小票 · 非业务凭证/)
  assert.match(html, /中文项目名称换行测试/)
  assert.match(html, /测试小票底部内容完整/)
  assert.match(html, /@page \{ size: 58mm 297mm; margin: 0; \}/)
  assert.match(html, /\.receipt \{ width: 55mm; max-width: 100%; margin: 0 auto; padding: 1mm;/)
  assert.match(html, /font: 14px\/1\.5/)
  assert.match(html, /@media print[\s\S]*\.receipt \{ width: calc\(100% - 2mm\); max-width: none; margin: 0 1mm; padding: 1mm; font-size: clamp\(14px, calc\(8vw - 2px\), 68px\); \}/)
  const wideHtml = buildSalesOrderReceiptHtml(order, { paperSize: '80' })
  assert.match(wideHtml, /\.receipt \{ width: 76mm; max-width: 100%; margin: 0 auto; padding: 1mm;/)
  assert.match(wideHtml, /font: 18px\/1\.5/)
  assert.match(wideHtml, /@media print[\s\S]*\.receipt \{ width: calc\(100% - 2mm\); max-width: none; margin: 0 1mm; padding: 1mm; font-size: clamp\(18px, calc\(8vw - 2px\), 68px\); \}/)
})

ok('旧结账快照适配器兼容完整快照', () => {
  const receipt = salesOrderReceiptFromCheckout({
    salesOrderNo: 'SO-1002',
    member: { name: '叶萍' },
    orderLines: [{ name: '护理项目', quantity: 1, payableAmount: 128 }],
    summary: { receivableAmount: 128, discountAmount: 0 },
    payment: { resultLines: [{ name: '微信', amount: 128 }] }
  })
  assert.equal(receipt.salesOrderNo, 'SO-1002')
  assert.equal(receipt.items[0].name, '护理项目')
  assert.equal(receipt.paymentDetails[0].name, '微信')
})

ok('小票只保留应付与实收汇总，并在有欠款时紧随应付显示欠款合计', () => {
  const html = buildSalesOrderReceiptHtml({
    amountSummary: {
      originalAmount: 1980,
      discountAmount: 80,
      payableAmount: 1400,
      debtAmount: 500,
      actualReceivedAmount: 1400
    }
  })
  const summary = html.match(/<div class="summary">([\s\S]*?)<\/div>\s*<div class="signatures"/)?.[1] || ''
  assert.match(summary, /应付金额<\/span><strong>¥1,400<\/strong>/)
  assert.match(summary, /欠款合计<\/span><strong>¥500<\/strong>/)
  assert.match(summary, /应付金额[\s\S]*欠款合计[\s\S]*实收金额/)
  assert.doesNotMatch(summary, /原价合计|优惠合计/)

  const noDebt = buildSalesOrderReceiptHtml({ amountSummary: { payableAmount: 100, debtAmount: 0 } })
  assert.doesNotMatch(noDebt, /欠款合计/)
})

ok('收银成功页小票搬运结账快照欠款，不用应付与实收差额推算', () => {
  const receipt = salesOrderReceiptFromCheckout({
    summary: { receivableAmount: 1400, originalAmount: 1980, discountAmount: 80 },
    debtAmount: 500,
    payment: { resultLines: [{ name: '现金', amount: 1400 }] }
  })
  assert.equal(receipt.amountSummary.debtAmount, 500)
  assert.match(buildSalesOrderReceiptHtml(receipt), /欠款合计<\/span><strong>¥500<\/strong>/)

  const withoutDebt = salesOrderReceiptFromCheckout({
    summary: { receivableAmount: 100 },
    payment: { resultLines: [{ name: '现金', amount: 80 }] }
  })
  assert.doesNotMatch(buildSalesOrderReceiptHtml(withoutDebt), /欠款合计/)
})

ok('纯权益订单小票只展示服务区块', () => {
  const html = buildSalesOrderReceiptHtml({ receiptMode: 'service', serviceRecords: [{
    serviceRecordNo: 'FW-1001',
    storeName: '测试门店',
    memberName: '测试会员',
    serviceProject: '东方熏蒸',
    entitlementSource: '6980随心挑',
    craftsmenSummary: '冯燕',
    usedTimes: 1
  }] })
  assert.match(html, /服务明细/)
  assert.match(html, /东方熏蒸/)
  assert.match(html, /<th>项目<\/th><th class="right">次数<\/th><th>来源<\/th>/)
  assert.match(html, /<th>来源<\/th>/)
  assert.match(html, />权益<\/td>/)
  assert.doesNotMatch(html, /<th>手艺人<\/th>/)
  assert.doesNotMatch(html, /冯燕/)
  assert.doesNotMatch(html, /6980随心挑/)
  assert.doesNotMatch(html, /销售明细/)
})

ok('权益项目从销售明细拆出并只展示名称次数余次', () => {
  const html = buildSalesOrderReceiptHtml({
    items: [
      { businessTag: '购买', name: '舒缓卡', quantity: 1, payableAmount: 1000 },
      { businessTag: '权益', name: '身体(收店)', quantity: 2, remainingTimes: 8, entitlementAmount: 0 }
    ],
    amountSummary: { payableAmount: 1000 }
  })
  const salesSection = html.slice(html.indexOf('<h2>销售明细</h2>'), html.indexOf('<h2>权益明细</h2>'))
  const entitlementSection = html.slice(html.indexOf('<h2>权益明细</h2>'), html.indexOf('<h2>收款明细</h2>'))
  assert.match(salesSection, /舒缓卡/)
  assert.doesNotMatch(salesSection, /身体\(收店\)/)
  assert.match(entitlementSection, /<th>名称<\/th><th class="right">次数<\/th><th class="right">余次<\/th>/)
  assert.match(entitlementSection, /身体\(收店\)<\/td><td class="right">2<\/td><td class="right">8<\/td>/)
  assert.doesNotMatch(entitlementSection, /金额|¥0/)
})

ok('混合结账小票同时展示销售和权益区块', () => {
  const receipt = salesOrderReceiptFromCheckout({
    salesOrderId: 'S-1001',
    lines: [
      { lineRole: 'sale', name: '买卡', quantity: 1, payableAmount: 200 },
      { lineRole: 'entitlement_service', name: '东方熏蒸', quantity: 1, remainingTimes: 3, sourceName: '次卡权益' },
      { lineRole: 'payment', name: '微信', quantity: 1, amount: 200 }
    ],
    payment: { selectedLines: [] },
    summary: [],
    amountSummary: { originalAmount: 200, discountAmount: 0, receivableAmount: 200 }
  })
  const html = buildSalesOrderReceiptHtml(receipt)
  assert.match(html, /销售明细/)
  assert.match(html, /权益明细/)
  assert.match(html, /买卡/)
  assert.match(html, /东方熏蒸/)
  assert.equal(receipt.items.length, 2)
  assert.equal(receipt.paymentDetails.length, 1)
  assert.equal(receipt.paymentDetails[0].name, '微信')
  assert.match(html, /应付金额/)
})

ok('用户点击后在当前页面显示小票预览且不自动打印', () => {
  const bodyChildren = []
  const headChildren = []
  const listeners = new Map()
  let printCount = 0
  const createElement = (tagName) => ({
    tagName,
    style: {},
    children: [],
    handlers: {},
    contentWindow: {},
    contentDocument: tagName === 'iframe'
      ? {
          querySelector(selector) {
            assert.equal(selector, '.receipt')
            return {
              scrollHeight: 320,
              getBoundingClientRect() { return { height: 318 } }
            }
          }
        }
      : undefined,
    scrollHeight: 680,
    getBoundingClientRect() { return { height: 680 } },
    setAttribute() {},
    appendChild(child) { this.children.push(child) },
    addEventListener(type, listener) { this.handlers[type] = listener },
    remove() {}
  })
  const hostWindow = {
    localStorage: { getItem() { return null } },
    DOMParser: class {
      parseFromString(html) {
        // Carry the real generated receipt into the print host, including paper-only signatures.
        return { querySelector() { return { innerHTML: html.match(/<main class="receipt">([\s\S]*?)<\/main>/)[1] } } }
      }
    },
    print() {
      printCount += 1
      assert.equal(bodyChildren[0].style.display, 'none')
      assert.equal(bodyChildren[1].style.display, 'block')
      assert.equal(bodyChildren[1].style.width, '63mm')
      assert.equal(bodyChildren[1].style.margin, '0')
      assert.equal(bodyChildren[1].style.padding, '.5mm')
      assert.equal(bodyChildren[1].style.fontSize, '21px')
      listeners.get('beforeprint')?.()
      listeners.get('afterprint')?.()
    },
    setTimeout(callback) { callback(); return 1 },
    clearTimeout() {},
    document: {
      body: { children: bodyChildren, appendChild(child) { bodyChildren.push(child) } },
      head: { appendChild(child) { headChildren.push(child) } },
      createElement,
      querySelector() { return null }
    },
    addEventListener(type, listener) { listeners.set(type, listener) },
    removeEventListener(type) { listeners.delete(type) }
  }
  const result = openSalesOrderReceiptPrint({ salesOrderNo: 'SO-1003' }, hostWindow)
  assert.equal(result.ok, true)
  assert.equal(bodyChildren.length, 1)
  assert.equal(bodyChildren[0].className, 'sales-order-receipt-preview-overlay')
  assert.match(result.preview.frame.srcdoc, /SO-1003/)
  assert.doesNotMatch(result.preview.frame.srcdoc, /onclick="window\.prepareSalesOrderReceiptPrint\(\); window\.print\(\)"/)
  assert.equal(printCount, 0)
  result.preview.printButton.handlers.click()
  assert.equal(printCount, 1)
  assert.equal(result.preview.printButton.disabled, false)
  assert.equal(bodyChildren[0].style.display, undefined)
  assert.equal(bodyChildren[1].style.position, '')
  assert.equal(bodyChildren[1].id, 'sales-order-receipt-print-document-v1')
  assert.match(headChildren[0].textContent, /body > #sales-order-receipt-print-document-v1/)
  assert.match(headChildren[0].textContent, /body > \* \{ display: none !important; \}/)
  assert.match(headChildren[0].textContent, /@page \{ size: 63mm 210mm; margin: 0; \}/)
  assert.match(headChildren[0].textContent, /width: 60mm; padding: 1mm;/)
  assert.match(headChildren[0].textContent, /font: 18px\/1\.5/)
  assert.match(headChildren[0].textContent, /\.items-table th:nth-child\(3\).*width: 31%/)
  assert.match(headChildren[0].textContent, /\.tear-guide .*height: 64mm/)
  assert.match(headChildren[0].textContent, /width: 63mm !important; margin: 0 !important; padding: \.5mm !important; font-size: 21px !important/)
  assert.match(headChildren[0].textContent, /\.signature-row \{ min-height: 20mm; padding-top: 4px; \}/)
  assert.match(headChildren[0].textContent, /#sales-order-receipt-print-document-v1 \.signatures \{ break-inside: avoid; page-break-inside: avoid; \}/)
  assert.match(bodyChildren[1].innerHTML, /店长签字:<\/div>\s*<div class="tear-guide" aria-hidden="true"><\/div>\s*<\/div>/)
  assert.match(headChildren[0].textContent, /\.meta div:first-child span:last-child \{ font-size: \.78em; \}/)
  assert.doesNotMatch(bodyChildren[1].innerHTML, /本小票仅展示已结账订单快照/)
  assert.match(bodyChildren[1].innerHTML, /客户签字:[\s\S]*前台签字:[\s\S]*店长签字:/)
  assert.equal(listeners.has('message'), true)
})

ok('系统打印未被浏览器接管时给出可见提示并恢复按钮', () => {
  const bodyChildren = []
  const listeners = new Map()
  const createElement = (tagName) => ({
    tagName,
    style: {},
    children: [],
    handlers: {},
    contentWindow: {},
    contentDocument: tagName === 'iframe'
      ? { querySelector() { return { scrollHeight: 320, getBoundingClientRect() { return { height: 318 } } } } }
      : undefined,
    scrollHeight: 680,
    getBoundingClientRect() { return { height: 680 } },
    setAttribute() {},
    appendChild(child) { this.children.push(child) },
    addEventListener(type, listener) { this.handlers[type] = listener },
    remove() {}
  })
  const hostWindow = {
    localStorage: { getItem() { return null } },
    DOMParser: class { parseFromString() { return { querySelector() { return { innerHTML: '<h1>SO-1006</h1>' } } } } },
    print() {},
    setTimeout(callback) { callback(); return 1 },
    clearTimeout() {},
    document: {
      body: { children: bodyChildren, appendChild(child) { bodyChildren.push(child) } },
      head: { appendChild() {} },
      createElement,
      querySelector() { return null }
    },
    addEventListener(type, listener) { listeners.set(type, listener) },
    removeEventListener(type) { listeners.delete(type) }
  }
  const result = openSalesOrderReceiptPrint({ salesOrderNo: 'SO-1006' }, hostWindow)
  result.preview.printButton.handlers.click()
  assert.equal(result.preview.printButton.disabled, false)
  assert.equal(bodyChildren[0].style.display, undefined)
  assert.match(result.preview.status.textContent, /未打开系统打印|Chrome 或 Edge/)
})

ok('系统打印窗口仍打开时不提前恢复页面弹窗', () => {
  const bodyChildren = []
  const listeners = new Map()
  const createElement = (tagName) => ({
    tagName,
    style: {},
    children: [],
    handlers: {},
    contentWindow: {},
    contentDocument: tagName === 'iframe'
      ? { querySelector() { return { scrollHeight: 320, getBoundingClientRect() { return { height: 318 } } } } }
      : undefined,
    scrollHeight: 680,
    getBoundingClientRect() { return { height: 680 } },
    setAttribute() {},
    appendChild(child) { this.children.push(child) },
    addEventListener(type, listener) { this.handlers[type] = listener },
    remove() {}
  })
  const hostWindow = {
    localStorage: { getItem() { return null } },
    DOMParser: class { parseFromString() { return { querySelector() { return { innerHTML: '<h1>SO-1007</h1>' } } } } },
    print() { assert.equal(bodyChildren[0].style.display, 'none') },
    setTimeout(callback) { callback(); return 1 },
    clearTimeout() {},
    document: {
      hasFocus() { return false },
      body: { children: bodyChildren, appendChild(child) { bodyChildren.push(child) } },
      head: { appendChild() {} },
      createElement,
      querySelector() { return null }
    },
    addEventListener(type, listener) { listeners.set(type, listener) },
    removeEventListener(type) { listeners.delete(type) }
  }
  const result = openSalesOrderReceiptPrint({ salesOrderNo: 'SO-1007' }, hostWindow)
  result.preview.printButton.handlers.click()
  assert.equal(bodyChildren[0].style.display, 'none')
  listeners.get('focus')?.()
  assert.equal(bodyChildren[0].style.display, undefined)
})

ok('浏览器不能承载当前页预览时返回中文提示', () => {
  const result = openSalesOrderReceiptPrint({ salesOrderNo: 'SO-1004' }, { document: null })
  assert.equal(result.ok, false)
  assert.match(result.message, /无法显示小票预览/)
})

ok('预览层关闭消息只接受自身页面且顶层打印内容保留撕纸区', () => {
  assert.equal(typeof mountSalesOrderReceiptPreview, 'function')
  const html = buildSalesOrderReceiptHtml({ salesOrderNo: 'SO-1005' })
  assert.match(html, /cashier-v3:close-receipt-preview/)
  assert.match(html, /window\.parent\.postMessage/)
})

ok('订单中心打印不再发送后端打印动作', () => {
  assert.match(orderOverlay, /SalesOrderReceiptPrintButton/)
  assert.match(printButton, /openSalesOrderReceiptPrint/)
  assert.match(printButton, /@click="printReceipt"/)
  assert.doesNotMatch(orderOverlay, /runAction\('print-sales-order-receipt'\)/)
})

ok('订单中心提供系统驱动纸宽设置与测试小票入口', () => {
  assert.match(orderCenter, /ReceiptPrinterSetupOverlay/)
  assert.match(orderCenter, />\s*打印设置\s*<\/button>/)
  assert.match(printerSetup, /SALES_ORDER_RECEIPT_PAPER_PROFILES/)
  assert.match(printerSetup, /打印测试小票/)
  assert.match(printerSetup, /由当前电脑管理/)
  assert.match(printerSetup, /使用系统已安装驱动/)
  assert.doesNotMatch(printerSetup, /ESC\/POS|USB|Printer_Impact_Printer/)
})

ok('结账成功页直接使用成功快照打开本地小票预览', () => {
  assert.match(checkoutOverlay, /v-if="canPrintReceipt"/)
  assert.match(checkoutOverlay, /hasEntitlementLines\.value[\s\S]*props\.isServiceOrder === true/)
  assert.match(checkoutOverlay, /'打印小票'/)
  assert.match(checkoutOverlay, /const checkoutReceipt = props\.receipt[\s\S]*salesOrderReceiptFromCheckout\(props\.checkout\)/)
  assert.match(checkoutOverlay, /openSalesOrderReceiptPrint\(checkoutReceipt\)/)
  assert.doesNotMatch(checkoutOverlay, /request\('print-sales-order-receipt'/)
  assert.match(checkoutOverlay, /打印服务小票/)
  assert.doesNotMatch(receiptPrint, /serviceRecordReceiptFromRecord/)
  assert.match(orderCenter, /'生成中…' : '打印'/)
  assert.doesNotMatch(orderCenter, /打印服务小票/)
  assert.match(checkoutOverlay, /正在加载小票/)
  assert.match(cashierWorkbench, /requestAction\('open-sales-order-detail', \{ orderId: salesOrderId \}\)/)
  assert.match(cashierWorkbench, /checkoutReceiptSnapshot/)
  assert.match(cashierWorkbench, /:receipt="checkoutReceiptSnapshot"/)
  assert.doesNotMatch(cashierWorkbench, /\.\.\.\(checkoutReceiptSnapshot\.value \|\| \{\}\)/)
  assert.match(cashierWorkbench, /checkoutReceiptSnapshot\.value = clonePlain\(\{[\s\S]*salesOrderReceiptFromCheckout\(receiptPreview\)/)
  assert.match(cashierWorkbench, /payload\?\.salesOrderId/)
  assert.match(cashierWorkbench, /return \{ \.\.\.result, receiptOrder: detail \}/)
  assert.doesNotMatch(cashierWorkbench, /requestAction\(action, \{\s*salesOrderId/)
})

// Handwriting space doubles to 20mm per row; order content and tear space remain unchanged.
ok('三种纸宽和各类小票底部均有三行空白签字栏', () => {
  for (const paperSize of ['58', '76', '80']) {
    for (const receiptMode of ['sales', 'service', 'mixed']) {
      const order = { receiptMode, storeAddress: '门店地址', orderNote: '原备注', items: [{ name: '项目', quantity: 1 }] }
      const before = JSON.stringify(order)
      const html = buildSalesOrderReceiptHtml(order, { paperSize })
      const labels = [...html.matchAll(/<div class="signature-row">([^<]+)<\/div>/g)].map((match) => match[1])
      assert.deepEqual(labels, ['客户签字:', '前台签字:', '店长签字:'])
      assert.ok(html.indexOf('<div class="signatures">') > html.indexOf('<div class="note">'))
      assert.ok(html.indexOf('<div class="signatures">') < html.indexOf('<div class="tear-guide"'))
    // The feed marker must stay inside the indivisible signature footer, including standalone printing.
    assert.match(html, /店长签字:<\/div>\s*<div class="tear-guide" aria-hidden="true"><\/div>\s*<\/div>/)
    assert.match(html, /\.signatures \{ break-inside: avoid; page-break-inside: avoid; \}/)
      assert.match(html, /\.signature-row \{ min-height: 20mm; padding-top: 4px; \}/)
      assert.match(html, /\.signature-row, \.tear-guide \{ break-inside: avoid/)
      assert.equal(JSON.stringify(order), before)
    }
  }
})

process.stdout.write(`ASSERT_PASSED=${passed}\n`)
process.stdout.write('ASSERT_FAILED=0\n')
