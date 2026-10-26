import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const overlay = fs.readFileSync(path.join(root, '前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue'), 'utf8')

assert.match(overlay, /\{ key: 'points', label: '积分变动记录' \},\s*\{ key: 'archive', label: '会员档案' \}/)
assert.match(overlay, /const archiveRows = computed\(\(\) => \[/)
assert.doesNotMatch(overlay, /const archiveRows = computed\(\(\) => compactRows/)
assert.match(overlay, /会员等级', value: profileValue\(\['memberLevel', 'levelName', 'level'\]\) \|\| '普通会员'/)
for (const label of ['性别', '生日', '身份证', '地址', '会员等级', '会员标签', '备注']) {
  assert.match(overlay, new RegExp(`label: '${label}'`))
}
assert.doesNotMatch(overlay, /const customFields[\s\S]*?\.filter\(\(field\) => hasValue\(field\.value\)\)/)
assert.match(overlay, /v-for="field in customFields"[^\n]*<dd>\{\{ text\(field\.value\) \}\}<\/dd>/)
assert.match(overlay, /activeTab === 'archive'/)
assert.match(overlay, /\.member-detail-overlay__archive-custom h4\s*\{[\s\S]*?margin: 0 17px 12px;[\s\S]*?padding: 0;/)

console.log('MEMBER_PROFILE_ARCHIVE_FRONTEND_CONTRACT_OK')
