import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

// 验证共享工具栏的显式布局开关及收起回退，保证未接入页面仍使用首行操作。
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')
const require = createRequire(path.join(root, '前端代码/inventory-vue3/package.json'))
const { parse, compileScript } = require('@vue/compiler-sfc')
const { parse: parseTemplate } = require('@vue/compiler-dom')
const source = fs.readFileSync(path.join(root, '前端代码/shared/unified-query-vue3/src/components/UnifiedQueryToolbar.vue'), 'utf8')
const { descriptor, errors } = parse(source)
assert.equal(errors.length, 0)
const script = compileScript(descriptor, { id: 'query-actions-contract' })
const declarations = script.scriptSetupAst.filter(node => node.type === 'VariableDeclaration').flatMap(node => node.declarations)
const propCall = declarations.find(node => node.id.name === 'props').init
const option = propCall.arguments[0].properties.find(node => node.key.name === 'queryActionsAfterQuickFields')
assert.equal(option.value.properties.find(node => node.key.name === 'default').value.value, false)
const computed = declarations.find(node => node.id.name === 'queryActionsInSecondary').init.arguments[0].body
const expression = descriptor.scriptSetup.content.slice(computed.start, computed.end)
const inSecondary = new Function('props', 'customFiltersExpanded', 'secondaryQuickFields', 'singleRowQueryActive', `return ${expression}`)
for (const enabled of [false, true]) {
  for (const expanded of [false, true]) {
    for (const fields of [[], [{}]]) {
      assert.equal(inSecondary({ queryActionsAfterQuickFields: enabled }, { value: expanded }, { value: fields }, { value: false }), enabled && expanded && fields.length > 0)
    }
  }
}
const ast = parseTemplate(descriptor.template.content)
function find(node, predicate) {
  if (predicate(node)) return node
  for (const child of node.children || []) {
    const match = find(child, predicate)
    if (match) return match
  }
}
const hasClass = (node, value) => node.type === 1 && node.props.some(prop => prop.name === 'class' && prop.value?.content.split(' ').includes(value))
const directive = (node, name) => node.props.find(prop => prop.type === 7 && prop.name === name)?.exp?.content
const secondary = find(ast, node => hasClass(node, 'unified-query-toolbar__secondary'))
const fields = find(secondary, node => hasClass(node, 'unified-query-toolbar__top-fields'))
const actions = find(fields, node => hasClass(node, 'unified-query-toolbar__field-actions'))
assert.equal(directive(actions, 'if'), 'queryActionsInSecondary')
const children = fields.children.filter(node => node.type === 1)
assert.equal(children.at(-1), actions)
assert.equal(children.at(-2).tag, 'label')
// 新单行模式另有受条件控制的导出；此契约继续验证所有页面共享的查询/重置顺序。
const buttons = actions.children.filter(node => node.type === 1 && ['submitQuery', 'resetQuery'].includes(directive(node, 'on')))
assert.deepEqual(buttons.map(node => node.children.find(child => child.type === 2).content), ['查询', '重置'])
assert.deepEqual(buttons.map(node => directive(node, 'on')), ['submitQuery', 'resetQuery'])
const primary = find(ast, node => hasClass(node, 'unified-query-toolbar__primary'))
for (const handler of ['submitQuery', 'resetQuery']) {
  const button = find(primary, node => node.type === 1 && node.tag === 'button' && directive(node, 'on') === handler)
  assert.equal(directive(button, 'if'), '!queryActionsInSecondary')
}
console.log('QUERY_ACTIONS_AFTER_FIELDS_CONTRACT_OK checks=17')
