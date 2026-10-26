const fs=require('fs'),path=require('path'),crypto=require('crypto');
const base='/private/tmp/mohe-r95-excel-release-20261024',evidence='tests/target-scheme/evidence/R95-excel-names-2026-10-24/release';
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const read=file=>JSON.parse(fs.readFileSync(file));
function assets(dir){const out={};function scan(at){for(const e of fs.readdirSync(at,{withFileTypes:true})){const full=path.join(at,e.name);if(e.isDirectory())scan(full);else out[path.relative(dir,full)]=sha(fs.readFileSync(full));}}scan(dir);return out;}
const builds=Object.fromEntries(['deployed','baseline','candidate'].map(v=>[v,read(path.join(base,v+'-admin-build.json'))]));
const maps=Object.fromEntries(Object.entries(builds).map(([v,b])=>[v,assets(b.output)]));
const proofs=[];
for(const site of ['rh','008','010']){
    const before=read(path.join(evidence,site+'-before.json')),targets=read(path.join(evidence,site+'-targets-before.json'));
    const root=before.root,entry=before.frontends.find(f=>f.root===root&&f.kind==='admin');
    const failures=[];
    if(entry.sha256!==maps.deployed['system.html'])failures.push('system.html');
    for(const [file,hash]of Object.entries(maps.deployed))if(file.startsWith('view_admin/')&&targets.adminAssets[root][file]!==hash)failures.push(file);
    if(failures.length)throw Error(site+' deployed reconstruction differs: '+failures.slice(0,10).join(','));
    proofs.push({site,entrySha256:entry.sha256,generatedAssetsMatched:Object.keys(maps.deployed).filter(f=>f.startsWith('view_admin/')).length});
}
const changed=(a,b)=>Object.keys(b).filter(file=>a[file]!==b[file]);
const result={builds,proofs,retainedAssetChanges:changed(maps.deployed,maps.baseline),excelAssetChanges:changed(maps.baseline,maps.candidate),candidateAssets:maps.candidate,result:'PASS'};
fs.writeFileSync(path.join(evidence,'build-proof.json'),JSON.stringify(result,null,2));
console.log(JSON.stringify({proofs,retainedAssetChanges:result.retainedAssetChanges,excelAssetChanges:result.excelAssetChanges,candidateEntry:maps.candidate['system.html']},null,2));
