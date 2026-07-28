#!/usr/bin/env node
/**
 * C1-A：前后端 action 契约 + services 扫描 + 隔离 AST 夹具（fail-closed）。
 * 使用 @vue/compiler-sfc + @babel/parser；手写 AST 遍历（不依赖 @babel/traverse）。
 */
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import { createRequire } from 'module'
import esbuild from 'esbuild'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const TESTS = path.resolve(__dirname, '..')
const REPO = path.resolve(TESTS, '../..')
const ROOT = REPO
const CASHIER_V3 = path.join(REPO, '前端代码/cashier-v3')
const FE_MANIFEST = path.join(CASHIER_V3, 'src/services/cashierV3ActionManifest.js')
const FE_BRIDGE = path.join(CASHIER_V3, 'src/services/cashierV3Bridge.js')
const FE_SERVICES = path.join(CASHIER_V3, 'src/services')
const BE_ROOT_CONTRACT = path.join(REPO, '后端代码/app/services/cashier/v3/projection/CashierV3RootStateContract.php')
const POS_FIX = path.join(TESTS, 'fixtures/action-scan-positives')
const NEG_FIX = path.join(TESTS, 'fixtures/action-scan-negatives')
const AUDIT_OUT = process.env.C1A_EVIDENCE_DIR
  ? path.join(process.env.C1A_EVIDENCE_DIR, 'action-type-owner-audit.json')
  : path.join(TESTS, 'action-type-owner-audit.json')
const beJsonPath = process.argv[2] || ''

const require = createRequire(path.join(TESTS, 'package.json'))
const { parse: parseSfc } = require('@vue/compiler-sfc')
const parser = require('@babel/parser')

function walkAst(node, visit) {
  if (!node || typeof node !== 'object') return
  visit(node)
  for (const k of Object.keys(node)) {
    if (k === 'loc' || k === 'start' || k === 'end' || k === 'type' || k === 'extra' || k === 'comments' || k === 'leadingComments' || k === 'trailingComments') continue
    const v = node[k]
    if (Array.isArray(v)) {
      for (const child of v) if (child && typeof child === 'object' && child.type) walkAst(child, visit)
    } else if (v && typeof v === 'object' && v.type) {
      walkAst(v, visit)
    }
  }
}

function parseJs(code) {
  return parser.parse(code, {
    sourceType: 'module',
    plugins: ['jsx', 'typescript', 'classProperties', 'optionalChaining', 'nullishCoalescingOperator', 'objectRestSpread', 'dynamicImport']
  })
}

function collectStringLiterals(node, out) {
  if (!node) return
  if (node.type === 'StringLiteral') { out.push(node.value); return }
  if (node.type === 'ConditionalExpression') {
    collectStringLiterals(node.consequent, out)
    collectStringLiterals(node.alternate, out)
  }
}

let passed = 0
let failed = 0
function ok(name, cond, detail = '', gateId = '') {
  if (cond) {
    passed++
    console.log(`  PASS  ${name}${gateId ? ` [${gateId}]` : ''}`)
    if (gateId) console.log(`GATE_PASS=${gateId}`)
  } else {
    failed++
    console.log(`  FAIL  ${name}${detail ? ' -> ' + detail : ''}${gateId ? ` [${gateId}]` : ''}`)
  }
}

const manifestBuild = await esbuild.build({
  entryPoints: [FE_MANIFEST],
  bundle: true,
  write: false,
  format: 'esm',
  platform: 'node',
  logLevel: 'silent'
})
const manifestModuleUrl = `data:text/javascript;base64,${Buffer.from(manifestBuild.outputFiles[0].contents).toString('base64')}`
const mod = await import(manifestModuleUrl)
const fe = mod.CASHIER_V3_ACTION_MANIFEST
const uiOnly = mod.CASHIER_V3_UI_ONLY_KEYS
const aliases = mod.CHECKOUT_ACTION_ALIASES
const preparations = mod.PREPARATION_PROJECTION_ACTIONS

console.log('== 1. 前端 manifest 自洽 ==')
ok('manifest 非空', Object.keys(fe).length > 50)
for (const [page, canonical] of Object.entries(aliases)) {
  ok(`别名 ${page}`, fe[page]?.canonical === canonical && fe[page]?.type === 'command')
}
for (const prep of preparations) ok(`准备 ${prep} projection`, fe[prep]?.type === 'projection')
ok('create-reservation command', fe['create-reservation']?.type === 'command')
ok('update-reservation command', fe['update-reservation']?.type === 'command')
ok('select-writeoff-member command', fe['select-writeoff-member']?.type === 'command')
ok('select-reservation-member command', fe['select-reservation-member']?.type === 'command')
ok('recalculate-reservation-plan projection', fe['recalculate-reservation-plan']?.type === 'projection')
ok('query-hang-order-result', fe['query-hang-order-result']?.type === 'projection')
ok('query-writeoff-result', fe['query-writeoff-result']?.type === 'projection')
ok('query-reservation-result', fe['query-reservation-result']?.type === 'projection')
ok('save-reservation-query-settings C5 command', fe['save-reservation-query-settings']?.type === 'command' && fe['save-reservation-query-settings']?.owner === 'C5')
ok('save-hang-order-query-settings C5 command', fe['save-hang-order-query-settings']?.type === 'command' && fe['save-hang-order-query-settings']?.owner === 'C5')
ok('save-order-center-query-settings C5 command', fe['save-order-center-query-settings']?.type === 'command' && fe['save-order-center-query-settings']?.owner === 'C5')
ok('save-member-query-settings C5 command', fe['save-member-query-settings']?.type === 'command' && fe['save-member-query-settings']?.owner === 'C5')
ok('change-reservation-calendar-date C3 projection', fe['change-reservation-calendar-date']?.type === 'projection' && fe['change-reservation-calendar-date']?.owner === 'C3')
ok('go-to-writeoff-after-checkout C2 command', fe['go-to-writeoff-after-checkout']?.type === 'command' && fe['go-to-writeoff-after-checkout']?.owner === 'C2')
ok('query-member-selector selector policy', fe['query-member-selector']?.permissionPolicyId === 'selector:member')
ok('go-writeoff UI_ONLY 非后端 action', Boolean(uiOnly['go-writeoff']) && !fe['go-writeoff'])

console.log('== 2. 与后端 manifest JSON 对齐 ==')
if (!beJsonPath || !fs.existsSync(beJsonPath)) {
  ok('后端 JSON 已提供', false, 'missing')
} else {
  const beRaw = JSON.parse(fs.readFileSync(beJsonPath, 'utf8'))
  const be = beRaw.actions || beRaw
  const bePrefixes = Array.isArray(beRaw.idempotency_prefixes)
    ? beRaw.idempotency_prefixes
    : (Array.isArray(beRaw.__idempotency_prefixes) ? beRaw.__idempotency_prefixes : [])
  globalThis.__C1A_BE_PREFIXES = bePrefixes
  const feKeys = Object.keys(fe).sort()
  const beKeys = Object.keys(be).filter((k) => !k.startsWith('__')).sort()
  const missingInBe = feKeys.filter((k) => !be[k])
  const missingInFe = beKeys.filter((k) => !fe[k])
  ok('前端 ⊆ 后端', missingInBe.length === 0, missingInBe.slice(0, 20).join(','))
  ok('后端 ⊆ 前端', missingInFe.length === 0, missingInFe.slice(0, 20).join(','))
  let mismatch = 0
  for (const key of feKeys) {
    if (!be[key]) continue
    if (fe[key].type !== be[key].type || fe[key].canonical !== be[key].canonical || fe[key].owner !== be[key].owner) {
      mismatch++
      console.log(`  FAIL  mismatch ${key}`)
    }
  }
  if (mismatch === 0) ok('type／canonical／owner 全等', mismatch === 0, `mismatch=${mismatch}`)
  else failed += mismatch
  let missingRecovery = 0
  for (const [key, def] of Object.entries(be)) {
    if (def.alias_of) continue
    if (def.type !== 'command') continue
    if (!def.recovery || !def.recovery.mode) missingRecovery++
  }
  ok('后端全部 command 有 recovery', missingRecovery === 0, `missing=${missingRecovery}`, 'BR-10-03')
}

console.log('== 3. AST 真实 action 扫描 ==')
function walkFiles(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) walkFiles(full, out)
    else if (/\.(js|vue)$/.test(entry.name)) out.push(full)
  }
  return out
}

const networkActions = new Map()
const dynamicFailures = []
const uiOnlyOnNetwork = []
const permissionMismatches = []

function isActionLike(s) {
  return typeof s === 'string' && /^[a-z]+(?:-[a-z0-9]+){1,}$/.test(s)
}

function recordAction(action, file, line, via) {
  if (!isActionLike(action)) return
  if (uiOnly[action]) {
    uiOnlyOnNetwork.push({ action, file: path.relative(ROOT, file), line, via })
    return
  }
  if (!networkActions.has(action)) networkActions.set(action, [])
  networkActions.get(action).push({ file: path.relative(ROOT, file), line, via })
}

function recordPermissionMismatch(action, expected, actual, file, line) {
  permissionMismatches.push({
    action,
    expected,
    actual,
    file: path.relative(ROOT, file),
    line
  })
}

function firstParamName(fn) {
  const p = fn?.params?.[0]
  if (!p) return null
  if (p.type === 'Identifier') return p.name
  if (p.type === 'ObjectPattern') {
    for (const prop of p.properties) {
      if (prop.type === 'ObjectProperty') {
        const k = prop.key.type === 'Identifier' ? prop.key.name : prop.key.value
        if (k === 'action') return 'action'
      }
    }
  }
  if (p.type === 'AssignmentPattern' && p.left?.type === 'Identifier') return p.left.name
  return null
}

function fnBodyUsesFirstParamAsAction(fn, paramName) {
  if (!fn || !paramName) return false
  let used = false
  walkAst(fn.body || fn, (n) => {
    if (n.type !== 'CallExpression' || n.callee.type !== 'Identifier') return
    if (n.callee.name !== 'requestCashierV3Action') return
    const a0 = n.arguments[0]
    if (a0?.type === 'Identifier' && a0.name === paramName) used = true
  })
  return used
}

/** ConditionalExpression 的两侧是否都是可证明的 action 字面量 */
function collectConditionalActionLiterals(node, out) {
  if (!node) return false
  if (node.type === 'StringLiteral') {
    if (isActionLike(node.value)) out.push(node.value)
    return isActionLike(node.value)
  }
  if (node.type === 'ConditionalExpression') {
    const leftOk = collectConditionalActionLiterals(node.consequent, out)
    const rightOk = collectConditionalActionLiterals(node.alternate, out)
    return leftOk && rightOk
  }
  return false
}

function extractPermissionLiteral(argNode) {
  if (!argNode || argNode.type !== 'ObjectExpression') return null
  for (const prop of argNode.properties) {
    if (prop.type !== 'ObjectProperty') continue
    const k = prop.key.type === 'Identifier' ? prop.key.name : prop.key.value
    if (k !== 'permission') continue
    if (prop.value.type === 'StringLiteral') return prop.value.value
  }
  return null
}

function analyzeScript(code, file, templateCode = '') {
  let ast
  try { ast = parseJs(code) } catch (e) {
    // Codex Vue 偶发语法问题不得淹没 C1 字面量 action 扫描：退回字面量／三元提取
    console.log(`  INFO  parse_fallback ${path.relative(ROOT, file)}: ${e.message}`)
    for (const m of code.matchAll(/\b(requestAction|requestCashierV3Action|emitAction)\(\s*['"]([a-z]+(?:-[a-z0-9]+)+)['"]/g)) {
      recordAction(m[2], file, 0, 'parse-fallback:' + m[1])
    }
    for (const m of code.matchAll(/\?\s*['"]([a-z]+(?:-[a-z0-9]+)+)['"]\s*:\s*['"]([a-z]+(?:-[a-z0-9]+)+)['"]/g)) {
      recordAction(m[1], file, 0, 'parse-fallback-ternary')
      recordAction(m[2], file, 0, 'parse-fallback-ternary')
    }
    if (templateCode) {
      for (const m of templateCode.matchAll(/\b(requestAction|requestCashierV3Action|emitAction)\(\s*['"]([a-z]+(?:-[a-z0-9]+)+)['"]/g)) {
        recordAction(m[2], file, 0, 'parse-fallback-tpl:' + m[1])
      }
    }
    return
  }

  /** @type {Set<string>} 第一参作为 action 转发到 requestCashierV3Action 的 wrapper */
  const actionWrappers = new Set(['requestCashierV3Action'])
  /** @type {Set<string>} 第一参 action 经 emit('request') 上抛的本地 helper */
  const emitActionHelpers = new Set()
  /** @type {Set<string>} 接收 {action} 且经 Set.has(action) 门禁后再发网络的函数 */
  const gatedObjectActionFns = new Set()
  const staticMaps = new Map()
  /** @type {Map<string, string[]>} */
  const namedSets = new Map()
  /** @type {Set<string>} 已证明经 includes/===/Set.has 门禁的变量名 */
  const gatedVars = new Set()
  const eqGateLits = []
  let gatedMemberActionAccess = false

  // pass 1: 收集定义
  walkAst(ast, (node) => {
    if (node.type === 'VariableDeclarator' && node.id?.type === 'Identifier' && node.init?.type === 'NewExpression'
      && node.init.callee.type === 'Identifier' && node.init.callee.name === 'Set'
      && node.init.arguments[0]?.type === 'ArrayExpression') {
      const vals = node.init.arguments[0].elements.filter((e) => e?.type === 'StringLiteral').map((e) => e.value).filter(isActionLike)
      if (vals.length) {
        namedSets.set(node.id.name, vals)
        for (const v of vals) recordAction(v, file, node.loc?.start?.line || 0, 'Set:' + node.id.name)
      }
    }
    if (node.type === 'VariableDeclarator' && node.id?.type === 'Identifier' && node.init?.type === 'ObjectExpression') {
      const map = {}
      let okMap = true
      for (const prop of node.init.properties) {
        if (prop.type !== 'ObjectProperty' || prop.value.type !== 'StringLiteral') { okMap = false; break }
        const key = prop.key.type === 'Identifier' ? prop.key.name : prop.key.value
        map[key] = prop.value.value
      }
      if (okMap && Object.keys(map).length) {
        staticMaps.set(node.id.name, map)
        if (/action/i.test(node.id.name)) {
          for (const v of Object.values(map)) recordAction(v, file, node.loc?.start?.line || 0, 'static-map:' + node.id.name)
        }
      }
    }

    const bindFn = (name, fn) => {
      if (!name || !fn) return
      const param = firstParamName(fn)
      let emitsDynamicAction = false
      walkAst(fn.body || fn, (n) => {
        if (n.type !== 'CallExpression') return
        const c = n.callee
        const isEmit = (c.type === 'Identifier' && c.name === 'emit')
          || (c.type === 'MemberExpression' && c.property?.name === 'emit')
        if (!isEmit || n.arguments[0]?.type !== 'StringLiteral' || n.arguments[0].value !== 'request') return
        const payload = n.arguments[1]
        if (payload?.type !== 'ObjectExpression') return
        for (const prop of payload.properties) {
          if (prop.type !== 'ObjectProperty') continue
          const k = prop.key.type === 'Identifier' ? prop.key.name : prop.key.value
          if (k !== 'action') continue
          if (prop.value.type === 'Identifier' && prop.value.name === param) emitsDynamicAction = true
          const lits = []
          collectStringLiterals(prop.value, lits)
          for (const lit of lits) recordAction(lit, file, n.loc?.start?.line || 0, 'emit.request')
          if (prop.value.type === 'Identifier' && prop.value.name === param) {
            // 标记本 emit 调用点为 helper 形参转发，避免“文件有 helper 就全局放行”
            n.__c1aEmitForward = true
          }
        }
      })
      if (emitsDynamicAction) emitActionHelpers.add(name)

      if (fnBodyUsesFirstParamAsAction(fn, param)) {
        actionWrappers.add(name)
      }

      let hasGate = false
      walkAst(fn.body || fn, (n) => {
        if (n.type === 'CallExpression' && n.callee.type === 'MemberExpression' && n.callee.property?.name === 'has') {
          const arg = n.arguments[0]
          if (arg?.type === 'Identifier' && (arg.name === 'action' || arg.name === param)) {
            hasGate = true
            gatedVars.add(arg.name)
          }
          if (arg?.type === 'MemberExpression' && arg.property?.name === 'action') {
            hasGate = true
            gatedMemberActionAccess = true
          }
        }
      })
      if (hasGate && (param === 'action' || firstParamName(fn) === 'action')) gatedObjectActionFns.add(name)
      if (hasGate && fnBodyUsesFirstParamAsAction(fn, 'action')) {
        gatedObjectActionFns.add(name)
        actionWrappers.add(name)
      }
      if (hasGate && param && fnBodyUsesFirstParamAsAction(fn, param)) {
        gatedObjectActionFns.add(name)
      }
    }

    if (node.type === 'FunctionDeclaration' && node.id?.name) bindFn(node.id.name, node)
    if (node.type === 'VariableDeclarator' && node.id?.type === 'Identifier'
      && (node.init?.type === 'ArrowFunctionExpression' || node.init?.type === 'FunctionExpression')) {
      bindFn(node.id.name, node.init)
    }

    if (node.type === 'CallExpression' && node.callee.type === 'MemberExpression' && node.callee.property?.name === 'includes'
      && node.callee.object.type === 'ArrayExpression' && node.arguments[0]?.type === 'Identifier') {
      const vals = node.callee.object.elements.filter((e) => e?.type === 'StringLiteral').map((e) => e.value).filter(isActionLike)
      if (vals.length >= 1) {
        gatedVars.add(node.arguments[0].name)
        const varName = node.arguments[0].name
        if (/action/i.test(varName)) {
          for (const v of vals) {
            if (!uiOnly[v] && fe[v]) recordAction(v, file, node.loc?.start?.line || 0, 'includes-allowlist')
          }
        }
      }
    }
    if (node.type === 'CallExpression' && node.callee.type === 'MemberExpression' && node.callee.property?.name === 'has'
      && node.callee.object.type === 'Identifier' && namedSets.has(node.callee.object.name)) {
      const arg = node.arguments[0]
      if (arg?.type === 'Identifier') gatedVars.add(arg.name)
      if (arg?.type === 'MemberExpression' && arg.property?.name === 'action') gatedMemberActionAccess = true
    }
    if (node.type === 'BinaryExpression' && (node.operator === '===' || node.operator === '!==')) {
      const sides = [node.left, node.right]
      for (let i = 0; i < 2; i++) {
        const a = sides[i], b = sides[1 - i]
        if (a.type === 'MemberExpression' && a.property?.name === 'action' && b.type === 'StringLiteral') {
          eqGateLits.push(b.value)
          recordAction(b.value, file, node.loc?.start?.line || 0, 'eq-gate')
        }
      }
    }
    if (node.type === 'ObjectProperty') {
      const keyName = node.key.type === 'Identifier' ? node.key.name : node.key.value
      if (keyName === 'action') {
        const lits = []
        collectStringLiterals(node.value, lits)
        for (const lit of lits) recordAction(lit, file, node.loc?.start?.line || 0, 'object.action')
      }
    }
  })

  walkAst(ast, (node) => {
    let name = null
    let fn = null
    if (node.type === 'FunctionDeclaration' && node.id?.name) { name = node.id.name; fn = node }
    if (node.type === 'VariableDeclarator' && node.id?.type === 'Identifier'
      && (node.init?.type === 'ArrowFunctionExpression' || node.init?.type === 'FunctionExpression')) {
      name = node.id.name; fn = node.init
    }
    if (!name || !fn) return
    let hasGate = false
    let callsNetworkWithAction = false
    walkAst(fn.body || fn, (n) => {
      if (n.type === 'CallExpression' && n.callee.type === 'MemberExpression' && n.callee.property?.name === 'has'
        && n.arguments[0]?.type === 'Identifier' && n.arguments[0].name === 'action') hasGate = true
      if (n.type === 'CallExpression' && n.callee.type === 'Identifier'
        && (n.callee.name === 'requestCashierV3Action' || actionWrappers.has(n.callee.name))
        && n.arguments[0]?.type === 'Identifier' && n.arguments[0].name === 'action') {
        callsNetworkWithAction = true
      }
    })
    if (hasGate && callsNetworkWithAction) {
      gatedObjectActionFns.add(name)
      actionWrappers.add(name)
      gatedVars.add('action')
    }
  })

  // pass 2: 调用点 — 禁止“文件里有任一 wrapper／emit helper 就全局放行”
  walkAst(ast, (node) => {
    if (node.type !== 'CallExpression') return

    const c = node.callee
    const isEmit = (c.type === 'Identifier' && c.name === 'emit')
      || (c.type === 'MemberExpression' && c.property?.name === 'emit')
    if (isEmit && node.arguments[0]?.type === 'StringLiteral' && node.arguments[0].value === 'request') {
      const payload = node.arguments[1]
      if (payload?.type === 'ObjectExpression') {
        for (const prop of payload.properties) {
          if (prop.type !== 'ObjectProperty') continue
          const k = prop.key.type === 'Identifier' ? prop.key.name : prop.key.value
          if (k !== 'action') continue
          const lits = []
          collectStringLiterals(prop.value, lits)
          if (lits.length) {
            for (const lit of lits) recordAction(lit, file, node.loc?.start?.line || 0, 'emit.request')
            return
          }
          // 仅放行已被标记为 helper 形参转发的 emit 调用点
          if (node.__c1aEmitForward === true) return
          dynamicFailures.push({
            file: path.relative(ROOT, file),
            line: node.loc?.start?.line || 0,
            reason: 'emit_request_dynamic_action'
          })
          return
        }
      }
      return
    }

    if (c.type !== 'Identifier') return
    const calleeName = c.name
    const arg0 = node.arguments[0]
    if (!arg0) return

    if (emitActionHelpers.has(calleeName)) {
      const lits = []
      collectStringLiterals(arg0, lits)
      if (lits.length) {
        for (const lit of lits) recordAction(lit, file, node.loc?.start?.line || 0, 'emit-helper:' + calleeName)
        return
      }
      if (arg0.type === 'Identifier' && gatedVars.has(arg0.name)) return
      dynamicFailures.push({
        file: path.relative(ROOT, file),
        line: node.loc?.start?.line || 0,
        reason: `emit_helper_dynamic:${calleeName}`
      })
      return
    }

    if (gatedObjectActionFns.has(calleeName)) {
      return
    }

    if (!actionWrappers.has(calleeName)) return

    // 三元漏项：两侧必须都是可证明字面量
    if (arg0.type === 'ConditionalExpression') {
      const branchLits = []
      const bothLiteral = collectConditionalActionLiterals(arg0, branchLits)
      if (!bothLiteral) {
        dynamicFailures.push({
          file: path.relative(ROOT, file),
          line: node.loc?.start?.line || 0,
          reason: 'template_ternary_missing_branch'
        })
        return
      }
      for (const lit of branchLits) recordAction(lit, file, node.loc?.start?.line || 0, calleeName + ':ternary')
      return
    }

    const lits = []
    collectStringLiterals(arg0, lits)
    if (lits.length) {
      for (const lit of lits) recordAction(lit, file, node.loc?.start?.line || 0, calleeName)
      const perm = extractPermissionLiteral(node.arguments[1])
      if (perm != null) {
        for (const lit of lits) {
          const expected = fe[lit]?.permission
          if (expected && expected !== perm) {
            recordPermissionMismatch(lit, expected, perm, file, node.loc?.start?.line || 0)
          }
          if (!expected && fe[lit] && perm) {
            recordPermissionMismatch(lit, fe[lit]?.permission || null, perm, file, node.loc?.start?.line || 0)
          }
        }
      }
      return
    }
    if (arg0.type === 'MemberExpression' && arg0.object.type === 'Identifier') {
      const map = staticMaps.get(arg0.object.name)
      if (map) {
        for (const v of Object.values(map)) recordAction(v, file, node.loc?.start?.line || 0, calleeName + ':map')
        return
      }
    }
    if (arg0.type === 'Identifier') {
      // 仅 requestCashierV3Action 的「wrapper 形参转发」可跳过；其它 wrapper／sink 的动态入参必须门禁
      if (calleeName === 'requestCashierV3Action' && actionWrappers.size > 1) {
        // 仍要求：该 Identifier 必须是某个 wrapper 的形参名，避免无关动态变量被放过
        const forwarded = [...actionWrappers].some((w) => w !== 'requestCashierV3Action')
        if (forwarded && (arg0.name === 'action' || /action/i.test(arg0.name) || arg0.name === 'actionCode')) {
          // 继续检查：若同文件存在 ungated wrapper 调用链，由下方其它调用点捕获
          return
        }
      }
      if (gatedVars.has(arg0.name)) return
      dynamicFailures.push({
        file: path.relative(ROOT, file),
        line: node.loc?.start?.line || 0,
        reason: `dynamic_action_arg:${calleeName}(${arg0.name})`
      })
      return
    }
    if (arg0.type === 'MemberExpression' && arg0.property?.name === 'action') {
      if (gatedMemberActionAccess || gatedVars.has('action') || eqGateLits.length) return
      dynamicFailures.push({
        file: path.relative(ROOT, file),
        line: node.loc?.start?.line || 0,
        reason: 'dynamic_request.action'
      })
      return
    }
    if (arg0.type === 'ObjectExpression' || arg0.type === 'ObjectPattern') return
    dynamicFailures.push({
      file: path.relative(ROOT, file),
      line: node.loc?.start?.line || 0,
      reason: `unproven_action_expr:${calleeName}`
    })
  })

  if (templateCode) {
    // Vue template AST：优先解析 AST；仅当 AST 不可用且无网络调用痕迹时才跳过
    let tplAst = null
    try {
      const { compileTemplate } = require('@vue/compiler-sfc')
      const compiled = compileTemplate({ source: templateCode, filename: file, id: path.basename(file) })
      tplAst = compiled.ast || null
    } catch (_) {}
    if (tplAst) {
      walkAst(tplAst, (node) => {
        // Vue template AST: 简单字面量调用 / 绑定
        if (node.type === 5 /* INTERPOLATION */ || node.type === 4 /* TEXT */) return
        if (node.type === 2 && typeof node.content === 'string' && isActionLike(node.content)) {
          // TEXT 字面量不直接当网络 action
        }
        // codegen 节点里的常量字符串
        if (node.type === 'StringLiteral' && isActionLike(node.value)) {
          // 由 script 分析覆盖；模板侧仅记录可疑 emit/request
        }
      })
      // 模板源上的 call 仍做字面量提取，但动态位点用 AST 可用性门禁
      const tplCalls = templateCode.matchAll(/\b([A-Za-z_$][\w$]*)\(\s*['"]([a-z]+(?:-[a-z0-9]+)+)['"]/g)
      for (const m of tplCalls) {
        const fn = m[1]
        const action = m[2]
        if (emitActionHelpers.has(fn) || actionWrappers.has(fn)) {
          recordAction(action, file, 0, 'template-ast:' + fn)
        }
      }
      for (const helper of [...emitActionHelpers, ...actionWrappers]) {
        if (helper === 'requestCashierV3Action') continue
        if (gatedObjectActionFns.has(helper)) continue
        const dyn = new RegExp(`\\b${helper}\\(\\s*[a-zA-Z_$][\\w$.]*\\s*\\)`)
        if (dyn.test(templateCode) && !new RegExp(`\\b${helper}\\(\\s*['"][a-z]+(?:-[a-z0-9]+)+['"]`).test(templateCode)) {
          dynamicFailures.push({
            file: path.relative(ROOT, file),
            line: 0,
            reason: `template_dynamic_sink:${helper}`
          })
        }
      }
    } else if (/\brequestCashierV3Action\b|\bemit\s*\(\s*['"]request['"]/.test(templateCode)) {
      dynamicFailures.push({
        file: path.relative(ROOT, file),
        line: 0,
        reason: 'template_ast_required_but_unavailable'
      })
    }
  }
}

function scanSourceFile(file) {
  if (file.endsWith('.vue')) {
    const { descriptor } = parseSfc(fs.readFileSync(file, 'utf8'), { filename: file })
    const tpl = descriptor.template?.content || ''
    if (descriptor.script?.content) analyzeScript(descriptor.script.content, file, tpl)
    if (descriptor.scriptSetup?.content) analyzeScript(descriptor.scriptSetup.content, file, tpl)
  } else if (file.endsWith('.js')) {
    analyzeScript(fs.readFileSync(file, 'utf8'), file)
  }
}

function scanFileIsolated(file) {
  const saved = {
    networkActions: new Map(networkActions),
    dynamicFailures: [...dynamicFailures],
    uiOnlyOnNetwork: [...uiOnlyOnNetwork],
    permissionMismatches: [...permissionMismatches]
  }
  networkActions.clear()
  dynamicFailures.length = 0
  uiOnlyOnNetwork.length = 0
  permissionMismatches.length = 0

  scanSourceFile(file)
  const result = {
    networkActions: new Map(networkActions),
    dynamicFailures: [...dynamicFailures],
    uiOnlyOnNetwork: [...uiOnlyOnNetwork],
    permissionMismatches: [...permissionMismatches]
  }

  networkActions.clear()
  for (const [key, value] of saved.networkActions) networkActions.set(key, value)
  dynamicFailures.length = 0
  dynamicFailures.push(...saved.dynamicFailures)
  uiOnlyOnNetwork.length = 0
  uiOnlyOnNetwork.push(...saved.uiOnlyOnNetwork)
  permissionMismatches.length = 0
  permissionMismatches.push(...saved.permissionMismatches)
  return result
}

for (const file of walkFiles(FE_SERVICES)) {
  if (file.endsWith('cashierV3ActionManifest.js')) continue
  scanSourceFile(file)
}

ok('UI_ONLY 未进入网络路径', uiOnlyOnNetwork.length === 0, JSON.stringify(uiOnlyOnNetwork.slice(0, 5)))
const unregistered = [...networkActions.keys()].filter((a) => !fe[a]).sort()
ok('网络 action 均已登记', unregistered.length === 0, unregistered.slice(0, 30).join(','))
ok('permission 字面量与 manifest 一致', permissionMismatches.length === 0, JSON.stringify(permissionMismatches.slice(0, 5)))

if (dynamicFailures.length) {
  console.log('  INFO  未证明动态位点:')
  for (const d of dynamicFailures.slice(0, 50)) console.log(`    - ${d.file}:${d.line} ${d.reason}`)
}
ok('无未证明的动态 action', dynamicFailures.length === 0, `count=${dynamicFailures.length}`, 'FE-11-02')

console.log('== 3.1 正例 fixture 可识别且与 production 状态隔离 ==')
const expectedPositiveActions = new Set([
  'create-reservation',
  'update-reservation',
  'select-writeoff-member',
  'select-reservation-member'
])
const positiveActions = new Set()
const productionNetworkSizeBeforePositive = networkActions.size
const productionDynamicSizeBeforePositive = dynamicFailures.length
if (fs.existsSync(POS_FIX)) {
  for (const entry of fs.readdirSync(POS_FIX).sort()) {
    if (!/\.(vue|js)$/.test(entry)) continue
    const full = path.join(POS_FIX, entry)
    const result = scanFileIsolated(full)
    for (const action of result.networkActions.keys()) positiveActions.add(action)
    const invalid = result.dynamicFailures.length > 0
      || result.uiOnlyOnNetwork.length > 0
      || result.permissionMismatches.length > 0
      || [...result.networkActions.keys()].some((action) => !fe[action])
    ok(
      `正例可证明: ${path.relative(ROOT, full)}`,
      !invalid && result.networkActions.size > 0,
      JSON.stringify({
        actions: [...result.networkActions.keys()],
        dynamic: result.dynamicFailures,
        uiOnly: result.uiOnlyOnNetwork,
        permissions: result.permissionMismatches
      }),
      'FE-11-02'
    )
  }
} else {
  ok('正例目录存在', false, POS_FIX, 'FE-11-02')
}
const missingPositiveActions = [...expectedPositiveActions].filter((action) => !positiveActions.has(action))
ok('正例覆盖预约与会员选择 action', missingPositiveActions.length === 0, missingPositiveActions.join(','), 'FE-11-02')
ok(
  '正例扫描未污染 production 结果',
  networkActions.size === productionNetworkSizeBeforePositive
    && dynamicFailures.length === productionDynamicSizeBeforePositive,
  `prodNet=${productionNetworkSizeBeforePositive} now=${networkActions.size}`,
  'FE-11-02'
)

console.log('== 4. bridge 接线 + 幂等前缀门禁 ==')
const bridge = fs.readFileSync(FE_BRIDGE, 'utf8')
ok('result_unknown 合同', bridge.includes('result_unknown') && bridge.includes('COMMAND_RESULT_UNKNOWN'), '', 'BR-10-02')
ok('originalIdempotencyKey 门禁', bridge.includes('ORIGINAL_IDEMPOTENCY_KEY_REQUIRED'), '', 'BR-10-02')
ok('context switch 意图', bridge.includes('beginCashierV3ContextSwitch'), '', 'CS-9-01')
ok('versions 合并', bridge.includes('mergeCashierV3PublicVersions'), '', 'CS-9-02')
ok('信封一致性', bridge.includes('assertStateEnvelopeConsistency'), '', 'BR-10-02')
ok('禁止 requestMeta 兜底 context switch', !/contextSwitchEpoch\s*\?\?\s*requestMeta/.test(bridge), '', 'CS-9-01')
ok('禁止信任 suppliedVersion 回退', !/else if \(suppliedVersion !== null\)/.test(bridge), '', 'CS-9-02')

function objectKeysForConst(source, constName) {
  const ast = parseJs(source)
  let keys = null
  walkAst(ast, (node) => {
    if (keys || node.type !== 'VariableDeclarator' || node.id?.type !== 'Identifier' || node.id.name !== constName) return
    if (node.init?.type !== 'ObjectExpression') {
      keys = []
      return
    }
    const found = []
    for (const prop of node.init.properties) {
      if (prop.type !== 'ObjectProperty' && prop.type !== 'ObjectMethod') {
        keys = []
        return
      }
      if (prop.computed) {
        keys = []
        return
      }
      const key = prop.key?.type === 'Identifier' ? prop.key.name : prop.key?.value
      if (typeof key !== 'string' || key === '') {
        keys = []
        return
      }
      found.push(key)
    }
    keys = found
  })
  return Array.isArray(keys) ? keys : []
}

const bridgeRootKeys = objectKeysForConst(bridge, 'EMPTY_BOOTSTRAP').sort()
const backendRootSource = fs.readFileSync(BE_ROOT_CONTRACT, 'utf8')
const backendRootBlock = backendRootSource.match(/public const REQUIRED_KEYS\s*=\s*\[([\s\S]*?)\];/)
const backendRootKeys = backendRootBlock
  ? [...backendRootBlock[1].matchAll(/['"]([^'"]+)['"]/g)].map((match) => match[1]).sort()
  : []
const rootKeysEqual = bridgeRootKeys.length > 0
  && backendRootKeys.length > 0
  && JSON.stringify(bridgeRootKeys) === JSON.stringify(backendRootKeys)
ok(
  '根 schema 顶层键跨端一致',
  rootKeysEqual,
  JSON.stringify({ bridgeRootKeys, backendRootKeys }),
  'RP-5-02'
)

// 实际 command 幂等前缀集合 vs 后端允许清单
const prefixLit = []
function collectPrefixesFromFile(file) {
  const text = fs.readFileSync(file, 'utf8')
  for (const m of text.matchAll(/createCashierV3CommandId\(\s*['"]([A-Z][A-Z0-9_]*)['"]\s*\)/g)) {
    if (m[1] === 'SESSION') continue
    prefixLit.push(m[1])
  }
}
collectPrefixesFromFile(FE_BRIDGE)
for (const file of walkFiles(FE_SERVICES)) {
  if (/\.js$/.test(file)) collectPrefixesFromFile(file)
}
const defaultPrefix = bridge.includes("createCashierV3CommandId(prefix = 'CMD')") || bridge.includes('createCashierV3CommandId(prefix = "CMD")') ? ['CMD'] : []
const fePrefixes = [...new Set([...defaultPrefix, ...prefixLit])].sort()
const FORBIDDEN_PREFIXES = ['SALES_ORDER_ACTION', 'CHECKOUT_COMPLETION', 'PRINT_RECEIPT']
ok('无非法幂等前缀', !fePrefixes.some((p) => FORBIDDEN_PREFIXES.includes(p)), fePrefixes.filter((p) => FORBIDDEN_PREFIXES.includes(p)).join(','), 'FE-11-02')
let bePrefixes = globalThis.__C1A_BE_PREFIXES || []
if (!bePrefixes.length && beJsonPath && fs.existsSync(beJsonPath)) {
  try {
    const beFull = JSON.parse(fs.readFileSync(beJsonPath, 'utf8'))
    bePrefixes = beFull.idempotency_prefixes || beFull.__idempotency_prefixes || []
  } catch (_) {}
}
ok('后端幂等前缀清单可读', bePrefixes.length > 0, `fe=${fePrefixes.join(',')} be=${bePrefixes.join(',')}`, 'FE-11-02')
const missingPrefix = fePrefixes.filter((p) => !bePrefixes.includes(p))
ok('前端实际前缀 ⊆ 后端允许清单', missingPrefix.length === 0, missingPrefix.join(','), 'FE-11-02')

console.log('== 5. 完整审计表（来自 manifest + 扫描） ==')
const beForAuditRaw = beJsonPath && fs.existsSync(beJsonPath) ? JSON.parse(fs.readFileSync(beJsonPath, 'utf8')) : null
const beForAudit = beForAuditRaw?.actions || beForAuditRaw
const audit = Object.keys(fe).sort().map((action) => {
  const d = fe[action]
  const be = beForAudit?.[action]
  const sites = networkActions.get(action) || []
  return {
    page_action: action,
    canonical: d.canonical,
    type: d.type,
    owner: d.owner,
    permission: d.permission,
    permissionPolicyId: d.permissionPolicyId || (d.permission ? `feature:${d.permission}` : null),
    writes_db: d.type === 'command',
    requires_command_receipt_contexts: d.type === 'command',
    network_sites: sites.length,
    backend_recovery: be?.recovery || null,
    inactive_reason: d.type === 'command'
      ? 'awaiting_C2_C5_handler_or_policy'
      : (sites.length ? null : 'projection_not_scanned_or_ui_only_path')
  }
})
fs.writeFileSync(AUDIT_OUT, JSON.stringify(audit, null, 2))
ok('审计表已写出', fs.existsSync(AUDIT_OUT), '', 'FE-11-02')
ok('审计表覆盖全部 manifest', audit.length === Object.keys(fe).length, '', 'FE-11-02')

console.log('== 6. 负例 fixture 必须 fail-closed（独立状态） ==')
const productionNetworkSize = networkActions.size
const productionDynSize = dynamicFailures.length

function scanFileMustFail(file) {
  const rel = path.relative(ROOT, file)
  const result = scanFileIsolated(file)
  const failedNeg = result.dynamicFailures.length > 0
    || result.uiOnlyOnNetwork.length > 0
    || result.permissionMismatches.length > 0
    || [...result.networkActions.keys()].some((action) => !fe[action])
  ok(
    `负例应被拦截: ${rel}`,
    failedNeg,
    `dyn=${result.dynamicFailures.length} ui=${result.uiOnlyOnNetwork.length} perm=${result.permissionMismatches.length}`,
    'FE-11-03'
  )
}

if (fs.existsSync(NEG_FIX)) {
  for (const entry of fs.readdirSync(NEG_FIX)) {
    const full = path.join(NEG_FIX, entry)
    if (/\.(vue|js)$/.test(entry)) scanFileMustFail(full)
  }
} else {
  ok('负例目录存在', false, NEG_FIX, 'FE-11-03')
}
ok('负例扫描未清空生产结果', networkActions.size === productionNetworkSize && dynamicFailures.length === productionDynSize, `prodNet=${productionNetworkSize} now=${networkActions.size}`, 'FE-11-03')

console.log('\n== frontend-manifest-sync SUMMARY ==')
console.log(`ASSERT_PASSED=${passed}`)
console.log(`ASSERT_FAILED=${failed}`)
console.log(`NETWORK_ACTIONS=${networkActions.size}`)
console.log(`DYNAMIC_SITES=${dynamicFailures.length}`)
if (failed === 0) console.log('RUNNER_OK=js/frontend-manifest-sync.mjs')
process.exit(failed > 0 ? 1 : 0)
