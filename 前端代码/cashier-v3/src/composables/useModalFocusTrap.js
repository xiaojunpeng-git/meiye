import { nextTick, onBeforeUnmount, onMounted } from 'vue'

// Multiple business dialogs may intentionally overlap while one dialog prepares
// the next one. Keep one shared lock per background element so the later dialog
// cannot snapshot an earlier dialog's temporary `inert` value as the permanent
// page state. Store it on globalThis as well as in the module: Vite HMR can keep
// two timestamped instances of this composable alive during local acceptance,
// and those instances must still coordinate the same browser-level lock.
const BACKGROUND_LOCKS_KEY = Symbol.for('mohe.cashierV3.modalBackgroundLocks')
const backgroundLocks = globalThis[BACKGROUND_LOCKS_KEY] instanceof WeakMap
  ? globalThis[BACKGROUND_LOCKS_KEY]
  : new WeakMap()
globalThis[BACKGROUND_LOCKS_KEY] = backgroundLocks

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])'
].join(',')

function enabledFocusableElements(container) {
  if (!(container instanceof HTMLElement)) return []
  return [...container.querySelectorAll(FOCUSABLE_SELECTOR)].filter((element) => (
    element instanceof HTMLElement
    && element.getAttribute('aria-hidden') !== 'true'
    && !element.hidden
    && element.offsetParent !== null
  ))
}

/**
 * V3 全屏页／弹层统一键盘合同：进入弹层、Tab 圈定、允许时 Esc 关闭、关闭后归还焦点。
 * 只处理页面可访问性，不决定任何业务状态或按钮权限。
 */
export function useModalFocusTrap({ containerRef, canClose = () => true, onClose }) {
  let previouslyFocusedElement = null
  let backgroundState = []

  function acquireBackgroundLock(element) {
    const existing = backgroundLocks.get(element)
    if (existing) {
      existing.owners += 1
      return
    }

    backgroundLocks.set(element, {
      owners: 1,
      ariaHidden: element.getAttribute('aria-hidden'),
      inertAttribute: element.hasAttribute('inert'),
      inert: element.inert === true
    })
    element.setAttribute('aria-hidden', 'true')
    element.setAttribute('inert', '')
    element.inert = true
  }

  function releaseBackgroundLock(element) {
    const lock = backgroundLocks.get(element)
    if (!lock) return
    lock.owners -= 1
    if (lock.owners > 0) return

    backgroundLocks.delete(element)
    if (!element.isConnected) return
    if (lock.ariaHidden === null) element.removeAttribute('aria-hidden')
    else element.setAttribute('aria-hidden', lock.ariaHidden)
    if (!lock.inertAttribute) element.removeAttribute('inert')
    element.inert = lock.inert
  }

  function topLayerElement(container) {
    let current = container
    while (current?.parentElement && current.parentElement !== document.body) {
      current = current.parentElement
    }
    return current?.parentElement === document.body ? current : null
  }

  function hideBackground(container) {
    const topLayer = topLayerElement(container)
    if (!topLayer) return
    backgroundState = [...document.body.children]
      .filter((element) => element instanceof HTMLElement && element !== topLayer && !['SCRIPT', 'STYLE'].includes(element.tagName))
    for (const element of backgroundState) {
      acquireBackgroundLock(element)
    }
  }

  function restoreBackground() {
    for (const element of backgroundState) {
      releaseBackgroundLock(element)
    }
    backgroundState = []
  }

  function closeAllowed() {
    return typeof canClose === 'function' ? canClose() === true : canClose?.value === true
  }

  function handleKeydown(event) {
    const container = containerRef.value
    if (!(container instanceof HTMLElement)) return

    if (event.key === 'Escape') {
      if (!closeAllowed() || typeof onClose !== 'function') return
      event.preventDefault()
      onClose()
      return
    }

    if (event.key !== 'Tab') return
    const focusable = enabledFocusableElements(container)
    if (!focusable.length) {
      event.preventDefault()
      container.focus()
      return
    }

    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  }

  onMounted(async () => {
    previouslyFocusedElement = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null
    document.addEventListener('keydown', handleKeydown)
    await nextTick()
    const container = containerRef.value
    if (!(container instanceof HTMLElement)) return
    hideBackground(container)
    const focusable = enabledFocusableElements(container)
    ;(focusable[0] || container).focus()
  })

  onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleKeydown)
    restoreBackground()
    const restoreTarget = previouslyFocusedElement
    window.queueMicrotask(() => {
      if (restoreTarget?.isConnected) restoreTarget.focus()
    })
  })
}
