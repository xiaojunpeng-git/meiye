<script setup>
import { computed, ref } from 'vue'
import { formatMoney } from '@/services/cashierV3Bridge'
const props=defineProps({kind:{type:String,required:true},result:{type:Object,required:true},categories:{type:Array,default:()=>[]},loading:Boolean,layout:{type:Object,required:true}})
const emit=defineEmits(['detail','page'])
const scrollArea=ref(null)
const title=computed(()=>props.kind==='advisor'?'顾问院店人效对比':props.kind==='leader'?'店长现金业绩':'城市经理现金业绩')
const money=n=>n==null?'—':formatMoney(Math.round(n))
const num=n=>n==null?'—':Number(n).toLocaleString('zh-CN',{maximumFractionDigits:1})
const rate=n=>n==null?'—':`${(n*100).toFixed(1)}%`
// 后端列路径仅访问已返回的数据字段；列名、宽度、分组和钻取身份不由展示文本推断。
const columns=computed(()=>props.layout.columns)
const identities=computed(()=>columns.value.filter(c=>c.group==='identity'))
const grouped=computed(()=>props.layout.groups.map(g=>({...g,columns:columns.value.filter(c=>c.group===g.id)})))
function sticky(column){if(!column.fixed)return {};let left=0;for(const c of columns.value){if(c.key===column.key)break;if(c.fixed)left+=c.width}return {position:'sticky',left:left+'px',zIndex:3,width:column.width+'px',minWidth:column.width+'px',maxWidth:column.width+'px'}}
function value(row,column){return column.path.reduce((v,key)=>v?.[key],row)}
function display(row,column){const n=value(row,column);return column.format==='money'?money(n):column.format==='rate'?rate(n):column.format==='text'?(n??'—'):num(n)}
// 翻页保留已提交查询条件，只由后端返回完整排序的当前页；前端不切片或重算人均。
function turn(page){emit('page',page);if(scrollArea.value)scrollArea.value.scrollTop=0}
</script>
<template>
 <section class="comparison" :aria-label="title">
  <header><h2>{{title}}</h2><p v-if="kind==='advisor'">按门店顾问现金业绩合计降序；同店顾问之后展示店长，每页10行，跨页保留门店和院店排名。</p><p v-else>按目标完成率降序；无法计算完成率的记录排在末尾。</p></header>
  <div class="table-scroll" ref="scrollArea" tabindex="0" :aria-label="`${title}表格`"><table :style="{minWidth:columns.reduce((n,c)=>n+c.width,0)+'px'}">
   <colgroup><col v-for="c in columns" :key="c.key" :style="{width:c.width+'px'}"></colgroup>
   <thead><tr><th v-for="c in identities" :key="c.key" rowspan="2" :style="sticky(c)">{{c.label}}</th><th v-for="g in grouped" :key="g.id" :colspan="g.columns.length" :class="g.tone">{{g.label}}</th></tr><tr><template v-for="g in grouped" :key="g.id"><th v-for="c in g.columns" :key="c.key">{{c.label}}</th></template></tr></thead>
   <tbody><tr v-for="r in result.rows" :key="r.id" :class="{'group-start':r.group_start,'manager-row':kind==='advisor'&&r.role_code==='leader'}"><td v-for="c in columns" :key="c.key" :style="sticky(c)" :title="['target','completion'].includes(c.key)?r.target_status:undefined" :class="{strong:c.key==='cash',achieved:c.key==='completion'&&r.completion>=1}"><button v-if="c.metric" @click="emit('detail',r,c.category_id,c.metric)">{{display(r,c)}}{{c.metric==='stores'?'家 →':''}}</button><template v-else>{{display(r,c)}}</template><small v-if="c.key==='completion'&&r.target_status==='待补齐历史管辖资料'"> · 待补齐历史管辖资料</small></td></tr><tr v-if="!result.rows.length"><td :colspan="columns.length">{{loading?'正在查询…':'当前条件暂无记录'}}</td></tr></tbody>
  </table></div>
  <footer><span class="explain">{{kind==='advisor'?'同店人员连续排序，每页10行':'目标完成率由后端按当前范围计算'}}<template v-if="kind!=='manager'"> · 人均分母为期末在岗美容师人数</template></span><span>共 {{result.total}} {{result.unit}} · 每页 {{result.page_size}} {{result.unit}} · 第 {{result.page}} / {{result.pages}} 页</span><button :disabled="loading||result.page===1" @click="turn(result.page-1)">上一页</button><button :disabled="loading||result.page===result.pages" @click="turn(result.page+1)">下一页</button></footer>
 </section>
</template>
<style scoped>
.comparison{background:#fff;border:1px solid #dfe7ef;border-radius:9px;min-width:0;display:flex;flex-direction:column}.comparison header{padding:17px 19px}.comparison h2{font-size:15px;margin:0;color:#233f54}.comparison p{font-size:11px;color:#8295a6;margin:4px 0 0}.table-scroll{overflow-x:auto;overflow-y:visible;min-height:0;flex-shrink:0}table{border-collapse:separate;border-spacing:0;min-width:2250px;width:100%;table-layout:fixed;white-space:nowrap;font-size:12px}th,td{height:32px;box-sizing:border-box;padding:6px 12px;text-align:right;border-bottom:1px solid #edf2f6}th{font-weight:500;color:#6d8498;background:#f5f8fb}thead{position:sticky;top:0;z-index:5}td:nth-child(-n+4),th[rowspan]{text-align:left}.service{background:#edf6f3;color:#357a72;text-align:center}.sales{background:#f8f3e9;color:#927549;text-align:center}.total{background:#edf4fa;text-align:center}tbody tr{background:white}td[style*="sticky"]{background:#fff;box-shadow:1px 0 0 #edf2f6}.group-start td{border-top:2px solid #dce6ee}.manager-row{background:#f3f7fb}.role-tag{padding:3px 6px;border-radius:4px;background:#eef4f8;color:#426782}.manager-row .role-tag{background:#e4edf7}.achieved{color:#27806d}button{font:inherit;border:0;background:transparent;color:#1f5f8b;cursor:pointer;padding:0}.strong{font-weight:650}button:hover{text-decoration:underline}button:focus-visible,.table-scroll:focus-visible{outline:2px solid #3383be;outline-offset:2px}footer{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid #e1e8ef;padding:12px 16px;font-size:11px;color:#758b9f}.explain{margin-right:auto}footer button{border:1px solid #dce4ec;border-radius:5px;padding:6px 12px;background:white}button:disabled{color:#b5c0ca;cursor:default;background:#f7f9fb;text-decoration:none}
</style>

<style scoped>
/* 固定身份列使用不透明底色，横向滚动不透出金额；10行自然展开，分页不被内层高度裁掉。 */
.manager-row td[style*="sticky"]{background:#f3f7fb}th,td{overflow:hidden;text-overflow:ellipsis}.comparison{flex-shrink:0}
</style>
