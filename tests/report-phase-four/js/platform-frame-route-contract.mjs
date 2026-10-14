import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import vm from 'node:vm'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(here, '../../..')
const admin = path.resolve(process.env.ADMIN_ROOT || path.join(repo, '前端代码/admin'))
const requireAdmin = createRequire(path.join(admin, 'package.json'))
const { parseComponent } = requireAdmin('vue-template-compiler')
const { parse } = requireAdmin('@babel/parser')

function registeredCodes(file, name) {
  const program = parse(fs.readFileSync(path.join(admin, file), 'utf8'), { sourceType: 'module' }).program
  const declaration = program.body
    .filter(node => node.type === 'VariableDeclaration')
    .flatMap(node => node.declarations)
    .find(node => node.id.name === name)
  assert.ok(declaration, `${name} must exist`)
  const reports = declaration.init.arguments[0]
  assert.equal(reports.type, 'ArrayExpression', `${name} must be a literal registry`)
  return reports.elements.map(report => {
    const code = report.properties.find(property => property.key.name === 'code')
    assert.equal(code?.value.type, 'StringLiteral', `${name} report code must be stable`)
    return code.value.value
  })
}

const sixCodes = registeredCodes('src/libs/sixDimensionReports.js', 'SIX_DIMENSION_REPORTS')
const operationCodes = registeredCodes('src/libs/phaseFourReports.js', 'PHASE_FOUR_OPERATION_REPORTS')
const crossEndCodes = registeredCodes('src/libs/phaseFourReports.js', 'PHASE_FOUR_CROSS_END_REPORTS')
const frame = parseComponent(fs.readFileSync(path.join(admin, 'src/pages/report/data/six_dimension.vue'), 'utf8'))
assert.ok(frame.script, 'platform report frame must have a script')
const script = frame.script.content
assert.match(script, /const FRAME_REPORTS = \[\.\.\.SIX_DIMENSION_REPORTS, \.\.\.PHASE_FOUR_REPORTS\]/)
assert.match(script, /const FRAME_REPORT_CODES = \[\.\.\.SIX_DIMENSION_REPORT_CODES, \.\.\.PHASE_FOUR_REPORT_CODES\]/)
const program = parse(script, { sourceType: 'module' }).program
const resolver = program.body.find(node => node.type === 'FunctionDeclaration' && node.id.name === 'resolveReportCode')
assert.ok(resolver, 'platform report frame must resolve the requested report code')

// Exercise the real resolver against every registered code: a valid operations
// menu target must never fall back to the first six-dimension report.
const resolveReportCode = vm.runInNewContext(
  `(${script.slice(resolver.start, resolver.end)})`,
  { FRAME_REPORT_CODES: [...sixCodes, ...operationCodes, ...crossEndCodes], SIX_DIMENSION_REPORT_CODES: sixCodes }
)
for (const code of [...sixCodes, ...operationCodes, ...crossEndCodes]) {
  assert.equal(resolveReportCode({ meta: { reportCode: code }, params: {}, query: {} }), code, code)
}
assert.match(script, /FRAME_REPORTS\.find\(item => item\.code === this\.reportCode\)/)
assert.match(script, /\/platform\/reports\/\$\{encodeURIComponent\(this\.reportCode\)\}/)
console.log(`PASS platform frame preserves ${sixCodes.length} six-dimension and ${operationCodes.length + crossEndCodes.length} phase-four report routes`)
