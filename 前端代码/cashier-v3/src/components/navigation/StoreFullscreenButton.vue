<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Maximize from '@lucide/vue/dist/esm/icons/maximize.mjs'
import Minimize from '@lucide/vue/dist/esm/icons/minimize.mjs'

const active = ref(false)
const supported = ref(false)
const pending = ref(false)
const error = ref('')

// 以浏览器真实全屏状态为准，Esc/浏览器退出也同步图标；不改页面布局或业务草稿。
function synchronize() {
  active.value = Boolean(document.fullscreenElement)
}
async function toggle() {
  if (pending.value || !supported.value) return
  pending.value = true
  error.value = ''
  try {
    // 全屏整个文档，保留导航、业务页面及挂载到body的弹窗，不另建页面或快照。
    if (document.fullscreenElement) await document.exitFullscreen()
    else await document.documentElement.requestFullscreen()
  } catch (_) {
    error.value = '浏览器未允许切换全屏，请重试。'
  } finally {
    synchronize()
    pending.value = false
  }
}
onMounted(() => {
  supported.value = document.fullscreenEnabled !== false
    && typeof document.documentElement.requestFullscreen === 'function'
    && typeof document.exitFullscreen === 'function'
  synchronize()
  document.addEventListener('fullscreenchange', synchronize)
})
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', synchronize))
</script>

<template>
  <span class="store-fullscreen">
    <button type="button" class="store-fullscreen__button" :aria-label="active ? '退出全屏' : '进入全屏'" :title="!supported ? '当前浏览器环境不支持全屏' : active ? '退出全屏（Esc）' : '进入全屏'" :aria-pressed="active" :aria-busy="pending" :disabled="!supported || pending" @click="toggle">
      <component :is="active ? Minimize : Maximize" :size="19" :stroke-width="1.9" aria-hidden="true" />
    </button>
    <span v-if="error" class="store-fullscreen__error" role="alert">{{ error }}</span>
  </span>
</template>

<style scoped>
.store-fullscreen{position:relative;display:inline-flex;flex:none;width:32px;height:32px}
.store-fullscreen__button{display:grid;place-items:center;width:32px;height:32px;padding:0;border:0;border-radius:4px;background:transparent;color:inherit;cursor:pointer}
.store-fullscreen__button:hover{background:#f1f5fb}
.store-fullscreen__button:focus-visible{outline:2px solid #4096ff;outline-offset:1px}
.store-fullscreen__button:disabled{opacity:.45;cursor:default}
.store-fullscreen__error{position:absolute;top:calc(100% + 8px);right:0;z-index:50;box-sizing:border-box;width:220px;padding:8px 10px;border:1px solid #e5c9c9;border-radius:4px;background:#fff;color:#b42318;font-size:12px;line-height:1.5;white-space:normal}
</style>
