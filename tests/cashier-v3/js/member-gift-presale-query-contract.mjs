import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8')
const overlay = read('前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue')
const shell = read('前端代码/cashier-v3/src/layouts/CashierShell.vue')
const service = read('后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php')

// 当前有效记录是跨日期的会员权益；旧页面把当天日期传给后端导致迁入数据消失。
assert.match(overlay, /\{ key: 'gift', label: '赠送\/预售' \}/)
assert.match(overlay, /\['assets', 'care', 'gift'\]\.includes\(activeTab\.value\) \|\| tabStatus\.value === ''/)
assert.match(overlay, /tab\.key === 'gift' && tabStatus\.value === 'active' \? '' : tabDateFrom\.value/)
assert.match(overlay, /activeTab\.value === 'gift' && tabStatus\.value === 'active' \? '' : tabDateFrom\.value/)
assert.match(shell, /const initialStatus = \['assets', 'gift'\]\.includes\(memberDetailInitialTab\.value\)/)
assert.match(service, /\$detailQuery\['tab'\] === 'gift' && \$giftStatus === '' \? \$detailQuery\['dateFrom'\] : ''/)
assert.match(service, /private function migratedProjectGifts\(/)
assert.match(service, /private function claimableGiftAndPresale\(/)
// 会员详情按会员展示全门店历史，不能再用来源门店筛掉迁入赠送。
assert.doesNotMatch(service, /\$this->applyStoreScope\(/)
assert.match(service, /\$this->assertVisible\(\$memberId, \$operatorScope, \$dataScope\)/)
assert.match(service, /isset\(\$issuedGiftItems\[/)
console.log('MEMBER_GIFT_PRESALE_QUERY_CONTRACT_OK')
