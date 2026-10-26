import assert from 'node:assert/strict'
import fs from 'node:fs'

const roomView = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/RoomStatusView.vue', import.meta.url), 'utf8')
const roomDetail = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/components/room/RoomDetailOverlay.vue', import.meta.url), 'utf8')
const reservationList = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/ReservationListView.vue', import.meta.url), 'utf8')

assert.match(roomView, /function isReservationRoomService/, '房态页必须识别预约来源的服务中状态')
assert.match(roomView, /room\.activeReservationServices/, '房态卡片必须使用预约服务集合')
assert.match(roomView, /v-if="!isReservationRoomService\(room\)"/, '预约来源的房态卡片不得展示服务单结束服务操作')
assert.match(roomView, /cashier-v3:open-reservation-detail/, '房态页必须可打开预约详情')
assert.match(roomView, /await router\.push\(\{ name: 'cashier-v3-reservation' \}\)/, '查看预约必须回到预约模块')
assert.match(roomDetail, /defineEmits\(\['close', 'open-reservation'\]\)/, '房间详情必须暴露查看预约事件')
assert.match(roomDetail, /activeReservationServices/, '房间详情必须展示全部服务中的预约')
assert.match(roomDetail, /当前有 \{\{ activeReservationServices\.length \}\} 笔预约服务中/, '同一房间的多笔预约不得折叠丢失')
assert.match(roomDetail, /@click="openReservation\(item\)"/, '每笔预约必须可进入详情')
assert.match(roomDetail, /预约服务请在预约详情中结束，结束后房态自动更新。/, '预约来源详情不得提示房态权限或版本校验')
assert.match(reservationList, /openReservationDetail\(\{ reservationId \}\)/, '从其他模块传入预约标识时必须主动加载完整详情')

console.log('ROOM_RESERVATION_SERVICE_FRONTEND_CONTRACT=PASS')
