import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'

// Explicit 010-only read probe; inject fixed source into CLI memory, not disk.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const hash = value => createHash('sha256').update(value).digest('hex')
if (process.argv.length !== 2 || fs.realpathSync(root) !== '/Users/xiaojunpeng/Desktop/魔核/美业/美容源码') throw Error('CANONICAL_NO_ARGUMENTS_REQUIRED')
const file = '后端代码/app/services/cashier/v3/checkout/provider/CashierV3MemberBalanceProvider.php'
const source = fs.readFileSync(path.join(root,file))
const request = Buffer.from(JSON.stringify({ source: source.toString('base64'), sha256: hash(source) })).toString('base64')
const script = fs.readFileSync(path.join(root,'scripts/r51-010-balance-read-probe.php'),'utf8')
  .replace('__R51_BALANCE_READ_PROBE__',request)
const folder = path.join(root,'tests/report-basic/evidence/010-balance-read-repair')
const output = path.join(folder,'remote-readonly.json')
if (fs.existsSync(output)) throw Error('PROBE_RECEIPT_EXISTS')
let result
try {
  result = JSON.parse(execFileSync('ssh',['-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes',
    '-o','ConnectTimeout=12','mohe-server','/www/server/php/74/bin/php'],
  { input: script, timeout: 90000, maxBuffer: 2 * 1024 * 1024, stdio: ['pipe','pipe','pipe'] }).toString())
} catch { throw Error('READONLY_PROBE_FAILED_NO_DEPLOYMENT') }
if (hash(fs.readFileSync(path.join(root,file))) !== hash(source) || result.result !== 'PASS' || result.business_writes !== false)
  throw Error('READONLY_RESULT_OR_SOURCE_CHANGED')
fs.mkdirSync(folder,{recursive:true})
fs.writeFileSync(output,JSON.stringify({ ...result, probe_sha256:hash(script), observedAt:new Date().toISOString() },null,2)+'\n',{flag:'wx',mode:0o600})
console.log(JSON.stringify({result:result.result,evidence:output,businessWrites:false,deployed:false}))
