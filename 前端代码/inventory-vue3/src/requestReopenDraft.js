/** 门店列表可看见本店供货的他店单据；复制只能针对本店请货仓，平台仍由详情与新建接口校验权限。 */
export function canReopenRequestForScope(row, mode, storeLocations) {
  if (!row || Number(row.id) <= 0) return false
  return mode === 'platform' || (mode === 'store' && Array.isArray(storeLocations) && storeLocations.some((location) => Number(location.id) === Number(row.location_id)))
}

/** 原主体不在当前可选目录时留空，不能悄悄改成目录中的第一项。 */
export function availableRequestCopySelection(sourceKey, options) {
  return Array.isArray(options) && options.some((option) => option.key === sourceKey) ? sourceKey : ''
}

/** 正常或作废请货单仅作为新建表单的可编辑初值；不继承原单身份、日期、人员或履约事实。 */
export function requestReopenDraft(detail) {
  const document = detail?.document
  const sourceLines = detail?.lines
  if (!document) throw new Error('原请货单详情不可用，请返回列表刷新。')
  // 旧规格可能已经失效；只把仍有完整身份和数量的明细放进新建表单。
  const lines = (Array.isArray(sourceLines) ? sourceLines : []).map((line) => ({
    product_id: Number(line.product_id), sku_id: Number(line.sku_id), sku_unique: String(line.sku_unique || ''),
    product_name: String(line.product_name_snapshot || ''), sku_name: String(line.sku_name_snapshot || ''),
    stock_unit: String(line.stock_unit_snapshot || ''), barcode: String(line.barcode_snapshot || ''),
    quantity: String(line.quantity || ''), detail_remark: String(line.detail_remark || ''),
    available_quantity: '-', reference_unit_cost: '-'
  })).filter((line) => line.product_id > 0 && line.sku_id > 0 && line.sku_unique && Number(line.quantity) > 0)
  const requestType = String(document.request_party_type || 'STORE').toUpperCase()
  const supplyType = String(document.supply_party_type || '').toUpperCase()
  return {
    requestParty: `${requestType}:${requestType === 'HQ' ? Number(document.location_id) : Number(document.request_party_id || document.store_id)}`,
    supplyParty: `${supplyType}:${supplyType === 'HQ' ? Number(document.supply_location_id) : Number(document.supply_party_id)}`,
    remark: String(document.remark || ''), lines
  }
}
