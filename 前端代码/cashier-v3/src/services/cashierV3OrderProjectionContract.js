const ORDER_CONTRACT_VERSION = 'cashier-v3.order-center.v3'

function isRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

export function unwrapCashierV3OrderEnvelope(result) {
  if (!isRecord(result)) return null
  if (isRecord(result.data) && isRecord(result.data.result) && isRecord(result.data.data)) {
    return result.data
  }
  return result
}

export function salesOrderProjectionFromResult(result) {
  const envelope = unwrapCashierV3OrderEnvelope(result)
  if (!envelope || String(envelope.result?.status || envelope.status || '') !== 'success') return null
  const partition = envelope.data?.orderCenter
  if (!isRecord(partition) || partition.contractVersion !== ORDER_CONTRACT_VERSION) return null
  return partition
}

export function mergeSalesOrderCenterProjection(current, incoming) {
  const base = isRecord(current) ? current : {}
  if (!isRecord(incoming) || incoming.contractVersion !== ORDER_CONTRACT_VERSION) return base
  const merged = { ...base, ...incoming }
  // 页签不再带数量；仅合并当前列表和分页元数据。
  for (const key of ['statusOptionsByType', 'recordsByType', 'pagesByType', 'querySettingsByType']) {
    if (isRecord(base[key]) || isRecord(incoming[key])) {
      merged[key] = {
        ...(isRecord(base[key]) ? base[key] : {}),
        ...(isRecord(incoming[key]) ? incoming[key] : {})
      }
    }
  }
  return merged
}

export function nextSalesOrderQueryWithCursor(query, cursor) {
  const next = isRecord(query) ? { ...query } : {}
  const token = typeof cursor === 'string' ? cursor.trim() : ''
  if (token) next.queryCursor = token
  else delete next.queryCursor
  // 查询模型可能来自旧版路由／导出快照，其中保留的蛇形字段
  // 与新字段具有同一后端语义。写入后回刷首页必须全部清除，
  // 否则会把写入前的签名游标带回服务端，造成“修改成功但页面报错”。
  delete next.query_cursor
  delete next.querySnapshot
  delete next.query_snapshot
  delete next.queryCutoffTimestamp
  delete next.query_cutoff_timestamp
  delete next.snapshotMaxOrderId
  delete next.snapshot_max_order_id
  return next
}

export function salesOrderPaginationFromPartition(partition) {
  const page = isRecord(partition?.pagesByType?.sales) ? partition.pagesByType.sales : {}
  const cursor = isRecord(page.paginationCursor)
    ? page.paginationCursor
    : (isRecord(partition?.paginationCursor) ? partition.paginationCursor : {})
  return {
    page: Math.max(1, Number(page.page ?? partition?.page) || 1),
    pageSize: Math.max(1, Number(page.pageSize ?? partition?.pageSize) || 20),
    current: typeof cursor.current === 'string' ? cursor.current : '',
    next: typeof cursor.next === 'string' ? cursor.next : '',
    hasMore: page.hasMore === true || (page.hasMore === undefined && partition?.hasMore === true)
  }
}

export function shouldApplySalesOrderDetailResponse({
  requestSequence,
  currentSequence,
  requestedOrderId,
  activeOrderId,
  isOpen
} = {}) {
  return Number.isSafeInteger(requestSequence)
    && requestSequence === currentSequence
    && isOpen === true
    && String(requestedOrderId || '') !== ''
    && String(requestedOrderId) === String(activeOrderId || '')
}

export function isUnavailableSalesOrderEconomics(record) {
  return isRecord(record) && record.economicsDataStatus === 'not_ready'
}

export { ORDER_CONTRACT_VERSION }
