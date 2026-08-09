import assert from 'node:assert/strict'
import fs from 'node:fs'

const source = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/ReservationListView.vue', import.meta.url), 'utf8')
const provider = fs.readFileSync(new URL('../../../后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php', import.meta.url), 'utf8')
const reservationModule = fs.readFileSync(new URL('../../../后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php', import.meta.url), 'utf8')
const baseCss = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/styles/base.css', import.meta.url), 'utf8')

assert.match(source, /blockStart >= slotStart[\s\S]*blockStart < slotEnd/, '非整点预约必须归入所属半小时时段')
assert.match(source, /const offsetRatio = \(startMinutes % 30\) \/ 30/, '预约卡片必须保留真实分钟偏移')
assert.match(source, /Math\.ceil\(\(endMinutes - startMinutes\) \/ 30\)/, '卡片高度必须与 30 分钟日历行一致')
assert.match(provider, /BUSINESS_TIMEZONE = 'Asia\/Shanghai'/, '预约业务时间必须固定使用门店业务时区')
assert.match(provider, /field\('day_start,day_end'\)/, '日历必须读取门店营业时间')
assert.match(provider, /营业时间只限制新预约的可选时段/, '营业时间不能隐藏已经创建的预约')
assert.match(provider, /\$resources = \[\['id' => 'staff:unassigned'/, '按手艺人时未分配必须排在首列')
assert.match(provider, /\$roomResources = \[\['id' => 'room:unassigned'/, '按房间时未分配必须排在首列')
assert.match(reservationModule, /CashierV3BusinessDocumentNumberServices::RESERVATION/, '预约必须使用统一号段服务')
assert.match(baseCss, /grid-template-columns: 82px repeat\(var\(--reservation-resource-count\), minmax\(0, 1fr\)\)/, '人员列不得通过最小宽度制造横向滚动')
assert.match(baseCss, /\.reservation-calendar \{[\s\S]*?overflow-x: hidden;/, '日历人员列区域必须禁止横向滑动')

console.log('RESERVATION_CALENDAR_CONTRACT=PASS')
