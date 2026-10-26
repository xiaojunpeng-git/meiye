const fs = require('fs');
const path = require('path');
const Module = require('module');
const cp = require('child_process');

// Rebuild the accepted canonical member tree through the exercised immutable
// HBuilderX pipeline; customer runtime inputs and shared source stay frozen.
const original = path.resolve('scripts/member-background-release-build.cjs');
const commit = '740c0c76732ab3a4e1108f45e80a1b8f7c0b2ee8';
const tree = 'accaaca7b20609fa84e04401a8128355c241cd7c';
if (cp.execFileSync('git', ['rev-parse', commit + ':前端代码/uniapp'], {encoding: 'utf8'}).trim() !== tree) throw Error('Accepted recharge tree differs');
let source = fs.readFileSync(original, 'utf8');
for (const [from, to] of [
  ['25c115271e945bdb8f2a46a8bcbb85f8b7137f6f', commit],
  ['1affeeabaff4f28ad9e2d6fab56a1d8e1f378c2f', tree],
  ['/private/tmp/m008-background-release-20261021', '/private/tmp/m008-recharge-style-release-20261021']
]) {
  if (!source.includes(from)) throw Error('Build adapter hook changed');
  source = source.split(from).join(to);
}
const runtime = new Module(original, module);
runtime.filename = original;
runtime.paths = module.paths;
runtime._compile(source, original);
