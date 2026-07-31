const DEFAULT_PREFIX = '/storeapi/product/inventory'
const TIMEOUT_MS = 30000

function tokenFromBrowser(browserWindow) {
  try {
    return String(browserWindow?.localStorage?.getItem('token') || '').trim()
  } catch (_) {
    return ''
  }
}

function messageOf(body, fallback) {
  const message = body && typeof body === 'object' ? String(body.msg || body.message || '').trim() : ''
  const businessMessages = {
    inventory_manual_inbound_idempotency_conflict: '本次入库内容与已提交记录不一致，请刷新后重新操作。',
    inventory_manual_outbound_stock_insufficient: '当前库存不足，请核对出库数量后重试。',
    inventory_salon_usage_date_invalid: '院装业务日期格式不正确，请重新选择日期。',
    inventory_salon_usage_project_not_found: '所选项目不存在、已停用或不属于当前门店。',
    inventory_salon_usage_return_exceeds_issue: '退回数量不能超过原领用数量。',
    inventory_batch_transfer_input_invalid: '调拨信息不完整，请检查调入仓和明细。',
    inventory_stock_request_line_invalid: '请货明细不合法，请检查商品和数量。',
    inventory_platform_warehouse_idempotency_conflict: '本次建仓内容与已提交记录不一致，请刷新后重新操作。'
  }
  return businessMessages[message] || message || fallback
}

function normalizeEnvelope(body) {
  if (!body || typeof body !== 'object') throw new Error('库存服务返回了无效数据。')
  const status = Number(body.status ?? 200)
  if (status !== 200) throw new Error(messageOf(body, '库存服务拒绝了本次请求。'))
  return body.data
}

function inventoryCommandIdempotencyKey() {
  const random = globalThis.crypto?.randomUUID?.()
  return random ? `inventory-uq-${random}` : `inventory-uq-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
}

export function createInventoryApi(options = {}) {
  const browserWindow = options.windowRef || (typeof window !== 'undefined' ? window : null)
  const fetchImpl = options.fetchImpl || browserWindow?.fetch?.bind(browserWindow) || globalThis.fetch?.bind(globalThis)
  if (typeof fetchImpl !== 'function') throw new Error('当前浏览器不支持库存网络请求。')
  const prefix = String(options.prefix || import.meta.env?.VITE_INVENTORY_API_PREFIX || DEFAULT_PREFIX).replace(/\/$/, '')

  async function request(path, requestOptions = {}) {
    const controller = typeof AbortController === 'function' ? new AbortController() : null
    const timer = controller ? setTimeout(() => controller.abort(), TIMEOUT_MS) : null
    const token = tokenFromBrowser(browserWindow)
    const [rawPath, pathQuery = ''] = String(path).split('?', 2)
    const queryParams = new URLSearchParams(pathQuery)
    Object.entries(requestOptions.query || {}).forEach(([key, value]) => {
      if (value === '' || value === null || value === undefined) return
      queryParams.set(key, typeof value === 'object' ? JSON.stringify(value) : String(value))
    })
    const serializedQuery = queryParams.toString()
    const query = serializedQuery ? `?${serializedQuery}` : ''
    try {
      const response = await fetchImpl(`${prefix}${rawPath}${query}`, {
        method: requestOptions.method || 'GET',
        credentials: 'include',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          ...(requestOptions.body ? { 'Content-Type': 'application/json' } : {}),
          ...(token ? { 'Authori-zation': `Bearer ${token}` } : {})
        },
        ...(requestOptions.body ? { body: JSON.stringify(requestOptions.body) } : {}),
        ...(controller ? { signal: controller.signal } : {})
      })
      const text = await response.text()
      let body
      try { body = text ? JSON.parse(text) : null } catch (_) { throw new Error('库存服务返回了非 JSON 数据。') }
      if (!response.ok) throw new Error(messageOf(body, `库存服务请求失败（HTTP ${response.status}）。`))
      return normalizeEnvelope(body)
    } catch (error) {
      if (error?.name === 'AbortError') throw new Error('库存服务响应超时，请先查询单据状态后再重试。')
      throw error instanceof Error ? error : new Error('库存服务请求未完成。')
    } finally {
      if (timer) clearTimeout(timer)
    }
  }

  const listPaths = Object.freeze({
    inbound: '/v3/movement?kind=inbound',
    outbound: '/v3/movement?kind=outbound',
    count: '/v3/count',
    stock: '/v3/batch-stock',
    movement: '/v3/movement?kind=movement',
    request: '/v3/request',
    transfer: '/v3/transfer',
    usage: '/v3/salon-usage',
    storeLocations: '/v3/locations',
    ...(options.listPaths || {})
  })

  return Object.freeze({
    list(page, query = {}) {
      const path = listPaths[page]
      if (!path) throw new Error(`库存页面 ${page} 暂不支持列表查询。`)
      return request(path, { query })
    },
    usageStatistics(query = {}) { return request('/salon/usage/statistics', { query }) },
    movementStatistics(query = {}) { return request('/v3/movement-statistics', { query }) },
    batchAnalysis(query = {}) { return request('/v3/batch-analysis', { query }) },
    unifiedBatchStock(query = {}) { return request('/v3/unified-query/batch-stock', { query }) },
    unifiedQueryCapabilities(query = {}) { return request('/v3/unified-query/capabilities', { query }) },
    unifiedQueryCommand(body = {}) {
      const idempotencyKey = String(body.idempotencyKey || body.idempotency_key || '').trim() || inventoryCommandIdempotencyKey()
      return request('/v3/unified-query/commands', { method: 'POST', body: { ...body, idempotencyKey } })
    },
    unifiedQueryExportTask(taskNo) { return request(`/v3/unified-query/export-task/${encodeURIComponent(String(taskNo || ''))}`) },
    createWarehouse(body) { return request('/v3/locations', { method: 'POST', body }) },
    searchCatalog(query = {}) { return request('/v3/catalog', { query }) },
    createInbound(body) { return request('/v3/inbound', { method: 'POST', body }) },
    createOutbound(body) { return request('/v3/outbound', { method: 'POST', body }) },
    confirmCount(body) { return request('/v3/count/confirm', { method: 'POST', body }) },
    applyRequest(body) { return request('/v3/request/apply', { method: 'POST', body }) },
    cancelRequest(id) { return request(`/v3/request/cancel/${Number(id)}`, { method: 'POST', body: {} }) },
    createTransfer(body) { return request('/v3/transfer', { method: 'POST', body }) },
    issueSalonUsage(body) { return request('/v3/salon-usage/issue', { method: 'POST', body }) },
    returnSalonUsage(body) { return request('/v3/salon-usage/return', { method: 'POST', body }) },
    pendingBadge() { return request('/request/pending_badge') },
    saveInbound(body) { return request('/in/order/add', { method: 'POST', body }) },
    saveOutbound(body) { return request('/out/order/add', { method: 'POST', body }) },
    saveCount(id, body) { return request(`/count/save/${Number(id) || 0}`, { method: 'POST', body }) },
    saveRequest(id, body) { return request(`/request/save/${Number(id) || 0}`, { method: 'POST', body }) },
    confirmRequest(id) { return request(`/request/confirm/${Number(id)}`, { method: 'POST', body: {} }) },
    saveTransfer(id, body) { return request(`/transfer/save/${Number(id) || 0}`, { method: 'POST', body }) },
    confirmTransfer(id) { return request(`/transfer/confirm/${Number(id)}`, { method: 'POST', body: {} }) }
  })
}

export const inventoryApi = typeof window === 'undefined' ? null : createInventoryApi()

export function createPlatformInventoryApi(options = {}) {
  return createInventoryApi({
    ...options,
    prefix: options.prefix || import.meta.env?.VITE_PLATFORM_INVENTORY_API_PREFIX || '/adminapi/product/inventory',
    listPaths: {
      stock: '/v3/batch-stock',
      platformLocations: '/v3/locations',
      warehouseSettings: '/v3/locations',
      recipe: '/recipe/list',
      ...(options.listPaths || {})
    }
  })
}

export const platformInventoryApi = typeof window === 'undefined' ? null : createPlatformInventoryApi()
