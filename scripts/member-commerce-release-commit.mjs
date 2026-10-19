import {execFileSync} from 'node:child_process';
import {readFileSync, writeFileSync, mkdirSync, copyFileSync, existsSync} from 'node:fs';
import {createHash} from 'node:crypto';
import path from 'node:path';

// Use an isolated index, then replay the original staged patch against the new
// HEAD. Never commit, discard or replace another task's staged business changes.
const run=(args,options={})=>execFileSync('git',args,{encoding:'utf8',maxBuffer:64*1024*1024,...options});
const hash=b=>createHash('sha256').update(b).digest('hex');
const manifest=JSON.parse(readFileSync('tests/member-checkout/evidence/verification.json'));
const baseline='d9c5849534c9313ded8cd587d27b40c7d0773afa';
if(run(['rev-parse','HEAD']).trim()!==baseline)throw Error('HEAD changed');
for(const file of Object.values(manifest.files).flat()){
  if(hash(readFileSync(file.path))!==file.sha256)throw Error('Accepted file changed: '+file.path);
}
const oldPatch=run(['diff','--cached','--binary'],{encoding:null});
if(hash(oldPatch)!==manifest.stagedDiffSha256)throw Error('Original staging changed');
const directory=path.resolve(run(['rev-parse','--git-dir']).trim(),'member-commerce-release-20261020');
mkdirSync(directory,{recursive:true});
const originalIndex=path.resolve(run(['rev-parse','--git-path','index']).trim());
if(existsSync(path.join(directory,'original.index')))throw Error('Commit operation already started');
copyFileSync(originalIndex,path.join(directory,'original.index'));
writeFileSync(path.join(directory,'original-staged.patch'),oldPatch);
const isolatedIndex=path.join(directory,'candidate.index');
const env={...process.env,GIT_INDEX_FILE:isolatedIndex};
run(['read-tree',baseline],{env});
const prerequisites=[
 ['92533c0a235ddb004798e8b641a2aa80e460868a','后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php'],
 ['92a02ef16c4802a05e170c32d44c45e208de9d8c','后端代码/app/services/user/UserBalanceAtomicServices.php'],
];
for(const [ref,file] of prerequisites){
 const patch=run(['diff',ref+'^',ref,'--',file],{encoding:null});
 run(['apply','--cached','--check'],{env,input:patch});
 run(['apply','--cached'],{env,input:patch});
}
run(['diff','--cached','--check'],{env});
const prerequisiteDiff=run(['diff','--cached','--stat'],{env});
console.log(prerequisiteDiff);
run(['commit','-m','M008-R3-BASE 保留已上线定制卡开关与余额退款基线'],{env});
const prerequisite=run(['rev-parse','HEAD']).trim();
const files=[...Object.values(manifest.files).flat().map(row=>row.path),
 'tests/member-checkout/local-store-regression-readonly.php',
 'tests/member-checkout/evidence/verification.json',
 'tests/member-checkout/evidence/store-regression.json',
 'scripts/member-commerce-release-inspect.mjs','scripts/member-commerce-release-commit.mjs'];
for(const file of files)run(['add','--',file],{env});
run(['diff','--cached','--check'],{env});
const actual=run(['-c','core.quotepath=false','diff','--cached','--name-only','-z'],{env}).split('\0').filter(Boolean);
if(actual.some(file=>!files.includes(file)))throw Error('Unexpected staged path');
writeFileSync('tests/member-checkout/evidence/release/commit-scope.json',JSON.stringify({baseline,prerequisite,
 acceptedFingerprint:manifest.patchFingerprint,files:actual,prerequisiteSources:prerequisites},null,2)+'\n');
run(['add','--','tests/member-checkout/evidence/release/commit-scope.json'],{env});
console.log(run(['diff','--cached','--stat'],{env}));
run(['commit','-m','M008-R3-002 会员统一结账充值与门店权益贯通闭环'],{env});
const commit=run(['rev-parse','HEAD']).trim();
// Validate replay before replacing the real index, which remains untouched up
// to this point. The saved original index/patch remains a recoverable audit.
const rebasedIndex=path.join(directory,'rebased.index');
const rebaseEnv={...process.env,GIT_INDEX_FILE:rebasedIndex};
run(['read-tree',commit],{env:rebaseEnv});
run(['apply','--cached','--check'],{env:rebaseEnv,input:oldPatch});
run(['apply','--cached'],{env:rebaseEnv,input:oldPatch});
if(hash(readFileSync(originalIndex))!==hash(readFileSync(path.join(directory,'original.index'))))throw Error('Another task changed real index');
copyFileSync(rebasedIndex,originalIndex);
const evidence={baseline,prerequisite,commit,tree:run(['rev-parse',commit+'^{tree}']).trim(),
 acceptedFingerprint:manifest.patchFingerprint,files:run(['-c','core.quotepath=false','diff-tree','--no-commit-id','--name-only','-r',commit]).trim().split('\n'),
 originalStagedPatch:hash(oldPatch),originalStagedChangesReplayed:true,
 remainingStatus:run(['-c','core.quotepath=false','status','--short'])};
writeFileSync('tests/member-checkout/evidence/release/committed.json',JSON.stringify(evidence,null,2)+'\n');
console.log(JSON.stringify({prerequisite,commit,tree:evidence.tree,files:evidence.files.length,originalStagingPreserved:true},null,2));
