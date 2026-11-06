<script setup>
import { computed } from 'vue'
import { formatMoney } from '@/services/cashierV3Bridge'

const props = defineProps({ result: { type: Object, required: true } })
// 所有计数、分档和均值都由后端计算；页面仅按列契约格式化，合计不能由四行相加。
const columns = computed(() => props.result.layout?.columns || [])
const rows = computed(() => props.result.available ? [props.result.total, ...props.result.rows] : [])
const cards = computed(() => props.result.available ? [
  { label: '有在职店长院店数', value: props.result.summary.with_leader, unit: '家' },
  { label: '无在职店长院店数', value: props.result.summary.without_leader, unit: '家' },
  { label: '在职顾问人数', value: props.result.summary.advisors, unit: '人' }
] : [])
function display(row, column) {
  const value = column.path.reduce((item, key) => item?.[key], row)
  if (value == null) return '—'
  if (column.format === 'text') return String(value)
  if (column.format === 'money') return formatMoney(Math.round(Number(value)))
  return Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 1 })
}
</script>

<template>
  <section class="store-cohorts" aria-label="店长/顾问院店分级看板">
    <p v-if="!result.available" class="scope-notice">{{ result.reason }}</p>
    <template v-else>
      <div class="cohort-cards">
        <article v-for="card in cards" :key="card.label">
          <span>{{ card.label }}</span><strong>{{ card.value.toLocaleString('zh-CN') }}<small>{{ card.unit }}</small></strong>
        </article>
      </div>
      <section class="cohort-panel">
        <header><h3>院店分类人效</h3><span>按整店月均现金业绩分档 · 统计 {{ result.months }} 个月</span></header>
        <div class="cohort-scroll" :class="{ 'fixed-layout': result.layout.fixed }" tabindex="0" aria-label="院店分类人效表格">
          <table :style="{ minWidth: columns.reduce((width, column) => width + column.width, 0) + 'px' }">
            <colgroup><col v-for="column in columns" :key="column.key" :style="{ width: column.width + 'px' }"></colgroup>
            <thead><tr><th v-for="column in columns" :key="column.key" :class="[column.group, { identity: column.fixed }]">{{ column.label }}</th></tr></thead>
            <tbody><tr v-for="row in rows" :key="row.id" :class="{ total: row.id === 'total' }">
              <td v-for="column in columns" :key="column.key" :class="[column.group, { identity: column.fixed }]">
                {{ display(row, column) }}<small v-if="column.key === 'label'">{{ row.rule }}</small>
              </td>
            </tr></tbody>
          </table>
        </div>
        <footer>月店均＝该类合计÷月份数÷院店数；人均服务指标再除以月店均美容师人数。人数按期末在职统计，零分母显示“—”。</footer>
      </section>
    </template>
  </section>
</template>

<style scoped>
.store-cohorts{display:flex;flex-direction:column;gap:16px;min-width:0}.cohort-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.cohort-cards article{border:1px solid #dce5ed;border-top:2px solid #598bab;border-radius:8px;background:#fff;padding:17px}.cohort-cards span{font-size:12px;color:#698197}.cohort-cards strong{display:block;padding-top:7px;font-size:25px;color:#2b4358}.cohort-cards small{font-size:12px;font-weight:400;margin-left:6px}.cohort-panel{background:#fff;border:1px solid #dfe7ef;border-radius:9px;min-width:0;overflow:hidden}.cohort-panel header{padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.cohort-panel h3{font-size:15px;margin:0;color:#233f54}.cohort-panel header span,footer{font-size:11px;color:#71869a}.cohort-scroll{overflow:auto;max-height:430px;min-height:0}.cohort-scroll:focus-visible{outline:2px solid #3383be;outline-offset:-2px}table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;font-size:12px}th,td{padding:10px 12px;text-align:right;border-bottom:1px solid #edf2f6;background:#fff;box-sizing:border-box}th{height:58px;background:#f5f8fb;color:#6d8498;font-weight:500;line-height:19px}.fixed-layout th{position:sticky;top:0;z-index:3}.fixed-layout .identity{position:sticky;left:0;z-index:2;box-shadow:1px 0 0 #e0e8ef;text-align:left}.fixed-layout th.identity{z-index:5}.fixed-layout .total td{position:sticky;top:58px;z-index:2;background:#f1f6fa;font-weight:600}.fixed-layout .total td.identity{z-index:4}td small{display:block;white-space:normal;font-size:11px;font-weight:400;color:#7b8d9d;padding-top:3px}.people{color:#2b4358}.service{color:#357a72}.average{color:#3b78a5}.money{color:#1f5f8b}th.people{background:#edf4fa}th.service,th.average{background:#edf6f3}th.money{background:#f8f3e9}footer{border-top:1px solid #edf1f6;padding:12px 18px;line-height:1.6}.scope-notice{padding:12px;background:#eef5fb;color:#50758f;border-radius:5px}@media(max-width:650px){.cohort-cards{grid-template-columns:1fr}.cohort-panel header{align-items:flex-start}}
</style>
