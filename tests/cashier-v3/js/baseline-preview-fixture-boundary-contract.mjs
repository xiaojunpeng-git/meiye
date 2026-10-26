import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const view = fs.readFileSync(
  path.join(root, '前端代码/cashier-v3/src/views/RoutePlaceholderView.vue'),
  'utf8'
)

const assertions = [
  ['production view has no static preview fixture import', !view.includes("from '@/dev/customerCarePreviewData'")],
  ['preview is restricted to local development and explicit query', view.includes('import.meta.env.DEV') && view.includes("new URLSearchParams(window.location.search).get('preview') === '1'")],
  ['preview fixture is loaded outside the build module graph', view.includes("const previewModulePath = '/src/dev/customerCarePreviewData.js'") && view.includes('import(/* @vite-ignore */ previewModulePath)')],
  ['missing fixture falls back to the real workbench loader', view.includes('await loadCareProjection()') && view.includes('catch (_)')],
  ['preview state remains reactive after asynchronous loading', view.includes('const isPreview = ref(false)') && view.includes('isPreview.value = true')]
]

let failed = 0
for (const [name, passed] of assertions) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failed += 1
}

console.log(`\n${assertions.length - failed} passed, ${failed} failed`)
process.exit(failed === 0 ? 0 : 1)
