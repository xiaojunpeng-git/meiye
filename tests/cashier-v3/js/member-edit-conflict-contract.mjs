import assert from 'node:assert/strict'
import fs from 'node:fs'

const memberList = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/MemberListView.vue', import.meta.url), 'utf8')
const memberModule = fs.readFileSync(new URL('../../../后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php', import.meta.url), 'utf8')

assert.match(memberList, /function isMemberEditConflict\(response\)/, '会员编辑必须识别版本冲突')
assert.match(memberList, /const draft = editableMemberDraft\(member\)/, '会员编辑必须先保留本次输入')
assert.match(memberList, /await requestAction\('open-member-editor', \{ memberId: member\.memberId \}\)/, '保存成功或冲突后必须重新读取权威档案')
assert.match(memberList, /editingMember\.value = \{ \.\.\.latest, \.\.\.draft \}/, '冲突刷新后必须保留本次输入')
assert.match(memberModule, /findManageableMember\([\s\S]*?true\n        \);/, '会员更新必须在锁定权威会员行后执行')
assert.match(memberModule, /registerMemberMutationPolicy\(\$dispatcher, 'update-member'\)/, '会员更新必须受工作台和会员版本策略保护')

console.log('MEMBER_EDIT_CONFLICT_CONTRACT=PASS')
