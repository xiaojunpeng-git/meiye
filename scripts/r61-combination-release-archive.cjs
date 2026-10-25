// Archive only this round's release controls and evidence after all authorized sites pass.
// The business commit/package is unchanged; shared checkout and index remain untouched.
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');const root=path.resolve(__dirname,'..');process.chdir(root);
const e='tests/combination-r61/release',p=JSON.parse(fs.readFileSync(e+'/trees.json')),r=JSON.parse(fs.readFileSync(e+'/commit.json'));
// Empty evidence files still have valid Git blobs. Explicit EOF avoids an empty-input pipe wait.
const sha=b=>crypto.createHash('sha256').update(b).digest('hex'),git=(a,input,env=process.env)=>cp.execFileSync('git',a,{input,env,maxBuffer:256e6,...(input&&input.length===0?{stdio:['ignore','pipe','pipe']}:{} )});
function guard(){if(git(['rev-parse','HEAD']).toString().trim()!==p.head||sha(fs.readFileSync(git(['rev-parse','--git-path','index']).toString().trim()))!==p.indexSHA256)throw Error('Shared state changed');for(const[f,h]of Object.entries(p.currentFileDigests))if(sha(fs.readFileSync(f))!==h)throw Error('Accepted input drift '+f);}
guard();for(const s of ['rh','008','010'])for(const kind of ['verified','runtime','retained','public','browser'])if(JSON.parse(fs.readFileSync(e+'/'+s+'-'+kind+'.json')).result!=='PASS')throw Error('Site verification missing');
const controls=['scripts/r61-combination-release-probe.php','scripts/r61-combination-release-prepare.cjs','scripts/r61-combination-release-build.cjs','scripts/r61-combination-install.php','scripts/r61-combination-migrate.php','scripts/r61-combination-010-migrate.php','scripts/r61-combination-runtime.php','scripts/r61-combination-publish.cjs','scripts/r61-combination-release-archive.cjs'];
const prior='scripts/r60-debt-switch-runtime.php';if(sha(git(['show','release/r60-debt-switch-20261025:'+prior]))!==sha(fs.readFileSync(prior)))throw Error('Existing retained probe drift');
const files=[...controls,prior];function walk(dir){for(const x of fs.readdirSync(dir,{withFileTypes:true})){const f=path.join(dir,x.name);if(x.isDirectory())walk(f);else if(x.isFile())files.push(f);else throw Error('Evidence symlink');}}
walk(e);walk('tests/combination-r61/evidence');
const idx='/private/tmp/r61-combination-archive-'+process.pid+'.index',env={...process.env,GIT_INDEX_FILE:idx};
try{git(['read-tree',r.commit],undefined,env);for(const f of [...new Set(files)].sort()){const blob=git(['hash-object','-w','--stdin'],fs.readFileSync(f)).toString().trim();git(['update-index','--add','--cacheinfo','100644',blob,f],undefined,env);}
 const tree=git(['write-tree'],undefined,env).toString().trim();git(['-c','core.whitespace=cr-at-eol','diff','--check',r.commit,tree,'--',...controls,e+'/发布结果.md']);
 const changed=git(['-c','core.quotepath=false','diff','--name-only',r.commit,tree]).toString().trim().split('\n');if(changed.some(f=>!files.includes(f)))throw Error('Archive scope');guard();
 const commit=git(['commit-tree',tree,'-p',r.commit],Buffer.from('R61-ARCHIVE 三站独立备份迁移部署核验记录\n\n仅归档本轮发布控制及验证证据；不再次切换业务包。瑞昊首次因压缩HTML解析限制回滚，修正核验后通过，完整保留两次记录。真实富友资金测试未执行。\n')).toString().trim();
 const ref='refs/heads/'+r.branch;git(['update-ref',ref,commit,r.commit]);guard();git(['push','origin',ref+':'+ref]);if(git(['ls-remote','origin',ref]).toString().split(/\s/)[0]!==commit)throw Error('Archive push mismatch');guard();
 fs.writeFileSync(git(['rev-parse','--git-path','r61-combination-release-receipt.json']).toString().trim(),JSON.stringify({businessCommit:r.commit,archiveCommit:commit,branch:r.branch,sourceTree:p.candidateTree,sites:['rh','008','010'],result:'PASS',sharedIndexUntouched:true,files:changed},null,2)+'\n');console.log('R61_ARCHIVE_PUSH_PASS',commit,'files='+changed.length);
}finally{if(fs.existsSync(idx))fs.unlinkSync(idx);}
