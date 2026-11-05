import { readStoreV3SessionToken } from './storeV3SessionToken.js'

/** 平台和门店共用业务契约，仅认证令牌与路由前缀不同，不能混用身份。 */
export function createStaffRosterApi(platform) {
  return async function request(path = '', params = {}, body = null) {
    const url = new URL((platform ? '/adminapi/staff-roster' : '/cashierapi/v3/staff-roster') + path, window.location.origin)
    Object.entries(params).forEach(([key, value]) => { if (value !== '' && value != null) url.searchParams.set(key, String(value)) })
    const cookie = platform ? document.cookie.match(/(?:^|;\s*)(?:admin-token|admin-agent_token)=([^;]*)/) : null
    const token = platform ? (cookie ? decodeURIComponent(cookie[1]) : '') : readStoreV3SessionToken()
    const response = await fetch(url, { method: body ? 'POST' : 'GET', cache: 'no-store', credentials: platform ? 'same-origin' : 'omit',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) })
    const payload = await response.json().catch(() => null)
    if (!response.ok || !payload || Number(payload.status) !== 200) {
      const error = new Error(payload?.msg || '排班请求失败，请重试。')
      error.conflicts = payload?.data?.conflicts || []
      // 网络异常可能已经成功，UI保留原请求重试；明确业务拒绝后才释放请求令牌。
      error.definitive = response.ok && !!payload && Number(payload.status) !== 200
      throw error
    }
    return payload.data
  }
}

/** CSV中不执行用户输入的公式，姓名/手机号/日期始终作为文本导出。 */
export function rosterCsv(data) {
  const cell = value => '"' + ('\t' + String(value ?? '')).replaceAll('"', '""') + '"'
  return '\ufeff' + [data.columns, ...data.rows].map(row => row.map(cell).join(',')).join('\r\n')
}
