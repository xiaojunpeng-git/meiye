const HISTORY_PREFIX = 'cashier-v3:recent-members:v1'
const HISTORY_LIMIT = 10

/**
 * 最近会员只属于当前浏览器中的当前账号、当前门店；缺少身份时不共享记录。
 * 浏览器 origin 自身隔离客户实例，缓存中仅保存会员 ID，不留姓名或手机号。
 */
export function recentCashierMemberKey(account, storeId) {
  const identity = String(account || '').trim()
  const store = Number(storeId)
  return identity && Number.isSafeInteger(store) && store > 0
    ? `${HISTORY_PREFIX}:${encodeURIComponent(identity)}:${store}`
    : ''
}

function validMemberId(value) {
  const id = String(value ?? '').trim()
  return /^[1-9]\d{0,18}$/.test(id) ? id : ''
}

/** 读取并清洗本地 ID 列表；损坏或旧格式缓存不能进入会员选择流程。 */
export function readRecentCashierMemberIds(storage, key) {
  if (!storage || !key) return []
  try {
    const stored = JSON.parse(storage.getItem(key) || '[]')
    if (!Array.isArray(stored)) return []
    return [...new Set(stored.map(validMemberId).filter(Boolean))].slice(0, HISTORY_LIMIT)
  } catch {
    return []
  }
}

/** 成功选客才更新顺序；重复选择置顶，超过 10 人时仅淘汰最旧的本地 ID。 */
export function rememberRecentCashierMember(storage, key, memberId) {
  const id = validMemberId(memberId)
  if (!storage || !key || !id) return []
  const next = [id, ...readRecentCashierMemberIds(storage, key).filter((saved) => saved !== id)]
    .slice(0, HISTORY_LIMIT)
  try {
    storage.setItem(key, JSON.stringify(next))
  } catch {
    // 禁用本地存储不应阻止本次收银选客。
  }
  return next
}
