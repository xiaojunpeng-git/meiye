import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot } from './helpers.mjs'

test('customer-care completion uses the server projection action and optimistic task version', () => {
	const page = fs.readFileSync(path.join(
		mobileRoot, 'src', 'merchant', 'pages', 'customer-care', 'index.uvue'
	), 'utf8')
	assert.equal(page.includes('completeMobileCustomerCareTask'), true)
	assert.equal(page.includes('task.member.name'), true)
	assert.equal(page.includes('task.typeLabel'), true)
	assert.equal(page.includes('task.owner.name'), true)
	assert.equal(page.includes("actions[index].code == 'complete-care-task'"), true)
	assert.equal(page.includes('expectedVersion: Number(completingTask.value.taskVersion)'), true)
	assert.equal(page.includes("resultCode = ref('FOLLOW_UP')"), true)
	assert.equal(page.includes('method.value'), true)
	assert.equal(page.includes('result.value'), true)
	assert.equal(page.includes('completion.methodOptions'), true)
	assert.equal(page.includes('serverResults[index].enabled !== false'), true)
	assert.equal(page.includes("code: 'APPOINTMENT_SUCCESS'"), false)
	assert.equal(page.includes('createNextTask: createNextTask.value'), true)
	assert.equal(page.includes('nextPlannedAt: nextPlannedAt.value.trim()'), true)
	assert.equal(page.includes('nextOwnerId: Number(nextOwnerId.value)'), true)
	assert.equal(page.includes('请填写下一次跟进时间并选择负责人。'), true)
	assert.equal(page.includes('createMobileCustomerCareTask'), true)
	assert.equal(page.includes('openCreateTask(customer)'), true)
	assert.equal(page.includes('assignees.value = Array.isArray(completion.assigneeOptions)'), true)
	assert.equal(page.includes('memberId: creatingForCustomer.value.memberId'), true)
	assert.equal(page.includes('ownerId: Number(ownerId.value)'), true)
	assert.equal(page.includes("idempotencyKey: 'care-create-'"), true)
	assert.equal(page.includes('plannedAt.value.trim().length == 0'), true)
	assert.equal(page.includes('callMobilePhone'), true)
	assert.equal(page.includes("actions[index].code == 'call-member'"), true)
	assert.equal(page.includes('callCustomer(task)'), true)
	assert.equal(page.includes('nextTaskLabel'), true)
	assert.equal(page.includes('回访设置'), false)
	assert.equal(page.includes('memberFilterId'), true)
	assert.equal(page.includes('onLoad((options : any)'), true)
	assert.equal(page.includes("memberId: Number(memberFilterId.value), bucket: 'all', statusGroup: 'open'"), true)
	assert.equal(page.includes("memberId: Number(memberFilterId.value), stream: 'human', dataScope: 'normal'"), true)
})
