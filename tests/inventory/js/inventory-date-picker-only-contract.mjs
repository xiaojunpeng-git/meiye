import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { preventInventoryDateKeyboardInput, preventInventoryDateTextTransfer } from '../../../前端代码/inventory-vue3/src/inventoryDatePickerOnly.js'

function event(type, key = '') {
  let prevented = false
  return {
    target: { tagName: 'INPUT', type }, key,
    preventDefault() { prevented = true },
    get prevented() { return prevented }
  }
}

const digit = event('date', '3')
assert.equal(preventInventoryDateKeyboardInput(digit), true)
assert.equal(digit.prevented, true)

for (const key of ['Tab', 'Escape', 'Enter', ' ']) {
  const navigation = event('date', key)
  assert.equal(preventInventoryDateKeyboardInput(navigation), false)
  assert.equal(navigation.prevented, false)
}

const ordinaryText = event('text', '3')
assert.equal(preventInventoryDateKeyboardInput(ordinaryText), false)
assert.equal(ordinaryText.prevented, false)

for (const type of ['beforeinput', 'paste', 'drop']) {
  const transfer = event('date')
  assert.equal(preventInventoryDateTextTransfer(transfer), true, `${type} must be blocked for date inputs`)
  assert.equal(transfer.prevented, true)
}

const app = readFileSync(new URL('../../../前端代码/inventory-vue3/src/App.vue', import.meta.url), 'utf8')
assert.match(app, /@keydown\.capture="preventInventoryDateKeyboardInput"/)
assert.match(app, /@beforeinput\.capture="preventInventoryDateTextTransfer"/)
assert.match(app, /@paste\.capture="preventInventoryDateTextTransfer"/)
assert.match(app, /@drop\.capture="preventInventoryDateTextTransfer"/)
console.log('INVENTORY_DATE_PICKER_ONLY_CONTRACT_OK')
