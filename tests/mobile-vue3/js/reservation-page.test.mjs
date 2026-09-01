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

test('service page uses actual-start timing, displays overtime and never auto-ends service', () => {
  for (const token of ["workflow: 'service'", 'startMobileReservationService', 'endMobileReservationService', 'actualServiceStartedAt', 'serviceCountdownEndsAt', 'totalDurationSeconds', 'serviceExpectedEndMs', '已超时 ', '不会自动结束服务']) assert.equal(servicePage.includes(token), true, token)
  assert.equal(servicePage.includes('endMobileReservationService(detailId.value, payload, done)'), true)
  assert.equal(servicePage.includes('setTimeout(() => endMobileReservationService'), false)
  assert.equal(servicePage.includes('setInterval(() => endMobileReservationService'), false)
  for (const forbidden of ['历史预约', '版本记录', 'cancelMobileReservation', 'createMobileReservation', 'updateMobileReservation']) assert.equal(servicePage.includes(forbidden), false, forbidden)
  assert.equal(servicePage.includes('expectedVersion: version'), true)
})

test('reservation transport declares V3 confirmation, rejection and service commands without legacy endpoints', () => {
  for (const endpoint of ['listReservations', 'reservationDetail', 'confirmReservation', 'rejectReservation', 'startReservationService', 'endReservationService']) assert.equal(contract.endpoints[endpoint].requestMetadataProfile, 'MERCHANT_SESSION', endpoint)
  assert.deepEqual(contract.endpoints.rejectReservation.requestBodyRequired, ['command', 'reason'])
  for (const pathValue of ['/mobile/merchant/reservations', '/mobile/merchant/reservations/:reservationId', '/mobile/merchant/reservations/:reservationId/confirm', '/mobile/merchant/reservations/:reservationId/reject', '/mobile/merchant/reservations/:reservationId/start-service', '/mobile/merchant/reservations/:reservationId/end-service']) assert.equal(client.includes(pathValue), true, pathValue)
  assert.equal(client.includes('/reservation/'), false)
  assert.equal(sessionClient.includes('switchMobileMerchantContext'), true)
})
