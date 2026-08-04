#!/usr/bin/env node
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const contractPath = path.join(repo, '前端代码/cashier-v3/src/services/cashierV3HangOrderResultContract.js')
const workbenchPath = path.join(repo, '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue')
const overlayPath = path.join(repo, '前端代码/cashier-v3/src/components/cashier/HangOrderOverlay.vue')
const { authoritativeHangOrderResult } = await import(pathToFileURL(contractPath).href)

let passed = 0
let failed = 0
function ok(name, condition, detail = '') {
  if (condition) {
    passed += 1
    console.log(`PASS ${name}`)
    return
  }
  failed += 1
  console.log(`FAIL ${name}${detail ? `: ${detail}` : ''}`)
}

const originalIdempotencyKey = 'HANG-123e4567-e89b-42d3-a456-426614174000'
const envelope = {
  result: { status: 'success', code: '', message: 'ok' },
  stateContextId: 'ctx-hang-recovery-1',
  boundAction: 'query-hang-order-result',
  boundCanonical: 'query-hang-order-result',
  boundOriginalIdempotencyKey: originalIdempotencyKey,
  correlationId: 'CORR-hang-query-1',
  boundCorrelationId: 'CORR-hang-query-1',
  data: {
    hangOrderResult: {
      contractVersion: 'cashier-v3-hang-order-result-v1',
      originalIdempotencyKey,
      status: 'success',
      phase: 'succeeded',
      code: '',
      message: '挂单已完成，房间已自动占用。',
      hangOrder: {
        hangOrderId: 'HGO0123456789abcdef0123456789abcdef01234567',
        hangOrderNo: 'HG20260730ABCDEF0123456789',
        hangMode: 'start_service',
        hangStatus: 'service_in_progress',
        hangVersion: 1,
        lineCount: 1,
        totalQuantity: 1,
        room: { roomId: 11, roomTimeSlotId: 'open-service:11', occupied: true }
      }
    }
  }
}

const verified = authoritativeHangOrderResult(envelope, { originalIdempotencyKey, stateContextId: 'ctx-hang-recovery-1' })
ok('verified result requires matching action, original key, correlation and room occupation', verified?.status === 'succeeded')
const clone = (value) => JSON.parse(JSON.stringify(value))
const tampered = clone(envelope)
tampered.data.hangOrderResult.hangOrder.room.occupied = false
ok('unoccupied start-service result is rejected', authoritativeHangOrderResult(tampered, { originalIdempotencyKey, stateContextId: 'ctx-hang-recovery-1' }) === null)
const unknown = clone(envelope)
unknown.data.hangOrderResult = {
  contractVersion: 'cashier-v3-hang-order-result-v1',
  originalIdempotencyKey,
  status: 'result_unknown',
  phase: 'pending',
  code: 'COMMAND_RESULT_UNKNOWN',
  message: '处理中',
  hangOrder: null
}
ok('pending recovery stays blocked rather than closing the overlay', authoritativeHangOrderResult(unknown, { originalIdempotencyKey, stateContextId: 'ctx-hang-recovery-1' })?.status === 'result_unknown')

const workbench = await (await import('node:fs/promises')).readFile(workbenchPath, 'utf8')
const overlay = await (await import('node:fs/promises')).readFile(overlayPath, 'utf8')
const overlayResultStatus = overlay.slice(
  overlay.indexOf('function resultStatus(result)'),
  overlay.indexOf('\n}\n\nfunction resultMessage')
)
ok('workbench elevates only verified hang recovery state to overlay status',
  workbench.includes('authoritativeHangOrderResult(result')
  && workbench.includes("code: 'HANG_ORDER_RESULT_UNVERIFIABLE'")
)
ok('route cleanup cannot downgrade a successful persisted hang order',
  workbench.includes('hangOrderSession.value = null\n    hangOrderPreparationId.value = null\n    try {\n      await router.replace({ query })')
  && workbench.includes("console.warn('Failed to clear completed hang-order route intent.', error)")
)
ok('hang overlay resolves the nested V3 business result before the HTTP envelope status',
  overlayResultStatus.indexOf('|| nested?.result?.status') < overlayResultStatus.indexOf('|| result?.status\n')
)

console.log(`\nhang-order-result-recovery-frontend-contract: ${passed} passed, ${failed} failed`)
if (failed > 0) process.exit(1)
