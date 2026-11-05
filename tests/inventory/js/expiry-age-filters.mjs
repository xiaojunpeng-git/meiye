// 执行页面实际筛选函数，验证边界、组合条件、清空与查询范围继承。
import fs from 'node:fs'
import vm from 'node:vm'
import assert from 'node:assert/strict'
const source = fs.readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
let query
const ctx = { statisticsSupplementalFilters:{value:[]}, statisticsExecutedQuery:{value:{filters:[{fieldKey:'batch_no',operator:'equals',value:'073'}]}}, statisticsProductFilter:{value:''}, statisticsBandFilter:{value:''}, activeStatisticsTab:{value:'expiry'}, queryStatisticsPage:q=>{query=q} }
vm.createContext(ctx)
vm.runInContext(source.slice(source.indexOf('function applyStatisticsFilters('), source.indexOf('\nfunction openExpiryRisk(')),ctx)
for (const [band, operator, value] of [['OVERDUE','less_than',0],['WITHIN_30','between',[0,30]],['DAYS_31_60','between',[31,60]],['DAYS_61_90','between',[61,90]],['SAFE_OVER_90','greater_than',90],['UNKNOWN','is_null',null]]) {
 ctx.statisticsBandFilter.value=band;ctx.applyStatisticsFilters();assert.equal(ctx.statisticsSupplementalFilters.value[0].operator,operator);assert.equal(JSON.stringify(ctx.statisticsSupplementalFilters.value[0].value),JSON.stringify(value))
}
ctx.activeStatisticsTab.value='age'
for (const [band,value] of [['0:30',[0,30]],['31:90',[31,90]],['91:180',[91,180]],['181:365',[181,365]],['366:',365],['UNKNOWN',null]]) {
 ctx.statisticsBandFilter.value=band;ctx.applyStatisticsFilters();assert.equal(ctx.statisticsSupplementalFilters.value[0].fieldKey,'inventory_age_days');assert.equal(JSON.stringify(ctx.statisticsSupplementalFilters.value[0].value),JSON.stringify(value))
}
ctx.statisticsProductFilter.value='  花园  ';ctx.applyStatisticsFilters();assert.equal(ctx.statisticsSupplementalFilters.value[0].value,'花园');assert.equal(query.filters[0].fieldKey,'batch_no')
ctx.applyStatisticsFilters(true);assert.equal(ctx.statisticsSupplementalFilters.value.length,0);assert.equal(query.filters[0].value,'073')
console.log('PASS expiry six boundaries, age six boundaries, product AND filter, reset')
// 真正请求仍保留高级筛选、门店范围，并把快捷条件固定为 AND 顶层条件。
const calls=[]; const ref=value=>({value})
Object.assign(ctx,{statisticsQueryPage:ref({pageCode:'inventory_statistics_expiry'}),statisticsRequestSequence:0,mode:ref('platform'),dashboardStoreSubject:ref(true),dashboardSelectionType:ref('STORE'),dashboardSelectionId:ref(23),dashboardWarehouseId:ref(0),statisticsDateRange:ref({}),analysisLoading:ref(false),analysisError:ref(''),movementStatisticsLoading:ref(false),movementStatisticsError:ref(''),statisticsPagination:ref({}),batchAnalysis:ref({}),movementStatistics:ref({})})
const api={unifiedOperationalQuery:async q=>{calls.push(q);return {records:[],total:0,page:q.page}}};ctx.inventoryApi=api;ctx.platformInventoryApi=api
vm.runInContext(source.slice(source.indexOf('async function queryStatisticsPage('),source.indexOf('\nasync function loadDashboard()')),ctx)
ctx.activeStatisticsTab.value='expiry';ctx.statisticsBandFilter.value='OVERDUE';ctx.applyStatisticsFilters();await new Promise(r=>setTimeout(r,0))
assert.equal(calls.at(-1).storeId,23);assert.equal(calls.at(-1).filters[0].value,'073');assert.equal(calls.at(-1).topFilterConditions[0].field,'remaining_shelf_life_days')
ctx.mode.value='store';await ctx.queryStatisticsPage(ctx.statisticsExecutedQuery.value,2);assert.equal(calls.at(-1).topFilterConditions[0].value,0);assert.equal('storeId' in calls.at(-1),false)
console.log('PASS shared request composition, advanced filter retention, platform store scope and store isolation')
