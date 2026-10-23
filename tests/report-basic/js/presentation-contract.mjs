import assert from 'node:assert/strict'
import { formatReportMoney } from '../../../前端代码/shared/unified-query-vue3/src/contracts/reportPresentation.js'

// Presentation rounding never changes the original value passed to filters/exports.
const cases = [[123.45, '¥123'], [123.5, '¥124'], [-123.5, '¥-124'], [-0.49, '¥0'],
  [0, '¥0'], [1000, '¥1,000'], [null, '-'], [undefined, '-'], ['', '-'], ['not-money', '-']]
for (const [input, expected] of cases) assert.equal(formatReportMoney(input), expected)
console.log(JSON.stringify({ pass: true, checks: cases.length, scope: 'whole-yuan display only' }))
