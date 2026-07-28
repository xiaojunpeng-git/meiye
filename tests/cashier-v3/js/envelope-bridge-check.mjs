#!/usr/bin/env node
/**
 * 信封桥接：业务成功不被根拒收改写；恢复策略由 manifest 驱动。
 */
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import esbuild from 'esbuild'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const TESTS = path.resolve(__dirname, '..')
const REPO = path.resolve(TESTS, '../..')
const FE_MANIFEST = path.join(REPO, '前端代码/cashier-v3/src/services/cashierV3ActionManifest.js')
const BRIDGE = path.join(REPO, '前端代码/cashier-v3/src/services/cashierV3Bridge.js')
const EV = process.env.C1A_EVIDENCE_DIR || ''

let passed = 0
let failed = 0
function ok(name, cond, detail = '', gateId = '') {
  if (cond) {
    passed++
    console.log(`  PASS  ${name}${gateId ? ` [${gateId}]` : ''}`)
    if (gateId) console.log(`GATE_PASS=${gateId}`)
  } else {
    failed++
    console.log(`  FAIL  ${name}${detail ? ' -> ' + detail : ''}`)
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
const bridgeSrc = fs.readFileSync(BRIDGE, 'utf8')

ok('STATE_REVISION_MISMATCH 在保留分支', bridgeSrc.includes('STATE_REVISION_MISMATCH'), '', 'BR-10-01')
ok('buildStateIgnoredResult 存在', bridgeSrc.includes('buildStateIgnoredResult'), '', 'BR-10-01')
ok('信封绑定 expectedAction', bridgeSrc.includes('expectedAction') && bridgeSrc.includes('boundAction'), '', 'BR-10-02')
ok('禁止 requestMeta 兜底', !/contextSwitchEpoch\s*\?\?\s*requestMeta/.test(bridgeSrc), '', 'BR-10-02')

let missingRecovery = 0
const recoveryMatrix = []
for (const [key, def] of Object.entries(fe)) {
  if (def.alias_of || def.aliasOf) continue
  if (def.type !== 'command') continue
  if (!def.recovery || !def.recovery.mode) missingRecovery++
  else recoveryMatrix.push({ action: key, ...def.recovery })
}
ok('全部 command 有 recovery', missingRecovery === 0, `missing=${missingRecovery}`, 'BR-10-03')
ok('recovery 矩阵非空', recoveryMatrix.length > 0, '', 'BR-10-03')

if (EV) {
  fs.writeFileSync(path.join(EV, 'recovery-matrix.json'), JSON.stringify(recoveryMatrix, null, 2))
}

const samplePath = EV ? path.join(EV, 'envelope-sample.json') : ''
const sampleExists = samplePath && fs.existsSync(samplePath)
if (sampleExists) {
  const sample = JSON.parse(fs.readFileSync(samplePath, 'utf8'))
  ok('样本含 stateContextId', !!sample.stateContextId, '', 'BR-10-02')
  ok('样本 state 内 revision 一致', String(sample.stateRevision) === String(sample.state?.stateRevision), '', 'BR-10-02')
  ok('样本含 boundAction', !!sample.boundAction, '', 'BR-10-02')
} else {
  // integration 尚未产出时：仍检查源码强制字段，不得字面量 PASS
  const hasStrict = bridgeSrc.includes('ENVELOPE_ACTION_MISMATCH')
    && bridgeSrc.includes('ENVELOPE_IDEMPOTENCY_MISMATCH')
    && bridgeSrc.includes('boundCorrelationId')
  ok('严格信封源码门禁（无样本时）', hasStrict, 'missing strict envelope checks', 'BR-10-02')
}

console.log(`ASSERT_PASSED=${passed}`)
console.log(`ASSERT_FAILED=${failed}`)
if (failed > 0) process.exit(1)
console.log('RUNNER_OK=js/envelope-bridge-check.mjs')
