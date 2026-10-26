import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const view = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/views/BusinessDashboardView.vue'), 'utf8')

assert.match(view, /import Info from '@lucide\/vue\/dist\/esm\/icons\/info\.mjs'/)
assert.match(view, /const descriptionMetricCode = ref\(''\)/)
assert.match(view, /function toggleDescription\(card = \{\}\)/)
assert.match(view, /class="business-metric-card__info"/)
assert.match(view, /:aria-expanded="isDescriptionOpen\(card\)"/)
assert.match(view, /class="business-metric-card__description-popover" role="tooltip"/)
assert.doesNotMatch(view, /class="business-metric-card__description">/)
assert.match(view, /height: 100%; min-width: 0; min-height: 0; box-sizing: border-box; padding: 20px; overflow-x: hidden; overflow-y: auto/)

console.log('BUSINESS_DASHBOARD_PRESENTATION_CONTRACT_OK')
