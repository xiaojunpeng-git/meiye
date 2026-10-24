const fs=require('fs'),path=require('path'),os=require('os'),cp=require('child_process'),crypto=require('crypto'),assert=require('assert').strict;
const root=process.cwd(),temp=fs.mkdtempSync(path.join(os.tmpdir(),'r95-excel-installer-'));
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const commit='1234567890123456789012345678901234567890';
const backup='backups/rh/r95-excel-12345678';
const write=(relative,value)=>{const f=path.join(temp,relative);fs.mkdirSync(path.dirname(f),{recursive:true});fs.writeFileSync(f,value);};
const installer=path.join(root,'scripts/r95-excel-install.php');
let container;
const docker=args=>cp.execFileSync('docker',args,{encoding:'utf8'});
const invoke=mode=>{
    docker(['cp',temp+'/.',container+':/www']);
    const result=cp.spawnSync('docker',['exec',container,'php','/installer.php','/www/'+backup,mode],{encoding:'utf8'});
    fs.rmSync(temp,{recursive:true,force:true});fs.mkdirSync(temp);
    docker(['cp',container+':/www/.',temp]);
    return result;
};
const manifest={site:'rh',commit,previewAuthorized:false,targets:{
    'formal/public/view_admin/js/new.js':{before:null,after:sha('new-asset')},
    'formal/public/system.html':{before:sha('old-entry'),after:sha('new-entry')}
}};
let checks=0;
// Disposable, network-isolated container sees only synthetic /www fixtures.
// No host site paths, database sockets or production credentials are mounted.
try{
    container=docker(['create','--network','none','--entrypoint','sleep','mohe-app:local','300']).trim();
    docker(['start',container]);docker(['exec',container,'mkdir','-p','/www']);docker(['cp',installer,container+':/installer.php']);
    write('wwwroot/rh.cc3798.com/public/system.html','old-entry');
    write(backup+'/payload/formal/public/system.html','new-entry');
    write(backup+'/payload/formal/public/view_admin/js/new.js','new-asset');
    write(backup+'/payload/manifest.json',JSON.stringify(manifest));
    let result=invoke('install');assert.equal(result.status,0,result.stdout+result.stderr);assert.equal(fs.readFileSync(path.join(temp,'wwwroot/rh.cc3798.com/public/system.html'),'utf8'),'new-entry');checks++;
    result=invoke('rollback');assert.equal(result.status,0,result.stdout+result.stderr);assert.equal(fs.readFileSync(path.join(temp,'wwwroot/rh.cc3798.com/public/system.html'),'utf8'),'old-entry');assert(!fs.existsSync(path.join(temp,'wwwroot/rh.cc3798.com/public/view_admin/js/new.js')));checks++;
    fs.unlinkSync(path.join(temp,backup,'prepared.json'));docker(['exec',container,'rm','/www/'+backup+'/prepared.json']);
    write('wwwroot/rh.cc3798.com/public/system.html','other-task-entry');
    result=invoke('install');assert.notEqual(result.status,0);assert.equal(fs.readFileSync(path.join(temp,'wwwroot/rh.cc3798.com/public/system.html'),'utf8'),'other-task-entry');assert(!fs.existsSync(path.join(temp,backup,'prepared.json')));checks++;
    manifest.targets={'preview/public/system.html':{before:null,after:sha('new-entry')}};write(backup+'/payload/manifest.json',JSON.stringify(manifest));
    result=invoke('install');assert.notEqual(result.status,0);assert.match(result.stdout+result.stderr,/PREVIEW_NOT_AUTHORIZED/);checks++;
    manifest.targets={'formal/.env':{before:null,after:sha('new-entry')}};write(backup+'/payload/manifest.json',JSON.stringify(manifest));
    result=invoke('install');assert.notEqual(result.status,0);assert.match(result.stdout+result.stderr,/TARGET_NOT_ALLOWED/);checks++;
    const report={checks,result:'PASS',scope:'network-isolated synthetic filesystem; no live site writes'};
    fs.writeFileSync('tests/target-scheme/evidence/R95-excel-names-2026-10-24/release/installer-tests.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report));
}finally{if(container)docker(['rm','-f',container]);fs.rmSync(temp,{recursive:true,force:true});}
