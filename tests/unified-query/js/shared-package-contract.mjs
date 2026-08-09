#!/usr/bin/env node

import fs from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repo = path.resolve(__dirname, '../../..')
const cashierTests = path.join(repo, 'tests/cashier-v3')
const require = createRequire(path.join(cashierTests, 'package.json'))
const babelParser = require('@babel/parser')
const { parse: parseSfc } = require('@vue/compiler-sfc')

const sharedRoot = path.join(repo, '前端代码/shared/unified-query-vue3')
const sharedSrc = path.join(sharedRoot, 'src')
const cashierRoot = path.join(repo, '前端代码/cashier-v3')

let passed = 0
let failed = 0
const emittedGates = new Set()

function ok(name, condition, detail = '', gateId = '') {
  if (condition) {
    passed += 1
    console.log(`  PASS  ${name}${gateId ? ` [${gateId}]` : ''}`)
    if (gateId && !emittedGates.has(gateId)) {
      emittedGates.add(gateId)
      console.log(`GATE_PASS=${gateId}`)
    }
    return
  }
  failed += 1
  console.log(`  FAIL  ${name}${detail ? ` -> ${detail}` : ''}${gateId ? ` [${gateId}]` : ''}`)
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'))
}

function sourceFiles(root) {
  const files = []
  const visit = (directory) => {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const file = path.join(directory, entry.name)
      if (entry.isDirectory()) visit(file)
      else if (/\.(?:js|vue|css)$/.test(entry.name)) files.push(file)
    }
  }
  visit(root)
  return files.sort()
}

function parseModule(source, filename) {
  return babelParser.parse(source, {
    sourceType: 'module',
    sourceFilename: filename,
    plugins: ['importMeta', 'topLevelAwait']
  })
}

function moduleSources(ast) {
  const sources = []
  const walk = (node) => {
    if (!node || typeof node !== 'object') return
    if (Array.isArray(node)) {
      node.forEach(walk)
      return
    }
    if (['ImportDeclaration', 'ExportNamedDeclaration', 'ExportAllDeclaration'].includes(node.type)
      && typeof node.source?.value === 'string') {
      sources.push(node.source.value)
    }
    if (node.type === 'CallExpression'
      && (node.callee?.type === 'Import'
        || (node.callee?.type === 'Identifier' && node.callee.name === 'require'))
      && typeof node.arguments?.[0]?.value === 'string') {
      sources.push(node.arguments[0].value)
    }
    for (const [key, value] of Object.entries(node)) {
      if (['loc', 'start', 'end', 'extra'].includes(key)) continue
      walk(value)
    }
  }
  walk(ast)
  return sources
}

function importsForFile(file) {
  const source = fs.readFileSync(file, 'utf8')
  if (file.endsWith('.js')) return moduleSources(parseModule(source, file))
  if (file.endsWith('.css')) {
    return [...source.matchAll(/@import\s+(?:url\()?\s*['"]([^'"]+)['"]/g)]
      .map((match) => match[1])
  }
  const parsed = parseSfc(source, { filename: file })
  if (parsed.errors.length) {
    throw new Error(`SFC_PARSE_FAILED=${file}:${parsed.errors.join('|')}`)
  }
  const scripts = [parsed.descriptor.script, parsed.descriptor.scriptSetup]
    .filter(Boolean)
    .map((block) => block.content)
  return scripts.flatMap((script) => moduleSources(parseModule(script, file)))
}

function relativeInside(root, importer, specifier) {
  const target = path.resolve(path.dirname(importer), specifier)
  return target === root || target.startsWith(`${root}${path.sep}`)
}

const expectedPackageFiles = [
  'package.json',
  'src/index.js',
  'src/contracts/unifiedQueryContract.js',
  'src/composables/useUnifiedQueryPage.js',
  'src/styles/unified-query.css',
  'src/components/QueryEntitySelectorOverlay.vue',
  'src/components/UnifiedQueryCustomFieldDrawer.vue',
  'src/components/UnifiedQueryExportDrawer.vue',
  'src/components/UnifiedQueryFieldRenameDrawer.vue',
  'src/components/UnifiedQuerySettingsDrawer.vue',
  'src/components/UnifiedQueryToolbar.vue'
]
const missingPackageFiles = expectedPackageFiles.filter((file) => !fs.existsSync(path.join(sharedRoot, file)))
const packageJson = missingPackageFiles.includes('package.json')
  ? {}
  : readJson(path.join(sharedRoot, 'package.json'))
const expectedExports = {
  '.': './src/index.js',
  './contract': './src/contracts/unifiedQueryContract.js',
  './composable': './src/composables/useUnifiedQueryPage.js',
  './styles.css': './src/styles/unified-query.css',
  './components/*': './src/components/*.vue'
}
const peerKeys = Object.keys(packageJson.peerDependencies || {}).sort()
const indexAst = missingPackageFiles.includes('src/index.js')
  ? null
  : parseModule(fs.readFileSync(path.join(sharedSrc, 'index.js'), 'utf8'), 'src/index.js')
const indexNamedExports = []
const indexExportAllSources = []
for (const statement of indexAst?.program?.body || []) {
  if (statement.type === 'ExportNamedDeclaration') {
    for (const specifier of statement.specifiers || []) {
      const exported = specifier.exported?.name || specifier.exported?.value || ''
      if (exported) indexNamedExports.push(exported)
    }
  } else if (statement.type === 'ExportAllDeclaration' && statement.source?.value) {
    indexExportAllSources.push(statement.source.value)
  }
}
indexNamedExports.sort()
const expectedNamedExports = [
  'QueryEntitySelectorOverlay',
  'UnifiedQueryCustomFieldDrawer',
  'UnifiedQueryExportDrawer',
  'UnifiedQueryFieldRenameDrawer',
  'UnifiedQuerySettingsDrawer',
  'UnifiedQueryToolbar',
  'useUnifiedQueryPage'
].sort()
ok(
  '共享统一查询包具有稳定入口、组件、composable、contract 与样式结构',
  missingPackageFiles.length === 0
    && packageJson.name === '@mohe/unified-query-vue3'
    && packageJson.private === true
    && packageJson.type === 'module'
    && JSON.stringify(packageJson.exports || {}) === JSON.stringify(expectedExports)
    && JSON.stringify(peerKeys) === JSON.stringify(['@lucide/vue', 'vue'])
    && packageJson.peerDependencies?.vue === '^3.4.38'
    && packageJson.peerDependencies?.['@lucide/vue'] === '^1.27.0'
    && Object.keys(packageJson.dependencies || {}).length === 0
    && JSON.stringify(indexNamedExports) === JSON.stringify(expectedNamedExports)
    && JSON.stringify(indexExportAllSources) === JSON.stringify([
      './contracts/unifiedQueryContract.js'
    ]),
  JSON.stringify({
    missingPackageFiles,
    packageJson,
    indexNamedExports,
    indexExportAllSources
  }),
  'UQ-SHARED-01'
)

const sharedFiles = missingPackageFiles.length ? [] : sourceFiles(sharedSrc)
const parseFailures = []
const dependencyViolations = []
for (const file of sharedFiles) {
  let imports = []
  try {
    imports = importsForFile(file)
  } catch (error) {
    parseFailures.push(String(error?.message || error))
    continue
  }
  for (const specifier of imports) {
    const lower = specifier.toLowerCase()
    const relative = specifier.startsWith('./') || specifier.startsWith('../')
    const allowedExternal = specifier === 'vue'
      || /^@lucide\/vue\/dist\/esm\/icons\/[a-z0-9-]+\.mjs$/.test(specifier)
    const domainCoupled = lower.includes('cashier')
      || lower.includes('member')
      || lower.includes('inventory')
      || specifier.startsWith('@/')
    if (domainCoupled
      || (!relative && !allowedExternal)
      || (relative && !relativeInside(sharedRoot, file, specifier))) {
      dependencyViolations.push({
        file: path.relative(repo, file),
        specifier,
        domainCoupled,
        escapesPackage: relative && !relativeInside(sharedRoot, file, specifier)
      })
    }
  }
}
const sharedContractSource = missingPackageFiles.length
  ? ''
  : fs.readFileSync(path.join(sharedSrc, 'contracts/unifiedQueryContract.js'), 'utf8')
ok(
  '共享源码只依赖 Vue、Lucide 单图标和包内相对模块且不含领域 import',
  sharedFiles.length >= 10
    && parseFailures.length === 0
    && dependencyViolations.length === 0
    && sharedContractSource.includes('extractUnifiedQueryProjection')
    && !sharedContractSource.includes('extractUnifiedQueryMemberProjection'),
  JSON.stringify({
    sharedFileCount: sharedFiles.length,
    parseFailures,
    dependencyViolations
  }),
  'UQ-SHARED-02'
)

const facadeRelativePaths = [
  'src/components/query/QueryEntitySelectorOverlay.vue',
  'src/components/query/UnifiedQueryCustomFieldDrawer.vue',
  'src/components/query/UnifiedQueryExportDrawer.vue',
  'src/components/query/UnifiedQueryFieldRenameDrawer.vue',
  'src/components/query/UnifiedQuerySettingsDrawer.vue',
  'src/components/query/UnifiedQueryToolbar.vue',
  'src/composables/useUnifiedQueryPage.js',
  'src/services/unifiedQueryContract.js'
]
const missingFacades = facadeRelativePaths.filter((file) => !fs.existsSync(path.join(cashierRoot, file)))
const facadeFailures = []
for (const relativePath of facadeRelativePaths) {
  const file = path.join(cashierRoot, relativePath)
  if (!fs.existsSync(file)) continue
  const source = fs.readFileSync(file, 'utf8')
  const imports = importsForFile(file)
  const sharedImports = imports.filter((specifier) => specifier.startsWith('@mohe/unified-query-vue3'))
  const lineCount = source.split(/\r?\n/).length
  let sizeLimit = 512
  let lineLimit = 16
  let allowedImports = ['@mohe/unified-query-vue3']
  if (relativePath.endsWith('UnifiedQueryToolbar.vue')) {
    sizeLimit = 2048
    lineLimit = 60
    allowedImports = [
      'vue',
      '@mohe/unified-query-vue3',
      '@mohe/unified-query-vue3/styles.css',
      '@/services/cashierV3Bridge'
    ]
    if (!source.includes('openCashierV3QueryEntitySelector')) {
      facadeFailures.push({ relativePath, reason: 'toolbar_host_selector_not_injected' })
    }
  } else if (relativePath.endsWith('useUnifiedQueryPage.js')) {
    allowedImports = ['@mohe/unified-query-vue3/composable']
  } else if (relativePath.endsWith('unifiedQueryContract.js')) {
    sizeLimit = 1024
    lineLimit = 24
    allowedImports = ['@mohe/unified-query-vue3/contract']
    if (!source.includes('extractUnifiedQueryMemberProjection')
      || !source.includes('extractUnifiedQueryProjection')) {
      facadeFailures.push({ relativePath, reason: 'member_projection_adapter_missing' })
    }
  }
  const unexpectedImports = imports.filter((specifier) => !allowedImports.includes(specifier))
  if (sharedImports.length === 0
    || Buffer.byteLength(source) > sizeLimit
    || lineCount > lineLimit
    || unexpectedImports.length > 0) {
    facadeFailures.push({
      relativePath,
      bytes: Buffer.byteLength(source),
      sizeLimit,
      lineCount,
      lineLimit,
      imports,
      unexpectedImports
    })
  }
}
ok(
  'cashier 原路径均为受控薄 facade，只有 Toolbar 注入宿主选择器',
  missingFacades.length === 0 && facadeFailures.length === 0,
  JSON.stringify({ missingFacades, facadeFailures }),
  'UQ-SHARED-03'
)

const cashierPackage = readJson(path.join(cashierRoot, 'package.json'))
const cashierLock = readJson(path.join(cashierRoot, 'package-lock.json'))
const viteSource = fs.readFileSync(path.join(cashierRoot, 'vite.config.js'), 'utf8')
const packageLink = cashierPackage.dependencies?.['@mohe/unified-query-vue3']
const lockedRootLink = cashierLock.packages?.['']?.dependencies?.['@mohe/unified-query-vue3']
const lockedSharedPackage = cashierLock.packages?.['../shared/unified-query-vue3']
const lockedNodeLink = cashierLock.packages?.['node_modules/@mohe/unified-query-vue3']
ok(
  'cashier 通过本地 package 依赖共享包并固定 Vue/Lucide 单实例解析',
  packageLink === 'file:../shared/unified-query-vue3'
    && lockedRootLink === packageLink
    && lockedSharedPackage?.version === packageJson.version
    && JSON.stringify(Object.keys(lockedSharedPackage?.peerDependencies || {}).sort())
      === JSON.stringify(['@lucide/vue', 'vue'])
    && lockedNodeLink?.link === true
    && lockedNodeLink?.resolved === '../shared/unified-query-vue3'
    && /preserveSymlinks\s*:\s*true/.test(viteSource)
    && /dedupe\s*:\s*\[[^\]]*['"]vue['"][^\]]*['"]@lucide\/vue['"][^\]]*\]/s.test(viteSource),
  JSON.stringify({
    packageLink,
    lockedRootLink,
    lockedSharedPackage,
    lockedNodeLink,
    viteConfig: path.relative(repo, path.join(cashierRoot, 'vite.config.js'))
  }),
  'UQ-SHARED-04'
)

console.log(`ASSERT_PASSED=${passed}`)
console.log(`ASSERT_FAILED=${failed}`)
process.exit(failed > 0 ? 1 : 0)
