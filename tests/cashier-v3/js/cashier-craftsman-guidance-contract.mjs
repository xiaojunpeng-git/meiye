#!/usr/bin/env node
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const read = (relativePath) => fs.readFileSync(path.join(repo, relativePath), 'utf8')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const workbench = read('前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const guidanceService = read('前端代码/cashier-v3/src/services/requiredFieldGuidance.js')
const guidanceComponent = read('前端代码/cashier-v3/src/components/common/RequiredFieldGuidance.vue')

let failed = 0
function check(condition, message) {
  if (condition) console.log(`PASS: ${message}`)
  else { failed += 1; console.error(`FAIL: ${message}`) }
}
function section(source, startMarker, endMarker) {
  const start = source.indexOf(startMarker)
  if (start < 0) return ''
  const end = source.indexOf(endMarker, start + startMarker.length)
  return source.slice(start, end < 0 ? source.length : end)
}

const reportCheckoutFailure = section(workbench, 'function reportCheckoutEntryFailure', '\nasync function selectCatalogItem')
const focusGuidance = section(workbench, 'async function focusRequiredFieldGuidance', '\nfunction cartLineServiceObject')
check(/REQUIRED_FIELD_GUIDANCE_EVENT = 'cashier-v3:required-field-guidance'/.test(guidanceService)
  && /REQUIRED_FIELD_GUIDANCE_DURATION_MS = 3200/.test(guidanceService)
  && /window\.dispatchEvent\(new CustomEvent\(REQUIRED_FIELD_GUIDANCE_EVENT/.test(guidanceService), 'required-field guidance is exposed through one reusable event contract')
check(/code === 'CASHIER_CRAFTSMAN_REQUIRED'/.test(reportCheckoutFailure)
  && /target: 'first-missing-craftsman'/.test(reportCheckoutFailure)
  && /message: '请在这里选择手艺人'/.test(reportCheckoutFailure), 'missing craftsmen directly starts required-field guidance instead of opening feedback')
check(/code === 'CASHIER_BUSINESS_SOURCE_REQUIRED'/.test(reportCheckoutFailure)
  && /target: 'checkout-business-source'/.test(reportCheckoutFailure)
  && /message: '请在这里选择客户来源'/.test(reportCheckoutFailure), 'missing checkout source uses the same required-field guidance contract')
check(!/focusTarget/.test(shell) && !/cashier-v3:focus-guidance/.test(shell), 'the shell no longer waits for a blocking feedback dialog before guiding a required field')
check(/firstCartLineMissingCraftsmen\(\)/.test(focusGuidance)
  && /scrollIntoView\(\{ behavior: 'smooth', block: 'center'/.test(focusGuidance)
  && /focus\(\{ preventScroll: true \}\)/.test(focusGuidance), 'guidance scrolls to and focuses the first missing target')
check(/window\.addEventListener\(REQUIRED_FIELD_GUIDANCE_EVENT, focusRequiredFieldGuidance\)/.test(workbench)
  && /window\.removeEventListener\(REQUIRED_FIELD_GUIDANCE_EVENT, focusRequiredFieldGuidance\)/.test(workbench), 'the cashier page owns and cleans up the shared required-field event listener')
check(/role="status"/.test(guidanceComponent) && /aria-live="polite"/.test(guidanceComponent)
  && /required-field-guidance::before/.test(guidanceComponent), 'the reusable component provides an anchored arrow and live status text')
check(/clearCraftsmanGuidance\(\)/.test(section(workbench, 'async function openCartLineCraftsmen', '\nasync function openCartLineSalespeople')), 'starting to correct the required field immediately clears its guidance')
check(/ref="toolbarBusinessSourceButtonRef"/.test(shell)
  && /:message="toolbarBusinessSourceGuidanceMessage"/.test(shell), 'the shell anchors source guidance to the existing toolbar control')
check(/clearToolbarBusinessSourceGuidance\(\)/.test(section(shell, 'function openToolbarBusinessSource', '\nfunction updateToolbarBusinessDate')), 'opening source selection immediately clears its required guidance')
if (failed > 0) process.exit(1)
console.log('CASHIER_CRAFTSMAN_GUIDANCE_CONTRACT=PASS')
