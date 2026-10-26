/** 岗位树中点击展示分组时，只保存真实业务编号；取消后能完整撤销。 */
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const file = path.join(root, '前端代码/admin/src/libs/permissionTreeSelection.js')
const selection = await import(`data:text/javascript;base64,${fs.readFileSync(file).toString('base64')}`)
const group = [{ id: -2804, grantable: false, children: [
  { id: -29023, grantable: false, children: [{ id: 301202, grantable: true, children: [] }] },
  { id: 301200, grantable: true, children: [] }
] }]
selection.applyPermissionIds(group, new Set())
assert.deepEqual(selection.collectPermissionIds(group), [])
assert.equal(selection.togglePermissionNode(group, -29023, true), true)
assert.deepEqual(selection.collectPermissionIds(group), [301202])
assert.equal(selection.togglePermissionNode(group, -29023, false), true)
assert.deepEqual(selection.collectPermissionIds(group), [])
selection.applyPermissionIds(group, new Set([301200]))
assert.equal(selection.togglePermissionNode(group, -29023, true), true)
assert.deepEqual(selection.collectPermissionIds(group).sort(), [301200, 301202].sort())
console.log('PASS basic report group saves only its real permission and preserves other grants')
