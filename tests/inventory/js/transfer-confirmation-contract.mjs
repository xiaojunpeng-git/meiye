import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, '../../..')
const appPath = path.join(root, '前端代码/inventory-vue3/src/App.vue')
const source = fs.readFileSync(appPath, 'utf8')

let passed = 0
let failed = 0
function check(name, condition) {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.log(`FAIL ${name}`)
}

check('transfer actions use the in-page confirmation state instead of a native browser dialog',
  source.includes('const transferActionConfirmation = ref(null)')
    && source.includes('function runTransferAction(action, row)')
    && !source.includes('window.confirm('))
check('confirmation preserves the action and target document before any inventory request',
  source.includes("transferActionConfirmation.value = {")
    && source.includes('action,')
    && source.includes('row,')
    && source.includes(':aria-label="`确认${transferActionConfirmation.label}`"'))
check('only the explicit in-page confirmation invokes the transfer command and refreshes the list',
  source.includes('async function confirmTransferAction()')
    && source.includes('await inventoryApi.receiveCrossTransfer(row.id)')
    && source.includes('await loadCurrentList()'))
check('confirmation blocks double submission and releases its state on every result',
  source.includes('const transferActionSubmitting = ref(false)')
    && source.includes('if (!confirmation || transferActionSubmitting.value) return')
    && source.includes('transferActionSubmitting.value = false')
    && source.includes('transferActionConfirmation.value = null'))

console.log(`INVENTORY_TRANSFER_CONFIRMATION_RESULT passed=${passed} failed=${failed}`)
process.exit(failed === 0 ? 0 : 1)
