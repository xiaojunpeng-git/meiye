import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'

const root = resolve(fileURLToPath(new URL('../../..', import.meta.url)))
const creator = readFileSync(resolve(root, '前端代码/cashier-v3/src/components/member/MemberCreatorPanel.vue'), 'utf8')
const memberModule = readFileSync(resolve(root, '后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php'), 'utf8')

assert.match(creator, /'staffName',\s*\n\s*'staff_name'/, '编辑回填必须显示关系记录中的手艺人名称')
assert.match(creator, /\['staffId', 'staff_id', 'id', 'employeeId', 'userId'\]/, '编辑保存必须优先提交门店人员 ID，而不是关系记录 ID')
assert.match(creator, /servicePerson\.value = service && typeof service === 'object' \? service : null/, '没有专属服务人时编辑窗体必须清空选择器')

assert.match(memberModule, /array_key_exists\('exclusiveServicePersonId', \$payload\)[\s\S]*array_key_exists\('exclusive_service_person_id', \$payload\)/, '后端必须区分未提交和明确清空专属服务人')
assert.match(memberModule, /if \(\$profile\['exclusive_service_person_supplied'\]\) \{[\s\S]*?updateExclusiveServicePerson/s, '仅明确提交专属服务人字段时才更新关系')
assert.match(memberModule, /if \(!\$current && \$person === null\) \{\s*return false;/, '无分配会员保存其他资料时不得因为空服务人产生校验或写入')
assert.match(memberModule, /'status' => \$person === null \? 0 : 1/, '明确清空必须逻辑解除当前关系')
assert.match(memberModule, /member_exclusive_service_change/, '专属服务人变更必须保留历史记录')

console.log('PASS member-exclusive-service-contract')
