// Run the production home-poster lifecycle and renderer against deterministic
// native/network failures. No real user token, message or album write is used.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/package.json');
const parser=require('@babel/parser');
function mp(text){const stack=[true],out=[];for(const line of text.split('\n')){const d=line.match(/\/\/\s*#(ifdef|ifndef|endif)\s*(.*)/);if(d){if(d[1]==='endif')stack.pop();else{const yes=d[2].trim().split(/\s*\|\|\s*/).some(v=>['MP','MP-WEIXIN'].includes(v));stack.push(stack.at(-1)&&(d[1]==='ifndef'?!yes:yes));}}else if(stack.at(-1))out.push(line);}return out.join('\n');}
const source=mp(fs.readFileSync('前端代码/uniapp/pages/users/user_spread_code/index.vue','utf8').match(/<script>([\s\S]*?)<\/script>/)[1]);
const object=text=>parser.parse(text,{sourceType:'module'}).program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
const page=object(source),methods=page.properties.find(p=>p.key?.name==='methods').value;
const utilSource=mp(fs.readFileSync('前端代码/uniapp/utils/util.js','utf8')),util=object(utilSource);
const compile=(p,s,c)=>vm.runInNewContext(p.value?'('+s.slice(p.value.start,p.value.end)+')':'('+(p.async?'async ':'')+'function('+p.params.map(v=>s.slice(v.start,v.end)).join(',')+')'+s.slice(p.body.start,p.body.end)+')',c);
function setup(opt={}){
 let qrRequests=0,requests=0,draws=0,hides=0;const notices=[],saved=[],timers=new Map();let seq=0;
 const data={spread:[{pic:'/static/mall/bg.png'},{pic:'/static/mall/bg2.png'}],nickname:'LOCAL',site_name:'LOCAL',avatar:''};
 const uni={showLoading:o=>assert.equal(o.mask,false),hideLoading:()=>hides++,getImageInfo:o=>opt.imageFail?o.fail({errMsg:'LOCAL image fail'}):o.success({path:o.src.replace(/^\//,''),width:305,height:473}),createSelectorQuery:()=>({in(){return this;},select(){return this;},fields(o,cb){cb(opt.rect===null?null:{width:305});return this;},exec(){}}),
 createCanvasContext:()=>new Proxy({draw:(reserve,cb)=>{draws++;cb();}},{get:(target,k)=>target[k]||(()=>{})}),canvasToTempFilePath:o=>opt.exportFail?o.fail({errMsg:'LOCAL export fail'}):o.success({tempFilePath:opt.emptyExport?'':'LOCAL-'+o.canvasId+'.png'}),saveImageToPhotosAlbum:o=>{saved.push(o.filePath);o.success();}};
 const resolver=vm.runInNewContext('('+fs.readFileSync('前端代码/uniapp/utils/memberAssetUrl.js','utf8').match(/export function resolveServerAssetUrl[\s\S]*/)[0].replace('export function resolveServerAssetUrl','function')+')');
 const context={uni,HTTP_REQUEST_URL:'https://local',resolveServerAssetUrl:resolver,Promise,Error,Array,String,setTimeout:fn=>{timers.set(++seq,fn);return seq;},clearTimeout:id=>timers.delete(id),spreadMsg:()=>{requests++;return opt.api?opt.api():Promise.resolve({data:opt.empty?{spread:[]}:data});},routineCode:()=>{qrRequests++;return Promise.resolve({data:{url:opt.noCode?'':'/static/images/def_avatar.png'}});}};
 const state={_pageReady:true,posterImage:[],spreadData:[],swiperIndex:0,wd:305,hg:473,$nextTick:fn=>fn(),$set:(o,k,v)=>o[k]=v,$util:{Tips:o=>notices.push(o.title),handleBorderRect(){},textByteLength:()=>[1,['LOCAL'],1]}};
 state.$util.userPosterCanvas=compile(util.properties.find(p=>p.key?.name==='userPosterCanvas'),utilSource,context);
 for(const name of ['spreadMsg','setDomain','downloadFilestoreImage','savePosterPathMp'])state[name]=compile(methods.properties.find(p=>p.key?.name===name),source,context);
 return {state,notices,saved,timers,unload:compile(page.properties.find(p=>p.key?.name==='onUnload'),source,context),get requests(){return requests;},get qrRequests(){return qrRequests;},get draws(){return draws;},get hides(){return hides;}};
}
let f=setup();const a=f.state.spreadMsg(),b=f.state.spreadMsg();assert.equal(a,b);await a;assert.equal(f.requests,1);assert.equal(f.qrRequests,1);assert.equal(f.draws,2);assert.equal(f.hides,1);assert.equal(f.timers.size,0);assert.equal(f.state.posterImage.length,2);console.log('PASS home multiple templates export, QR fetched once, one loading owner');
await f.state.savePosterPathMp();assert.deepEqual(f.saved,['LOCAL-myCanvas0.png']);assert(f.notices.includes('保存成功'));console.log('PASS home save uses generated current template');
for(const opt of [{imageFail:true},{emptyExport:true},{exportFail:true},{noCode:true},{empty:true},{api:()=>Promise.reject(Error('LOCAL API fail'))}]){f=setup(opt);await f.state.spreadMsg();assert.equal(f.hides,1);assert.equal(f.state._spreadPromise,null);assert.equal(f.notices.length,1);assert.equal(f.timers.size,0);}console.log('PASS home API, missing template/QR, image and export failures settle visibly');
f=setup({rect:null});await f.state.spreadMsg();assert.equal(f.draws,2);console.log('PASS missing measurement uses safe initial dimensions');
f=setup({api:()=>new Promise(()=>{})});const pending=f.state.spreadMsg();[...f.timers.values()][0]();await pending;assert.match(f.notices[0],/超时/);assert.equal(f.hides,1);console.log('PASS home silent native/API hang times out');
let release;f=setup({api:()=>new Promise(r=>release=r)});const left=f.state.spreadMsg();f.unload.call(f.state);await left;release({data:{spread:[{pic:'late'}]}});for(let i=0;i<20;i++)await Promise.resolve();assert.equal(f.draws,0);assert.equal(f.state.posterImage.length,0);assert.equal(f.notices.length,0);console.log('PASS leaving home poster prevents late drawing and stale loading');
