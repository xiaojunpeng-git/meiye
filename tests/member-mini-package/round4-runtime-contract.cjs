const fs=require('fs'),path=require('path'),assert=require('assert'),vm=require('vm'),cp=require('child_process'),crypto=require('crypto');
const cli='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules/';
const parser=require(cli+'@babel/parser'),base=process.argv[2];
assert(base,'Pass the immutable build directory');
const after=path.join(base,'008-member'),before=path.join(base,'before-factoring');
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const list=dir=>{const rows=[];const walk=(d,p='')=>{for(const e of fs.readdirSync(d,{withFileTypes:true})){const f=p+e.name;if(e.isDirectory())walk(path.join(d,e.name),f+'/');else rows.push(f);}};walk(dir);return rows;};
// Exercise the actual build hook with shared webpack-4 options: an entry-only
// plugin appended to module A must never appear in module B or the base rule.
const builder=fs.readFileSync(path.resolve(__dirname,'../../scripts/build/member-008-round4-mini.cjs'),'utf8');
const hookAst=parser.parse(builder),hooks=[];
const visit=node=>{if(!node||typeof node!=='object')return;
 if(node.type==='CallExpression'&&node.arguments[0]&&node.arguments[0].value==='MemberMiniLoaderIsolation'&&node.arguments[1]&&node.arguments[1].params&&node.arguments[1].params[1]&&node.arguments[1].params[1].name==='module')hooks.push(node.arguments[1]);
 for(const [key,value]of Object.entries(node))if(!['loc','start','end'].includes(key))Array.isArray(value)?value.forEach(visit):visit(value);
};visit(hookAst);assert.strictEqual(hooks.length,1);
const isolate=vm.runInNewContext('('+builder.slice(hooks[0].start,hooks[0].end)+')');
const shared={plugins:[['syntax-transform']]},a={loaders:[{loader:'/node_modules/babel-loader/lib/index.js',options:shared}]},b={loaders:[{loader:'/node_modules/babel-loader/lib/index.js',options:shared}]};
isolate({},a);a.loaders[0].options.plugins.push(['entry-create-app']);isolate({},b);
assert.strictEqual(shared.plugins.length,1);assert.strictEqual(b.loaders[0].options.plugins.length,1);assert.notStrictEqual(a.loaders[0].options,b.loaders[0].options);assert.notStrictEqual(a.loaders[0].options.plugins[0],b.loaders[0].options.plugins[0]);
const receipt=JSON.parse(fs.readFileSync(after+'-css-dedupe.json'));
const generated=new Set(receipt.generated.map(v=>v.file)),changed=new Set(receipt.changed.map(v=>v.file));
const files=list(before),outFiles=list(after),allJs=[];
let scripts=0,styles=0;
// Packaging may factor styles, but may never mutate script, template, image,
// routing or stylesheet semantics after the compiler has emitted them.
for(const file of files){
 const a=fs.readFileSync(path.join(before,file)),b=fs.readFileSync(path.join(after,file));
 if(file.endsWith('.wxss')){
  const expanded=changed.has(file)?[...b.toString().matchAll(/@import\s+("(?:[^"\\]|\\.)*");/g)].map(m=>{
   const target=path.posix.normalize(path.posix.join(path.posix.dirname(file),JSON.parse(m[1])));assert(generated.has(target));return fs.readFileSync(path.join(after,target),'utf8');
  }).join(''):b.toString();
  assert.strictEqual(expanded,a.toString(),'Style semantics changed '+file);styles++;
 }else assert(a.equals(b),'Compiler artifact changed '+file);
 if(file.endsWith('.js')){const s=b.toString();parser.parse(s,{sourceType:'unambiguous'});allJs.push(s);scripts++;}
}
for(const file of outFiles)assert(files.includes(file)||generated.has(file)||file==='project.private.config.json','Unowned artifact '+file);
const config=JSON.parse(fs.readFileSync(path.join(after,'project.config.json')));assert.strictEqual(config.appid,'wx85d4bfd0b4b6aafb');
const app=JSON.parse(fs.readFileSync(path.join(after,'app.json')));assert(app.subPackages.find(v=>v.root==='pages/goods').pages.includes('member_checkout/index'));
assert(allJs.some(s=>s.includes('catalog_types')));assert(allJs.some(s=>s.includes('member/commerce/quote')));
const context={global:{},wx:{},webpackJsonp:[]};context.global.webpackJsonp=context.webpackJsonp;vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(after,'common/vendor.js'),'utf8'),context,{timeout:5000});
const modules=Object.assign({},...context.webpackJsonp.map(e=>e[1])),cache={};
// Regression for the observed blank screen: native component mounting must
// retain this.$vm, not wrap it in createApp and replace $scope with getApp().
const runtime=Object.values(modules).filter(fn=>String(fn).includes('mpInstance:this')&&String(fn).includes('createPage'));
assert.strictEqual(runtime.length,1,'Unique native component bridge');
const ast=parser.parse('('+String(runtime[0])+')'),mountReceivers=[];
const walk=node=>{if(!node||typeof node!=='object')return;
 if(node.type==='CallExpression'&&node.callee.type==='MemberExpression'&&node.callee.property.name==='$mount')mountReceivers.push(node.callee.object);
 for(const [key,value]of Object.entries(node))if(!['loc','start','end'].includes(key))Array.isArray(value)?value.forEach(walk):walk(value);
};walk(ast);
assert.strictEqual(mountReceivers.length,1,'Exactly one native component mount');
assert(mountReceivers[0].type==='MemberExpression'&&mountReceivers[0].object.type==='ThisExpression'&&mountReceivers[0].property.name==='$vm','Component mount incorrectly registers an application');
const ids=Object.keys(modules).filter(id=>{const s=String(modules[id]);return s.includes('HTTP_REQUEST_URL')&&s.includes('TOKENNAME')&&s.includes('EXPIRE');});assert.strictEqual(ids.length,1);
const requireModule=id=>{if(cache[id])return cache[id].exports;const m=cache[id]={exports:{}};modules[id](m,m.exports,requireModule);return m.exports;};
const api=requireModule(ids[0]);assert.strictEqual(api.HTTP_REQUEST_URL,'https://008.cc3798.com');assert.strictEqual(api.VUE_APP_WS_URL,'wss://008.cc3798.com/ws');
assert(Object.values(receipt.after).every(n=>n<=2048*1024));assert(receipt.changed.every(v=>v.originalSha256===v.expandedSha256));
const input=JSON.parse(fs.readFileSync(base+'/input.json'));
// Every source input must remain the accepted Git blob. Customer identity is
// recorded separately as process-local configuration, never a customer fork.
for(const row of input.files){
 const file=path.join(base,'readonly',row.file),bytes=fs.readFileSync(file);assert.strictEqual(sha(bytes),row.sha256);
 // A pre-commit fixed candidate may include only its explicit canonical-source
 // allowlist. Every other input remains byte-identical to the Git baseline.
 const expected=(input.sourceOverrides||[]).includes(row.file)
  ?fs.readFileSync(path.resolve(__dirname,'../..',row.file))
  :cp.execFileSync('git',['show',input.commit+':'+row.file],{maxBuffer:16e6});
 assert(bytes.equals(expected),row.file);
}
const result={result:'PASS',commit:input.commit,sourceFilesVerified:input.files.length,compared:files.length,outputFiles:outFiles.length,scriptsParsed:scripts,stylesRestored:styles,mutableLoaderOptionsIsolated:true,nativeComponentMountNotApplication:true,apiOrigin:api.HTTP_REQUEST_URL,appid:config.appid,packages:receipt.after,artifactSha256:sha(Buffer.from(files.sort().map(file=>file+'\0'+sha(fs.readFileSync(path.join(after,file)))).join('\n')))};
fs.writeFileSync(base+'/verification.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify(result));
