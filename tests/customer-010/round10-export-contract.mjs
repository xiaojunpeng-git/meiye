/** 执行现有CSV函数与真实后端匿名导出结果；只验证本次12列，不复制维护另一套导出算法。 */
import fs from 'node:fs'
import assert from 'node:assert/strict'
const root=new URL('../../',import.meta.url)
const source=fs.readFileSync(new URL('前端代码/cashier-v3/src/services/basicReportApi.js',root),'utf8')
const body=source.slice(source.indexOf('export function registrationCsv(')).replace(/^export /,'')
const csv=new Function(body+'\nreturn registrationCsv;')()
const fixture=JSON.parse(fs.readFileSync(new URL('tests/customer-010/evidence/round10-employee-summary/browser-fixture.json',root),'utf8'))
const fields=fixture.page.queryFields.filter(f=>f.defaultVisible)
const result=csv(fields,fixture.page.exportRows,fixture.page.summaries,fixture.page.field_sources)
const expected=['门店','职务','姓名','现金业绩','劳动业绩','客数','客单价','项目数','指定客','服务总新客','成交总新客','新客成交率%']
assert.equal(result.split('\r\n')[0],'\ufeff'+expected.map(s=>'"'+s+'"').join(','))
assert.equal(fixture.page.exportRows.length,30)
assert.ok(!result.split('\r\n')[0].includes('手工费'))
assert.ok(result.includes('3.33')&&result.includes('2.33'))
assert.ok(result.includes('298元'))
assert.ok(result.includes('整数百分数'))
assert.ok(!fields.find(f=>f.key==='new_customer_conversion_rate').summable)
const evidence=new URL('tests/customer-010/evidence/round10-employee-summary/export-tests.json',root)
fs.writeFileSync(evidence,JSON.stringify({result:'PASS',checks:7,columns:expected,export_rows:30,source:'existing CSV implementation + actual PHP/SQL/Host synthetic output'},null,2))
console.log('PASS 12 exact columns, 30 full rows, fractional counts, field sources and nonadditive integer rate')
