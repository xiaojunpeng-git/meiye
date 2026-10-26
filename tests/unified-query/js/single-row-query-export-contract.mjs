import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

// 只验证显式单行接入：旧收起偏好不隐藏字段、导出继承权限及执行路径，其他页面不启用。
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const require = createRequire(path.join(root, '前端代码/inventory-vue3/package.json'))
const { parse, compileScript } = require('@vue/compiler-sfc')
const { parse: parseTemplate } = require('@vue/compiler-dom')
const source = fs.readFileSync(path.join(root, '前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue'), 'utf8')
const { descriptor, errors } = parse(source)
assert.equal(errors.length, 0)
const script = compileScript(descriptor, { id: 'single-row-contract' })
const declarations = script.scriptSetupAst.filter(node => node.type === 'VariableDeclaration').flatMap(node => node.declarations)
const props = declarations.find(node => node.id.name === 'props').init.arguments[0].properties
assert.equal(props.find(node => node.key.name === 'singleRowQuickQuery').value.properties.find(node => node.key.name === 'default').value.value, false)
const expression = name => {
  const body = declarations.find(node => node.id.name === name).init.arguments[0].body
  return descriptor.scriptSetup.content.slice(body.start, body.end)
}
const active = new Function('props', 'secondaryQuickFields', `return ${expression('singleRowQueryActive')}`)
const secondary = new Function('props', 'customFiltersExpanded', 'singleRowQueryActive', 'secondaryQuickFields', `return ${expression('queryActionsInSecondary')}`)
for (const enabled of [false, true]) for (const after of [false, true]) for (const fields of [[], [{}]]) {
  const p = { singleRowQuickQuery: enabled, queryActionsAfterQuickFields: after }
  const isActive = active(p, { value: fields })
  assert.equal(isActive, enabled && after && fields.length > 0)
  for (const expanded of [false, true]) {
    assert.equal(secondary(p, { value: expanded }, { value: isActive }, { value: fields }), after && (expanded || isActive) && fields.length > 0)
  }
}
const ast = parseTemplate(descriptor.template.content)
const directive = (node, name) => node.props.find(prop => prop.type === 7 && prop.name === name)?.exp?.content
const find = (node, predicate) => predicate(node) ? node : (node.children || []).map(child => find(child, predicate)).find(Boolean)
const hasClass = (node, value) => node.type === 1 && node.props.some(prop => prop.name === 'class' && prop.value?.content.split(' ').includes(value))
assert.equal(directive(find(ast, node => hasClass(node, 'unified-query-toolbar__topline')), 'if'), '!singleRowQueryActive')
assert.match(directive(find(ast, node => hasClass(node, 'unified-query-toolbar__secondary')), 'if'), /customFiltersExpanded \|\| singleRowQueryActive/)
const actions = find(ast, node => hasClass(node, 'unified-query-toolbar__field-actions'))
const buttons = actions.children.filter(node => node.type === 1)
assert.equal(buttons.length, 3)
assert.deepEqual(buttons.slice(0, 2).map(node => directive(node, 'on')), ['submitQuery', 'resetQuery'])
assert.equal(directive(buttons[2], 'if'), 'singleRowQueryActive && canExport')
assert.equal(directive(buttons[2], 'on'), 'exportButtonAfterSettings && directQueryExport ? startDirectQueryExport() : (isExportOpen = true)')
assert.match(buttons[2].loc.source, /:disabled="exportButtonAfterSettings && isDirectExporting"/)
const app = fs.readFileSync(path.join(root, '前端代码/inventory-vue3/src/App.vue'), 'utf8')
assert.match(app, /:single-row-quick-query="mode === 'store'"/)
console.log('SINGLE_ROW_QUERY_EXPORT_CONTRACT_OK')
