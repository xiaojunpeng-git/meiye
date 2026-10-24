const fs = require('fs');
const cp = require('child_process');
const path = require('path');
const crypto = require('crypto');
const root = process.cwd();
const base = '/private/tmp/mohe-r95-excel-release-20261024';
const evidence = 'tests/target-scheme/evidence/R95-excel-names-2026-10-24';
const hash = b => crypto.createHash('sha256').update(b).digest('hex');
const run = (args, options={}) => cp.execFileSync('git',args,{cwd:root,maxBuffer:128*1024*1024,...options});
fs.mkdirSync(base,{recursive:true});
// Canonical accepted blobs only: preserve deployed target features separately,
// then apply the exact PM-approved Excel patch. The shared index is never used.
const source = run(['rev-parse','d4615e66']).toString().trim();
const preserve = run(['rev-parse','372b9283']).toString().trim();
const index = path.join(base,'prepare.index');
const env = {...process.env,GIT_INDEX_FILE:index};
const git = (...args) => run(args,{env});
const indexPath = path.resolve(root,run(['rev-parse','--git-path','index']).toString().trim());
const before = hash(fs.readFileSync(indexPath));
const patch = fs.readFileSync(path.join(evidence,'business.patch'));
if(hash(patch)!=='eb48c97621b20468aca4b2a5930574cc11d79da85fb5601fbc9f2980cc0f889c')throw Error('Accepted patch drift');
const manifest=JSON.parse(fs.readFileSync(path.join(evidence,'fixed-candidate.json')));
for(const entry of manifest.files)if(hash(fs.readFileSync(entry.file))!==entry.sha256)throw Error('Accepted file drift: '+entry.file);
git('read-tree',source);
const retained = [
    '后端代码/app/controller/admin/v1/target/StoreTarget.php',
    '后端代码/app/services/target/TargetSchemeServices.php',
    '后端代码/app/services/report/GroupManagementDashboardTargetServices.php',
    ...run(['ls-tree','-rz','--name-only',preserve,'--','后端代码/database/upgrades/2026-10-20-目标方案逐行生效','tests/target-scheme/js/r95-scheme-controls.mjs','tests/target-scheme/php/r95-line-binding-integration.php','tests/target-scheme/php/r95-local-migration-parity.php']).toString().split('\0').filter(Boolean)
];
for(const file of retained){const blob=git('rev-parse',preserve+':'+file).toString().trim();git('update-index','--add','--cacheinfo',`100644,${blob},${file}`);}
const baselineTree=git('write-tree').toString().trim();
run(['apply','--cached','--check','-'],{env,input:patch});
run(['apply','--cached','-'],{env,input:patch});
const candidateTree=git('write-tree').toString().trim();
git('diff','--check',baselineTree,candidateTree);
if(hash(fs.readFileSync(indexPath))!==before)throw Error('Shared index changed during preparation');
const deployedTree='25aa8f4af420ac3735906b436d1bce699b94151e';
const result={source,preserve,deployedTree,baselineTree,candidateTree,retained,businessPatchSha256:hash(patch),sharedHead:git('rev-parse','HEAD').toString().trim(),sharedIndexSha256:before};
fs.writeFileSync(path.join(base,'trees.json'),JSON.stringify(result,null,2));
fs.writeFileSync(path.join(evidence,'release/trees.json'),JSON.stringify(result,null,2));
console.log(JSON.stringify(result,null,2));
