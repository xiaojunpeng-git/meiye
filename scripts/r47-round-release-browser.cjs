// Serve only immutable candidate output locally; API calls stay on the existing local development backend.
const fs=require('fs'),path=require('path'),http=require('http'),cp=require('child_process');
const repo=path.resolve(__dirname,'..'),e=path.join(repo,'../任务管理/验收证据/R47-发布-2026-11-05');
const build=JSON.parse(fs.readFileSync(path.join(e,'candidate-cashier-v3-build.json')));
if(build.result!=='PASS'||!build.sourceStable)throw Error('Fixed candidate build required');
const source=JSON.parse(fs.readFileSync(path.join(e,'candidate.json')));if(build.tree!==source.candidateTree)throw Error('Candidate/build mismatch');
const server=http.createServer((req,res)=>{
 const pathname=decodeURIComponent(new URL(req.url,'http://localhost').pathname);
 if(pathname.startsWith('/view_cashier_v3/')){
  let rel=pathname.slice('/view_cashier_v3/'.length)||'index.html';const f=path.resolve(build.output,rel);
  if(!f.startsWith(path.resolve(build.output)+path.sep)||!fs.existsSync(f)||!fs.statSync(f).isFile()){res.writeHead(404);return res.end();}
  const ext=path.extname(f);res.setHeader('Content-Type',({'.html':'text/html','.js':'application/javascript','.css':'text/css','.svg':'image/svg+xml','.json':'application/json'})[ext]||'application/octet-stream');return fs.createReadStream(f).pipe(res);
 }
 const upstream=http.request({host:'127.0.0.1',port:18091,path:req.url,method:req.method,headers:{...req.headers,host:'127.0.0.1:18091'}},r=>{res.writeHead(r.statusCode,r.headers);r.pipe(res)});upstream.on('error',()=>{res.writeHead(502);res.end('local backend unavailable')});req.pipe(upstream);
});
server.listen(19091,'127.0.0.1',async ()=>{
 try{
  for(const[name,test]of[['price','r47-ordinary-card-total-browser.cjs'],['project','r47-project-purchase-browser.cjs']]){
   const dir=path.join(e,'browser-'+name);fs.mkdirSync(dir,{recursive:true});const original=path.join(repo,'tests/cashier-v3/js',test);
   let code=fs.readFileSync(original,'utf8').replaceAll('http://127.0.0.1:18091','http://127.0.0.1:19091').replaceAll('http://localhost:18091','http://127.0.0.1:19091');
   const marker=/const evidence = path\.resolve\(root, '[^']+'\);/;
   if(!marker.test(code))throw Error('Reviewed browser script location changed');code=code.replace(marker,'const evidence = '+JSON.stringify(dir)+';');
   const wrapper="const Module=require('module');const m=new Module("+JSON.stringify(original)+",module);m.filename="+JSON.stringify(original)+";m.paths=module.paths;m._compile("+JSON.stringify(code)+","+JSON.stringify(original)+");";
   // Async child keeps this proxy's event loop live while Playwright drives the real page.
   let originalFlag;
   const config = value => cp.execFileSync('docker',['exec','-i','mohe-cashier-app','php'],{encoding:'utf8',input:"<?php require '/var/www/html/vendor/autoload.php';$app=new think\\App('/var/www/html/');$app->initialize();$c=think\\facade\\Db::connect()->getConfig();if($c['hostname']!=='mysql'||$c['database']!=='ruihao')throw new RuntimeException('local only');$q=think\\facade\\Db::name('system_config')->where('menu_name','cashier_project_auto_complete_switch')->where('is_store',0);"+(value===undefined?"echo json_encode($q->value('value'));":"$q->update(['value'=>"+JSON.stringify(String(value))+"]);mohe\\services\\SystemConfigService::clear();")});
   if(name==='project'){originalFlag=JSON.parse(config());config('1');}
   try {
   await new Promise((resolve,reject)=>cp.execFile(process.execPath,['-e',wrapper],{cwd:repo,timeout:150000,maxBuffer:8e6},(err,stdout,stderr)=>{fs.writeFileSync(path.join(dir,'run.log'),stdout+stderr);if(err)reject(Error(name+': '+(stderr||err.message)));else{console.log(name,stdout.trim());resolve();}}));
   } finally {if(name==='project')config(originalFlag);}

  }
  server.close();
 }catch(err){console.error(err);server.close();process.exitCode=1;}
});
