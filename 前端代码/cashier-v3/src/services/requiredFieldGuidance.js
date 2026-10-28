export const REQUIRED_FIELD_GUIDANCE_EVENT = 'cashier-v3:required-field-guidance'
export const REQUIRED_FIELD_GUIDANCE_DURATION_MS = 3200

/**
 * 请求页面定位第一个缺失的必填项。
 * 这里只传递目标和业务提示，不自动补值、不提交数据，也不替代后端最终校验。
 */
export function guideRequiredField({ target, message = '请完成此必填项' } = {}) {
  const normalizedTarget = String(target || '').trim()
  if (!normalizedTarget || typeof window === 'undefined') return false
  window.dispatchEvent(new CustomEvent(REQUIRED_FIELD_GUIDANCE_EVENT, {
    detail: {
      target: normalizedTarget,
      message: String(message || '请完成此必填项')
    }
  }))
  return true
}
