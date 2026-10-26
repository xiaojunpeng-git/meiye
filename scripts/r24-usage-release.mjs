import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { execFileSync } from 'node:child_process'

// R24 releases only the accepted R7 inventory picker change onto each verified live frontend base.
// The shared checkout is never built or copied into a customer package.
const [mode, instance] = process.argv.slice(2)
const root = process.cwd()
const commit = '9f598a0d60c0cf1ffbc9de0cdaa9996aeb4d3690'
const output = process.env.R24_OUTPUT || `/private/tmp/mohe-r24-usage-${commit.slice(0, 8)}`
const acceptedView = '前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue'
const refundView = '前端代码/cashier-v3/src/views/OrderCenterView.vue'
const reportView = '前端代码/cashier-v3/src/views/StoreBusinessReportView.vue'
const backend = [
  'app/controller/store/product/inventory/InventoryStoreReadModel.php',
  'app/services/product/inventory/InventoryStoreReadModelServices.php',
  'app/services/product/inventory/query/InventoryBatchStockQueryProvider.php'
]
const bases = {
  formal: { tree: '89f562dc3f16e11d918edcfd957839df3ce6253a', reportBlob: 'a9310eb10e086c70aad09e34cae660ae757bdf42' },
  preview: { tree: 'db11f5bab86bca781e32a8a78b95e1ea309cb881' }
}
const current = {
  formal: { index: '4a241e1aaf71a85c2cfb0d4205b01a727fd6b0392c213e31f6b73a605b91f942', assets: {
    'assets/index-f661de8c.js': '1d5c96bddd21f720a15c57fa10d3f4e4ab37ee81420b0a8fc8f728f94281cf00',
    'assets/index-a2148a5a.css': 'a2148a5ab97d889721e90eeda6162793c39ddfbe559a2938747d647756166ceb'
  } },
  preview: { index: 'fd8f0ddf0dea9c780ca9e766f7dc200a21f8b5ba5b0c117a7bba81b0d78f382c', assets: {
    'assets/index-3cb906fa.js': '648c2d6be2adf4f9ab43411f1177d3a751854b8ce26e5495a93202dda4b05722',
    'assets/index-978c81c4.css': '978c81c4aa3c73d5d516c8302041ab687bd4751fe5e1c71c2f8b38c27bb220b6'
  } }
}
const targets = {
  rh: { host: 'rh-server', domain: 'rh.cc3798.com', database: 'ruihao', migrationId: 235,
    restart: '/etc/init.d/ruihao_swoole restart', health: "ss -lnt | grep -q ':20800 '" },
  '008': { host: 'mohe-server', domain: '008.cc3798.com', database: 'mohe_008', migrationId: 28,
    restart: 'systemctl restart mohe-008-swoole.service', health: 'systemctl is-active --quiet mohe-008-swoole.service' }
}
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex')
const run = (file, args, options = {}) => execFileSync(file, args, { cwd: root, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, ...options })
const gitBytes = reference => run('git', ['show', reference], { encoding: null })
const save = (name, data) => fs.writeFileSync(path.join(output, `${name}.json`), JSON.stringify(data, null, 2) + '\n')
const load = name => JSON.parse(fs.readFileSync(path.join(output, `${name}.json`), 'utf8'))
const equal = (left, right) => JSON.stringify(left) === JSON.stringify(right)
const fingerprint = dist => {
  const html = fs.readFileSync(path.join(dist, 'index.html'))
  const refs = [...html.toString().matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)]
    .map(match => `assets/${path.basename(match[1])}`)
  if (refs.length !== 2) throw Error('Unexpected cashier entry references')
  return { index: sha(html), assets: Object.fromEntries(refs.map(ref => [ref, sha(fs.readFileSync(path.join(dist, ref)))])) }
}
const ssh = (host, script) => run('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', host, 'bash -s'], { input: script })
const target = targets[instance]
if (!['prepare', 'preflight', 'deploy', 'verify'].includes(mode)) throw Error('Choose prepare, preflight, deploy or verify')
if (run('git', ['rev-parse', commit]).trim() !== commit) throw Error('Accepted R7 commit unavailable')

if (mode === 'prepare') {
  if (fs.existsSync(output)) throw Error(`Output already exists: ${output}`)
  fs.mkdirSync(output, { recursive: true })
  const accepted = gitBytes(`${commit}:${acceptedView}`)
  const parentHash = sha(gitBytes(`${commit}^:${acceptedView}`))
  const builds = {}
  for (const [name, source] of Object.entries(bases)) {
    const dir = path.join(output, name)
    fs.mkdirSync(dir)
    const archive = run('git', ['archive', '--format=tar', source.tree,
      '前端代码/cashier-v3', '前端代码/inventory-vue3', '前端代码/shared'], { encoding: null })
    run('tar', ['-xf', '-', '-C', dir], { input: archive })
    fs.symlinkSync(path.join(root, '前端代码/cashier-v3/node_modules'), path.join(dir, '前端代码/cashier-v3/node_modules'))
    fs.symlinkSync(path.join(root, '前端代码/inventory-vue3/node_modules'), path.join(dir, '前端代码/inventory-vue3/node_modules'))
    // R56 is already live on both entries; reproduce its one accepted view overlay before R7.
    fs.writeFileSync(path.join(dir, refundView), gitBytes(`c9e76b88a9d311817f9a681d221a39967ae57c64:${refundView}`))
    if (source.reportBlob) fs.writeFileSync(path.join(dir, reportView), run('git', ['cat-file', 'blob', source.reportBlob], { encoding: null }))
    if (sha(fs.readFileSync(path.join(dir, acceptedView))) !== parentHash) throw Error(`${name} picker source is not R7 parent`)
    const frontend = path.join(dir, '前端代码/cashier-v3')
    // Rebuilding the untouched base proves this temporary source snapshot is the live version.
    run('node', [path.join(root, '前端代码/cashier-v3/node_modules/vite/bin/vite.js'), 'build'], { cwd: frontend })
    if (!equal(fingerprint(path.join(frontend, 'dist')), current[name])) throw Error(`${name} rebuilt base differs from live`)
    fs.writeFileSync(path.join(dir, acceptedView), accepted)
    run('node', [path.join(root, '前端代码/cashier-v3/node_modules/vite/bin/vite.js'), 'build'], { cwd: frontend })
    builds[name] = { sourceBase: source.tree, acceptedViewSha256: sha(accepted), ...fingerprint(path.join(frontend, 'dist')) }
  }
  const payload = path.join(output, 'payload')
  fs.mkdirSync(payload)
  for (const file of backend) {
    const destination = path.join(payload, 'backend', file)
    fs.mkdirSync(path.dirname(destination), { recursive: true })
    fs.writeFileSync(destination, gitBytes(`${commit}:后端代码/${file}`))
  }
  for (const name of Object.keys(bases)) fs.cpSync(path.join(output, name, '前端代码/cashier-v3/dist'), path.join(payload, name), { recursive: true })
  run('tar', ['-czf', path.join(output, 'payload.tar.gz'), '-C', payload, '.'])
  const manifest = {
    commit, builds, payloadSha256: sha(fs.readFileSync(path.join(output, 'payload.tar.gz'))),
    parentBackend: Object.fromEntries(backend.map(file => [file, sha(gitBytes(`${commit}^:后端代码/${file}`))])),
    acceptedBackend: Object.fromEntries(backend.map(file => [file, sha(gitBytes(`${commit}:后端代码/${file}`))]))
  }
  save('manifest', manifest)
  console.log(JSON.stringify(manifest, null, 2))
  process.exit(0)
}

if (!target) throw Error('Explicit rh or 008 instance required')
const manifest = load('manifest')
if (manifest.commit !== commit || sha(fs.readFileSync(path.join(output, 'payload.tar.gz'))) !== manifest.payloadSha256) throw Error('Package changed')
const site = `/www/wwwroot/${target.domain}`
const fronts = instance === 'rh' ? [
  { name: 'formal', path: `${site}/public/view_cashier_v3` },
  { name: 'preview', path: '/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3' }
] : [{ name: 'formal', path: `${site}/public/view_cashier_v3` }]
const probe = () => {
  const php = fs.readFileSync(path.join(root, 'scripts/r24-usage-release-probe.php'))
  const result = JSON.parse(run('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', target.host,
    `php -r 'eval("?>" . stream_get_contents(STDIN));' -- ${instance}`], { input: php }))
  const hashes = run('ssh', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', target.host,
    `sha256sum ${backend.map(file => `'${site}/${file}'`).join(' ')}`]).trim().split('\n')
  result.inventoryBackend = Object.fromEntries(hashes.map((line, index) => [backend[index], line.trim().split(/\s+/)[0]]))
  return result
}
const assertBase = value => {
  if (value.instance !== instance || value.database !== target.database || Number(value.migration?.id) !== target.migrationId
    || value.migration?.upgrade_key !== '20261015-002-product-category-statistics-switch') throw Error('Instance or migration changed')
  if (!equal(value.inventoryBackend, manifest.parentBackend) || value.fronts.length !== fronts.length) throw Error('Backend baseline changed')
  for (let i = 0; i < fronts.length; i++) {
    if (value.fronts[i].path !== fronts[i].path || !equal({ index: value.fronts[i].index_sha256, assets: value.fronts[i].assets }, current[fronts[i].name])) {
      throw Error(`${instance} ${fronts[i].name} live frontend changed`)
    }
  }
}
if (mode === 'preflight') {
  const before = probe()
  assertBase(before)
  save(`${instance}-before`, before)
  console.log(JSON.stringify(before, null, 2))
  process.exit(0)
}

const before = load(`${instance}-before`)
if (mode === 'deploy') {
  assertBase(before)
  if (!equal(probe(), before)) throw Error('Instance changed after preflight')
  const backup = `/www/backups/${target.domain}/20261015-r24-usage-${commit.slice(0, 8)}`
  const hash = 'hash() { sha256sum "$1" | cut -d\' \' -f1; }'
  const guards = [
    ...backend.map(file => `test "$(hash '${site}/${file}')" = '${manifest.parentBackend[file]}'`),
    ...fronts.flatMap(front => [`test "$(hash '${front.path}/index.html')" = '${current[front.name].index}'`,
      ...Object.entries(current[front.name].assets).map(([file, digest]) => `test "$(hash '${front.path}/${file}')" = '${digest}'`)])
  ].join('\n')
  const setup = `set -euo pipefail\n${hash}\n${target.health}\n${guards}\ntest ! -e '${backup}'\nmkdir -p '${backup}/stage'\n` +
    `tar -czf '${backup}/backend-before.tar.gz' -C '${site}' ${backend.map(file => `'${file}'`).join(' ')}\n` +
    fronts.map((front, index) => `tar -czf '${backup}/front-${index}-before.tar.gz' -C '${front.path}' index.html ${Object.keys(current[front.name].assets).map(file => `'${file}'`).join(' ')}`).join('\n') +
    `\ngzip -t '${backup}'/*before.tar.gz\nsha256sum '${backup}'/*before.tar.gz\n`
  const backupResult = ssh(target.host, setup)
  save(`${instance}-backup`, { backup, backupResult })
  run('scp', ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', path.join(output, 'payload.tar.gz'), `${target.host}:${backup}/payload.tar.gz`])
  const stage = `${backup}/stage`
  let script = `set -euo pipefail\n${hash}\ntest "$(hash '${backup}/payload.tar.gz')" = '${manifest.payloadSha256}'\ntar -xzf '${backup}/payload.tar.gz' -C '${stage}'\n${guards}\n`
  for (const file of backend) script += `test "$(hash '${stage}/backend/${file}')" = '${manifest.acceptedBackend[file]}'\nphp -l '${stage}/backend/${file}'\n`
  for (const front of fronts) {
    const build = manifest.builds[front.name]
    script += `test "$(hash '${stage}/${front.name}/index.html')" = '${build.index}'\n`
    for (const [file, digest] of Object.entries(build.assets)) script += `test "$(hash '${stage}/${front.name}/${file}')" = '${digest}'\n`
  }
  script += `rollback() {\nset +e\ntar -xzf '${backup}/backend-before.tar.gz' -C '${site}'\n` +
    fronts.map((front, index) => `tar -xzf '${backup}/front-${index}-before.tar.gz' -C '${front.path}' index.html`).join('\n') +
    `\n${target.restart}\necho R24_ROLLBACK_ATTEMPTED >&2\n}\ntrap rollback ERR\n`
  for (const front of fronts) {
    script += `while IFS= read -r -d '' file; do rel="\${file#'${stage}/${front.name}/'}"; dest='${front.path}'/"$rel"; if test -e "$dest"; then cmp -s "$file" "$dest"; else install -D -o www -g www -m 644 "$file" "$dest"; fi; done < <(find '${stage}/${front.name}' -type f ! -name index.html -print0)\n`
  }
  for (const file of backend) script += `install -o www -g www -m 644 '${stage}/backend/${file}' '${site}/${file}.r24'\nmv -f '${site}/${file}.r24' '${site}/${file}'\n`
  script += `${target.restart}\n${target.health}\n`
  for (const front of fronts) script += `install -o www -g www -m 644 '${stage}/${front.name}/index.html' '${front.path}/index.html.r24'\nmv -f '${front.path}/index.html.r24' '${front.path}/index.html'\n`
  script += `trap - ERR\necho R24_SWITCH_OK\n`
  const result = ssh(target.host, script)
  save(`${instance}-switch`, { backup, result })
  console.log(JSON.stringify({ instance, backup, result: result.trim() }, null, 2))
  process.exit(0)
}

const after = probe()
if (!equal(after.inventoryBackend, manifest.acceptedBackend) || !equal(after.migration, before.migration)) throw Error('Backend or migration mismatch')
for (let i = 0; i < fronts.length; i++) {
  const build = manifest.builds[fronts[i].name]
  if (!equal({ index: after.fronts[i].index_sha256, assets: after.fronts[i].assets }, { index: build.index, assets: build.assets })) throw Error('Disk frontend mismatch')
}
const publicResults = []
for (const front of fronts) {
  const url = `https://${target.domain}${front.name === 'preview' ? '/preview-8080' : ''}/view_cashier_v3/`
  const html = run('curl', ['--fail', '--silent', '--show-error', '--max-time', '45', url])
  const refs = [...html.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(match => match[1])
  if (refs.length !== 2) throw Error('Unexpected public asset references')
  const assets = {}
  for (const ref of refs) {
    const file = `assets/${path.basename(ref)}`
    const bytes = run('curl', ['--fail', '--silent', '--show-error', '--max-time', '60', new URL(ref, url).href], { encoding: null })
    assets[file] = sha(bytes)
  }
  if (!equal(assets, manifest.builds[front.name].assets)) throw Error(`${front.name} public assets differ`)
  publicResults.push({ url, assets })
}
const verified = { instance, commit, migration: after.migration, backend: after.inventoryBackend, fronts: after.fronts,
  public: publicResults, backup: load(`${instance}-switch`).backup }
save(`${instance}-verified`, verified)
console.log(JSON.stringify(verified, null, 2))
