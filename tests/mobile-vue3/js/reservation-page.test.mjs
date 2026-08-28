import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot } from './helpers.mjs'

const page = fs.readFileSync(path.join(mobileRoot, 'src', 'merchant', 'pages', 'reservations', 'index.uvue'), 'utf8')
const client = fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'api', 'mobile-reservation-client.uts'), 'utf8')
const sessionClient = fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'api', 'mobile-merchant-session-client.uts'), 'utf8')
const contract = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'src', 'shared', 'contracts', 'mobile-merchant-v1.contract.json'), 'utf8'))
const repoRoot = path.resolve(mobileRoot, '..', '..')
const services = fs.readFileSync(path.join(repoRoot, '后端代码', 'app', 'services', 'mobile', 'reservation', 'MobileReservationServices.php'), 'utf8')
const controller = fs.readFileSync(path.join(repoRoot, '后端代码', 'app', 'controller', 'mobile', 'merchant', 'Reservation.php'), 'utf8')

test('reservation page exposes the unified lifecycle and current-store context', () => {
  for (const name of ['queryMobileReservations', 'queryMobileReservationDetail', 'openMobileReservationEditor', 'queryMobileReservationProjectCatalog', 'createMobileReservation', 'updateMobileReservation', 'cancelMobileReservation', 'startMobileReservationService', 'endMobileReservationService']) assert.equal(page.includes(name), true, name)
  for (const token of ['storeContexts', 'activeStoreLabel', 'switchMobileMerchantContext', '当前门店', '预约数据与门店端实时同步', '重新加载']) assert.equal(page.includes(token), true, token)
  assert.equal(page.includes("if (merchantLoginIsRequired()) { openMerchantLogin(); return }"), true)
})

test('reservation transport uses the shared merchant contract and no legacy endpoint', () => {
  for (const endpoint of ['listReservations', 'reservationDetail', 'openReservationEditor', 'queryReservationProjectCatalog', 'queryReservationMemberCandidates', 'recalculateReservation', 'createReservation', 'updateReservation', 'cancelReservation', 'startReservationService', 'endReservationService']) assert.equal(contract.endpoints[endpoint].requestMetadataProfile, 'MERCHANT_SESSION', endpoint)
  for (const pathValue of ['/mobile/merchant/reservations', '/mobile/merchant/reservations/:reservationId', '/mobile/merchant/reservations/editor', '/mobile/merchant/reservations/project-catalog', '/mobile/merchant/reservations/member-candidates', '/mobile/merchant/reservations/recalculate', '/mobile/merchant/reservations/:reservationId/cancel', '/mobile/merchant/reservations/:reservationId/start-service', '/mobile/merchant/reservations/:reservationId/end-service']) assert.equal(client.includes(pathValue), true, pathValue)
  assert.equal(client.includes('/reservation/'), false)
  assert.equal(sessionClient.includes('switchMobileMerchantContext'), true)
})

test('reservation editor supports create, edit, cancel and explicit error states', () => {
  for (const token of ['openCreateEditor', 'openEditEditor', 'runAction', 'detailActions()', '预约保存失败。', '预约操作未完成。', '预约编辑器暂不可用。']) assert.equal(page.includes(token), true, token)
  assert.equal(page.includes("command(action, version)"), true)
  assert.equal(page.includes('expectedVersion'), true)
})

test('reservation backend remains the shared V3 service and member permission is server checked', () => {
  for (const action of ['open-reservation-detail', 'query-reservation-project-catalog', 'create-reservation', 'update-reservation', 'cancel-reservation', 'start-reservation-service', 'end-reservation-service']) assert.equal(services.includes(`'${action}'`), true, action)
  assert.equal(services.includes("Db::name('cashier_v3_reservation')"), false)
  assert.equal(controller.includes('memberCandidates'), true)
  assert.equal(controller.includes('RESERVATION_CREATE'), true)
})
