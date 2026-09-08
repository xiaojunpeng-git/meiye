#!/usr/bin/env bash
set -euo pipefail
test_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source_root="$(cd "$test_dir/../.." && pwd)"
"${MOHE_TEST_NODE:-node}" - "$source_root" <<'NODE'
// Execute the real entrypoint in a disposable tree, replacing only its fixed cwd.
// A fixture PHP executable records invocation; no application or Swoole starts.
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const assert = require('node:assert/strict')
const { spawnSync } = require('node:child_process')
const script = fs.readFileSync(path.join(process.argv[2], 'docker/entrypoint.sh'), 'utf8')
assert.equal(script.split('cd /var/www/html').length, 2, 'entrypoint cwd must remain uniquely replaceable')
const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'mohe-entrypoint-permissions-'))
let checks = 0
const check = (condition, label) => { assert.ok(condition, label); checks++ }
const mode = file => fs.statSync(file).mode & 0o777
try {
  const bin = path.join(fixture, 'bin')
  fs.mkdirSync(bin, { mode: 0o700 })
  fs.writeFileSync(path.join(bin, 'php'), '#!/bin/sh\n[ "$#" = 2 ] && [ "$1" = think ] && [ "$2" = swoole ] || exit 98\n: > "$ENTRYPOINT_FIXTURE_CALLED"\n', { mode: 0o700 })
  const run = (name, setup) => {
    const cwd = path.join(fixture, name)
    fs.mkdirSync(cwd, { mode: 0o700 })
    const marker = path.join(cwd, 'fake-php-called')
    setup(cwd)
    const command = script.replace('cd /var/www/html', 'cd "$ENTRYPOINT_FIXTURE_CWD"')
    const result = spawnSync('/bin/sh', ['-c', command], {
      cwd, encoding: 'utf8', timeout: 5000,
      env: { PATH: bin + ':/usr/bin:/bin:/usr/sbin:/sbin', ENTRYPOINT_FIXTURE_CWD: cwd, ENTRYPOINT_FIXTURE_CALLED: marker },
    })
    return { cwd, marker, result }
  }
  const normal = run('normal', cwd => {
    fs.mkdirSync(path.join(cwd, 'runtime/private/nested'), { recursive: true, mode: 0o777 })
    for (const relative of ['runtime/private', 'runtime/private/nested']) fs.chmodSync(path.join(cwd, relative), 0o777)
    fs.writeFileSync(path.join(cwd, 'runtime/private/nested/fixture.txt'), 'synthetic fixture, not a key', { mode: 0o666 })
    fs.mkdirSync(path.join(cwd, 'runtime/cache'), { recursive: true })
    fs.writeFileSync(path.join(cwd, 'runtime/cache/legacy.txt'), 'fixture', { mode: 0o600 })
  })
  check(normal.result.status === 0, normal.result.stderr)
  check(fs.existsSync(normal.marker), 'only fake PHP receives startup invocation')
  check(mode(path.join(normal.cwd, 'runtime')) === 0o755, 'runtime traversal without global write')
  for (const dir of ['log', 'cache', 'temp', 'session']) check(mode(path.join(normal.cwd, 'runtime', dir)) === 0o777, 'legacy cache writable: ' + dir)
  check(mode(path.join(normal.cwd, 'runtime/cache/legacy.txt')) === 0o777, 'existing legacy cache remains writable')
  for (const dir of ['private', 'private/nested']) check(mode(path.join(normal.cwd, 'runtime', dir)) === 0o700, 'private directory restricted: ' + dir)
  check(mode(path.join(normal.cwd, 'runtime/private/nested/fixture.txt')) === 0o600, 'private file restricted')
  const empty = run('no-private', () => {})
  check(empty.result.status === 0 && fs.existsSync(empty.marker), 'first startup without private directory succeeds with fake PHP')
  const external = path.join(fixture, 'external-fixture')
  fs.mkdirSync(external, { mode: 0o750 })
  fs.writeFileSync(path.join(external, 'sentinel'), 'untouched synthetic fixture', { mode: 0o640 })
  const linked = run('symlink', cwd => {
    fs.mkdirSync(path.join(cwd, 'runtime'))
    fs.symlinkSync(external, path.join(cwd, 'runtime/private'), 'dir')
  })
  check(linked.result.status !== 0 && linked.result.stderr.includes('PRIVATE_RUNTIME_SYMLINK_REJECTED'), 'private root symlink rejected')
  check(!fs.existsSync(linked.marker), 'rejected startup cannot invoke PHP')
  check(mode(external) === 0o750 && mode(path.join(external, 'sentinel')) === 0o640, 'symlink target permissions unchanged')
  check(fs.readFileSync(path.join(external, 'sentinel'), 'utf8') === 'untouched synthetic fixture', 'symlink target content unchanged')
  console.log(`PASS ${checks} isolated entrypoint permission checks; fake PHP only, no application/runtime/secrets accessed`)
} finally {
  assert.equal(path.dirname(fixture), os.tmpdir())
  assert.ok(path.basename(fixture).startsWith('mohe-entrypoint-permissions-'))
  fs.rmSync(fixture, { recursive: true, force: true })
}
NODE
