const DEFAULT_PREFIX = '/storeapi/product/inventory'
const TIMEOUT_MS = 30000
let embeddedSessionToken = ''

export function setInventoryEmbeddedSessionToken(token) {
  embeddedSessionToken = String(token || '').trim()
}

function tokenFromBrowser(browserWindow) {
  // Embedded platform/store entries receive a scoped session from the host
  // window. Prefer it over any stale localStorage token left by a previous
  // standalone store/cashier session; otherwise the iframe can authenticate
  // against the wrong guard and report a misleading login-state error.
  if (embeddedSessionToken) return embeddedSessionToken
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

export function inventoryCommandIdempotencyKey() {
  const random = globalThis.crypto?.randomUUID?.()
  return random ? `inventory-uq-${random}` : `inventory-uq-${Date.now()}-${Math.random().toString(36).slice(2, 12)}`
}

export function createInventoryApi(options = {}) {
  const browserWindow = options.windowRef || (typeof window !== 'undefined' ? window : null)
  const fetchImpl = options.fetchImpl || browserWindow?.fetch?.bind(browserWindow) || globalThis.fetch?.bind(globalThis)
  if (typeof fetchImpl !== 'function') throw new Error('当前浏览器不支持库存网络请求。')
  const prefix = String(options.prefix || import.meta.env?.VITE_INVENTORY_API_PREFIX || DEFAULT_PREFIX).replace(/\/$/, '')
  const apiRootPrefix = String(options.apiRootPrefix || prefix.replace(/\/product\/inventory$/, '')).replace(/\/$/, '')

  async function request(path, requestOptions = {}, requestPrefix = prefix) {
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
      const response = await fetchImpl(`${requestPrefix}${rawPath}${query}`, {
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

  async function download(path) {
    const token = tokenFromBrowser(browserWindow)
    const response = await fetchImpl(`${prefix}${path}`, {
      method: 'GET', credentials: 'include', cache: 'no-store',
      headers: { Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) }
    })
    const contentType = String(response.headers?.get?.('content-type') || '').toLowerCase()
    // Store middleware can return a 200 JSON envelope for an expired session.
    // Never save that envelope with an .xlsx extension.
    if (!response.ok || contentType.includes('json')) {
      const raw = await response.text()
      let body = null
      try { body = raw ? JSON.parse(raw) : null } catch (_) {}
      throw new Error(messageOf(body, response.status === 401 || response.status === 403 ? '登录已失效，请重新登录。' : '模板下载失败。'))
    }
    if (!contentType.includes('spreadsheetml.sheet')) throw new Error('模板服务未返回有效的 Excel 文件。')
    return response.blob()
  }

  async function uploadImportFile(file) {
    if (!(file instanceof Blob)) throw new Error('请选择xlsx文件。')
    const token = tokenFromBrowser(browserWindow)
    const body = new FormData()
    body.append('file', file, file.name || 'inventory-import.xlsx')
    const response = await fetchImpl(`${prefix}/v3/import/upload`, {
      method: 'POST', credentials: 'include', cache: 'no-store',
      headers: { Accept: 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) }, body
    })
    const raw = await response.text()
    let payload
    try { payload = raw ? JSON.parse(raw) : null } catch (_) { throw new Error('上传服务返回了非JSON数据。') }
    if (!response.ok) throw new Error(messageOf(payload, '上传失败。'))
    const data = normalizeEnvelope(payload)
    if (!data?.src) throw new Error('上传服务未返回文件地址。')
    return data
  }

  const catalogPath = options.catalogPath || '/v3/catalog'
  const listPaths = Object.freeze({
    inbound: '/v3/movement?kind=inbound',
    outbound: '/v3/movement?kind=outbound',
    count: '/v3/count',
    stock: '/v3/batch-stock',
    productSummary: '/v3/product-summary',
    movement: '/v3/movement?kind=movement',
    request: '/v3/request',
    transfer: '/v3/cross-transfer',
    usage: '/v3/salon-usage',
    import: '/v3/import',
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
    dashboard() { return request('/v3/dashboard') },
    productDetail(productId) { return request(`/v3/product-summary/${Number(productId)}/detail`) },
    unifiedBatchStock(query = {}) { return request('/v3/unified-query/batch-stock', { query }) },
    unifiedOperationalQuery(query = {}) { return request('/v3/unified-query/operational', { query }) },
    unifiedQueryCapabilities(query = {}) { return request('/v3/unified-query/capabilities', { query }) },
    unifiedQueryCommand(body = {}) {
      const idempotencyKey = String(body.idempotencyKey || body.idempotency_key || '').trim() || inventoryCommandIdempotencyKey()
      return request('/v3/unified-query/commands', { method: 'POST', body: { ...body, idempotencyKey } })
    },
    unifiedQueryExportTask(taskNo, query = {}) { return request(`/v3/unified-query/export-task/${encodeURIComponent(String(taskNo || ''))}`, { query }) },
    createWarehouse(body) { return request('/v3/locations', { method: 'POST', body }) },
    listHqInbound(query = {}) { return request('/v3/hq/inbound', { query }) },
    hqInboundDetail(id, query = {}) { return request(`/v3/hq/inbound/${encodeURIComponent(String(id || ''))}/detail`, { query }) },
    hqInboundOutboundDetails(id, query = {}) { return request(`/v3/hq/inbound/${encodeURIComponent(String(id || ''))}/outbound-details`, { query }) },
    createHqInbound(body) { return request('/v3/hq/inbound', { method: 'POST', body }) },
    voidHqInbound(id, body) { return request(`/v3/hq/inbound/${encodeURIComponent(String(id || ''))}/void`, { method: 'POST', body }) },
    listHqOutbound(query = {}) { return request('/v3/hq/outbound', { query }) },
    hqOutboundDetail(id, query = {}) { return request(`/v3/hq/outbound/${encodeURIComponent(String(id || ''))}/detail`, { query }) },
    createHqOutbound(body) { return request('/v3/hq/outbound', { method: 'POST', body }) },
    voidHqOutbound(id, body) { return request(`/v3/hq/outbound/${encodeURIComponent(String(id || ''))}/void`, { method: 'POST', body }) },
    confirmHqCount(body) { return request('/v3/hq/count/confirm', { method: 'POST', body }) },
    hqCountDetail(id, query = {}) { return request(`/v3/hq/count/${Number(id)}/detail`, { query }) },
    hqRequestCounterparties(query = {}) { return request('/v3/hq/request/counterparties', { query }) },
    hqRequestParties(query = {}) { return request('/v3/hq/request/counterparties', { query: { ...query, directory: 'request_party' } }) },
    hqRequestRequester(query = {}) { return request('/v3/hq/request/requester', { query }) },
    applyHqRequest(body) { return request('/v3/hq/request/apply', { method: 'POST', body }) },
    hqRequestDetail(id, query = {}) { return request(`/v3/hq/request/${Number(id)}/detail`, { query }) },
    cancelHqRequest(id, body) { return request(`/v3/hq/request/${Number(id)}/cancel`, { method: 'POST', body }) },
    terminateHqRequest(id, body) { return request(`/v3/hq/request/${Number(id)}/terminate`, { method: 'POST', body }) },
    hqProductSummary(query = {}) { return request('/v3/hq/product-summary', { query }) },
    hqProductDetail(productId, query = {}) { return request(`/v3/hq/product-summary/${Number(productId)}/detail`, { query }) },
    listHqCrossTransfers(query = {}) { return request('/v3/hq/cross-transfer', { query }) },
    hqCrossTransferCounterparties(query = {}) { return request('/v3/hq/cross-transfer/counterparties', { query }) },
    hqCrossTransferIncomingRequests(query = {}) { return request('/v3/hq/cross-transfer/incoming-requests', { query }) },
    hqCrossTransferStaffs(query = {}) { return request('/v3/hq/cross-transfer/transfer-staffs', { query }) },
    hqCrossTransferDetail(id, query = {}) { return request(`/v3/hq/cross-transfer/${Number(id)}/detail`, { query }) },
    createHqCrossTransfer(body) { return request('/v3/hq/cross-transfer', { method: 'POST', body }) },
    dispatchHqCrossTransfer(id, body) { return request(`/v3/hq/cross-transfer/${Number(id)}/dispatch`, { method: 'POST', body }) },
    receiveHqCrossTransfer(id, body) { return request(`/v3/hq/cross-transfer/${Number(id)}/receive`, { method: 'POST', body }) },
    cancelHqCrossTransfer(id, body) { return request(`/v3/hq/cross-transfer/${Number(id)}/cancel`, { method: 'POST', body }) },
    reverseHqCrossTransfer(id, body) { return request(`/v3/hq/cross-transfer/${Number(id)}/reverse`, { method: 'POST', body }) },
    searchCatalog(query = {}) { return request(catalogPath, { query }) },
    findCatalogByBarcode(query = {}) { return request(`${catalogPath}/barcode`, { query }) },
    downloadImportTemplate(direction, storeId = 0, storeIds = []) { const ids = Array.isArray(storeIds) && storeIds.length ? storeIds : (Number(storeId) > 0 ? [storeId] : []); return download(`/v3/import/template?direction=${encodeURIComponent(String(direction || ''))}${ids.length ? `&store_ids=${encodeURIComponent(ids.join(','))}` : ''}`) },
    uploadImportFile(file) { return uploadImportFile(file) },
    submitImport(body) { return request('/v3/import', { method: 'POST', body }) },
    importDetail(id) { return request(`/v3/import/${Number(id)}/detail`) },
    createInbound(body) { return request('/v3/inbound', { method: 'POST', body }) },
    inboundDetail(id) { return request(`/v3/inbound/${encodeURIComponent(String(id || ''))}/detail`) },
    inboundOutboundDetails(id) { return request(`/v3/inbound/${encodeURIComponent(String(id || ''))}/outbound-details`) },
    voidInbound(id, body) { return request(`/v3/inbound/${encodeURIComponent(String(id || ''))}/void`, { method: 'POST', body }) },
    createOutbound(body) { return request('/v3/outbound', { method: 'POST', body }) },
    voidOutbound(id, body) { return request(`/v3/outbound/${encodeURIComponent(String(id || ''))}/void`, { method: 'POST', body }) },
    confirmCount(body) { return request('/v3/count/confirm', { method: 'POST', body }) },
    applyRequest(body) { return request('/v3/request/apply', { method: 'POST', body }) },
    requestCounterparties() { return request('/v3/request/counterparties') },
    requestRequester() { return request('/v3/request/requester') },
    requestDetail(id) { return request(`/v3/request/${Number(id)}/detail`) },
    updateRequest(id, body) { return request(`/v3/request/${Number(id)}/update`, { method: 'POST', body }) },
    cancelRequest(id, body) { return request(`/v3/request/cancel/${Number(id)}`, { method: 'POST', body }) },
    terminateRequest(id, body) { return request(`/v3/request/terminate/${Number(id)}`, { method: 'POST', body }) },
    crossTransferCounterparties() { return request('/v3/cross-transfer/counterparties') },
    crossTransferIncomingRequests() { return request('/v3/cross-transfer/incoming-requests') },
    crossTransferDetail(id) { return request(`/v3/cross-transfer/${Number(id)}/detail`) },
    createCrossTransfer(body) { return request('/v3/cross-transfer', { method: 'POST', body }) },
    dispatchCrossTransfer(id) { return request(`/v3/cross-transfer/${Number(id)}/dispatch`, { method: 'POST', body: {} }) },
    receiveCrossTransfer(id) { return request(`/v3/cross-transfer/${Number(id)}/receive`, { method: 'POST', body: {} }) },
    cancelCrossTransfer(id) { return request(`/v3/cross-transfer/${Number(id)}/cancel`, { method: 'POST', body: {} }) },
    reverseCrossTransfer(id, body) { return request(`/v3/cross-transfer/${Number(id)}/reverse`, { method: 'POST', body }) },
    salonUsageProjects(query = {}) { return request('/v3/salon-usage/projects', { query }) },
    issueSalonUsage(body) { return request('/v3/salon-usage/issue', { method: 'POST', body }) },
    returnSalonUsage(body) { return request('/v3/salon-usage/return', { method: 'POST', body }) },
    recipeInfo(id) { return request(`/recipe/info/${Number(id)}`) },
    saveRecipe(id, body) { return request(`/recipe/save/${Number(id) || 0}`, { method: 'POST', body }) },
    setRecipeStatus(id, status) { return request(`/recipe/status/${Number(id)}`, { method: 'POST', body: { status: Number(status) === 0 ? 0 : 1 } }) },
    deleteRecipe(id) { return request(`/recipe/${Number(id)}`, { method: 'DELETE' }) },
    searchRecipeCatalog(query = {}) {
      const kind = String(query.kind || '') === 'project' ? 'project' : 'consumable'
      const chooseType = kind === 'project' ? 96 : 95
      return request('/product/product/list', {
        query: {
          page: Number(query.page) || 1,
          limit: Number(query.limit) || 12,
          store_name: String(query.keyword || '').trim(),
          choose_type: chooseType,
          product_type: kind === 'project' ? 6 : 0,
          is_supplier: 0
        }
      }, apiRootPrefix)
    },
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
    catalogPath: options.catalogPath || '/v3/hq/catalog',
    listPaths: {
      outbound: '/out/order',
      count: '/count/list',
      movement: '/detail/list',
      request: '/v3/hq/request',
      usage: '/salon/usage/list',
      stock: '/v3/batch-stock',
      platformLocations: '/v3/locations',
      warehouseSettings: '/v3/locations',
      recipe: '/recipe/list',
      ...(options.listPaths || {})
    }
  })
}

export const platformInventoryApi = typeof window === 'undefined' ? null : createPlatformInventoryApi()
