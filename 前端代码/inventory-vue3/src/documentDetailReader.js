/**
 * Both entry points render the same document lines. Only the authenticated API
 * boundary differs; capture the scope before async reads so a late response
 * cannot use a newly selected warehouse or fall back to the other terminal.
 */
export function createDocumentDetailReader({ mode, hqLocationId, storeId, warehouseSelection = 'HQ:0', organizationReadMode = false, organizationStoreId = 0, storeApi, platformApi }) {
  if (!['store', 'platform'].includes(mode)) throw new Error('库存入口类型不合法。')
  const hqScope = { hq_location_id: Number(hqLocationId) || 0 }
  const [subject, selectedStoreId] = String(warehouseSelection).split(':')
  // 组织行必须携带服务端解析的有效门店；不能用当前筛选 ID 冒充跨店单据归属。
  const scopedStoreId = row => {
    const id = Number(organizationStoreId || row.store_id)
    if (!Number.isInteger(id) || id <= 0) throw new Error('单据缺少有效的门店归属。')
    return id
  }
  const documentScope = row => organizationReadMode
    ? { subject: 'STORE', storeId: scopedStoreId(row), locationId: Number(row.location_id) || 0 }
    : subject === 'STORE'
    ? { subject: 'STORE', storeId: Number(selectedStoreId) || 0, locationId: Number(row.location_id) || 0 }
    : { hq_location_id: Number(row.location_id) || hqScope.hq_location_id }
  const readers = mode === 'platform' ? {
    inbound: row => platformApi.documentRemarkDetail('inbound', row.source_id || row.id, documentScope(row)),
    inboundTrace: row => platformApi.hqInboundOutboundDetails(row.source_id || row.id, documentScope(row)),
    outbound: row => platformApi.documentRemarkDetail('outbound', row.source_id || row.id, documentScope(row)),
    request: row => platformApi.documentRemarkDetail('request', row.id, organizationReadMode ? { store_id: scopedStoreId(row) } : hqScope),
    transfer: row => platformApi.documentRemarkDetail('transfer', row.id, organizationReadMode ? { store_id: scopedStoreId(row) } : hqScope),
    // 使用列表行原仓身份，异步换筛选器也不能把总部领用单按门店仓读取。
    usage: row => platformApi.documentRemarkDetail('usage', row.id, organizationReadMode
      ? { store_id: scopedStoreId(row) }
      : Number(row.store_id) > 0
      ? { store_id: Number(row.store_id) }
      : { hq_location_id: Number(row.location_id) || 0 }),
  } : {
    inbound: row => storeApi.documentRemarkDetail('inbound', row.source_id || row.id),
    inboundTrace: row => storeApi.inboundOutboundDetails(row.source_id || row.id),
    outbound: row => storeApi.documentRemarkDetail('outbound', row.source_id || row.id),
    request: row => storeApi.documentRemarkDetail('request', row.id),
    transfer: row => storeApi.documentRemarkDetail('transfer', row.id),
    usage: row => storeApi.documentRemarkDetail('usage', row.id),
  }
  return (page, row) => {
    if (!Object.prototype.hasOwnProperty.call(readers, page)) throw new Error('不支持的库存单据类型。')
    const detail = readers[page](row)
    if (mode !== 'platform' || !organizationReadMode) return detail
    // 组织/门店选择只借用门店读模型；详情中的原岗位操作能力不得在平台跨店视图呈现。
    return Promise.resolve(detail).then(result => ({
      ...result, remark_editable: false,
      can_edit: false, can_delete: false, can_cancel: false, can_terminate: false,
      can_transfer: false, can_dispatch: false, can_withdraw: false,
      can_receive: false, can_reverse: false, can_void: false,
      document: result?.document ? { ...result.document, can_void: false } : result?.document
    }))
  }
}
