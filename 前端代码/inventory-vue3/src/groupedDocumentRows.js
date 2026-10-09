/** 门店单据列表共用一个商品表头；各页字段仍与其权威详情契约对应。 */
export const groupedDetailColumns = {
  inbound: ['商品', '规格', '批次号', '生产日期', '到期日', '入库数量', '库存单位', '入库单价', '入库金额', '明细备注'],
  outbound: ['商品', '规格', '扣减批次', '生产日期', '到期日', '出库数量', '库存单位', '成本单价', '成本金额', '明细备注'],
  request: ['商品', '规格', '申请数量', '库存单位', '明细备注'],
  transfer: ['商品', '规格', '调拨数量', '库存单位', '批次分配', '状态', '明细备注'],
  usage: ['商品', '规格', '领用/退回数量', '已退回', '可退回', '库存单位', '明细备注']
}

export function groupedDetailKey(row) {
  return String(row.source_id || row.id)
}

/** 只格式化展示；成本计算和保存继续使用服务端返回的分。 */
export function groupedDisplayMoney(value) {
  if (value === null || value === undefined || value === '') return '-'
  const cents = Number(value)
  if (!Number.isFinite(cents)) return '-'
  const yuan = Math.sign(cents) * Math.round(Math.abs(cents) / 100)
  return `¥${yuan.toLocaleString('zh-CN')}`
}

function displayText(value, fallback = '-') {
  return value === null || value === undefined || value === '' ? fallback : String(value)
}

function transferQuantity(units, scale = 0) {
  const precision = Number(scale || 0)
  const quantity = Number(units || 0) / (10 ** precision)
  return Number.isFinite(quantity) ? String(Number(quantity.toFixed(precision))) : '-'
}

/** 商品行只映射详情接口字段，不从主单金额、状态或备注反推明细事实。 */
export function groupedLineCells(page, line, detail) {
  if (page === 'inbound' || page === 'outbound') return [
    displayText(line.product_name), displayText(line.sku_name, '默认规格'), displayText(line.batch_no),
    displayText(line.manufactured_date), displayText(line.expire_date), displayText(line.quantity),
    displayText(line.stock_unit), groupedDisplayMoney(line.unit_cost_cents),
    groupedDisplayMoney(line.cost_amount_cents), displayText(line.detail_remark)
  ]
  if (page === 'request') return [
    displayText(line.product_name_snapshot), displayText(line.sku_name_snapshot, '默认规格'),
    displayText(line.quantity), displayText(line.stock_unit_snapshot), displayText(line.detail_remark)
  ]
  if (page === 'transfer') return [
    displayText(line.product_name_snapshot), displayText(line.sku_name_snapshot, '默认规格'),
    transferQuantity(line.requested_quantity_units, line.quantity_scale), displayText(line.stock_unit_snapshot),
    `${Array.isArray(line.allocations) ? line.allocations.length : 0} 个批次`,
    displayText(detail?.status_name || detail?.document_status), displayText(line.detail_remark)
  ]
  if (page === 'usage') return [
    displayText(line.product_name), displayText(line.sku_name, '默认规格'), displayText(line.quantity),
    displayText(line.returned_quantity), displayText(line.returnable_quantity),
    displayText(line.stock_unit), displayText(line.detail_remark)
  ]
  return []
}
