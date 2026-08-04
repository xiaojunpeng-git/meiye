#!/usr/bin/env node
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(dirname, '../../..')
const frontend = path.join(repo, '前端代码/cashier-v3/src')
const backend = path.join(repo, '后端代码/app/services/cashier/v3')
const contractPath = path.join(frontend, 'services/cashierV3RoomOpenIntent.js')
const roomViewPath = path.join(frontend, 'views/RoomStatusView.vue')
const cashierViewPath = path.join(frontend, 'views/CashierWorkbenchView.vue')
const overlayPath = path.join(frontend, 'components/cashier/HangOrderOverlay.vue')
const shellPath = path.join(frontend, 'layouts/CashierShell.vue')
const fixturePath = path.join(frontend, 'dev/pdV3FixtureMain.js')
const frontendManifestPath = path.join(frontend, 'services/cashierV3ActionManifest.js')
const backendManifestPath = path.join(backend, 'manifest/CashierV3C3ServiceModule.php')
const backendModulePath = path.join(backend, 'hang/CashierV3HangModule.php')

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

const contract = await import(pathToFileURL(contractPath).href)
const validQuery = {
  roomOpenIntent: '1',
  roomOpenIntentSource: 'room_status_idle',
  roomOpenIntentId: 'ROOM_INTENT-00000001',
  preferredRoomId: '11',
  preferredRoomName: '普通房 01',
  preferredRoomVersion: '17',
  preferredRoomTimeSlotId: 'open-service:11',
  preferredRoomTimeSlotVersion: '31'
}

console.log('== strict room intent query ==')
const intent = contract.roomOpenIntentFromRouteQuery(validQuery)
ok('valid backend query is accepted', intent?.roomId === '11' && intent?.roomVersion === 17)
ok('invalid source is rejected', contract.roomOpenIntentFromRouteQuery({ ...validQuery, roomOpenIntentSource: 'client_guess' }) === null)
ok('unsafe version is rejected', contract.roomOpenIntentFromRouteQuery({ ...validQuery, preferredRoomVersion: '9007199254740992' }) === null)
ok('partial intent is rejected', contract.roomOpenIntentFromRouteQuery({ ...validQuery, preferredRoomTimeSlotId: '' }) === null)
ok('hang payload contains only validated identifiers and versions', JSON.stringify(contract.roomOpenIntentHangPayload(intent)) === JSON.stringify({
  roomOpenIntentSource: 'room_status_idle',
  roomOpenIntentId: 'ROOM_INTENT-00000001',
  preferredRoomId: '11',
  preferredRoomVersion: 17,
  preferredRoomTimeSlotId: 'open-service:11',
  preferredRoomTimeSlotVersion: 31
}))

const roomView = fs.readFileSync(roomViewPath, 'utf8')
const cashierView = fs.readFileSync(cashierViewPath, 'utf8')
const overlay = fs.readFileSync(overlayPath, 'utf8')
const shell = fs.readFileSync(shellPath, 'utf8')
const fixture = fs.readFileSync(fixturePath, 'utf8')
const frontendManifest = fs.readFileSync(frontendManifestPath, 'utf8')
const backendManifest = fs.readFileSync(backendManifestPath, 'utf8')
const backendModule = fs.readFileSync(backendModulePath, 'utf8')

console.log('== room to cashier workflow ==')
ok('idle room exposes the confirmed open-order button', roomView.includes("normalizedRoomStatus(room) === '空闲'") && roomView.includes("'prepare-empty-room-cashier'"))
ok('room action requires a positive server room version', roomView.includes('Number.isSafeInteger(Number(payload.roomVersion))'))
ok('cashier reads the room intent without clearing cart', cashierView.includes('roomOpenIntentFromRouteQuery(route.query)') && !cashierView.includes('clearCartForRoomIntent'))
ok('open hang order sends preferred room back for revalidation', cashierView.includes('roomOpenIntentHangPayload(roomOpenIntent.value)'))
ok('hang preparation reads action-bound response data', cashierView.includes('responseDataBlock(result).hangOrderPreparation'))
ok('overlay defaults to start service and exact preferred room', overlay.includes("preferredMode === 'start_service'") && overlay.includes('props.hangOrder.preferredRoomId'))
ok('cashier banner distinguishes pending room from occupied service room', shell.includes('cashierRoomOpenIntent.roomName') && shell.includes('<strong>待挂单</strong>'))

console.log('== preparation and submit boundaries ==')
ok('frontend and backend manifests contain the projection action', frontendManifest.includes("'prepare-empty-room-cashier': FEATURE_ROOM") && backendManifest.includes("'prepare-empty-room-cashier'"))
ok('backend keeps eventless preparation projections', backendModule.includes("registerProjection('prepare-empty-room-cashier'") && backendModule.includes("registerProjection('open-hang-order'"))
ok('backend activates atomic submit hang order', backendModule.includes("registerCommand('submit-hang-order'") && backendModule.includes('CashierV3HangSubmissionServices'))
ok('submit command exposes only the workspace context', backendModule.includes("'submit-hang-order',\n            ['cashier_workspace'],\n            []") && backendModule.includes("'required_touched_roles' => ['cashier_workspace']"))
ok('frontend binds submit to the frozen preparation room snapshot', cashierView.includes('preparedRoomId: session.snapshot.preferredRoomId') && cashierView.includes('preparedRoomTimeSlotVersion'))
ok('hang overlay reads the top-level command result before response data', overlay.includes('result?.result?.status') && overlay.includes('result?.result?.message'))
ok('fixture keeps every business effect false before submit', [
  'roomOccupied: false',
  'salesCreated: false',
  'serviceCreated: false',
  'writeoffCreated: false',
  'performanceCreated: false',
  'eventCreated: false',
  'outboxCreated: false'
].every((needle) => fixture.includes(needle)))
ok('fixture returns a new preferred snapshot without replacing root state', fixture.includes('actionDataEnvelope({ hangOrderPreparation: snapshot }') && !fixture.includes('state.cashier.cart = []'))

console.log(`\nroom-hang-intent-frontend-contract: ${passed} passed, ${failed} failed`)
if (failed > 0) process.exit(1)
