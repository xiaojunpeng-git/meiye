/**
 * Both entry points render the same document lines. Only the authenticated API
 * boundary differs; capture the scope before async reads so a late response
 * cannot use a newly selected warehouse or fall back to the other terminal.
 */
export function createDocumentDetailReader({ mode, hqLocationId, storeId, warehouseSelection = 'HQ:0', storeApi, platformApi }) {
  if (!['store', 'platform'].includes(mode)) throw new Error('库存入口类型不合法。')
  const hqScope = { hq_location_id: Number(hqLocationId) || 0 }
  const [subject, selectedStoreId] = String(warehouseSelection).split(':')
  // 单据原仓优先于当前总部筛选；平台选中的门店仓只缩小后台数据范围，不能退回总部仓或门店会话读取。
  const documentScope = row => subject === 'STORE'
    ? { subject: 'STORE', storeId: Number(selectedStoreId) || 0, locationId: Number(row.location_id) || 0 }
    : { hq_location_id: Number(row.location_id) || hqScope.hq_location_id }
  const readers = mode === 'platform' ? {
    inbound: row => platformApi.hqInboundDetail(row.source_id || row.id, documentScope(row)),
    inboundTrace: row => platformApi.hqInboundOutboundDetails(row.source_id || row.id, documentScope(row)),
    outbound: row => platformApi.hqOutboundDetail(row.source_id || row.id, documentScope(row)),
    request: row => platformApi.hqRequestDetail(row.id, hqScope),
    transfer: row => platformApi.hqCrossTransferDetail(row.id, hqScope),
    // 使用列表行原仓身份，异步换筛选器也不能把总部领用单按门店仓读取。
    usage: row => platformApi.salonUsageDetail(row.id, Number(row.store_id) > 0
      ? { store_id: Number(row.store_id) }
      : { hq_location_id: Number(row.location_id) || 0 }),
  } : {
    inbound: row => storeApi.inboundDetail(row.source_id || row.id),
    inboundTrace: row => storeApi.inboundOutboundDetails(row.source_id || row.id),
    outbound: row => storeApi.outboundDetail(row.source_id || row.id),
    request: row => storeApi.requestDetail(row.id),
    transfer: row => storeApi.crossTransferDetail(row.id),
    usage: row => storeApi.salonUsageDetail(row.id),
  }
  return (page, row) => {
    if (!Object.prototype.hasOwnProperty.call(readers, page)) throw new Error('不支持的库存单据类型。')
    return readers[page](row)
  }
}
