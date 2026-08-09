import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8')

const adminLayout = read('前端代码/admin/src/layouts/basic-layout/index.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const reservation = read('前端代码/cashier-v3/src/views/ReservationListView.vue')
const memberList = read('前端代码/cashier-v3/src/views/MemberListView.vue')
const memberSelector = read('前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue')
const staffList = read('前端代码/cashier-v3/src/views/StaffListView.vue')
const memberModule = read('后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php')
const memberDetail = read('后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php')
const salesOrderQuery = read('后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php')

assert.match(adminLayout, /path: `\$\{prefix\}\/store\/region\/list\?tab=stores`/)
assert.match(adminLayout, /title: '人员管理'/)
assert.match(adminLayout, /menu_name: '人员管理'/)
assert.match(adminLayout, /path: `\$\{prefix\}\/store\/region\/list\?tab=people`/)
assert.match(adminLayout, /\['stores', 'people'\]\.includes/)

assert.match(reservation, /const viewMode = ref\('calendar'\)/)
assert.equal((reservation.match(/>新增预约</g) || []).length, 0)
assert.equal((shell.match(/>\s*新增预约\s*</g) || []).length, 1)

assert.match(memberModule, /'creatorSchema' => self::memberCreatorSchema\(\)/)
assert.match(memberModule, /SystemConfigService::get\('user_extend_info'/)
assert.match(shell, /result\?\.data\?\.data\?\.creatorSchema/)
assert.match(memberList, /:profile-fields="creatorSchema\.profileFields \|\| creatorSchema\.fields \|\| \[\]"/)
assert.match(memberList, /const detail = detailEnvelope\?\.data\?\.detail/)
assert.match(memberList, /detail: \{ memberId, fallback: detail \}/)
assert.match(memberDetail, /'principalBalance' => \$principalBalance/)
assert.match(memberDetail, /'giftBalance' => \$giftBalance/)
assert.match(memberModule, /new CashierV3SalesOrderQueryServices\(\)/)
assert.match(memberModule, /'memberId' => \$memberId/)
assert.match(memberModule, /\$detail\['salesOrders'\] = array_map/)
assert.match(memberModule, /\$record\['orderTypeLabel'\] = '销售订单'/)
assert.match(memberModule, /\$record\['statusLabel'\]/)
assert.match(salesOrderQuery, /->where\('o\.member_id', \(int\)\$criteria\['memberId'\]\)/)
assert.match(salesOrderQuery, /->where\('o\.uid', \(int\)\$criteria\['memberId'\]\)/)

const staffHeader = shell.slice(shell.indexOf('v-else-if="isStaffPage"'), shell.indexOf('v-else-if="hasPageHelp"'))
assert.ok(staffHeader.indexOf('新增员工') < staffHeader.indexOf('页面说明'))
assert.match(shell, /cashier-v3:open-staff-creator/)
assert.match(staffList, /addEventListener\('cashier-v3:open-staff-creator', openEditor\)/)
assert.doesNotMatch(staffList, /staff-list-page__actions/)

assert.match(memberSelector, /@submit\.prevent="submitQuery"/)
assert.match(memberSelector, /@keydown\.enter\.prevent="submitQuery"/)
assert.match(memberSelector, /CornerDownLeft/)
assert.doesNotMatch(memberSelector, /watch\(keyword/)
assert.doesNotMatch(memberSelector, /setTimeout\([^)]*runQuery/)

console.log('CONFIRMED_NON_INVENTORY_FIXES_CONTRACT_OK')
