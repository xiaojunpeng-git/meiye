// 日历边界与统一接入回归：只发布完整日期，不改动业务操作。
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { periodRange, monthDays, parseDateKey } from '../../../前端代码/shared/unified-query-vue3/src/contracts/dateRange.js'
const now = new Date(2026,8,27,12)
for (const [key,min,max] of [['today','2026-09-27','2026-09-27'],['yesterday','2026-09-26','2026-09-26'],['last7','2026-09-21','2026-09-27'],['last30','2026-08-29','2026-09-27'],['lastMonth','2026-08-01','2026-08-31'],['month','2026-09-01','2026-09-30'],['year','2026-01-01','2026-12-31']]) assert.deepEqual(periodRange(key,now),{min,max})
assert.deepEqual(periodRange('lastMonth',new Date(2024,2,1,12)),{min:'2024-02-01',max:'2024-02-29'})
assert.deepEqual(periodRange('yesterday',new Date(2026,0,1,12)),{min:'2025-12-31',max:'2025-12-31'})
assert.equal(parseDateKey('2026-02-30'),null)
assert.equal(monthDays(now).length,42)
assert.equal(monthDays(now)[0].key,'2026-08-30')
const toolbar=readFileSync('前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue','utf8')
assert.equal((toolbar.match(/<UnifiedQueryDateRange /g)||[]).length,2)
assert.ok(!toolbar.includes('unified-query-top-field__range--date'))
assert.match(toolbar,/function applyDateRange[\s\S]*?submitQuery\(\)/)
const picker=readFileSync('前端代码/shared/unified-query-vue3/src/components/UnifiedQueryDateRange.vue','utf8')
const styles=readFileSync('前端代码/shared/unified-query-vue3/src/styles/unified-query.css','utf8')
assert.ok(!picker.includes('uq-period-inputs'))
assert.match(styles,/\.unified-query-top-field\.unified-query-top-field--label-hidden\s*\{\s*grid-template-columns: minmax\(0, 1fr\)/)
console.log('PASS: periods, boundaries, shared submit, full-width trigger and calendar-only footer')
