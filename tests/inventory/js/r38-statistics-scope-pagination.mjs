// 执行正本统计查询函数，覆盖总部范围继承、翻页、越界、并发旧响应和返回入口。
import fs from 'node:fs'; import vm from 'node:vm'; import assert from 'node:assert/strict'
const source = fs.readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue',import.meta.url),'utf8')
const body = source.slice(source.indexOf('async function queryStatisticsPage('),source.indexOf('\nasync function loadDashboard()'))
const calls=[]; let reply=q=>({records:[{record_id:'batch:'+q.page}],total:25,page:q.page})
const ref=value=>({value});const api={unifiedOperationalQuery:async q=>{calls.push(q);return reply(q)}}
const ctx={mode:ref('platform'),dashboardWarehouseId:ref(0),platformInventoryApi:api,inventoryApi:api,statisticsQueryPage:ref({pageCode:'inventory_statistics_expiry'}),activeStatisticsTab:ref('expiry'),statisticsExecutedQuery:ref({}),statisticsPagination:ref({page:1,total:0,limit:20}),statisticsRequestSequence:0,statisticsDateRange:ref({min:'2026-10-01',max:'2026-10-27'}),movementStatisticsLoading:ref(false),movementStatisticsError:ref(''),analysisLoading:ref(false),analysisError:ref(''),movementStatistics:ref({}),batchAnalysis:ref({})}
vm.createContext(ctx);vm.runInContext(body,ctx)
const query={filters:[{fieldKey:'remaining_shelf_life_days',operator:'greater_than',value:90}]}
await ctx.queryStatisticsPage(query);assert.equal(calls.at(-1).hq_location_id,0);assert.equal(ctx.statisticsPagination.value.total,25)
ctx.turnStatisticsPage(1);await new Promise(r=>setTimeout(r,0));assert.equal(calls.at(-1).page,2);assert.equal(calls.at(-1).filters[0].value,90)
ctx.dashboardWarehouseId.value=142;await ctx.queryStatisticsPage(query);assert.equal(calls.at(-1).hq_location_id,142)
reply=q=>({records:[],total:2,page:q.page});await ctx.queryStatisticsPage({},2);assert.equal(calls.at(-1).page,1)
let finish; reply=q=>new Promise(r=>finish=r);const old=ctx.queryStatisticsPage({});ctx.statisticsRequestSequence++;finish({records:[{record_id:'stale'}],total:1,page:1});await old;assert.notEqual(ctx.batchAnalysis.value.list[0]?.record_id,'stale')
ctx.mode.value='store';reply=q=>({records:[],total:0,page:1});await ctx.queryStatisticsPage({});assert.equal('hq_location_id' in calls.at(-1),false);assert.equal(ctx.statisticsPagination.value.total,0)
assert.ok(source.includes('返回库存首页'));assert.ok(source.includes('@click="selectPage(\'overview\')"'))
console.log('PASS headquarters all/single scope, submitted filters, page 2, clamp, stale-response rejection, store isolation, empty state, return entry')
