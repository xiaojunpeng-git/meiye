import { readStoreV3SessionToken } from '@/services/storeV3SessionToken'

/** 登录管理不进入通用命令草稿，防止当前输入密码被根状态或操作回执保存。 */
export function memberLoginAdapter(platform = false) {
  const prefix = platform ? '/adminapi/user/member-center/login/' : '/cashierapi/v3/member-login/'
  async function request(id, suffix = '', data = null) {
    const cookie = platform ? document.cookie.match(/(?:^|;\s*)admin-token=([^;]*)/) : null
    const token = platform ? (cookie ? decodeURIComponent(cookie[1]) : '') : readStoreV3SessionToken()
    const response = await fetch(`${prefix}${Number(id)}${suffix}`, {
      method: data ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'Authori-zation': `Bearer ${token}` },
      ...(data ? { body: JSON.stringify(data) } : {})
    })
    const result = await response.json()
    if (!response.ok || Number(result.status) !== 200) throw new Error(result.msg || '登录信息操作失败')
    return result.data
  }
  return { read: id => request(id), password: (id, data) => request(id, '/password', data), clear: (id, data) => request(id, '/clear-wechat', data) }
}
