/** 单表叶子按权限规则呈现；显式勾选、取消及分组级联都只保存真实叶子编号。 */
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const file = path.join(root, '前端代码/admin/src/libs/permissionTreeSelection.js')
const selection = await import(`data:text/javascript;base64,${fs.readFileSync(file).toString('base64')}`)
const group = [{ id: -2804, grantable: false, children: [
  { id: -29023, grantable: false, children: [
    { id: 33001, grantable: true, children: [] },
    { id: 33002, grantable: true, children: [] },
  ] },
  // 后续规则目录新增的可授权分组由同一个树组件读取，不需要岗位页面维护固定名单。
  { id: -29100, grantable: false, children: [{ id: 45001, grantable: true, children: [] }] },
  { id: 301200, grantable: true, children: [] }
] }]
selection.applyPermissionIds(group, new Set())
assert.deepEqual(selection.collectPermissionIds(group), [])
assert.equal(selection.togglePermissionNode(group, -29023, true), true)
assert.deepEqual(selection.collectPermissionIds(group), [33001, 33002])
assert.equal(selection.togglePermissionNode(group, -29023, false), true)
assert.deepEqual(selection.collectPermissionIds(group), [])
selection.applyPermissionIds(group, new Set([301200]))
assert.equal(selection.togglePermissionNode(group, 33002, true), true)
assert.deepEqual(selection.collectPermissionIds(group).sort(), [301200, 33002].sort())
assert.equal(selection.togglePermissionNode(group, -29023, true), true)
assert.deepEqual(selection.collectPermissionIds(group).sort(), [301200, 33001, 33002].sort())
assert.equal(selection.togglePermissionNode(group, -29100, true), true)
assert.deepEqual(selection.collectPermissionIds(group).sort(), [301200, 33001, 33002, 45001].sort())
console.log('PASS basic report group saves independent report leaves and preserves other grants')
