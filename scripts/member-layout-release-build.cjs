const fs = require('fs');
const path = require('path');
const Module = require('module');
const cp = require('child_process');

// Reuse the exercised immutable HBuilderX pipeline. Only the accepted member
// tree and private artifact directory differ; no live configuration is copied.
const original = path.resolve('scripts/member-background-release-build.cjs');
const commit = 'd728656bcda854555f905dd95d896b902f601fdf';
if (cp.execFileSync('git', ['rev-parse', commit + ':前端代码/uniapp'], {encoding: 'utf8'}).trim() !== '54859e2d07c6b8215e7b23567aea28a0e2455e78') throw Error('Accepted tree differs');
let source = fs.readFileSync(original, 'utf8');
for (const [from, to] of [
  ['25c115271e945bdb8f2a46a8bcbb85f8b7137f6f', commit],
  ['1affeeabaff4f28ad9e2d6fab56a1d8e1f378c2f', '54859e2d07c6b8215e7b23567aea28a0e2455e78'],
  ['/private/tmp/m008-background-release-20261021', '/private/tmp/m008-layout-release-20261021']
]) {
  if (!source.includes(from)) throw Error('Build adapter hook changed');
  source = source.split(from).join(to);
}
const runtime = new Module(original, module);
runtime.filename = original;
runtime.paths = module.paths;
runtime._compile(source, original);
