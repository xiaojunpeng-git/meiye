import assert from 'node:assert/strict'
import fs from 'node:fs'

const creator = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/components/member/MemberCreatorPanel.vue', import.meta.url), 'utf8')
const list = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/views/MemberListView.vue', import.meta.url), 'utf8')
const selector = fs.readFileSync(new URL('../../../前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue', import.meta.url), 'utf8')

// 会员列表的保存反馈走弹窗；收银复用同一表单，但不能被本次页面调整改变。
assert.match(list, /<MemberCreatorPanel[\s\S]*?save-feedback-as-dialog[\s\S]*?@save-feedback="showMemberSaveFeedback"/)
assert.doesNotMatch(selector, /save-feedback-as-dialog/)
assert.match(creator, /function notifySave\(kind, message\)[\s\S]*?emit\('save-feedback', \{ kind, message \}\)/)
assert.match(creator, /if \(props\.saveFeedbackAsDialog\) notifySave\('error', Object\.values\(errors\)\[0\]\)/)
assert.match(creator, /if \(outcome\.status === 'result_unknown' \|\| outcome\.status === 'pending'\)[\s\S]*?notifySave\('warning'/)
assert.match(creator, /if \(outcome\.status !== 'succeeded'\)[\s\S]*?notifySave\('error'/)
assert.match(creator, /notifySave\('success'[\s\S]*?emit\('created'/)
assert.match(list, /role="dialog" aria-modal="true" aria-labelledby="member-save-feedback-title"/)
assert.match(list, /function dismissMemberSaveFeedback\(\)[\s\S]*?aria-invalid/)

console.log('R43_MEMBER_SAVE_DIALOG_CONTRACT=PASS')
