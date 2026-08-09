<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  page: { type: String, default: 'overview' },
  sessionToken: { type: String, default: '' },
  origin: { type: String, default: '' }
})

const frame = ref(null)

const inventoryOrigin = computed(() => {
  const configured = String(props.origin || '').trim()
  if (configured) return configured.replace(/\/$/, '')
  if (typeof window !== 'undefined') {
    const current = new URL(window.location.href)
    // Local cashier verification runs the fixed inventory workbench on the
    // adjacent preview port. Production keeps both surfaces same-origin.
    const previewPort = current.port === '18091' ? '18092' : current.port === '18087' ? '18088' : ''
    if (previewPort) return `${current.protocol}//${current.hostname}:${previewPort}`
    return window.location.origin
  }
  return ''
})

const parentOrigin = computed(() => (typeof window !== 'undefined' ? window.location.origin : ''))

const inventoryUrl = computed(() => {
  if (!inventoryOrigin.value || !parentOrigin.value) return ''
  const url = new URL('/view_inventory_v3/', inventoryOrigin.value)
  url.searchParams.set('source', 'store')
  url.searchParams.set('page', String(props.page || 'overview'))
  url.searchParams.set('parent_origin', parentOrigin.value)
  return url.toString()
})

function sendSession(targetWindow = frame.value?.contentWindow) {
  const token = String(props.sessionToken || '').trim()
  if (!targetWindow || !token || !inventoryOrigin.value) return
  targetWindow.postMessage({ type: 'cashier-v3:inventory-session', token }, inventoryOrigin.value)
}

function handleMessage(event) {
  if (event?.data?.type !== 'cashier-v3:inventory-session-request') return
  if (!frame.value?.contentWindow || event.source !== frame.value.contentWindow) return
  if (event.origin !== inventoryOrigin.value) return
  sendSession(event.source)
}

function handleLoad() {
  sendSession()
}

onMounted(() => window.addEventListener('message', handleMessage))
onBeforeUnmount(() => window.removeEventListener('message', handleMessage))
watch(() => [props.sessionToken, inventoryUrl.value], () => sendSession())
</script>

<template>
  <iframe
    ref="frame"
    class="cashier-inventory-frame"
    title="库存管理"
    :src="inventoryUrl"
    frameborder="0"
    @load="handleLoad"
  />
</template>

<style scoped>
.cashier-inventory-frame {
  display: block;
  width: 100%;
  height: 100%;
  min-height: calc(100vh - 112px);
  border: 0;
  background: #f2f1ef;
}
</style>
