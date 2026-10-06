import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const module = read('后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php')
const overlay = read('前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue')
const migration = read('后端代码/database/upgrades/2026-10-06-会员姓名首字母检索/02-正式升级.sql')
const dictionary = read('后端代码/database/upgrades/2026-10-06-会员姓名首字母检索/01-汉字首字母映射.sql')

// 首字母只是姓名检索投影，必须沿用选择器的门店可见关系与服务端分页。
const checks = [
  ['selector advertises initials only where enabled', overlay.includes("showScopeToggle ? '姓名或拼音首字母")],
  ['only cashier/reservation selector opts in to initials', /\$supportsExplicitStoreScope && !\$isAllScope,\s*\/\/[^\n]*\n\s*\$supportsExplicitStoreScope/.test(module)],
  ['alpha keyword is normalized before matching', module.includes("preg_match('/^[A-Za-z]{1,60}$/D', $keyword)") && module.includes('strtoupper($keyword)')],
  ['scope remains on the same query', module.includes("join('store_user su'") && module.includes("leftJoin('member_name_initial_search name_search'")],
  ['derived index has prefix index', migration.includes('KEY idx_member_name_initials (initials, uid)')],
  ['dictionary covers common characters outside GBK initial ranges', dictionary.includes("('倩','Q')") && dictionary.includes("('婷','T')") && dictionary.includes("('璐','L')")],
  ['all insert and rename paths synchronize through user triggers', migration.includes('AFTER INSERT ON eb_user') && migration.includes('AFTER UPDATE ON eb_user')],
  ['migration backfills history', migration.includes('INSERT IGNORE INTO eb_member_name_initial_search')],
]

let failures = 0
for (const [name, passed] of checks) {
  console.log(`${passed ? 'PASS' : 'FAIL'} ${name}`)
  if (!passed) failures += 1
}
process.exitCode = failures ? 1 : 0
