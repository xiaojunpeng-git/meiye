#!/usr/bin/env node
/**
 * 静态门禁：required gate 禁止字面量 true 伪造 PASS。
 * 扫描必须覆盖：根 run-all.sh + requirements-matrix 全部 required gate runner。
 */
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const TESTS = path.resolve(__dirname, '..')
const MATRIX = path.join(TESTS, 'requirements-matrix.json')

let failed = 0
const hits = []
const scanned = new Set()

function scanFile(p) {
  const abs = path.resolve(p)
  if (scanned.has(abs)) return
  scanned.add(abs)
  if (!fs.existsSync(abs)) {
    hits.push(`MISSING_RUNNER_FILE ${abs}`)
    failed++
    return
  }
  const text = fs.readFileSync(abs, 'utf8')
  const lines = text.split(/\n/)
  const lineAt = (offset) => text.slice(0, offset).split(/\n/).length
  const assignments = new Map()
  const declaration = /(?:\b(?:const|let|var)\s+|\$)([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(true|1)\b/gi
  for (const match of text.matchAll(declaration)) {
    const list = assignments.get(match[1]) || []
    list.push(true)
    assignments.set(match[1], list)
  }
  const allAssignments = /(?:\b(?:const|let|var)\s+|\$)([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(false|0|true|1)\b/gi
  for (const match of text.matchAll(allAssignments)) {
    const list = assignments.get(match[1]) || []
    list.push(/^(true|1)$/i.test(match[2]))
    assignments.set(match[1], list)
  }
  const constantTrue = new Set([...assignments.entries()]
    .filter(([, values]) => values.length > 0 && values.every(Boolean))
    .map(([name]) => name))

  function matchingParen(start) {
    let depth = 0
    let quote = ''
    let escaped = false
    for (let i = start; i < text.length; i += 1) {
      const ch = text[i]
      if (quote) {
        if (escaped) escaped = false
        else if (ch === '\\') escaped = true
        else if (ch === quote) quote = ''
        continue
      }
      if (ch === "'" || ch === '"' || ch === '`') {
        quote = ch
        continue
      }
      if (ch === '(') depth += 1
      if (ch === ')' && --depth === 0) return i
    }
    return -1
  }

  function splitArgs(value) {
    const args = []
    let start = 0
    let depth = 0
    let quote = ''
    let escaped = false
    for (let i = 0; i < value.length; i += 1) {
      const ch = value[i]
      if (quote) {
        if (escaped) escaped = false
        else if (ch === '\\') escaped = true
        else if (ch === quote) quote = ''
        continue
      }
      if (ch === "'" || ch === '"' || ch === '`') { quote = ch; continue }
      if ('([{'.includes(ch)) depth += 1
      else if (')]}'.includes(ch)) depth -= 1
      else if (ch === ',' && depth === 0) {
        args.push(value.slice(start, i).trim())
        start = i + 1
      }
    }
    args.push(value.slice(start).trim())
    return args
  }

  function isStaticTrue(value) {
    const normalized = value
      .replace(/\/\*[^]*?\*\//g, '')
      .replace(/\/\/.*$/gm, '')
      .replace(/\s+/g, '')
      .toLowerCase()
    if (normalized === 'true' || normalized === '1' || normalized === 'boolean(true)') return true
    if (/^(true|1)(===|==)(true|1)$/.test(normalized)) return true
    const variable = normalized.replace(/^\$/, '')
    return constantTrue.has(variable)
  }

  for (const match of text.matchAll(/\bok\s*\(/g)) {
    const open = match.index + match[0].length - 1
    const close = matchingParen(open)
    if (close < 0) continue
    const args = splitArgs(text.slice(open + 1, close))
    if (args.length >= 2 && isStaticTrue(args[1])) {
      hits.push(`${abs}:${lineAt(match.index)}: constant truthy condition in ok(...)`)
      failed++
    }
  }

  // Shell runners must not claim a gate while the command is missing entirely.
  // Their semantic checks remain enforced by the matrix and RUNNER_OK ledger.
  lines.forEach((line, i) => {
    if (/^\s*(?:echo|printf)\b.*GATE_PASS=/.test(line) && /\btrue\b/i.test(line)) {
      hits.push(`${abs}:${i + 1}: gate output depends on literal true`)
      failed++
    }
  })
}

function walk(dir) {
  if (!fs.existsSync(dir)) return
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name)
    const st = fs.statSync(p)
    if (st.isDirectory()) {
      if (name === 'node_modules' || name === '_evidence' || name === '_tmp') continue
      walk(p)
      continue
    }
    if (!/\.(php|mjs|js|sh)$/.test(name)) continue
    if (name === 'scan-literal-gates.mjs') continue
    scanFile(p)
  }
}

for (const r of [path.join(TESTS, 'php'), path.join(TESTS, 'js'), path.join(TESTS, 'sql')]) {
  walk(r)
}

// 根 run-all.sh 必须扫描
scanFile(path.join(TESTS, 'run-all.sh'))

const matrix = JSON.parse(fs.readFileSync(MATRIX, 'utf8'))
const requiredRunners = [...new Set(matrix.gates.filter((g) => g.required).map((g) => g.runner))].sort()
for (const runner of requiredRunners) {
  scanFile(path.join(TESTS, runner))
}
console.log('LITERAL_SCAN_REQUIRED_RUNNERS=' + requiredRunners.join(','))
console.log('LITERAL_SCAN_FILE_COUNT=' + scanned.size)

if (!requiredRunners.includes('run-all.sh')) {
  hits.push('matrix missing run-all.sh coverage expectation')
  failed++
}

if (failed > 0) {
  console.error('LITERAL_GATE_FAIL count=' + failed)
  for (const h of hits) console.error(h)
  process.exit(1)
}
console.log('LITERAL_GATE_SCAN_OK')
console.log('GATE_PASS=PG-13-05')
process.exit(0)
