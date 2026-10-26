const fs = require('fs'), path = require('path'), Module = require('module'), cp = require('child_process');

// Freeze the PM-accepted member/shared trees. The existing HBuilderX pipeline
// reads immutable Git inputs, never another task's uncommitted business code.
const original = path.resolve('scripts/member-background-release-build.cjs');
const commit = '78d6367991976a0082879efd6412f531365815e1';
const tree = '7bc92243f3e7b1ef65e9ed10ac732881d68d0543';
// A precision push has different ancestry, not different member source. Permit
// its HEAD only when the accepted tree is exactly recoverable on that chain.
const selected = cp.spawnSync('git',['cat-file','-e',commit+'^{commit}'],{stdio:'ignore'}).status === 0 ? commit : 'HEAD';
if (cp.execFileSync('git', ['rev-parse', selected + ':前端代码/uniapp'], {encoding:'utf8'}).trim() !== tree) throw Error('Accepted presentation tree differs');
let source = fs.readFileSync(original, 'utf8');
for (const [from,to] of [
  ['25c115271e945bdb8f2a46a8bcbb85f8b7137f6f', commit],
  ['1affeeabaff4f28ad9e2d6fab56a1d8e1f378c2f', tree],
  ['/private/tmp/m008-background-release-20261021', '/private/tmp/m009-presentation-release-20261021']
]) {
  if (!source.includes(from)) throw Error('Build adapter hook changed');
  source = source.split(from).join(to);
}
const runtime = new Module(original,module); runtime.filename=original; runtime.paths=module.paths; runtime._compile(source,original);
