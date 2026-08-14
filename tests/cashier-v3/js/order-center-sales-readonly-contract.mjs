import assert from 'node:assert/strict'
import {
  ORDER_CONTRACT_VERSION,
  isUnavailableSalesOrderEconomics,
  mergeSalesOrderCenterProjection,
  nextSalesOrderQueryWithCursor,
  salesOrderPaginationFromPartition,
  shouldApplySalesOrderDetailResponse,
  salesOrderProjectionFromResult
} from '../../../前端代码/cashier-v3/src/services/cashierV3OrderProjectionContract.js'
import { readFileSync } from 'node:fs'

let passed = 0
function ok(label, callback) {
  callback()
  passed += 1
  process.stdout.write(`[PASS] ${label}\n`)
}

const listPartition = {
  contractVersion: ORDER_CONTRACT_VERSION,
  recordsByType: { sales: [{ id: 'SO-2' }] },
  pagesByType: {
    sales: {
      page: 2,
      pageSize: 20,
      total: 21,
      paginationMode: 'keyset',
      paginationCursor: { current: 'c5o1.current.signature', next: 'c5o1.next.signature' },
      hasMore: true
    }
  },
  salesOrders: [{ id: 'SO-2' }],
  paginationMode: 'keyset',
  paginationCursor: { current: 'c5o1.current.signature', next: 'c5o1.next.signature' },
  hasMore: true
}

ok('标准 projection 信封可提取销售订单分区', () => {
  assert.deepEqual(salesOrderProjectionFromResult({
    result: { status: 'success' },
    data: { orderCenter: listPartition }
  }), listPartition)
})

ok('Axios data 外壳与直接信封使用同一合同', () => {
  assert.deepEqual(salesOrderProjectionFromResult({
    data: {
      result: { status: 'success' },
      data: { orderCenter: listPartition }
    }
  }), listPartition)
})

ok('失败信封和错误合同版本不得污染页面', () => {
  assert.equal(salesOrderProjectionFromResult({ result: { status: 'failed' }, data: { orderCenter: listPartition } }), null)
  assert.equal(salesOrderProjectionFromResult({
    result: { status: 'success' },
    data: { orderCenter: { ...listPartition, contractVersion: 'wrong' } }
  }), null)
})

ok('列表局部投影保留其他八类记录与现有详情', () => {
  const merged = mergeSalesOrderCenterProjection({
    contractVersion: ORDER_CONTRACT_VERSION,
    recordsByType: { recharge: [{ id: 'R-1' }] },
    pagesByType: { recharge: { page: 1 } },
    salesOrderDetail: { id: 'SO-1' }
  }, listPartition)
  assert.deepEqual(merged.recordsByType.recharge, [{ id: 'R-1' }])
  assert.deepEqual(merged.recordsByType.sales, [{ id: 'SO-2' }])
  assert.deepEqual(merged.pagesByType.recharge, { page: 1 })
  assert.deepEqual(merged.salesOrderDetail, { id: 'SO-1' })
})

ok('详情局部投影不清空当前列表', () => {
  const merged = mergeSalesOrderCenterProjection(listPartition, {
    contractVersion: ORDER_CONTRACT_VERSION,
    salesOrderDetail: { id: 'SO-2', items: [{ name: '护理项目' }] }
  })
  assert.deepEqual(merged.salesOrders, [{ id: 'SO-2' }])
  assert.equal(merged.salesOrderDetail.id, 'SO-2')
})

ok('结账成功后查看订单先保存可信详情再跳转', () => {
  const cashierView = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/CashierWorkbenchView.vue', import.meta.url), 'utf8')
  assert.match(cashierView, /salesOrderProjectionFromResult\(result\)/)
  assert.match(cashierView, /SALES_ORDER_DETAIL_INVALID/)
  assert.match(cashierView, /state\.orderCenter\s*=\s*mergeSalesOrderCenterProjection\(state\.orderCenter, projection\)/)
  assert.match(cashierView, /mergeSalesOrderCenterProjection\(state\.orderCenter, projection\)[\s\S]*?closeCheckoutOverlay\(\)/)
})

ok('下一页请求只携带不透明游标并清理旧快照字段', () => {
  const next = nextSalesOrderQueryWithCursor({
    page: 2,
    keyword: '肖',
    querySnapshot: { cutoffTimestamp: 1 },
    queryCutoffTimestamp: 1,
    snapshotMaxOrderId: 2
  }, 'c5o1.next.signature')
  assert.equal(next.queryCursor, 'c5o1.next.signature')
  assert.equal(next.keyword, '肖')
  assert.equal('querySnapshot' in next, false)
  assert.equal('queryCutoffTimestamp' in next, false)
  assert.equal('snapshotMaxOrderId' in next, false)
})

ok('分页投影解析当前页和下一页游标', () => {
  assert.deepEqual(salesOrderPaginationFromPartition(listPartition), {
    page: 2,
    pageSize: 20,
    current: 'c5o1.current.signature',
    next: 'c5o1.next.signature',
    hasMore: true
  })
})

ok('not_ready 时 null、0 或其他值都不得展示为有效经营金额', () => {
  const record = { economicsDataStatus: 'not_ready' }
  assert.equal(isUnavailableSalesOrderEconomics(record, null), true)
  assert.equal(isUnavailableSalesOrderEconomics(record, ''), true)
  assert.equal(isUnavailableSalesOrderEconomics(record, 0), true)
  assert.equal(isUnavailableSalesOrderEconomics(record, 123.45), true)
  assert.equal(isUnavailableSalesOrderEconomics({ economicsDataStatus: 'ready' }, null), false)
})

ok('详情 A/B 乱序时只有当前序号且当前订单一致才能应用', () => {
  assert.equal(shouldApplySalesOrderDetailResponse({
    requestSequence: 1,
    currentSequence: 2,
    requestedOrderId: 'A',
    activeOrderId: 'B',
    isOpen: true
  }), false)
  assert.equal(shouldApplySalesOrderDetailResponse({
    requestSequence: 2,
    currentSequence: 2,
    requestedOrderId: 'B',
    activeOrderId: 'B',
    isOpen: true
  }), true)
  assert.equal(shouldApplySalesOrderDetailResponse({
    requestSequence: 2,
    currentSequence: 2,
    requestedOrderId: 'B',
    activeOrderId: 'B',
    isOpen: false
  }), false)
})

ok('页面使用列表/详情独立序号，六类记录走已安装只读 handler', () => {
  const source = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
  assert.match(source, /let salesQuerySequence = 0/)
  assert.match(source, /let salesDetailSequence = 0/)
  assert.match(source, /shouldApplySalesOrderDetailResponse/)
  assert.match(source, /availableTabs/)
  assert.match(source, /requestAction\('query-order-center-records'/)
  assert.match(source, /key: 'card_operation'/)
  assert.match(source, /countsByType/)
  assert.doesNotMatch(source, /requestAction\('save-order-center-query-settings'/)
})

ok('销售页启用默认关闭的顺序分页，不改变其他列表默认行为', () => {
  const pagination = readFileSync(new URL('../../../前端代码/cashier-v3/src/components/common/TablePagination.vue', import.meta.url), 'utf8')
  const view = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
  assert.match(pagination, /sequential:[\s\S]*?default: false/)
  assert.match(view, /:sequential="activeTabKey === 'sales'"/)
  assert.match(view, /:has-more="hasMore"/)
})

ok('订单列表中的真实会员可打开既有会员详情，游客保持普通文本', () => {
  const view = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
  assert.match(view, /function memberDetailPayload\(record = \{\}\)/)
  assert.match(view, /Number\(memberId\) <= 0/)
  assert.match(view, /fieldItem\.key === 'member_name' && canOpenMemberDetail\(record\)/)
  assert.match(view, /cashier-v3:open-member-detail/)
  assert.match(view, /查看\$\{displayRecordField\(record, fieldItem\.key\)\}的会员详情/)
})

ok('服务记录列表展示结账事实中的手工费、服务业绩类型与业绩比例', () => {
  const view = readFileSync(new URL('../../../前端代码/cashier-v3/src/views/OrderCenterView.vue', import.meta.url), 'utf8')
  assert.match(view, /field\('labor_fee_amount', '手工费', 'money'\)/)
  assert.match(view, /field\('labor_performance_type', '服务业绩类型'\)/)
  assert.match(view, /field\('labor_performance_ratio', '业绩比例'\)/)
  assert.match(view, /labor_fee_amount: \['laborFeeAmount', 'manualLaborFeeAmount'\]/)
  assert.match(view, /labor_performance_type: \['laborPerformanceTypeLabel', 'laborPerformanceType'\]/)
  assert.match(view, /labor_performance_ratio: \['laborPerformanceRatio'\]/)
})

process.stdout.write(`ASSERT_PASSED=${passed}\n`)
process.stdout.write('ASSERT_FAILED=0\n')
process.stdout.write('C5_O1_FRONTEND_PROJECTION=PASS\n')
