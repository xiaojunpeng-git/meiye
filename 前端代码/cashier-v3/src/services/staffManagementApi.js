import { readStoreV3SessionToken } from './storeV3SessionToken.js'

async function request(path, options = {}) {
  const url = new URL(path, window.location.origin)
  if (url.origin !== window.location.origin) {
    throw new Error('员工管理接口必须与当前门店端同源。')
  }
  const token = readStoreV3SessionToken()
  const response = await fetch(url.href, {
    method: options.method || 'GET',
    credentials: 'omit',
    cache: 'no-store',
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'Authori-zation': `Bearer ${token}` } : {}),
      ...(options.requestToken ? { 'X-Request-Token': options.requestToken } : {})
    },
    ...(options.body ? { body: JSON.stringify(options.body) } : {}),
    ...(options.formData ? { body: options.formData } : {})
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok || !payload || Number(payload.status || 200) !== 200) {
    throw new Error(String(payload?.msg || payload?.message || '员工管理请求失败。'))
  }
  return payload
}

export function readStoreStaff(staffId) {
  return request(`/storeapi/staff/read/${encodeURIComponent(staffId)}`)
}

export function readStoreStaffComplete(staffId) {
  return request(`/storeapi/staff/staff/person_complete/${encodeURIComponent(staffId)}`)
}

export function readStoreStaffPositions() {
  return request('/storeapi/staff/positions')
}

export function readStoreStaffWorkMembers() {
  return request('/storeapi/staff/workMember/list')
}

export async function uploadStoreStaffAvatar(file) {
  if (!(file instanceof File)) throw new Error('请选择头像图片。')
  const formData = new FormData()
  formData.append('pid', '0')
  formData.append('file', file)
  const payload = await request('/storeapi/file/upload', { method: 'POST', formData })
  const source = payload?.data?.src || payload?.data?.url || ''
  if (!source) throw new Error('头像上传失败。')
  return String(source)
}

function createRequestToken() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID()
  }
  if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
    throw new Error('当前浏览器无法生成安全请求令牌，请升级浏览器后重试。')
  }
  const bytes = window.crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

function normalizedBoolean(value) {
  return value ? 1 : 0
}

function normalizedPositiveIds(values) {
  return Array.isArray(values)
    ? [...new Set(values.map((value) => Number(value)).filter((value) => Number.isInteger(value) && value > 0))]
    : []
}

function normalizedDate(value) {
  const raw = String(value || '').trim()
  return raw || null
}

export function saveStoreStaff(staffId, values) {
  const requestToken = createRequestToken()
  return request(`/storeapi/staff/staff/${encodeURIComponent(staffId)}`, {
    method: 'POST',
    requestToken,
    body: {
      staff_name: String(values.staffName || '').trim(),
      phone: String(values.phone || '').trim(),
      avatar: String(values.avatar || ''),
      account: String(values.account || '').trim(),
      pwd: String(values.password || ''),
      position_ids: normalizedPositiveIds(values.positionIds),
      scope_mode: values.scopeMode === 'store_self' ? 'store_self' : 'personal',
      work_member_id: Number(values.workMemberId) || 0,
      notify: normalizedBoolean(values.notify),
      status: normalizedBoolean(values.status),
      cashier_salesperson_enabled: normalizedBoolean(values.salespersonEnabled),
      cashier_craftsman_enabled: normalizedBoolean(values.craftsmanEnabled),
      is_customer: normalizedBoolean(values.isCustomer),
      customer_url: String(values.customerUrl || '').trim(),
      is_reservable: normalizedBoolean(values.isReservable),
      employee_number: String(values.employeeNumber || '').trim(),
      id_card: String(values.idCard || '').trim(),
      age: values.age === '' || values.age === null || values.age === undefined ? null : Number(values.age),
      join_area: String(values.joinArea || '').trim(),
      join_date: normalizedDate(values.joinDate),
      birthday_date: normalizedDate(values.birthdayDate),
      birthday_type: Number(values.birthdayType) === 2 ? 2 : 1,
      birthday_area: String(values.birthdayArea || '').trim(),
      now_area: String(values.nowArea || '').trim(),
      contract_begin: normalizedDate(values.contractBegin),
      contract_end: normalizedDate(values.contractEnd),
      salary_status: normalizedBoolean(values.salaryStatus),
      department: String(values.department || '').trim(),
      employment_type_code: String(values.employmentTypeCode || ''),
      employment_type_version: Number(values.employmentTypeVersion),
      request_token: requestToken
    }
  })
}
