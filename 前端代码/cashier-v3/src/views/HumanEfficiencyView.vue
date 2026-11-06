<script setup>
import { ref, computed, onMounted } from 'vue'
import { UnifiedQueryDateRange } from '@mohe/unified-query-vue3'
import HumanEfficiencyRanking from '../components/human-efficiency/HumanEfficiencyRanking.vue'
import OrganizationStoreScopePicker from '../components/OrganizationStoreScopePicker.vue'
import { queryHumanEfficiency, queryHumanEfficiencyScope } from '../services/humanEfficiencyApi'
import { formatMoney } from '../services/cashierV3Bridge'
// 所有经营值来自后端；结果与筛选说明在成功时一起切换，翻页保留滚动容器；请求失败清空，不回退演示数值。
const today=new Date(),day=n=>`${n.getFullYear()}-${String(n.getMonth()+1).padStart(2,'0')}-${String(n.getDate()).padStart(2,'0')}`
const from=ref(day(new Date(today.getFullYear(),today.getMonth(),1))),to=ref(day(today)),period=ref('本月')
const scopeTree=ref([]),allowedStores=ref([]),selectedStores=ref([]),scopeLabel=ref('当前权限范围')
const replacing=ref(false) // 翻页保留结果区和滚动位置；重新查询范围时才隐藏旧结果。
const data=ref(null),loading=ref(false),notice=ref(''),modal=ref(null),detailLoading=ref(false),sources=ref(false)
const applied=ref(null),pages=ref({advisor:1,leader:1,manager:1});let sequence=0,detailSequence=0
const money=n=>n==null?'—':formatMoney(Math.round(Number(n)))
const fmt=n=>n==null?'—':Number(n).toLocaleString('zh-CN',{maximumFractionDigits:1})
const metrics=[{key:'active',label:'活客数'},{key:'visits',label:'售后人次'},{key:'items',label:'服务项目数'},{key:'consume',label:'生美消耗业绩',money:true},{key:'buyers',label:'成交顾客人数'},{key:'orders',label:'销售单数'},{key:'cash',label:'现金业绩',money:true}]
const beautyCards=computed(()=>data.value?[
 ['期末在职美容师',data.value.beauty.count,'人'],['活客数',data.value.beauty.active,'人'],['生美消耗业绩',data.value.beauty.consume,'元'],['现金业绩',data.value.beauty.cash,'元'],['人均现金业绩',data.value.beauty.average.cash,'元']]:[])
const colors=['#608eaf','#4b9c91','#b99a60','#8d86b5','#aab5bf']
const gradient=computed(()=>{let end=0;const parts=(data.value?.cohorts||[]).map((g,i)=>{const start=end;end+=(g.share||0)*100;return `${colors[i]} ${start}% ${end}%`});return data.value?.beauty.count?`conic-gradient(${parts.join(',')})`:'#e4eaf0'})
// 与集团看板复用日期弹窗；快捷周期立即查询，自定义确认只更新待查询条件。
const customRange=computed(()=>({min:from.value,max:to.value}))
function applyCustomRange(value){from.value=String(value?.min||'');to.value=String(value?.max||'')}
function choosePeriod(p){period.value=p;if(p==='今天')from.value=day(today);if(p==='本月')from.value=day(new Date(today.getFullYear(),today.getMonth(),1));if(p==='本年')from.value=day(new Date(today.getFullYear(),0,1));if(p!=='自定义'){to.value=day(today);query()}}
function scopeChange(v){scopeLabel.value=v.label||'当前权限范围'}
async function query(reset=true){
 const current=++sequence;replacing.value=reset;loading.value=true;notice.value='';modal.value=null;detailSequence++
 if(reset)pages.value={advisor:1,leader:1,manager:1}
 const nextApplied=reset?{start_date:from.value,end_date:to.value,store_ids:selectedStores.value.join(','),label:scopeLabel.value}:applied.value
 const filters={...nextApplied,advisor_page:pages.value.advisor,leader_page:pages.value.leader,manager_page:pages.value.manager};delete filters.label
 try{const result=await queryHumanEfficiency(filters);if(current!==sequence)return;data.value=result;applied.value=nextApplied}
 catch(e){if(current===sequence){data.value=null;notice.value=e.message}}
 finally{if(current===sequence)loading.value=false}
}
function turn(kind,page){pages.value={...pages.value,[kind]:page};query(false)}
async function detail(row,category=0,metric='cash',page=1){
 const current=++detailSequence;detailLoading.value=true;modal.value={row,category,metric,page,result:null}
 try{const result=await queryHumanEfficiency({...applied.value,action:'detail',entity_id:row.id,category_id:category,metric,page});if(current===detailSequence)modal.value={row,category,metric,page,result}}
 catch(e){if(current===detailSequence)modal.value.error=e.message}
 finally{if(current===detailSequence)detailLoading.value=false}
}
function summaryDetail(id,metric){detail({id:'summary:'+id,name:'当前范围'},0,metric)}
function closeDetail(){detailSequence++;modal.value=null}
// 导出使用完整后端查询集合和分精度金额；CSV转义同时避免姓名/门店内容被表格软件当作公式执行。
async function exportAll(){
 try{
  const result=await queryHumanEfficiency({...applied.value,action:'export'})
  // 各榜单使用后端完整查询计算的分类契约，导出不因当前页的零值改变列。
  const rows=[]
  for(const [section,categories] of Object.entries(result.section_categories)){
   const members=result.rows.filter(r=>r.section===section)
   if(!members.length)continue
   if(rows.length)rows.push([])
   rows.push(['榜单','排名','门店','岗位','姓名','管辖门店数','在岗美容师人数','活客数','美容人均活客数','售后人次','美容人均售后人次','项目数','消耗业绩',...categories.flatMap(c=>[`${c.name}成交顾客`,`${c.name}现金业绩`]),'成交顾客','现金业绩','目标','目标完成率','目标说明'])
   rows.push(...members.map(r=>[({beauty:'美容师人效',advisor:'顾问院店人效对比',leader:'店长现金业绩',manager:'城市经理现金业绩',unknown:'岗位未核实'})[r.section],r.rank??'',r.store,r.role,r.name,r.stores??'',r.staff,r.active,r.average_active,r.visits,r.average_visits,r.items,r.consume,...categories.flatMap(c=>[r.category_values[c.id]?.buyers??0,r.category_values[c.id]?.cash??0]),r.buyers,r.cash,r.target,r.completion,r.target_status]))
  }
  const escape=v=>{let text=v==null?'':String(v);if(typeof v==='string'&&/^[=+@\-\t\r]/.test(text))text="'"+text;return '"'+text.replaceAll('"','""')+'"'}
  const url=URL.createObjectURL(new Blob(['\uFEFF'+rows.map(r=>r.map(escape).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download='人效看板.csv';a.click();URL.revokeObjectURL(url)
 }catch(e){notice.value=e.message}
}
onMounted(async()=>{try{const s=await queryHumanEfficiencyScope();scopeTree.value=s.tree||[];allowedStores.value=s.allowed_store_ids||[];await query()}catch(e){notice.value=e.message}})
</script>
<template>
 <main class="he" :aria-busy="loading">
  <header class="heading"><h1>人效看板</h1><small>按当前权限范围查看</small></header>
  <section class="filters"><div class="filter-line">
   <OrganizationStoreScopePicker v-model="selectedStores" :tree="scopeTree" :allowed-store-ids="allowedStores" :label="scopeLabel" @change="scopeChange" />
   <div class="seg"><button v-for="p in ['今天','本月','本年','自定义']" :key="p" :class="{selected:period===p}" @click="choosePeriod(p)">{{p}}</button></div>
   <div v-if="period==='自定义'" class="custom-period"><UnifiedQueryDateRange :model-value="customRange" :allow-clear="false" label="人效看板自定义周期" @change="applyCustomRange" /></div>
   <button class="primary" :disabled="loading" @click="query()">{{loading?'查询中…':'查询'}}</button>
   <div class="tools"><button @click="sources=true">列名取值来源</button><button :disabled="loading" @click="query()">刷新</button><button :disabled="!data||loading" @click="exportAll">导出</button></div>
  </div></section>
  <div class="results">
   <div v-if="notice" class="notice" role="alert">{{notice}}</div>
   <p v-if="loading" role="status">正在读取当前范围内的人员、服务与业绩…</p>
   <template v-if="data && (!loading || !replacing)">
    <div class="context"><span>{{applied.label}} · {{applied.start_date}} 至 {{applied.end_date}}</span><span>更新于 {{data.data_as_of}}</span></div>
    <p v-for="warning in data.warnings" :key="warning" class="notice">{{warning}}</p>
    <section class="role-section" aria-label="美容师人效"><h2 class="role-title">美容师人效</h2>
     <div class="cards"><article v-for="(c,i) in beautyCards" :key="c[0]" :class="['card','tone-'+i]"><span>{{c[0]}}</span><strong class="card-value">{{c[2]==='元'?money(c[1]):fmt(c[1])}}<small>{{c[2]==='元'?'':c[2]}}</small></strong></article></div>
     <div class="overview-grid">
      <section class="panel"><header><h2>美容师工龄结构</h2></header><div class="structure"><button class="link" @click="summaryDetail('beauty','people')">查看人员</button><div class="ring" :style="{background:gradient}"><div><strong>{{data.beauty.count}}</strong><small>期末在职人数</small></div></div><div class="legend"><div v-for="(g,i) in data.cohorts" :key="g.id"><i :style="{background:colors[i]}"></i><span>{{g.name}}</span><b>{{g.count}}人</b><em>{{g.share==null?'—':fmt(g.share*100)+'%'}}</em></div></div></div><footer>按查询截止日计算工龄，缺失入职日期单列。</footer></section>
      <section class="panel"><header><h2>服务与销售效率</h2></header><table class="efficiency"><thead><tr><th>指标</th><th>数据</th><th>人均</th></tr></thead><tbody><tr v-for="m in metrics" :key="m.key"><td>{{m.label}}</td><td><button class="number" @click="summaryDetail('beauty',m.key)">{{m.money?money(data.beauty[m.key]):fmt(data.beauty[m.key])}}</button></td><td>{{m.money?money(data.beauty.average[m.key]):fmt(data.beauty.average[m.key])}}</td></tr><tr><td>单次销售金额</td><td>{{money(data.beauty.sale_average)}}</td><td>—</td></tr></tbody></table></section>
     </div>
     <section class="panel"><header><h2>不同工龄的人效对比</h2></header><div class="cohorts"><article v-for="(g,i) in data.cohorts" :key="g.id" :class="'cohort tone-'+i"><header><h3>{{g.name}}</h3><span>{{g.count}}人</span></header><table><thead><tr><th>指标</th><th>数据</th><th>占比</th><th>人均</th></tr></thead><tbody><tr v-for="m in metrics" :key="m.key"><td>{{m.label}}</td><td><button class="number" @click="summaryDetail('cohort:'+g.id,m.key)">{{m.money?money(g[m.key]):fmt(g[m.key])}}</button></td><td>{{g.contribution?.[m.key]==null?"—":fmt(g.contribution[m.key]*100)+"%"}}</td><td>{{m.money?money(g.average[m.key]):fmt(g.average[m.key])}}</td></tr></tbody></table></article></div><footer>活客及成交顾客占比按全部去重顾客数计算，同一顾客跨组时，各组占比之和可能超过100%。</footer></section>
    </section>
    <section class="role-section" aria-label="店长 / 顾问人效"><h2 class="role-title">店长 / 顾问人效</h2>
     <div class="cards"><article v-for="c in [['期末在职店长',data.leader_summary.count,'人'],['期末在职顾问',data.advisor_summary.count,'人'],['成交顾客人数',data.personal_summary.buyers,'人'],['个人现金业绩合计',data.personal_summary.cash,'元'],['人均现金业绩',data.personal_summary.average.cash,'元']]" :key="c[0]" class="card"><span>{{c[0]}}</span><strong class="card-value">{{c[2]==='元'?money(c[1]):fmt(c[1])}}</strong></article></div>
     <section class="panel"><header><h2>岗位汇总</h2></header><div class="wide"><table><thead><tr><th>岗位</th><th>期末在职人数</th><th v-for="m in metrics" :key="m.key">{{m.label}}</th><th>人均现金业绩</th></tr></thead><tbody><tr v-for="pair in [['店长',data.leader_summary],['顾问',data.advisor_summary]]" :key="pair[0]"><td>{{pair[0]}}</td><td>{{pair[1].count}}</td><td v-for="m in metrics" :key="m.key">{{m.money?money(pair[1][m.key]):fmt(pair[1][m.key])}}</td><td>{{money(pair[1].average.cash)}}</td></tr></tbody></table></div></section>
     <HumanEfficiencyRanking v-for="kind in ['advisor','leader']" :key="kind" :kind="kind" :categories="data.categories" :result="data.tables[kind]" :layout="data.ranking_layouts[kind]" :loading="loading" @page="turn(kind,$event)" @detail="detail" />
    </section>
    <section class="role-section" aria-label="城市经理人效"><h2 class="role-title">城市经理人效</h2><p class="context">经理按当前管辖门店汇总所选期间业绩和目标；组织调整后历史期间的归集结果会随当前归属变化。</p>
     <div class="cards"><article v-for="c in [['城市经理',data.manager_summary.count,'人'],['管辖门店',data.manager_summary.stores,'家'],['成交顾客人数',data.manager_summary.buyers,'人'],['现金业绩',data.manager_summary.cash,'元'],['平均每店现金业绩',data.manager_summary.average_store_cash,'元']]" :key="c[0]" class="card"><span>{{c[0]}}</span><strong class="card-value">{{c[2]==='元'?money(c[1]):fmt(c[1])}}</strong></article></div>
     <HumanEfficiencyRanking kind="manager" :categories="data.categories" :result="data.tables.manager" :layout="data.ranking_layouts.manager" :loading="loading" @page="turn('manager',$event)" @detail="detail" />
    </section>
   </template>
  </div>
  <div v-if="sources" class="overlay" @click.self="sources=false"><section role="dialog" aria-modal="true" aria-label="列名取值来源" class="dialog"><header><h2>列名取值来源</h2><button @click="sources=false">关闭</button></header><div class="dialog-body"><p v-for="(text,key) in data?.source_explanations||{}" :key="key">{{text}}</p><p>活客在当前统计范围内按顾客身份去重；分类人头不能直接相加。服务人次与人员分摊沿用统一服务事实。经理使用当前管辖归属；经理汇总及榜单仅统计已配置当前经理的门店。</p></div></section></div>
  <div v-if="modal" class="overlay" @click.self="closeDetail" @keydown.esc="closeDetail"><section role="dialog" aria-modal="true" :aria-label="modal.row.name+'明细'" class="dialog"><header><div><h2>{{modal.row.name}} · 明细</h2><p>{{applied.label}} · {{applied.start_date}} 至 {{applied.end_date}}</p></div><button @click="closeDetail">关闭</button></header><div class="dialog-body"><p v-if="detailLoading">正在查询…</p><p v-if="modal.error" role="alert">{{modal.error}}</p><template v-if="modal.result"><div class="wide"><table><thead><tr><th>业务日期</th><th>门店</th><th v-if="modal.metric!=='stores'">{{modal.metric==='people'?'姓名':'顾客'}}</th><th v-if="!['stores','people'].includes(modal.metric)">人员</th><th v-if="modal.metric!=='stores'">来源单据</th><th v-if="modal.metric!=='stores'">项目</th><th v-if="modal.metric==='cash'">现金业绩</th><th v-if="modal.metric==='consume'">消耗业绩</th><th v-if="modal.metric==='items'">项目数</th><th v-if="modal.metric==='visits'">人次</th></tr></thead><tbody><tr v-for="(r,i) in modal.result.rows" :key="i"><td>{{r.business_date||'—'}}</td><td>{{r.store_name}}</td><template v-if="modal.metric!=='stores'"><td>{{modal.metric==='people'?r.employee_name:r.member_name}}</td><td v-if="modal.metric!=='people'">{{r.employee_name}}</td><td>{{r.document_no}}</td><td>{{r.item_name}}</td><td v-if="modal.metric==='cash'">{{money(r.cash_cents/100)}}</td><td v-if="modal.metric==='consume'">{{money(r.consume_cents/100)}}</td><td v-if="modal.metric==='items'">{{fmt(r.items)}}</td><td v-if="modal.metric==='visits'">{{fmt(r.visits)}}</td></template></tr><tr v-if="!modal.result.rows.length"><td colspan="6">暂无对应明细</td></tr></tbody></table></div><p v-if="modal.metric==='cash'">全部明细合计：{{money(modal.result.cash_cents/100)}}</p><p v-else-if="modal.metric==='consume'">全部明细合计：{{money(modal.result.consume_cents/100)}}</p><p v-else-if="['items','visits'].includes(modal.metric)">全部明细合计：{{fmt(modal.result[modal.metric])}}</p></template></div><footer v-if="modal.result"><span>共 {{modal.result.total}} 条 · 第 {{modal.result.page}} / {{modal.result.pages}} 页</span><button :disabled="detailLoading||modal.result.page===1" @click="detail(modal.row,modal.category,modal.metric,modal.result.page-1)">上一页</button><button :disabled="detailLoading||modal.result.page===modal.result.pages" @click="detail(modal.row,modal.category,modal.metric,modal.result.page+1)">下一页</button></footer></section></div>
 </main>
</template>
<style scoped>
.card-value{display:block;padding:7px 0;font-size:25px;color:#1f5f8b}.dialog-body p{margin-bottom:12px}button:disabled{opacity:.55;cursor:default}
.role-section{display:flex;flex-direction:column;gap:16px;min-width:0}.role-section>*{flex-shrink:0}.role-title{border-left:3px solid #3984ad;padding:3px 10px;font-size:17px}.role-section+.role-section{border-top:1px solid #dce5ed;padding-top:24px;margin-top:8px}

.he{width:100%;min-width:0;height:100vh;box-sizing:border-box;display:flex;flex-direction:column;background:#f2f5f8;color:#2b4358;font:13px/1.5 -apple-system,BlinkMacSystemFont,'Microsoft YaHei',sans-serif;padding:20px 24px;gap:14px}.he *{box-sizing:border-box}.results>*{flex-shrink:0}.card,.overview-grid,.cohorts{min-width:0}.heading{display:flex;align-items:center;gap:14px}h1,h2,h3,p{margin:0}h1{font-size:23px;color:#18394c;letter-spacing:.5px}h2{font-size:15px;color:#233f54}h3{font-size:13px}.preview{font-size:11px;padding:4px 10px;color:#946b27;background:#fff4dc;border:1px solid #efdfba;border-radius:20px}button,input,select{font:inherit}button{border:1px solid #dce4ec;border-radius:5px;background:#fff;color:#38546b;padding:7px 12px;cursor:pointer}button:hover{background:#edf4fa;border-color:#aac6dd}button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid #3383be;outline-offset:2px}.filters{padding:12px;background:#fff;border:1px solid #e0e8ef;border-radius:8px;z-index:5}.filter-line{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.tools{display:flex;gap:7px;margin-left:auto}.primary{background:#226b9a;color:white;border-color:#226b9a}.primary:hover{background:#175780}.date{display:flex;align-items:center;gap:6px;color:#73899b}.date input{border:1px solid #dce4ec;background:white;border-radius:4px;color:#38546b;padding:6px;width:126px}.seg{display:flex;border:1px solid #dce4ec;border-radius:5px;overflow:hidden}.seg button{border:0;border-radius:0;padding:7px 10px}.seg .selected{color:#17699e;background:#eaf4fc}.scope{position:relative}.scope-trigger{min-width:172px;text-align:left}.scope-trigger span{float:right;margin-left:12px}.scope-panel{position:absolute;top:40px;left:0;background:#fff;width:330px;padding:14px;border:1px solid #dce4ec;border-radius:7px;box-shadow:0 12px 34px #18394c28}.scope-panel small{float:right;font-weight:normal;color:#aa7d30}.scope-body{display:grid;grid-template-columns:1fr 1fr;margin:12px 0;min-height:150px}.scope-body>div{padding:5px;border-right:1px solid #e7edf3}.scope-body button{display:block;border:0;text-align:left;width:100%}.extra{display:flex;align-items:center;gap:18px;border-top:1px solid #edf1f5;margin-top:12px;padding-top:12px}.extra select{padding:5px;border:1px solid #dce4ec;border-radius:4px}.extra small{color:#8796a5}.link,.text-button{border:0;background:transparent;color:#2875a8;padding:2px 0}.results{min-height:0;overflow:auto;display:flex;flex-direction:column;gap:16px;padding:0 3px 16px}.context{display:flex;justify-content:space-between;color:#7e93a5;font-size:11px}.cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.card{border:1px solid #dce5ed;border-top:2px solid #598bab;border-radius:8px;background:white;padding:17px}.card>span{font-size:12px;color:#698197}.card button{display:block;border:0;background:transparent;padding:7px 0;font-size:25px;font-weight:650;color:#1f5f8b}.card small{font-size:12px;margin-left:5px;font-weight:400}.card p{font-size:10px;color:#889aab}.tone-0{border-top-color:#608eaf!important}.tone-1{border-top-color:#4b9c91!important}.tone-2{border-top-color:#b99a60!important}.tone-3{border-top-color:#8d86b5!important}.tone-4{border-top-color:#4f8aa7!important}.overview-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.panel{border:1px solid #dfe7ef;border-radius:9px;background:white;min-width:0}.panel>header{display:flex;justify-content:space-between;align-items:center;padding:17px 19px;gap:16px}.panel header p{font-size:11px;color:#8295a6;margin-top:4px}.tag{font-size:11px;color:#7092a7;background:#f0f5f8;padding:3px 9px;border-radius:4px}.structure{display:flex;align-items:center;gap:32px;padding:28px 25px;min-height:255px}.ring{height:155px;width:155px;border-radius:50%;background:conic-gradient(#608eaf 0 30%,#4b9c91 30% 70%,#b99a60 70% 90%,#8d86b5 90% 100%);padding:18px;flex-shrink:0}.ring>div{height:100%;border-radius:50%;background:white;display:flex;align-items:center;justify-content:center;flex-direction:column}.ring strong{font-size:30px;color:#274b66}.ring small{font-size:10px;color:#8598a9}.legend{flex:1}.legend>div{display:flex;align-items:center;gap:9px;margin:18px 0;font-size:12px}.legend span{flex:1}.legend b{font-weight:600}.legend em{font-style:normal;font-size:11px;width:32px;text-align:right;color:#7f91a3}.legend i{width:7px;height:7px;border-radius:50%;background:#608eaf}.legend .dot-1{background:#4b9c91}.legend .dot-2{background:#b99a60}.legend .dot-3{background:#8d86b5}footer{padding:12px 19px;color:#8295a6;font-size:11px;border-top:1px solid #edf1f6}table{border-collapse:collapse;width:100%;white-space:nowrap;font-size:12px}th{font-weight:500;color:#6d8498;background:#f5f8fb}td,th{text-align:right;padding:10px 14px;border-bottom:1px solid #edf2f6}td:first-child,th:first-child{text-align:left}.efficiency td,.efficiency th{padding:7px 20px}.number{color:#1f5f8b;border:0;background:none;padding:0;border-radius:0;font-variant-numeric:tabular-nums}.number:hover{text-decoration:underline;background:none}.strong{font-weight:650}.cohorts{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(230px,1fr);overflow-x:auto;gap:12px;padding:0 16px 18px}.cohort{border:1px solid #e2e9ef;border-top:3px solid;border-radius:6px;overflow:auto}.cohort header{padding:14px;background:#f8fafc}.cohort header span{display:block;margin-top:7px;color:#49677d;font-weight:600}.cohort header small{font-size:10px;font-weight:400;color:#8597a6;margin-left:5px}.cohort table{font-size:10px}.cohort td,.cohort th{padding:9px 7px}.wide{overflow:auto}.pending{color:#8795a2;font-size:11px;padding:2px 0}.notice{background:#eef5fb;border:1px solid #dcebf5;padding:10px 14px;border-radius:5px;color:#50758f;font-size:12px}.overlay{position:fixed;inset:0;z-index:30;background:#182d4260;display:grid;place-items:center;padding:24px}.dialog{background:#fff;border-radius:10px;width:min(780px,100%);max-height:85vh;overflow:auto;box-shadow:0 18px 65px #16324940}.dialog>header{display:flex;align-items:center;justify-content:space-between;padding:20px;border-bottom:1px solid #e5edf4}.dialog header p{font-size:11px;color:#a38343;margin-top:4px}.dialog-body{padding:20px}.dialog-body table{margin-top:16px}.dialog footer{display:flex;justify-content:space-between;align-items:center}.dialog dt{font-weight:600;margin-top:15px}.dialog dd{margin:6px 0 15px;color:#72869a;line-height:1.8}.empty{text-align:center;padding:55px;color:#7f93a4}@media(max-width:1450px){.structure{gap:18px;padding:20px}.tools{margin-left:0}}@media(max-width:800px){.he{padding:12px}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.overview-grid{grid-template-columns:1fr}.structure{flex-wrap:wrap}.context{display:block}.tools{flex-wrap:wrap}}
</style>

<style scoped>
.custom-period{flex:0 1 290px;min-width:210px;max-width:100%}
</style>

<style scoped>
/* 工龄组始终单行，窄屏横向滚动；角色区不压缩表格的10条记录。 */
.role-section{flex-shrink:0}.cohorts{grid-template-columns:none}.dialog{width:min(1100px,100%)}.dialog-body td,.dialog-body th{padding:6px 10px}
</style>
