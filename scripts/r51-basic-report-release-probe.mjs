import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import https from 'node:https'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { brotliDecompressSync, gunzipSync, inflateSync } from 'node:zlib'

// Inventory only. PHP is sent to an allowlisted SSH process on stdin, never
// uploaded/stored remotely. Exclusive local evidence creation prevents silently
// replacing a deployment baseline. No deploy/commit/push/overwrite option exists.
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const canonical = '/Users/xiaojunpeng/Desktop/魔核/美业/美容源码'
if (realpathSync(repo) !== canonical) throw new Error('CANONICAL_SOURCE_REQUIRED')
const sites = {
  rh: { host: 'rh-server', domain: 'rh.cc3798.com', database: 'ruihao', port: 3306 },
  '008': { host: 'mohe-server', domain: '008.cc3798.com', database: 'mohe_008', port: 3307 },
  '010': { host: 'mohe-server', domain: '010.cc3798.com', database: 'mohe_010_app', port: 3307 }
}
const args = process.argv.slice(2)
if (args.length && (args.length !== 2 || args[0] !== '--site' || !Object.hasOwnProperty.call(sites, args[1]))) {
  throw new Error('USE_NO_ARGUMENTS_OR_EXPLICIT_SITE')
}
const selected = args.length ? [args[1]] : ['rh', '008', '010']
const folder = path.join(repo, 'tests/report-basic/evidence/release')
for (const site of selected) if (existsSync(path.join(folder, `${site}-before.json`))) throw new Error(`BASELINE_EXISTS_REFUSING_OVERWRITE:${site}`)
const php = readFileSync(path.join(repo, 'scripts/r51-basic-report-release-probe.php'))
const hash = bytes => createHash('sha256').update(bytes).digest('hex')
const run = (bin, argv, options = {}) => execFileSync(bin, argv, { cwd: repo, encoding: 'utf8', maxBuffer: 96 * 1024 * 1024, ...options })
const head = run('git', ['rev-parse', 'HEAD']).trim()
const evidenceScripts = { php_sha256: hash(php), mjs_sha256: hash(readFileSync(fileURLToPath(import.meta.url))) }

// Only HTTPS static entry/asset reads on the named instance; no credentials,
// cookies, logins, redirects into application routes or cross-origin requests.
// Bodies remain in memory just long enough to hash/parse their references.
function requestStatic(url, domain) {
  return new Promise(resolve => {
    const parsed = new URL(url)
    if (parsed.protocol !== 'https:' || parsed.hostname !== domain || parsed.username || parsed.password || (parsed.port && parsed.port !== '443')) {
      resolve({ status: null, error: 'ORIGIN_NOT_ALLOWLISTED' }); return
    }
    const req = https.get(parsed, { headers: { 'accept-encoding': 'identity', 'user-agent': 'R51-readonly-release-probe/1' } }, res => {
      const chunks = []; let bytes = 0
      res.on('data', chunk => { bytes += chunk.length; if (bytes > 48 * 1024 * 1024) req.destroy(new Error('BODY_LIMIT')); else chunks.push(chunk) })
      res.on('error', () => resolve({ status: res.statusCode, error: 'HTTP_BODY_READ_FAILED' }))
      res.on('end', () => {
        try {
          let body = Buffer.concat(chunks)
          const encoding = res.headers['content-encoding']
          if (encoding === 'gzip') body = gunzipSync(body)
          else if (encoding === 'br') body = brotliDecompressSync(body)
          else if (encoding === 'deflate') body = inflateSync(body)
          resolve({ status: res.statusCode, bytes: body.length, sha256: hash(body), body,
            basic_auth_challenge: /^Basic(?:\s|$)/i.test(res.headers['www-authenticate'] || '') })
        } catch { resolve({ status: res.statusCode, error: 'HTTP_BODY_DECODE_FAILED' }) }
      })
    })
    req.setTimeout(18000, () => req.destroy(new Error('TIMEOUT')))
    req.on('error', () => resolve({ status: null, error: 'HTTPS_READ_FAILED' }))
  })
}

function references(html, base, domain) {
  const out = new Map()
  for (const match of html.matchAll(/(?:src|href)\s*=\s*["']?([^"'\s>]+)/gi)) {
    try {
      const url = new URL(match[1].replace(/&amp;/g, '&'), base)
      if (!/\.(?:js|css)$/i.test(url.pathname)) continue
      if (url.hostname !== domain || url.protocol !== 'https:' || url.username || url.password) continue
      out.set(url.href, url)
    } catch { /* Malformed references are not executed. Disk inventory remains available. */ }
  }
  return [...out.values()]
}

function publicRecord(record) { const { body, ...safe } = record; return safe }

async function inspectPublic(frontend, domain) {
  const entry = await requestStatic(frontend.url, domain)
  const result = { url: frontend.url, entry: publicRecord(entry), references: [], redirects_followed: false, credentials_used: false }
  if (entry.status !== 200 || !entry.body) {
    result.limitation = entry.status === 401 ? 'Authentication preserved; only disk resource inventory available without credentials' : 'Entry not publicly readable; disk inventory recorded separately'
    return result
  }
  const refs = references(entry.body.toString('utf8'), frontend.url, domain)
  // Bound concurrency without dropping references or returning a fake first page.
  for (let offset = 0; offset < refs.length; offset += 8) {
    const batch = await Promise.all(refs.slice(offset, offset + 8).map(async url => {
      const response = await requestStatic(url.href, domain)
      const normalized = url.pathname.replace(/^\/preview-8080\//, '/').replace(/^\//, '')
      const disk = frontend.references[normalized] || frontend.asset_trees?.files?.[normalized] || null
      return { path: url.pathname, normalized_path: normalized, ...publicRecord(response),
        disk_sha256: disk?.sha256 || null, matches_disk: response.status === 200 && disk?.sha256 ? response.sha256 === disk.sha256 : null }
    }))
    result.references.push(...batch)
  }
  result.html_matches_disk = frontend.entry_fingerprint ? entry.sha256 === frontend.entry_fingerprint.sha256 : null
  // Preview Nginx rewrites only HTML prefixes, so raw HTML equality is not a
  // publication gate. The independently fetched resource fingerprints are.
  result.preview_html_rewrite_expected = frontend.preview
  return result
}

mkdirSync(folder, { recursive: true })
let failed = false
for (const site of selected) {
  const config = sites[site]
  console.log(`${site}: collecting deployed PHP/resource fingerprints and read-only schema`)
  const receipt = { site, started_at: new Date().toISOString(), local_baseline_head: head, scripts: evidenceScripts,
    scope: 'before-readonly', ssh_alias: config.host, writes_performed: false, uploads_performed: false,
    commit_performed: false, push_performed: false, deployment_performed: false, errors: [] }
  try {
    const raw = run('ssh', ['-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=12',
      '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2', config.host, `/www/server/php/74/bin/php -- ${site}`], { input: php, timeout: 240000, stdio: ['pipe', 'pipe', 'pipe'] })
    const remote = JSON.parse(raw)
    if (remote.site !== site || remote.database?.identity?.database_name !== config.database || Number(remote.database?.identity?.server_port) !== config.port) throw new Error('REMOTE_IDENTITY_OR_PROBE_FAILED')
    receipt.remote = remote
    receipt.public = []
    for (const frontend of remote.frontends) {
      console.log(`${site}: fingerprinting ${frontend.preview ? 'independent preview' : 'formal'} ${frontend.kind} public entry`)
      receipt.public.push(await inspectPublic(frontend, config.domain))
    }
    receipt.collection_completed = true
    receipt.protection = { unauthenticated_probe_only: true, server_configuration_unchanged: remote.protected_unchanged_during_probe,
      challenge_observed: receipt.public.some(item => item.entry.status === 401 && item.entry.basic_auth_challenge) }
  } catch (error) {
    // exec errors can contain commands/stdout/env fragments; never stringify
    // them into evidence or terminal output. Persist only controlled error codes.
    receipt.collection_completed = false
    receipt.errors.push(error.message === 'REMOTE_IDENTITY_OR_PROBE_FAILED' ? error.message : 'READ_ONLY_COLLECTION_FAILED')
    failed = true
  }
  receipt.finished_at = new Date().toISOString()
  receipt.local_head_after = run('git', ['rev-parse', 'HEAD']).trim()
  receipt.local_head_unchanged = receipt.local_head_after === head
  const file = path.join(folder, `${site}-before.json`)
  writeFileSync(file, JSON.stringify(receipt, null, 2) + '\n', { flag: 'wx', mode: 0o600 })
  console.log(JSON.stringify({ site, evidence: file, completed: receipt.collection_completed,
    php_files: receipt.remote?.backend?.file_count, public_status: receipt.public?.map(item => item.entry.status),
    audit_schema_exists: receipt.remote?.database?.schemas?.eb_basic_report_registration_audit?.exists,
    permission_revision: receipt.remote?.database?.permission_catalog?.revision, errors: receipt.errors }))
}
if (failed) process.exitCode = 1
