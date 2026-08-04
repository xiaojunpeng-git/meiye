import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'

const root = resolve(fileURLToPath(new URL('../../..', import.meta.url)))
const creator = readFileSync(resolve(root, '前端代码/cashier-v3/src/components/member/MemberCreatorPanel.vue'), 'utf8')
const memberList = readFileSync(resolve(root, '前端代码/cashier-v3/src/views/MemberListView.vue'), 'utf8')
const shell = readFileSync(resolve(root, '前端代码/cashier-v3/src/layouts/CashierShell.vue'), 'utf8')

assert.match(creator, /initialProfileMode/, '建档组件必须支持调用方锁定完整资料模式')
assert.match(creator, /lockProfileMode/, '完整建档模式不得回退为简易模式')
assert.match(creator, /cancelLabel/, '列表和收银必须能使用不同的取消文案')
assert.match(creator, /submitLabel/, '列表和收银必须能使用不同的保存文案')
assert.match(creator, /allowSelectExisting/, '列表重复手机号冲突不得进入收银的“选择已有会员”流程')
assert.match(creator, /该手机号已存在会员，不能重复建档。/, '列表建档重复手机号必须明确阻止重复创建')
assert.match(creator, /default: '保存并选择'/, '收银选择会员必须继续保留“保存并选择”')

assert.match(memberList, /import MemberCreatorPanel/, '会员列表必须复用完整建档组件')
assert.match(memberList, /initial-profile-mode="full"/, '会员列表新增会员必须直接打开完整模式')
assert.match(memberList, /lock-profile-mode/, '会员列表不得显示简易建档切换')
assert.match(memberList, /cancel-label="取消"/, '会员列表底部取消按钮必须明确取消')
assert.match(memberList, /submit-label="保存"/, '会员列表底部保存按钮必须不带选择语义')
assert.match(memberList, /:allow-select-existing="false"/, '重复手机号只能由后端拦截，列表不得切入会员选择流程')
assert.match(memberList, /openCashierV3QueryEntitySelector\(/, '完整建档的专属服务人必须继续接入统一人员选择器')
assert.match(memberList, /requestAction\('create-member', serializablePayload\)/, '会员建档必须继续走 V3 权威写命令')
assert.match(memberList, /initialQueryPayload\(queryCapability\.value\)/, '保存成功后必须重置列表查询基线')
assert.match(memberList, /member\?\.phone \|\| payload\?\.phone/, '保存成功后必须以新会员手机号查询并回显')
assert.match(memberList, /cashier-v3:open-member-list-creator/, '会员列表必须接收顶部新增会员事件')

assert.match(shell, /if \(isMemberPage\.value\) \{[\s\S]*cashier-v3:open-member-list-creator/, '会员页顶部新增入口必须直接打开列表完整建档')
assert.match(shell, /return openMemberSelector\(\{ context: 'cashier', initialView: 'creator' \}\)/, '收银选择会员必须继续走原有选择器流程')

console.log('PASS member-list-creator-contract')
