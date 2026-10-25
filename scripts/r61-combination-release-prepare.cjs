// Freeze only R61 accepted images in canonical Git objects, without touching shared HEAD/index.
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'..');process.chdir(root);const e='tests/combination-r61/release';
const sha=b=>crypto.createHash('sha256').update(b).digest('hex'),git=(a,input,env=process.env)=>cp.execFileSync('git',a,{input,env,maxBuffer:256e6});
const fixed=JSON.parse(fs.readFileSync('tests/combination-r61/evidence/fixed-version.json'));
const parent='5f790d6751f1ffb2626fc94a8c280362642dddda',baselineTree=git(['rev-parse',parent+'^{tree}']).toString().trim();
const head=git(['rev-parse','HEAD']).toString().trim(),index=path.resolve(git(['rev-parse','--git-path','index']).toString().trim());
const indexSHA256=sha(fs.readFileSync(index)),privateIndex='/private/tmp/r61-combination-'+process.pid+'.index',env={...process.env,GIT_INDEX_FILE:privateIndex};
const currentFileDigests={},candidateFiles={},blobs={};
try{
 git(['read-tree',parent],undefined,env);
 for(const x of fixed.files){
  const f=x.path;let bytes=fs.readFileSync(f);if(sha(bytes)!==x.sha256)throw Error('Accepted file drift '+f);currentFileDigests[f]=x.sha256;
  if(f==='后端代码/route/admin.php'){
   const baseline=git(['show',parent+':'+f]).toString(),lines=bytes.toString().split(/\r?\n/).filter(l=>/combination\/order_pink\/:id\/(export|complete|refund)|Uses the existing authenticated group-record access/.test(l));
   if(lines.length!==4)throw Error('R61 route boundary');
   const anchor="        //拼团人列表";if(!baseline.includes(anchor))throw Error('Route anchor');bytes=Buffer.from(baseline.replace(anchor,lines.join('\n')+'\n'+anchor));
  }
  const blob=git(['hash-object','-w','--stdin'],bytes).toString().trim();blobs[f]=blob;candidateFiles[f]=sha(bytes);git(['update-index','--add','--cacheinfo','100644',blob,f],undefined,env);
 }
 const candidateTree=git(['write-tree'],undefined,env).toString().trim();git(['-c','core.whitespace=cr-at-eol','diff','--check',baselineTree,candidateTree]);
 const paths=git(['-c','core.quotepath=false','diff','--name-only',baselineTree,candidateTree]).toString().trim().split('\n');if(paths.some(f=>!candidateFiles[f]))throw Error('Expanded scope');
 if(git(['rev-parse','HEAD']).toString().trim()!==head||sha(fs.readFileSync(index))!==indexSHA256)throw Error('Shared state drift');
 const plan={canonicalGit:root,head,indexSHA256,baselineCommit:parent,baselineTree,candidateTree,paths,candidateFiles,blobs,currentFileDigests,migration:true,migrationAuthorized:true,acceptedByUser:true,sites:['rh','008','010'],exclusions:['Other tasks route growth/report/permission changes','Unaccepted platform/member/cashier changes'],retained:['R60 debt switch','R59 store settings and breadcrumb','R58 reservation custom services','R57 business source policy','Existing imported-rights store picker from accepted canonical release baseline']};
 fs.writeFileSync(e+'/trees.json',JSON.stringify(plan,null,2)+'\n',{flag:'wx'});fs.writeFileSync(e+'/r61-only.patch',git(['diff',baselineTree,candidateTree]),{flag:'wx'});console.log(JSON.stringify({candidateTree,files:paths.length}));
}finally{if(fs.existsSync(privateIndex))fs.unlinkSync(privateIndex);}
