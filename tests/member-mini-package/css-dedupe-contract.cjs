// Check package isolation, middle-of-file cascade order, relative URL safety,
// original business-source preservation and complete real-build equivalence.
const assert=require('assert');
const fs=require('fs');
const path=require('path');
const cp=require('child_process');
const crypto=require('crypto');
const vm=require('vm');
const root=path.resolve(__dirname,'../..');
const plugins=process.env.UNI_HBUILDERX_PLUGINS||'/Applications/HBuilderX.app/Contents/HBuilderX/plugins';
const dependencies={postcss:require(path.join(plugins,'uniapp-cli/node_modules/postcss')),valueParser:require(path.join(plugins,'uniapp-cli/node_modules/postcss-value-parser'))};
const {optimize}=require('../../scripts/build/member-mini-css-dedupe.cjs');
const asset=content=>({source:()=>content,size:()=>Buffer.byteLength(content)});
const sha=value=>crypto.createHash('sha256').update(value).digest('hex');
const normalize=value=>value&&typeof value==='object'?Array.isArray(value)?value.map(normalize):Object.fromEntries(Object.keys(value).sort().map(key=>[key,normalize(value[key])])):value;
for(const file of ['App.vue','uni_modules/uni-icons/components/uni-icons/uni-icons.vue','pages/goods/lottery/wheel/index.vue']){
  const relative='前端代码/uniapp/'+file;
  assert(fs.readFileSync(path.join(root,relative)).equals(cp.execFileSync('git',['show','2ddff36f:'+relative],{cwd:root,maxBuffer:4*1024*1024})),`Unexpected business-source change ${file}`);
}
const shared='.shared{background-image:url(data:image/png;base64,'+'A'.repeat(560000)+');color:red}';
const fixture={
  'app.json':asset(JSON.stringify({subPackages:[{root:'pages/test',pages:['one/index','two/index','three/index','four/index']}]})),
  'project.config.json':asset('{}'),'app.js':asset('UNCHANGED'),
};
for(const name of ['one','two','three','four'])fixture[`pages/test/${name}/index.wxss`]=asset(`.before-${name}{color:blue}${shared}.relative{background:url('./image.png')}${shared}.after-${name}{color:green}`);
const original={...fixture};
const proof=optimize(fixture,dependencies);
assert(proof.after['pages/test']<proof.before['pages/test']/2);
assert(proof.changed.every(row=>row.originalSha256===row.expandedSha256));
assert(proof.generated.every(row=>row.file.startsWith('pages/test/')));
assert.strictEqual(fixture['app.js'],original['app.js']);
assert(proof.generated.some(row=>fixture[row.file].source().includes("url('./image.png')")&&row.file.includes('/one/')));
const afterFirst=Object.fromEntries(Object.entries(fixture).map(([file,value])=>[file,sha(value.source())]));
optimize(fixture,dependencies);
assert.deepStrictEqual(Object.fromEntries(Object.entries(fixture).map(([file,value])=>[file,sha(value.source())])),afterFirst);
if(process.argv[2]&&process.argv[3]){
  const before=process.argv[2],after=process.argv[3];let compared=0,styleImports=0,jsonOrderOnly=0;
  function expand(file,chain=[]){
    assert(!chain.includes(file));
    return fs.readFileSync(path.join(after,file),'utf8').replace(/@import\s+("(?:[^"\\]|\\.)*");\n?/g,(all,quoted)=>{
      const target=path.posix.normalize(path.posix.join(path.posix.dirname(file),JSON.parse(quoted)));
      if(!target.includes('/wxss-shared/')&&!target.includes('.__wxss_part_'))return all;
      styleImports++;
      return expand(target,[...chain,file]);
    });
  }
  function walk(dir,relative=''){
    for(const item of fs.readdirSync(dir,{withFileTypes:true})){
      const file=relative+item.name;
      if(item.isDirectory()){walk(path.join(dir,item.name),file+'/');continue;}
      if(['project.config.json','project.private.config.json'].includes(file))continue;
      const a=fs.readFileSync(path.join(before,file)),b=fs.readFileSync(path.join(after,file));compared++;
      if(file.endsWith('.wxss'))assert.strictEqual(expand(file),a.toString(),`CSS semantics/order changed ${file}`);
      else if(file.endsWith('.json')){
        assert.deepStrictEqual(normalize(JSON.parse(a)),normalize(JSON.parse(b)),`Route/metadata changed ${file}`);
        if(!a.equals(b))jsonOrderOnly++;
      }else assert(a.equals(b),`Script, template or asset changed ${file}`);
    }
  }
  walk(before);
  // Exclusions must not remove an active local resource. CSS remains embedded
  // except iconfont.woff2, which is deliberately retained in the upload package.
  const config=JSON.parse(fs.readFileSync(path.join(after,'project.config.json')));
  assert.strictEqual(config.appid,'wx85d4bfd0b4b6aafb');
  const context={global:{},wx:{},webpackJsonp:[]};context.global.webpackJsonp=context.webpackJsonp;
  vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(after,'common/vendor.js'),'utf8'),context);
  const modules=Object.assign({},...context.webpackJsonp.map(entry=>entry[1]));
  function requireModule(id){const module={exports:{}};modules[id](module,module.exports,requireModule);return module.exports;}
  const api=requireModule('f35b');
  assert.strictEqual(api.HTTP_REQUEST_URL,'https://008.cc3798.com');
  assert.strictEqual(api.VUE_APP_WS_URL,'wss://008.cc3798.com/ws');
  const ignored=file=>(config.packOptions.ignore||[]).some(rule=>rule.type==='folder'?file===rule.value||file.startsWith(rule.value+'/'):rule.type==='file'&&file===rule.value);
  assert(!ignored('static/iconfont/iconfont.woff2'));
  assert(fs.existsSync(path.join(after,'static/iconfont/iconfont.woff2')));
  const main=fs.readFileSync(path.join(after,'common/main.wxss'),'utf8');
  const expandMain=main.includes('@import')?expand('common/main.wxss'):main;
  assert(expandMain.includes('/static/iconfont/iconfont.woff2'));
  assert(!/url\(['"]?\/?static\/(?:fonts|css|mall|html)\//.test(expandMain));
  const receipt=JSON.parse(fs.readFileSync(after+'-css-dedupe.json'));
  assert(Object.values(receipt.after).every(bytes=>bytes<=2048*1024));
  console.log(JSON.stringify({pass:true,compared,jsonOrderOnly,styleImports,cssFilesFactored:receipt.changed.length,packageBytes:receipt.after}));
}else console.log('PASS: same-package extraction, byte-identical expansion, relative URLs, cascade order, idempotency and unchanged business source.');
