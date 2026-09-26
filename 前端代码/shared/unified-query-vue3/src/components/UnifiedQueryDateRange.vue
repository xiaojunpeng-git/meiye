<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import CalendarDays from '@lucide/vue/dist/esm/icons/calendar-days.mjs'
import ChevronLeft from '@lucide/vue/dist/esm/icons/chevron-left.mjs'
import ChevronRight from '@lucide/vue/dist/esm/icons/chevron-right.mjs'
import ChevronsLeft from '@lucide/vue/dist/esm/icons/chevrons-left.mjs'
import ChevronsRight from '@lucide/vue/dist/esm/icons/chevrons-right.mjs'
import { dateKey, parseDateKey, monthDays, periodOptions, periodRange } from '../contracts/dateRange.js'

// 只维护草稿日期；确认/清空才发布完整起止值，父级继续负责统一刷新与权限。
const props = defineProps({ modelValue: { type: Object, default: () => ({ min: '', max: '' }) }, label: { type: String, default: '查询周期' } })
const emit = defineEmits(['update:modelValue', 'change'])
const opened = ref(false), trigger = ref(null), panel = ref(null)
const draft = ref({ min: '', max: '' }), selectingEnd = ref(false), hoverDay = ref('')
const leftMonth = ref(new Date()), position = ref({})
const months = computed(() => [0, 1].map(offset => new Date(leftMonth.value.getFullYear(), leftMonth.value.getMonth() + offset, 1, 12)))
const caption = computed(() => props.modelValue.min && props.modelValue.max ? `${props.modelValue.min} 至 ${props.modelValue.max}` : '自定义时间')
const valid = computed(() => parseDateKey(draft.value.min) && parseDateKey(draft.value.max) && draft.value.min <= draft.value.max)
const today = dateKey(new Date())
function place() {
  const rect = trigger.value?.getBoundingClientRect()
  if (!rect) return
  const width = Math.min(840, window.innerWidth - 24)
  const height = panel.value?.offsetHeight || 450
  position.value = { width: `${width}px`, left: `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`,
    top: `${Math.max(12, rect.bottom + height + 8 <= window.innerHeight ? rect.bottom + 6 : rect.top - height - 6)}px` }
}
function dismiss(event) {
  if (!panel.value?.contains(event.target) && !trigger.value?.contains(event.target)) close(false)
}
function close(focus = true) {
  opened.value = false
  document.removeEventListener('pointerdown', dismiss)
  window.removeEventListener('resize', place)
  window.removeEventListener('scroll', place, true)
  if (focus) trigger.value?.focus()
}
async function open() {
  if (opened.value) return close()
  draft.value = { min: props.modelValue.min || '', max: props.modelValue.max || '' }
  selectingEnd.value = false; hoverDay.value = ''
  leftMonth.value = parseDateKey(draft.value.min) || new Date()
  opened.value = true
  place()
  await nextTick(); place(); panel.value?.focus()
  document.addEventListener('pointerdown', dismiss)
  window.addEventListener('resize', place)
  window.addEventListener('scroll', place, true)
}
function move(amount) { leftMonth.value = new Date(leftMonth.value.getFullYear(), leftMonth.value.getMonth() + amount, 1, 12) }
function preset(key) { draft.value = periodRange(key); leftMonth.value = parseDateKey(draft.value.min); selectingEnd.value = false; hoverDay.value = '' }
function select(day) {
  if (!selectingEnd.value) { draft.value = { min: day, max: '' }; selectingEnd.value = true }
  else { draft.value = { min: day < draft.value.min ? day : draft.value.min, max: day < draft.value.min ? draft.value.min : day }; selectingEnd.value = false }
}
function inRange(day) {
  const end = draft.value.max || hoverDay.value
  if (!draft.value.min || !end) return false
  return day >= (end < draft.value.min ? end : draft.value.min) && day <= (end > draft.value.min ? end : draft.value.min)
}
function publish(clear = false) {
  if (!clear && !valid.value) return
  const value = clear ? { min: '', max: '' } : { ...draft.value }
  emit('update:modelValue', value); emit('change', value); close()
}
// 弹层使用 Teleport 避免被查询栏横向滚动裁切；Tab 保持在弹层，Esc 取消草稿。
function keyboard(event) {
  if (event.key === 'Escape') { event.preventDefault(); close(); return }
  if (event.key !== 'Tab') return
  const nodes = [...panel.value.querySelectorAll('button:not(:disabled), input')]
  const first = nodes[0], last = nodes[nodes.length - 1]
  if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
onBeforeUnmount(() => close(false))
</script>

<template>
  <button ref="trigger" type="button" class="uq-period-trigger" :aria-label="label" aria-haspopup="dialog" :aria-expanded="opened" @click="open">
    <span :class="{ 'is-placeholder': !modelValue.min || !modelValue.max }">{{ caption }}</span><CalendarDays :size="18" />
  </button>
  <Teleport to="body">
    <section v-if="opened" ref="panel" class="uq-period-panel" :style="position" role="dialog" aria-modal="true" :aria-label="`${label}选择`" tabindex="-1" @keydown="keyboard">
      <aside class="uq-period-shortcuts" aria-label="快捷周期"><button v-for="[key, text] in periodOptions" :key="key" type="button" @click="preset(key)">{{ text }}</button></aside>
      <div class="uq-period-main">
        <div class="uq-period-calendars">
          <section v-for="(month, index) in months" :key="dateKey(month)" class="uq-period-month">
            <header>
              <div><template v-if="index === 0"><button type="button" aria-label="上一年" @click="move(-12)"><ChevronsLeft :size="18" /></button><button type="button" aria-label="上一月" @click="move(-1)"><ChevronLeft :size="18" /></button></template></div>
              <strong>{{ month.getFullYear() }}年 {{ month.getMonth() + 1 }}月</strong>
              <div><template v-if="index === 1"><button type="button" aria-label="下一月" @click="move(1)"><ChevronRight :size="18" /></button><button type="button" aria-label="下一年" @click="move(12)"><ChevronsRight :size="18" /></button></template></div>
            </header>
            <div class="uq-period-grid"><span v-for="weekday in ['日','一','二','三','四','五','六']" :key="weekday" class="uq-period-weekday">{{ weekday }}</span>
              <button v-for="day in monthDays(month)" :key="day.key" type="button" :aria-label="day.key" :aria-pressed="day.key === draft.min || day.key === draft.max" :class="{ 'is-outside': day.outside, 'is-range': inRange(day.key), 'is-endpoint': day.key === draft.min || day.key === draft.max, 'is-today': day.key === today }" @click="select(day.key)" @mouseenter="hoverDay = day.key">{{ day.day }}</button>
            </div>
          </section>
        </div>
        <!-- 日期只通过日历或快捷周期选择，底部不再维护第二套输入入口。 -->
        <footer><button type="button" @click="publish(true)">清空</button><button type="button" class="uq-period-confirm" :disabled="!valid" @click="publish()">确定</button></footer>
      </div>
    </section>
  </Teleport>
</template>

<style scoped>
.uq-period-trigger { display:flex; align-items:center; justify-content:space-between; gap:12px; width:100%; min-height:36px; padding:7px 12px; border:1px solid #dce3ee; border-radius:6px; background:#fff; color:#515d76; font:inherit; cursor:pointer; white-space:nowrap; }
.uq-period-trigger:focus-visible { outline:2px solid #9ac6ff; border-color:#438fff; }
.uq-period-trigger .is-placeholder { color:#b6bdc9; }
.uq-period-trigger svg { flex:none; color:#969fb0; }
.uq-period-panel { position:fixed; z-index:3000; display:flex; max-height:calc(100vh - 24px); overflow:auto; background:#fff; color:#515d76; border:1px solid #e5e7ed; border-radius:6px; box-shadow:0 3px 12px #0002; font:14px/1.5 Arial,"Microsoft YaHei",sans-serif; outline:none; }
.uq-period-panel button { font:inherit; color:inherit; background:transparent; border:0; cursor:pointer; }
.uq-period-panel button:focus-visible { outline:2px solid #438fff; outline-offset:-2px; }
.uq-period-shortcuts { flex:0 0 145px; padding:4px 0; background:#f7f7f9; border-right:1px solid #e5e7ed; }
.uq-period-shortcuts button { display:block; width:100%; text-align:left; padding:11px 25px; }
.uq-period-shortcuts button:hover { background:#eaf3ff; color:#438fff; }
.uq-period-main { flex:1; min-width:0; }
.uq-period-calendars { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); }
.uq-period-month header { height:50px; display:grid; grid-template-columns:60px 1fr 60px; align-items:center; border-bottom:1px solid #e5e7ed; padding:0 10px; text-align:center; }
.uq-period-month header strong { font-size:16px; font-weight:400; white-space:nowrap; }
.uq-period-month header div { display:flex; }
.uq-period-month header button { padding:2px; display:flex; color:#b5bcc7; }
.uq-period-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); padding:16px 16px 22px; row-gap:8px; text-align:center; }
.uq-period-weekday { color:#bbc0cb; padding:5px 0; }
.uq-period-grid button { height:36px; padding:0; border:1px solid transparent; }
.uq-period-grid .is-outside { color:#c3c8d2; }
.uq-period-grid .is-range { background:#edf5ff; }
.uq-period-grid .is-endpoint { border-color:#438fff; border-radius:4px; color:#438fff; }
.uq-period-grid button:hover { color:#438fff; background:#edf5ff; }
.uq-period-grid .is-today { font-weight:700; }
.uq-period-panel footer { display:flex; align-items:center; justify-content:flex-end; gap:6px; padding:12px; border-top:1px solid #e5e7ed; }
.uq-period-panel footer button { padding:4px 12px; border:1px solid #dce3ee; border-radius:4px; white-space:nowrap; }
.uq-period-panel footer .uq-period-confirm { color:#fff; background:#438fff; border-color:#438fff; }
.uq-period-panel footer button:disabled { opacity:.45; cursor:not-allowed; }
@media(max-width:650px) { .uq-period-panel { flex-direction:column; } .uq-period-shortcuts { flex:auto; display:flex; flex-wrap:wrap; border-right:0; } .uq-period-shortcuts button { width:auto; padding:6px 10px; } .uq-period-month header { padding:0 2px; grid-template-columns:40px 1fr 40px; } .uq-period-month header strong { font-size:13px; } .uq-period-grid { padding:8px 4px; row-gap:4px; } .uq-period-panel footer { flex-wrap:wrap; } }
</style>
