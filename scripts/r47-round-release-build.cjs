// Reuse the verified immutable Git-export compiler; only evidence/output locations change.
const fs = require('fs'), path = require('path'), Module = require('module');
const original = path.join(__dirname, 'r6-refund-restore-build.cjs');
let source = fs.readFileSync(original, 'utf8');
for (const [from, to] of [
 ["const base = '/private/tmp/r6-refund-restore-supplement';", "const base = '/private/tmp/r47-round-release-v7';"],
 ["const release = path.join(root, '../_local_evidence/2026-11-05-r6-refund-restore');", "const release = path.join(root, '../任务管理/验收证据/R47-发布-2026-11-05');"],
 ["const treesFile = path.join(release, 'trees-supplement.json');", "const treesFile = path.join(release, 'candidate.json');"],
 ["variant + '-' + terminal + '-supplement-build.json'", "variant + '-' + terminal + '-build.json'"]
]) { if (source.split(from).length !== 2) throw Error('Verified compiler anchor changed'); source = source.replace(from, to); }
const child = new Module(__filename, module); child.filename=__filename;child.paths=module.paths;child._compile(source,__filename);
