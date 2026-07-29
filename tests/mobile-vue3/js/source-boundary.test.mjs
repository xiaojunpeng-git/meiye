import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import { exactSorted, listFiles, mobileRoot, readJson, repoRoot, sourceRoot, testRoot } from './helpers.mjs'

const textExtensions = new Set([
	'.css',
	'.html',
	'.js',
	'.json',
	'.md',
	'.scss',
	'.ts',
	'.uts',
	'.uvue',
	'.vue'
])

function productionCodeFiles() {
	return [
		...listFiles(sourceRoot),
		path.join(mobileRoot, 'App.uvue'),
		path.join(mobileRoot, 'main.uts'),
		path.join(mobileRoot, 'index.html'),
		path.join(mobileRoot, 'manifest.json'),
		path.join(mobileRoot, 'package-lock.json'),
		path.join(mobileRoot, 'package.json'),
		path.join(mobileRoot, 'pages.json'),
		path.join(mobileRoot, 'platformConfig.json'),
		path.join(mobileRoot, 'uni.scss')
	]
}

test('source tree contains app, member, merchant, and shared boundaries', () => {
	for (const boundary of ['app', 'member', 'merchant', 'shared']) {
		assert.equal(fs.statSync(path.join(sourceRoot, boundary)).isDirectory(), true)
	}
	assert.equal(
		fs.existsSync(path.join(mobileRoot, 'pages', 'index', 'index.uvue')),
		false
	)
})

test('production source has no fixture, fake, or manual verification injection path', () => {
	const files = productionCodeFiles()
	const bannedPathPart = /(^|[\\/])(fixtures?|fakes?|mocks?|dev)([\\/]|$)/i
	const bannedText = [
		/\bactive_role\b/,
		/\bmall_unique_auth\b/,
		/\blegacyMobileOk\b/,
		/\b(?:plus|wx)\s*\./,
		/AccessKeySecret/i,
		/\bSMS_ACCESS_KEY\b/,
		/\bmanualVerification\b/,
		/\binjectVerification\b/
	]

	for (const filePath of files) {
		const relativePath = path.relative(mobileRoot, filePath)
		assert.equal(bannedPathPart.test(relativePath), false, relativePath)
		if (!textExtensions.has(path.extname(filePath))) {
			continue
		}
		const content = fs.readFileSync(filePath, 'utf8')
		for (const pattern of bannedText) {
			assert.equal(pattern.test(content), false, `${relativePath} matches ${pattern}`)
		}
		assert.equal(
			/from\s+['"][^'"]*(?:fixture|fake|mock)[^'"]*['"]/i.test(content),
			false,
			relativePath
		)
	}
})

test('platform directives remain isolated and pages never call platform APIs directly', () => {
	for (const filePath of productionCodeFiles()) {
		if (!textExtensions.has(path.extname(filePath))) {
			continue
		}
		const relativePath = path.relative(mobileRoot, filePath)
		const content = fs.readFileSync(filePath, 'utf8')
		if (/#ifn?def/.test(content)) {
			assert.equal(relativePath.startsWith(`src${path.sep}shared${path.sep}platform${path.sep}`), true)
		}
		if (/\buni\s*\./.test(content)) {
			assert.equal(
				relativePath.startsWith(`src${path.sep}shared${path.sep}platform${path.sep}`) ||
					relativePath.startsWith(`src${path.sep}shared${path.sep}api${path.sep}`),
				true
			)
		}
		if (/\buni\s*\.\s*request\b/.test(content)) {
			assert.equal(relativePath.startsWith(`src${path.sep}shared${path.sep}api${path.sep}`), true)
		}
	}
})

test('root configuration is Vue 3 uni-app x with all four target identities', () => {
	const manifest = readJson(path.join(mobileRoot, 'manifest.json'))
	const pages = readJson(path.join(mobileRoot, 'pages.json'))
	const targets = readJson(path.join(mobileRoot, 'platformConfig.json'))

	assert.equal(manifest.vueVersion, '3')
	assert.ok(Object.hasOwn(manifest, 'uni-app-x'))
	assert.ok(Object.hasOwn(manifest, 'mp-weixin'))
	assert.ok(Object.hasOwn(manifest, 'app-android'))
	assert.ok(Object.hasOwn(manifest, 'app-ios'))
	assert.ok(Object.hasOwn(manifest, 'app-harmony'))
	assert.equal(pages.pages[0].path, 'src/app/pages/bootstrap/index')
	assert.deepEqual(
		exactSorted(targets.targets),
		exactSorted(['APP-ANDROID', 'APP-IOS', 'APP-HARMONY', 'MP-WEIXIN'])
	)

	const platformContract = readJson(
		path.join(sourceRoot, 'shared', 'platform', 'mobile-platform-v1.json')
	)
	assert.deepEqual(
		exactSorted(platformContract.platforms),
		exactSorted(['MP_WEIXIN', 'APP_ANDROID', 'APP_IOS', 'APP_HARMONY'])
	)
	assert.deepEqual(
		exactSorted(platformContract.capabilities.map((item) => item.code)),
		exactSorted(['STORAGE', 'SAFE_AREA', 'CLIPBOARD', 'PHONE_CALL', 'SCAN_CODE'])
	)
	assert.equal(platformContract.implementationStatus, 'INTERFACE_ONLY')
	assert.equal(platformContract.businessPagesMayCallPlatformApisDirectly, false)
})

test('local ignores cover every generated dependency and evidence directory', () => {
	const sourceIgnore = fs.readFileSync(path.join(mobileRoot, '.gitignore'), 'utf8')
	const testIgnore = fs.readFileSync(path.join(testRoot, '.gitignore'), 'utf8')

	for (const required of ['unpackage/', '**/node_modules/', '.hbuilderx/', '*.log', '.env']) {
		assert.equal(sourceIgnore.includes(required), true, required)
	}
	for (const required of ['node_modules/', '.cache/', 'tmp/', 'evidence/', '*.log', '.generated/']) {
		assert.equal(testIgnore.includes(required), true, required)
	}
	const generatedFiles = execFileSync('git', ['ls-files', '--', 'tests/mobile-vue3/.generated'], {
		cwd: repoRoot,
		encoding: 'utf8'
	}).trim()
	assert.equal(generatedFiles, '')
})

test('source and test package manifests stay dependency-free on Node 22', () => {
	const sourcePackage = readJson(path.join(mobileRoot, 'package.json'))
	const testPackage = readJson(path.join(testRoot, 'package.json'))

	assert.equal(sourcePackage.engines.node, '>=22.22.0')
	assert.equal(testPackage.engines.node, '>=22.22.0')
	assert.equal(Object.hasOwn(sourcePackage, 'dependencies'), false)
	assert.equal(Object.hasOwn(sourcePackage, 'devDependencies'), false)
	assert.equal(Object.hasOwn(testPackage, 'dependencies'), false)
	assert.equal(Object.hasOwn(testPackage, 'devDependencies'), false)
})
