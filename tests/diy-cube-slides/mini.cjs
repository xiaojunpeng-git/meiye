// Run the actual compiled mini-program, with a temporary request mock and bundled test photos.
const fs=require('fs'),path=require('path'),cp=require('child_process'),assert=require('assert/strict');
const project='/private/tmp/m008-cube-slides-20261022/mini/008-member';
const cli='/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide';
const evidence=path.join(__dirname,'evidence');
function tool(name,args=[]){const out=cp.execFileSync(cli,['-c','Codex',name,'--project',project,...args],{encoding:'utf8',timeout:60000});const result=JSON.parse(out.slice(out.indexOf('{')));assert(result.ok,JSON.stringify(result));return result.result;}
function evaluate(fn,args){const list=['--fn-source',fn.toString()];if(args){const f='/private/tmp/m008-cube-slides-20261022/evaluate-args.json';fs.writeFileSync(f,JSON.stringify(args));list.push('--args-file',f);}const r=tool('automation_evaluate',list);return r.result.result;}
const fixture=JSON.parse(fs.readFileSync(path.join(evidence,'public-response.json'),'utf8').replaceAll('/cube-a.png','/static/cube-proof-a.png').replaceAll('/cube-b.png','/static/cube-proof-b.png'));
for(const [from,to]of [['beauty-carousel-spa.png','cube-proof-a.png'],['beauty-carousel-care.png','cube-proof-b.png']]){
 const bytes=fs.readFileSync(path.join(__dirname,'../../前端代码/uniapp/static/design-system',from)),dest=project+'/static/'+to;
 if(!fs.existsSync(dest)||!bytes.equals(fs.readFileSync(dest)))fs.writeFileSync(dest,bytes);
}
evaluate(function(fixture){const app=getApp();if(!app.__cubeOriginalRequest)app.__cubeOriginalRequest=wx.request;wx.request=function(o){
 if(o.method&&o.method.toUpperCase()!=='GET'){if(o.fail)o.fail({errMsg:'No writes in cube test'});return {abort(){}};}
 let data={};if(o.url.includes('/diy/get_diy/'))data=fixture.data;
 else if(o.url.includes('diy_version'))data={version:'cube-native-proof'};
 else if(o.url.includes('color_change'))data={status:7,navigation:0};
 else if(o.url.includes('get_entry_store'))data={store_id:0,user_entry_rules:[],shop_operation_type:1};
 else if(o.url.includes('nearby'))data={info:{id:0,name:'本地验证',is_alone:0}};
 else if(o.url.includes('/navigation'))data={name:'pageFoot',effectConfig:{tabVal:1},navConfig:{tabVal:0},navStyleConfig:{tabVal:0},toneConfig:{tabVal:0},topConfig:{val:0},bottomConfig:{val:0},prConfig:{val:0},mbConfig:{val:0},bgColor:{color:[{item:'#fff'}]},bgColor2:{color:[{item:'#fff'}]},menuList:[]};
 const r={statusCode:200,data:{status:200,msg:'ok',data}};if(o.success)o.success(r);if(o.complete)o.complete(r);return {abort(){}};
 };wx.removeStorageSync('diyData');wx.removeStorageSync('diyVersion');wx.reLaunch({url:'/pages/index/index'});return {mocked:true};},[fixture]);
const measure=async function(){const all=[];function walk(vm){if(vm.$options.name==='cubeRegionImage')all.push(vm);(vm.$children||[]).forEach(walk);}walk(getCurrentPages().slice(-1)[0].$vm);
 return await Promise.all(all.map(vm=>new Promise(resolve=>{wx.createSelectorQuery().in(vm.$scope).select('.cube-region-image').boundingClientRect(r=>resolve({width:r&&r.width,height:r&&r.height,left:r&&r.left,right:r&&r.right,slides:vm.slides.length})).exec();})));};
// Tool waits give native layout/rendering time, without changing component state.
tool('simulator_screenshot',['--path',path.join(evidence,'mini-a.png'),'--optimize','false','--wait','2']);
const before=evaluate(measure);assert.equal(before.length,35);assert(before.every(r=>r.width>5&&r.height>5&&r.left>=0&&r.right<=390));
tool('simulator_screenshot',['--path',path.join(evidence,'mini-b.png'),'--optimize','false','--wait','3']);
const after=evaluate(measure);before.forEach((r,i)=>{assert(Math.abs(r.width-after[i].width)<1);assert(Math.abs(r.height-after[i].height)<1);});
fs.writeFileSync(path.join(evidence,'mini-result.json'),JSON.stringify({result:'PASS',project,source:'actual native mini-program with isolated local fixtures',layouts:12,regions:35,before,after,upload:false},null,2));
// Leave the selected four-region layout visible for PM inspection, without a production save.
evaluate(function(doc){const app=getApp();app.__cubeProofDoc=doc;wx.request=function(o){const data=o.url.includes('/diy/get_diy/')?doc.data:o.url.includes('diy_version')?{version:'cube-selected'}:o.url.includes('get_entry_store')?{store_id:0,user_entry_rules:[],shop_operation_type:1}:o.url.includes('nearby')?{info:{id:0,name:'本地验证',is_alone:0}}:o.url.includes('color_change')?{status:7,navigation:0}:{};if(o.success)o.success({statusCode:200,data:{status:200,msg:'ok',data}});return {abort(){}};};wx.removeStorageSync('diyData');wx.removeStorageSync('diyVersion');wx.reLaunch({url:'/pages/index/index'});return true;},[{...fixture,data:{...fixture.data,value:{cube:fixture.data.value.cube09}}}]);
tool('simulator_screenshot',['--path',path.join(evidence,'mini-selected.png'),'--optimize','false','--wait','2']);
cp.execFileSync(process.execPath,[path.join(__dirname,'mini-motion.cjs')],{stdio:'inherit'});
console.log('PASS: actual mini-program, 12 layouts/35 regions, nonzero bounds and stable dimensions; selected cube visible. No upload.');
