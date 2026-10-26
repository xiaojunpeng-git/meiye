function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize)
  if (value && typeof value === 'object') {
    return Object.keys(value)
      .filter((key) => value[key] !== undefined)
      .sort()
      .reduce((record, key) => {
        record[key] = canonicalize(value[key])
        return record
      }, {})
  }
  if (typeof value === 'number' && !Number.isFinite(value)) return String(value)
  return value
}

export function cashierDraftCommandFingerprint(payload = {}) {
  return JSON.stringify(canonicalize(payload))
}

const STORAGE_KEY = 'cashier-v3:draft-command-recovery:v1'
let sharedRecovery = null

function clonePlain(value) {
  return JSON.parse(JSON.stringify(canonicalize(value)))
}

function browserSessionStorage() {
  try {
    return typeof window !== 'undefined' && window.sessionStorage ? window.sessionStorage : null
  } catch (_error) {
    return null
  }
}

/**
 * A1 草稿命令没有独立结果查询接口，结果未知时只能按 manifest 合同使用
 * “同 action + 同 payload + 同 contexts + 同幂等键”重试。
 *
 * 未决请求保留在当前浏览器标签的 sessionStorage，路由卸载／重新进入不会
 * 丢失原键。一个 workspace 范围同一时间只允许一个未决草稿请求；点击原操作
 * 时始终重放第一次保存的 payload，禁止用新 token、版本或数量偷换请求内容。
 */
export function createCashierV3DraftCommandRecovery(createIdempotencyKey, options = {}) {
  if (typeof createIdempotencyKey !== 'function') {
    throw new TypeError('createIdempotencyKey must be a function')
  }

  const unresolved = new Map()
  const storage = options.storage === undefined ? browserSessionStorage() : options.storage
  const storageKey = String(options.storageKey || STORAGE_KEY)

  function publicTicket(ticket, extra = {}) {
    return {
      ...clonePlain(ticket),
      ...extra
    }
  }

  function persist() {
    if (!storage || typeof storage.setItem !== 'function') return
    try {
      storage.setItem(storageKey, JSON.stringify([...unresolved.values()]))
    } catch (_error) {
      // 浏览器禁用存储时仍保留内存级同键恢复，不把存储异常变成业务写失败。
    }
  }

  function restore() {
    if (!storage || typeof storage.getItem !== 'function') return
    try {
      const records = JSON.parse(storage.getItem(storageKey) || '[]')
      for (const ticket of Array.isArray(records) ? records : []) {
        if (!ticket
          || typeof ticket !== 'object'
          || !String(ticket.operationKey || '').trim()
          || !String(ticket.scopeKey || '').trim()
          || !String(ticket.action || '').trim()
          || !String(ticket.idempotencyKey || '').trim()
          || !String(ticket.fingerprint || '').trim()
          || !ticket.payload
          || typeof ticket.payload !== 'object') continue
        unresolved.set(String(ticket.operationKey), clonePlain(ticket))
      }
    } catch (_error) {
      // 损坏的本地恢复记录不得伪造新请求；忽略后继续使用当前内存。
    }
  }

  restore()

  const recovery = {
    begin({
      operationKey,
      scopeKey,
      action,
      payload = {},
      idempotencyPrefix = 'CASHIER_DRAFT'
    } = {}) {
      const normalizedOperationKey = String(operationKey || '').trim()
      const normalizedScopeKey = String(scopeKey || '').trim()
      const normalizedAction = String(action || '').trim()
      if (!normalizedOperationKey) {
        throw new TypeError('operationKey is required')
      }
      if (!normalizedScopeKey) {
        throw new TypeError('scopeKey is required')
      }
      if (!normalizedAction) {
        throw new TypeError('action is required')
      }
      const normalizedPayload = clonePlain(payload)
      const fingerprint = cashierDraftCommandFingerprint({
        action: normalizedAction,
        payload: normalizedPayload
      })
      const previous = unresolved.get(normalizedOperationKey)
      if (previous) {
        return publicTicket(previous, {
          accepted: true,
          reused: true,
          requestedPayloadChanged: previous.fingerprint !== fingerprint
        })
      }

      const pendingInScope = [...unresolved.values()].find((ticket) => ticket.scopeKey === normalizedScopeKey)
      if (pendingInScope) {
        return publicTicket(pendingInScope, {
          accepted: false,
          reused: false,
          reason: 'scope_has_unresolved_command',
          requestedOperationKey: normalizedOperationKey
        })
      }

      const idempotencyKey = String(createIdempotencyKey(idempotencyPrefix) || '').trim()
      if (!idempotencyKey) {
        throw new TypeError('idempotencyKey is required')
      }
      const ticket = {
        accepted: true,
        reused: false,
        operationKey: normalizedOperationKey,
        scopeKey: normalizedScopeKey,
        action: normalizedAction,
        idempotencyKey,
        fingerprint,
        payload: normalizedPayload
      }
      unresolved.set(normalizedOperationKey, ticket)
      persist()
      return publicTicket(ticket)
    },

    settle(ticket, status) {
      if (!ticket?.accepted) return
      const current = unresolved.get(ticket.operationKey)
      if (!current
        || current.idempotencyKey !== ticket.idempotencyKey
        || current.fingerprint !== ticket.fingerprint) return

      // 只有服务端已经明确判定成功／失败／冲突，才能释放原键。未知、空响应、
      // 本地异常或 pending integration 都继续保留原键。
      if (['success', 'succeeded', 'failed', 'conflict'].includes(String(status || ''))) {
        unresolved.delete(ticket.operationKey)
        persist()
      }
    },

    clear() {
      unresolved.clear()
      if (storage && typeof storage.removeItem === 'function') {
        try {
          storage.removeItem(storageKey)
        } catch (_error) {
          // 与 persist 相同，存储异常不能影响业务恢复仓的内存状态。
        }
      }
    },

    pending(operationKey) {
      const ticket = unresolved.get(String(operationKey || '').trim())
      return ticket ? publicTicket(ticket) : null
    },

    pendingForScope(scopeKey) {
      const normalizedScopeKey = String(scopeKey || '').trim()
      const ticket = [...unresolved.values()].find((row) => row.scopeKey === normalizedScopeKey)
      return ticket ? publicTicket(ticket) : null
    }
  }
  return recovery
}

export function useCashierV3DraftCommandRecovery(createIdempotencyKey) {
  if (!sharedRecovery) {
    sharedRecovery = createCashierV3DraftCommandRecovery(createIdempotencyKey)
  }
  return sharedRecovery
}
