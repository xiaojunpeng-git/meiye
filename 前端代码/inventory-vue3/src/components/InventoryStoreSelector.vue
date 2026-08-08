<script setup>
import { computed, ref, watch } from 'vue'
import { ChevronDown, Search, X } from '@lucide/vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: '搜索并选择供货方' },
  loading: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  presentation: { type: String, default: 'dropdown' }
})

const emit = defineEmits(['update:modelValue'])
const keyword = ref('')
const expanded = ref(false)

const selected = computed(() => props.options.find((option) => String(option?.key || '') === String(props.modelValue || '')) || null)
const visibleOptions = computed(() => {
  const query = keyword.value.trim().toLowerCase()
  if (!query) return props.options
  return props.options.filter((option) => String(option?.name || '').toLowerCase().includes(query))
})

watch(() => props.modelValue, () => { if (selected.value) keyword.value = '' })

function open() {
  if (props.disabled) return
  expanded.value = true
}

function toggle() {
  if (props.disabled) return
  if (props.presentation === 'modal') {
    expanded.value = true
    return
  }
  expanded.value = !expanded.value
}

function close() { window.setTimeout(() => { expanded.value = false }, 120) }
function choose(option) {
  emit('update:modelValue', String(option.key || ''))
  keyword.value = ''
  expanded.value = false
}
</script>

<template>
  <div class="store-selector">
    <button
      v-if="presentation === 'modal'"
      class="store-selector__modal-trigger"
      type="button"
      :disabled="disabled"
      :aria-expanded="expanded"
      aria-haspopup="dialog"
      @click="toggle"
    >
      <span class="store-selector__modal-trigger-main">
        <Search :size="15" />
        <span class="store-selector__modal-trigger-text">{{ selected?.name || placeholder }}</span>
      </span>
      <span class="store-selector__modal-trigger-tag">{{ selected ? (selected.type === 'HQ' ? '总部仓' : selected.type === 'ALL' ? '全部' : '门店') : '弹窗选择' }}</span>
      <ChevronDown :size="15" aria-hidden="true" />
    </button>
    <div v-else class="store-selector__input">
      <Search :size="15" />
      <input :value="selected?.name || keyword" :placeholder="placeholder" :disabled="disabled" readonly @focus="open" @click="open" />
      <button class="store-selector__toggle" type="button" :aria-label="expanded ? '收起供货方列表' : '展开供货方列表'" @mousedown.prevent @click="toggle"><ChevronDown :size="15" aria-hidden="true" /></button>
    </div>
    <div v-if="expanded && presentation !== 'modal'" class="store-selector__menu">
      <p v-if="loading" class="store-selector__empty">正在读取供货方...</p>
      <p v-else-if="!visibleOptions.length" class="store-selector__empty">没有可选供货方</p>
      <button v-for="option in visibleOptions" v-else :key="option.key" type="button" :class="{ active: String(option.key) === String(modelValue) }" @mousedown.prevent="choose(option)">
        <span>{{ option.name }}</span><small>{{ option.type === 'HQ' ? '总部仓' : option.type === 'ALL' ? '全部' : '门店' }}</small>
      </button>
    </div>
    <div v-if="expanded && presentation === 'modal'" class="store-selector__backdrop" @click.self="expanded = false">
      <section class="store-selector__dialog" role="dialog" aria-modal="true" aria-label="选择库存仓">
        <header><strong>选择库存仓</strong><button type="button" aria-label="关闭" @click="expanded = false"><X :size="17" /></button></header>
        <div class="store-selector__search"><Search :size="15" /><input v-model="keyword" autofocus placeholder="搜索总部仓或门店" /></div>
        <div class="store-selector__tree">
          <button v-for="option in visibleOptions" :key="option.key" type="button" :class="{ active: String(option.key) === String(modelValue) }" @click="choose(option)">
            <span class="store-selector__tree-dot" :class="option.type === 'HQ' ? 'hq' : 'store'"></span><span>{{ option.name }}</span><small>{{ option.type === 'HQ' ? '总部仓' : '门店' }}</small>
          </button>
          <p v-if="loading" class="store-selector__empty">正在读取库存仓...</p><p v-else-if="!visibleOptions.length" class="store-selector__empty">没有可选库存仓</p>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.store-selector { position: relative; min-width: 0; }.store-selector__input { display: flex; align-items: center; gap: 6px; width: 100%; height: 34px; padding: 0 7px 0 9px; border: 1px solid #d8e3ed; border-radius: 8px; background: #fff; color: #7f8fa0; }.store-selector__input:focus-within { border-color: #82b8ea; box-shadow: 0 0 0 2px rgba(23, 111, 209, .08); }.store-selector__input input { width: 100%; min-width: 0; height: 100%; padding: 0; border: 0 !important; border-radius: 0 !important; outline: 0; background: transparent; color: #40566d; }.store-selector__toggle { display: inline-grid; width: 22px; height: 22px; flex: 0 0 22px; place-items: center; padding: 0; border: 0; border-radius: 4px; background: transparent; color: #6f8295; }.store-selector__toggle:hover { background: #edf6ff; color: #176fd1; }.store-selector__modal-trigger { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 34px; padding: 0 10px 0 11px; border: 1px solid #d8e3ed; border-radius: 8px; background: linear-gradient(180deg, #fbfdff 0%, #f5f9ff 100%); color: #40566d; text-align: left; transition: border-color .15s ease, box-shadow .15s ease, background .15s ease; }.store-selector__modal-trigger:hover { border-color: #82b8ea; box-shadow: 0 0 0 2px rgba(23, 111, 209, .08); background: #f2f8ff; }.store-selector__modal-trigger:disabled { cursor: not-allowed; opacity: .72; }.store-selector__modal-trigger-main { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; color: #7f8fa0; }.store-selector__modal-trigger-text { overflow: hidden; min-width: 0; color: #40566d; text-overflow: ellipsis; white-space: nowrap; }.store-selector__modal-trigger-tag { flex: 0 0 auto; padding: 0 8px; border-radius: 999px; background: #eaf3ff; color: #176fd1; font-size: 11px; line-height: 20px; }.store-selector__menu { position: absolute; z-index: 12; top: calc(100% + 4px); right: 0; left: 0; max-height: 224px; overflow: auto; padding: 4px; border: 1px solid #cfddeb; border-radius: 8px; background: #fff; box-shadow: 0 8px 22px rgba(35, 62, 91, .14); }.store-selector__menu button { display: flex; align-items: center; justify-content: space-between; width: 100%; min-height: 32px; padding: 0 8px; border: 0; border-radius: 5px; background: transparent; color: #40566d; font-size: 12px; text-align: left; }.store-selector__menu button:hover, .store-selector__menu button.active { background: #edf6ff; color: #176fd1; }.store-selector__menu small { color: #8393a4; font-size: 11px; }.store-selector__empty { margin: 0; padding: 9px 8px; color: #8191a2; font-size: 12px; }
.store-selector__backdrop { position: fixed; z-index: 100; inset: 0; display: grid; place-items: center; background: rgba(26, 45, 67, .32); }.store-selector__dialog { width: min(520px, calc(100vw - 32px)); max-height: min(620px, calc(100vh - 48px)); overflow: hidden; border: 1px solid #d9e5f0; border-radius: 12px; background: #fff; box-shadow: 0 18px 48px rgba(22, 48, 78, .2); }.store-selector__dialog header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid #edf2f7; color: #2d435a; }.store-selector__dialog header button { display: grid; place-items: center; padding: 4px; border: 0; background: transparent; color: #7a8b9d; }.store-selector__search { display: flex; align-items: center; gap: 7px; margin: 14px 16px 8px; padding: 0 10px; height: 34px; border: 1px solid #d8e3ed; border-radius: 7px; color: #8495a7; }.store-selector__search input { width: 100%; border: 0; outline: 0; color: #40566d; }.store-selector__tree { max-height: 460px; overflow: auto; padding: 4px 10px 14px; }.store-selector__tree button { display: flex; align-items: center; gap: 9px; width: 100%; min-height: 38px; padding: 0 10px; border: 0; border-radius: 6px; background: transparent; color: #40566d; text-align: left; }.store-selector__tree button:hover, .store-selector__tree button.active { background: #edf6ff; color: #176fd1; }.store-selector__tree small { margin-left: auto; color: #8192a4; font-size: 11px; }.store-selector__tree-dot { width: 8px; height: 8px; border-radius: 50%; background: #8ab3db; }.store-selector__tree-dot.hq { background: #7a64d8; }.store-selector__tree-dot.store { background: #47b889; }
</style>
