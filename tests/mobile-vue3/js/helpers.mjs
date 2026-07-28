import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

export const testRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
export const repoRoot = path.resolve(testRoot, '..', '..')
export const mobileRoot = path.join(repoRoot, '前端代码', 'mobile-vue3')
export const sourceRoot = path.join(mobileRoot, 'src')
export const fixtureRoot = path.join(testRoot, 'fixtures')

export function readJson(filePath) {
	return JSON.parse(fs.readFileSync(filePath, 'utf8'))
}

export function sourceJson(relativePath) {
	return readJson(path.join(sourceRoot, relativePath))
}

export function fixtureJson(relativePath) {
	return readJson(path.join(fixtureRoot, relativePath))
}

export function exactSorted(values) {
	return [...values].sort((left, right) => left.localeCompare(right))
}

export function listFiles(rootPath) {
	const files = []
	for (const entry of fs.readdirSync(rootPath, { withFileTypes: true })) {
		const absolutePath = path.join(rootPath, entry.name)
		if (entry.isDirectory()) {
			files.push(...listFiles(absolutePath))
		} else if (entry.isFile()) {
			files.push(absolutePath)
		}
	}
	return files
}

export function assertRequired(assert, value, required, label) {
	for (const field of required) {
		assert.ok(Object.hasOwn(value, field), `${label} is missing ${field}`)
	}
}
