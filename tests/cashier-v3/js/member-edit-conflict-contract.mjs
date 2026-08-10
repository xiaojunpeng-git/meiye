import assert from 'node:assert/strict'
import fs from 'node:fs'

const memberList = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/MemberListView.vue', import.meta.url), 'utf8')
const memberCreator = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/components/member/MemberCreatorPanel.vue', import.meta.url), 'utf8')
const memberModule = fs.readFileSync(new URL('../../../后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php', import.meta.url), 'utf8')

assert.match(memberList, /function isMemberEditConflict\(response\)/, '会员编辑必须识别版本冲突')
assert.match(memberList, /const draft = editableMemberDraft\(member\)/, '会员编辑必须先保留本次输入')
assert.match(memberList, /await requestAction\('open-member-editor', \{ memberId: member\.memberId \}\)/, '保存成功或冲突后必须重新读取权威档案')
assert.match(memberList, /editingMember\.value = \{ \.\.\.latest, \.\.\.draft \}/, '冲突刷新后必须保留本次输入')
assert.match(memberCreator, /'key', 'info', 'id'/, '无 param 的编辑档案字段必须回退为权威 info 键提交')
assert.match(memberModule, /findManageableMember\([\s\S]*?true\n        \);/, '会员更新必须在锁定权威会员行后执行')
assert.match(memberModule, /registerMemberMutationPolicy\(\$dispatcher, 'update-member'\)/, '会员更新必须受工作台和会员版本策略保护')
assert.match(memberModule, /self::normalizeProfileFields\(/, '会员更新必须归一化并保留档案字段')
assert.match(memberModule, /private static function normalizeProfileFields/, '档案字段必须支持旧键名兼容与回显')
assert.match(memberModule, /profile-field-%d/, '旧版无参数档案字段的序号键必须兼容保存')
assert.match(memberModule, /private static function encodeMemberExtendInfo/, '会员档案必须显式序列化为可持久化 JSON')
assert.match(memberModule, /json_encode\(\$configured, JSON_UNESCAPED_UNICODE\)/, '会员档案写入必须使用 JSON 文本而不是嵌套 PHP 数组')
assert.match(memberModule, /!array_key_exists\(\$key, \$submitted\).*?\$storedValues/s, '仅改档案时必须保留未编辑的历史字段值')

console.log('MEMBER_EDIT_CONFLICT_CONTRACT=PASS')
