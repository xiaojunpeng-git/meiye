import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { mobileRoot } from './helpers.mjs'

const profilePage = path.join(
	mobileRoot,
	'src',
	'merchant',
	'pages',
	'profile',
	'index.uvue'
)

test('merchant profile page provides account empty state and complete static entries', () => {
	const content = fs.readFileSync(profilePage, 'utf8')
	for (const label of [
		'账号信息尚未加载',
		'账号资料',
		'账号与安全',
		'门店与权限',
		'消息通知',
		'帮助与反馈',
		'关于'
	]) {
		assert.equal(content.includes(`'${label}'`) || content.includes(`>${label}<`), true, label)
	}
	for (const forbiddenValue of ['fixture', 'mock', '测试账号', '测试用户']) {
		assert.equal(content.includes(forbiddenValue), false, forbiddenValue)
	}
})

test('merchant profile page is registered as a standalone route', () => {
	const pages = JSON.parse(fs.readFileSync(path.join(mobileRoot, 'pages.json'), 'utf8'))
	const paths = pages.pages.map((page) => page.path)
	assert.equal(paths.includes('src/merchant/pages/profile/index'), true)
})
