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
  serviceRecordReceiptFromRecord,
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
  assert.match(html, /&lt;门店&gt;/)
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/)
  assert.doesNotMatch(html, /<script>alert\(1\)<\/script>/)
  assert.match(html, /游客/)
  assert.match(html, /@page \{ size: 63mm 297mm; margin: 0; \}/)
  assert.match(html, /\.receipt \{ width: 56mm;/)
  assert.match(html, /table-layout: fixed/)
  assert.match(html, /\.items-table th:nth-child\(3\).*width: 28%/)
  assert.match(html, /font: 15px\/1\.5/)
  assert.match(html, /h1 .*font-size: 20px/)
  assert.match(html, /h2 .*text-align: center/)
  assert.match(html, /\.tear-guide .*height: 64mm/)
  assert.match(html, />撕纸处<\/div>/)
  assert.match(html, /beforeprint/)
  assert.match(html, /prepareSalesOrderReceiptPrint/)
})

ok('76mm 打印使用驱动支持的 296mm 页长，为作业结束走纸留出空间', () => {
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
  assert.equal(height, 296)
  assert.equal(appendedStyle.id, 'sales-order-receipt-page-size')
  assert.equal(appendedStyle.textContent, '@page { size: 63mm 296mm; margin: 0; }')

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
  assert.match(html, /\.receipt \{ width: 44mm;/)
  assert.match(html, /@media print[\s\S]*\.receipt \{ width: 44mm; max-width: none; margin: 0 0 0 7mm; \}/)
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

ok('服务记录小票只展示服务区块', () => {
  const html = buildSalesOrderReceiptHtml(serviceRecordReceiptFromRecord({
    serviceRecordNo: 'FW-1001',
    storeName: '测试门店',
    memberName: '测试会员',
    serviceProject: '东方熏蒸',
    entitlementSource: '6980随心挑',
    craftsmenSummary: '冯燕',
    usedTimes: 1
  }))
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

ok('混合结账小票同时展示销售和服务区块', () => {
  const receipt = salesOrderReceiptFromCheckout({
    salesOrderId: 'S-1001',
    lines: [
      { lineRole: 'sale', name: '买卡', quantity: 1, payableAmount: 200 },
      { lineRole: 'entitlement_service', name: '东方熏蒸', quantity: 1, sourceName: '次卡权益' },
      { lineRole: 'payment', name: '微信', quantity: 1, amount: 200 }
    ],
    payment: { selectedLines: [] },
    summary: [],
    amountSummary: { originalAmount: 200, discountAmount: 0, receivableAmount: 200 }
  })
  const html = buildSalesOrderReceiptHtml(receipt)
  assert.match(html, /销售明细/)
  assert.match(html, /服务明细/)
  assert.match(html, /买卡/)
  assert.match(html, /东方熏蒸/)
  assert.equal(receipt.items.length, 1)
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
      parseFromString() {
        return { querySelector() { return { innerHTML: '<h1>SO-1003</h1>' } } }
      }
    },
    print() {
      printCount += 1
      listeners.get('beforeprint')?.()
      listeners.get('afterprint')?.()
    },
    setTimeout(callback) { callback(); return 1 },
    clearTimeout() {},
    document: {
      body: { appendChild(child) { bodyChildren.push(child) } },
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
  assert.equal(bodyChildren[1].id, 'sales-order-receipt-print-document-v1')
  assert.match(headChildren[0].textContent, /body > #sales-order-receipt-print-document-v1/)
  assert.match(headChildren[0].textContent, /body > \* \{ display: none !important; \}/)
  assert.match(headChildren[0].textContent, /@page \{ size: 63mm 296mm; margin: 0; \}/)
  assert.match(headChildren[0].textContent, /margin: 0 0 0 3\.5mm !important/)
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
      body: { appendChild(child) { bodyChildren.push(child) } },
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
  assert.match(result.preview.status.textContent, /未打开系统打印|Chrome 或 Edge/)
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
  assert.match(receiptPrint, /serviceRecordReceiptFromRecord/)
  assert.match(orderCenter, /打印销售小票/)
  assert.match(orderCenter, /打印服务小票/)
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

process.stdout.write(`ASSERT_PASSED=${passed}\n`)
process.stdout.write('ASSERT_FAILED=0\n')
