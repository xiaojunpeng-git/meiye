import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs'
import { createHash } from 'node:crypto'

// Git blobs preserve the dirty shared baseline without creating editable source copies.
const files = [
  '后端代码/app/services/system/config/SystemConfigServices.php',
  '后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php',
  '后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php',
  '后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php',
  '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue'
]
const output = 'tests/cashier-v3/evidence/R49-before.json'
if (existsSync(output)) throw new Error('R49 baseline already captured; never replace it')
const git = (...args) => execFileSync('git', args, { encoding: 'utf8' }).trim()
const manifest = { head: git('rev-parse', 'HEAD'), remote: '不适用：仅本地开发', files: files.map(path => ({
  path, sha256: createHash('sha256').update(readFileSync(path)).digest('hex'),
  blob: git('hash-object', '-w', '--', path), status: git('status', '--short', '--', path),
  indexBlob: git('rev-parse', `:${path}`)
})) }
mkdirSync('tests/cashier-v3/evidence', { recursive: true })
writeFileSync(output, JSON.stringify(manifest, null, 2) + '\n')
console.log(output)
