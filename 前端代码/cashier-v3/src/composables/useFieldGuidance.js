import { nextTick, onBeforeUnmount, onMounted, reactive } from 'vue'
import { guideRequiredField, REQUIRED_FIELD_GUIDANCE_EVENT, REQUIRED_FIELD_GUIDANCE_DURATION_MS } from '@/services/requiredFieldGuidance'

/** 简单表单复用同一必填引导协议：只定位首个缺项，不自动填值或提交。 */
export function useFieldGuidance(root) {
  const guidance = reactive({ target: '', message: '' })
  let timer
  function clear() { clearTimeout(timer); guidance.target = ''; guidance.message = '' }
  async function handle(event) {
    const target = event.detail?.target
    const element = root.value?.querySelector(`[data-field="${CSS.escape(target || '')}"]`)
    if (!element) return
    clear(); Object.assign(guidance, { target, message: event.detail.message })
    await nextTick(); element.scrollIntoView({ behavior: 'smooth', block: 'center' }); element.focus({ preventScroll: true })
    timer = setTimeout(clear, REQUIRED_FIELD_GUIDANCE_DURATION_MS)
  }
  onMounted(() => window.addEventListener(REQUIRED_FIELD_GUIDANCE_EVENT, handle))
  onBeforeUnmount(() => { clear(); window.removeEventListener(REQUIRED_FIELD_GUIDANCE_EVENT, handle) })
  return { guidance, clear, guide: (target, message) => guideRequiredField({ target, message }) }
}
