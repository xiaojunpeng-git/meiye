// Backend status codes remain the source of truth for filtering and actions.
// This projection is only for human-readable inventory UI labels.
const inventoryStatusLabels = {
  SETTLED: '已结算',
  CONFIRMED: '已确认',
  DRAFT: '草稿',
  APPLIED: '申请中',
  PARTIAL: '部分履约',
  DONE: '已完成',
  DISPATCHED: '在途',
  RECEIVED: '已收货',
  CANCELLED: '已取消',
  TERMINATED: '已终止剩余请货',
  REVERSED: '已作废',
  VOIDED: '已作废',
  SUCCEEDED: '成功',
  FAILED: '失败',
  PROCESSING: '处理中',
  ACTIVE: '有效',
  INACTIVE: '已停用',
  NORMAL: '正常'
}

export function inventoryStatusLabel(value, fallback = '-') {
  if (value === null || value === undefined || value === '') return fallback
  const raw = String(value).trim()
  return inventoryStatusLabels[raw.toUpperCase()] || raw
}

export { inventoryStatusLabels }
