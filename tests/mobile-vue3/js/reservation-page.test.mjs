import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot } from './helpers.mjs'

const servicePage = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'reservations', 'index.uvue'), 'utf8')
const confirmationPage = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'reservation-confirmations', 'index.uvue'), 'utf8')
const workbench = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'workbench', 'index.uvue'), 'utf8')
const client = fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'api', 'mobile-reservation-client.uts'), 'utf8')
const sessionClient = fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'api', 'mobile-merchant-session-client.uts'), 'utf8')
const pages = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'pages.json'), 'utf8'))
const contract = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'contracts', 'mobile-merchant-v1.contract.json'), 'utf8'))

test('merchant workbench exposes confirmation and service as two independent reservation functions', () => {
  for (const token of ['预约确认', '预约服务', '/src/merchant/pages/reservation-confirmations/index', '/src/merchant/pages/reservations/index']) assert.equal(workbench.includes(token), true, token)
  assert.equal(workbench.includes('预约管理</text>'), false)
  const pagePaths = pages.pages.map(page => page.path)
  assert.equal(pagePaths.includes('src/merchant/pages/reservation-confirmations/index'), true)
  assert.equal(pagePaths.includes('src/merchant/pages/reservations/index'), true)
})

test('confirmation page only handles pending V3 reservations and requires a rejection reason', () => {
  for (const token of ["workflow: 'confirmation'", "quickFilter: 'pending_confirmation'", 'confirmMobileReservation', 'rejectMobileReservation', '请填写拒绝原因', "action == 'confirm-reservation'", "action == 'reject-reservation'"]) assert.equal(confirmationPage.includes(token), true, token)
  for (const forbidden of ['legacy', '历史预约', '版本记录', 'cancelMobileReservation', 'startMobileReservationService']) assert.equal(confirmationPage.includes(forbidden), false, forbidden)
  assert.equal(confirmationPage.includes('expectedVersion: version'), true)
})

test('merchant confirmation edits the same pending reservation while keeping customer read-only', () => {
  for (const token of ['修改并确认', '客户姓名（不可修改）', '预约项目', '预约时间', '手艺人（可不选）', '房间（可不选）', '暂不分配', '当前门店没有可预约的手艺人。', '当前门店未配置可用房间，本次可暂不分配。', '保存并确认', "mode: 'edit'", 'openMobileReservationEditor', 'payload.reservation = reservation']) {
    assert.equal(confirmationPage.includes(token), true, token)
  }
  assert.equal(/v-for="staff[^>]*v-if=/.test(confirmationPage), false)
  assert.equal(/v-for="room[^>]*v-if=/.test(confirmationPage), false)
  assert.equal(confirmationPage.includes('queryMobileReservationMembers'), false)
  assert.equal(confirmationPage.includes('updateMobileReservation'), false)
})

test('merchant confirmation schedules before optional projects and keeps an explicit end time', () => {
  const scheduleIndex = confirmationPage.indexOf('预约时间')
  const projectsIndex = confirmationPage.indexOf('预约项目（可不选）')
  assert.equal(scheduleIndex >= 0 && projectsIndex > scheduleIndex, true)
  for (const token of ['appointmentStartClock', 'appointmentEndClock', 'appointmentStartAt()', 'appointmentEndAt()', 'changeAppointmentEnd', '结束时间必须在预约当日且晚于开始时间。', '项目可暂不选，本次仅占用预约时段。']) assert.equal(confirmationPage.includes(token), true, token)
  assert.equal(confirmationPage.includes('nextDate(date)'), false)
  assert.equal(confirmationPage.includes('selectedProjects.value.length == 0 ||'), false)
  assert.equal(confirmationPage.includes('selectedProjects.value.length == 0 &&'), false)
})

test('merchant confirmation recalculates only after schedule or project changes', () => {
  for (const token of ['recalculateMobileReservation', 'refreshSuggestedEnd()', 'changeAppointmentDate', 'changeAppointmentStart', 'toggleProject(project)', 'appointmentEndClock.value = String(value || \'\').slice(0, 5)']) assert.equal(confirmationPage.includes(token), true, token)
  assert.equal(confirmationPage.includes('function toggleCraftsman(staff : any)'), true)
  assert.equal(confirmationPage.includes('toggleCraftsman(staff) : void { if (staff.selectable === false) return; refreshSuggestedEnd'), false)
})

test('service page uses actual-start timing, displays overtime and never auto-ends service', () => {
  for (const token of ["workflow: 'service'", 'startMobileReservationService', 'endMobileReservationService', 'actualServiceStartedAt', 'serviceCountdownEndsAt', 'totalDurationSeconds', 'serviceExpectedEndMs', '已超时 ', '不会自动结束服务']) assert.equal(servicePage.includes(token), true, token)
  for (const token of ["let status = String(entity.statusCode || entity.status || '')", "status != 'IN_SERVICE' && status != '服务中'", 'entity.actualServiceEndedAt || entity.actualEndAt']) assert.equal(servicePage.includes(token), true, token)
  assert.equal(servicePage.includes('endMobileReservationService(detailId.value, payload, done)'), true)
  assert.equal(servicePage.includes('setTimeout(() => endMobileReservationService'), false)
  assert.equal(servicePage.includes('setInterval(() => endMobileReservationService'), false)
for (const forbidden of ['历史预约', '版本记录']) assert.equal(servicePage.includes(forbidden), false, forbidden)
  assert.equal(servicePage.includes('expectedVersion: version'), true)
})

test('service tab discards responses from an earlier filter or page', () => {
  for (const token of ['let listRequestSerial = 0', 'let requestedFilter = activeFilter.value', 'let requestSerial = ++listRequestSerial', 'requestSerial != listRequestSerial || requestedFilter != activeFilter.value', 'loadingMore.value = false']) assert.equal(servicePage.includes(token), true, token)
  // 列表投影的 statusCode 才是状态码；status 是中文标签，已结束不能回落成待服务。
  for (const token of ["entity.statusCode || entity.status", "code == 'COMPLETED') return '已结束'", "code == 'UNSTARTED') return '待服务'"]) assert.equal(servicePage.includes(token), true, token)
})

test('service detail offers edit beside start only before service and reuses the prepared draft', () => {
  for (const token of ["detail.value.status != 'UNSTARTED'", "action == 'edit-reservation'", 'canEditUnstarted()', 'openEditEditor', "mode: 'edit'", 'draft.reservationVersion', 'scheduleParts(draft.appointmentStartAt', 'editingReservationId.value > 0', 'updateMobileReservation(editingId, payload, done)', 'draft.reservationVersion = editingReservationVersion.value']) assert.equal(servicePage.includes(token), true, token)
  assert.equal(servicePage.indexOf('@tap="runAction(action.action)"') < servicePage.indexOf('@tap="openEditEditor"'), true)
  assert.equal(servicePage.includes("if (editingReservationId.value > 0) return; memberPickerOpen.value = true"), true)
})

test('unstarted reservation deletion follows the server action, version and confirmation boundary', () => {
  for (const token of ["detail.value.status != 'UNSTARTED'", "action == 'cancel-reservation' && detail.value.actions[i].enabled === true", 'confirmMobileAction(\'删除预约\'', "command('cancel-reservation', version)", 'cancelMobileReservation(reservationId', 'closeDetail(); loadReservations(true)']) assert.equal(servicePage.includes(token), true, token)
  assert.equal(servicePage.indexOf('@tap="openEditEditor"') < servicePage.indexOf('@tap="deleteUnstartedReservation"'), true)
  assert.equal(contract.endpoints.cancelReservation.method, 'DELETE')
  assert.equal(contract.endpoints.cancelReservation.path, '/mobile/merchant/reservations/:reservationId')
  assert.equal(contract.endpoints.cancelReservation.authorization, 'RESERVATION_MANAGE_AND_SERVER_DATA_SCOPE')
})

test('service page creates through the V3 empty-context contract and requests store-scoped history', () => {
  for (const token of ['aria-label="新增预约"', 'openMobileReservationEditor', 'createMobileReservation', 'businessSourcePrimaryId', "allDates: true", 'contexts: []']) assert.equal(servicePage.includes(token), true, token)
  assert.equal(servicePage.includes('editorCommandContexts'), false)
  assert.equal(servicePage.includes('reservation-page__metrics'), false)
  assert.equal(servicePage.includes('会员端和门店端的新 V3 预约会同步到这里'), false)
})

test('service start gives a mobile-side prerequisite prompt without requiring a room', () => {
  for (const token of ['startServiceMissingFields', "missing.push('项目')", "missing.push('手艺人')", '开始服务前请先补充', '房间可不选。']) assert.equal(servicePage.includes(token), true, token)
})

test('reservation transport declares V3 confirmation, rejection and service commands without legacy endpoints', () => {
  for (const endpoint of ['listReservations', 'reservationDetail', 'confirmReservation', 'rejectReservation', 'startReservationService', 'endReservationService']) assert.equal(contract.endpoints[endpoint].requestMetadataProfile, 'MERCHANT_SESSION', endpoint)
  assert.deepEqual(contract.endpoints.rejectReservation.requestBodyRequired, ['command', 'reason'])
  for (const pathValue of ['/mobile/merchant/reservations', '/mobile/merchant/reservations/:reservationId', '/mobile/merchant/reservations/:reservationId/confirm', '/mobile/merchant/reservations/:reservationId/reject', '/mobile/merchant/reservations/:reservationId/start-service', '/mobile/merchant/reservations/:reservationId/end-service']) assert.equal(client.includes(pathValue), true, pathValue)
  assert.equal(client.includes('/reservation/'), false)
  assert.equal(sessionClient.includes('switchMobileMerchantContext'), true)
})
