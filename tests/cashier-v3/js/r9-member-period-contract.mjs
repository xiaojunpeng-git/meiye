import assert from 'node:assert/strict'
import fs from 'node:fs'
import { pathToFileURL } from 'node:url'
import path from 'node:path'
import { execFileSync } from 'node:child_process'
import { reportFilterDraft, buildReportFilterQuery } from '../../../前端代码/shared/unified-query-vue3/src/contracts/reportFilters.js'
const root = process.cwd()
const helper = fs.readFileSync('前端代码/cashier-v3/src/services/memberReportFilters.js', 'utf8').replace("'@mohe/unified-query-vue3'", JSON.stringify(pathToFileURL(path.join(root, '前端代码/shared/unified-query-vue3/src/contracts/unifiedQueryContract.js')).href))
const {memberReportFields, memberReportDraftSnapshot, buildMemberReportQuery} = await import('data:text/javascript;base64,' + Buffer.from(helper).toString('base64'))
const backend = JSON.parse(execFileSync('php', ['tests/cashier-v3/php/r55-member-filter-metadata.php'], {encoding:'utf8'}))
const fields = memberReportFields(backend.fields)
const dates = fields.filter(f => f.filter.type === 'date')
assert.deepEqual(dates.map(f=>f.key), ['birthday','latest_purchase_date','latest_visit_date','created_at'])
assert.ok(dates.every(f=>f.filter.range))
// 日期时间仅在元数据明确开启时转换，其他页面的精确时刻条件不受影响。
assert.equal(memberReportFields([{key:'custom_date',type:'datetime',capabilities:{filter:true}}])[0].filter.dateTime,true)
for (const pageCode of ['member_list','platform_member_list']) {
  const original={pageCode,page:2,store_ids:[9],topFilters:[],filters:[{field:'member_name',operator:'contains',value:'保留'}],filterRelation:'any'}
  const draft=reportFilterDraft(fields)
  for(const f of dates)draft[f.key]={min:'2026-10-01',max:'2026-10-31'}
  const panel=buildReportFilterQuery(fields,draft,memberReportDraftSnapshot(original,pageCode),pageCode)
  const query=buildMemberReportQuery(original,panel,' keyword ','normal',20,false,'',pageCode)
  assert.equal(query.pageCode,pageCode); assert.equal(query.page,1)
  assert.deepEqual(query.store_ids,[9]);assert.deepEqual(query.filters,original.filters)
  for(const f of dates){
    const values=query.topFilters.filter(c=>c.field===f.key).map(c=>c.value)
    assert.deepEqual(values,f.key==='created_at'?['2026-10-01 00:00:00','2026-10-31 23:59:59']:['2026-10-01','2026-10-31'])
  }
  assert.deepEqual(reportFilterDraft(fields,{filters:query.topFilters}),draft)
  assert.deepEqual(memberReportDraftSnapshot({...original,pageCode:'other'},pageCode).filters,[])
}
const exact={filters:[{field:'created_at',operator:'gte',value:'2026-10-01 13:05:00'}]}
assert.deepEqual(buildReportFilterQuery(fields,reportFilterDraft(fields,exact),exact).filters,exact.filters)
assert.throws(()=>buildReportFilterQuery(fields,{birthday:{min:'2026-02-30',max:'2026-03-01'}}),/有效日期/)
const plain=[{key:'at',type:'datetime-local',filter:{enabled:true}}]
assert.deepEqual(buildReportFilterQuery(plain,{at:'2026-10-01T13:05'}).filters,[{field:'at',operator:'eq',value:'2026-10-01 13:05:00'}])
console.log('PASS: both member page identities, all four date periods, full-day timestamp bounds, exact filters retained, invalid dates rejected; backend existing contract '+backend.checks+' checks')
