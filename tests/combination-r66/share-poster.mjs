// Execute production page methods and the shared canvas renderer. Native share,
// album, network and canvas APIs are deterministic local fakes, never messages
// to real friends or writes to a phone album / production business database.
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/package.json');
const parser=require('@babel/parser');
const page=fs.readFileSync('前端代码/uniapp/pages/activity/goods_combination_status/index.vue','utf8');
function miniSource(text){
 const stack=[true],out=[];
 for(const line of text.split('\n')){
  const directive=line.match(/\/\/\s*#(ifdef|ifndef|endif)\s*(.*)/);
  if(directive){const[,kind,expr]=directive;if(kind==='endif')stack.pop();else{const matches=expr.trim().split(/\s*\|\|\s*/).some(v=>['MP','MP-WEIXIN'].includes(v));stack.push(stack[stack.length-1]&&(kind==='ifndef'?!matches:matches));}continue;}
  if(stack[stack.length-1])out.push(line);
 }
 return out.join('\n');
}
const script=miniSource(page.match(/<script>([\s\S]*?)<\/script>/)[1]);
const component=parser.parse(script,{sourceType:'module'}).program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
const methods=component.properties.find(n=>n.key?.name==='methods').value;
const utilSource=miniSource(fs.readFileSync('前端代码/uniapp/utils/util.js','utf8'));
const util=parser.parse(utilSource,{sourceType:'module'}).program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;
const compile=(p,source,ctx)=>vm.runInNewContext(p.value?'('+source.slice(p.value.start,p.value.end)+')':'('+(p.async?'async ':'')+'function('+p.params.map(v=>source.slice(v.start,v.end)).join(',')+')'+source.slice(p.body.start,p.body.end)+')',ctx);
const flush=async()=>{for(let i=0;i<30;i++)await Promise.resolve();};
function fixture(options={}){
 const timers=new Map(),notices=[],previews=[],saves=[],modals=[];let serial=0,requests=0,draws=0,settings=0;
 const uni={getImageInfo:o=>options.downloadFail?o.fail({errMsg:'LOCAL download failed'}):o.success({path:options.imagePath||(options.stripRoot?o.src.replace(/^\//,''):o.src),width:750,height:1140}),
  createCanvasContext:()=>new Proxy({measureText:()=>({width:10}),draw:(reserve,cb)=>{draws++;cb();}},{get:(target,key)=>target[key]||(()=>{})}),
  canvasToTempFilePath:o=>options.canvasFail?o.fail({errMsg:'LOCAL canvas failed'}):o.success({tempFilePath:'LOCAL-POSTER.png'}),hideLoading(){},
  previewImage:o=>{previews.push(o.current);if(options.previewFail)o.fail();},
  saveImageToPhotosAlbum:o=>{saves.push(o.filePath);options.albumFail?o.fail({errMsg:options.albumFail}):o.success();},
  showModal:o=>modals.push(o),openSetting:o=>{settings++;}
 };
 const resolver=vm.runInNewContext('('+fs.readFileSync('前端代码/uniapp/utils/memberAssetUrl.js','utf8').match(/export function resolveServerAssetUrl[\s\S]*/)[0].replace('export function resolveServerAssetUrl','function')+')');
 const ctx={resolveServerAssetUrl:resolver,uni,Promise,Error,JSON,String,encodeURIComponent,setTimeout:(fn,delay)=>{timers.set(++serial,{fn,delay});return serial;},clearTimeout:id=>timers.delete(id),
  getCombinationPosterData:()=>{requests++;return options.api?options.api():Promise.resolve({data:{image:'https://local/item.png',url:'https://local/code.png',title:'LOCAL GROUP',price:1}});}
 };
 const tools={Tips:o=>notices.push(o.title),handleBorderRect(){},textByteLength:()=>[1,['LOCAL'],1]};
 tools.bargainPosterCanvas=compile(util.properties.find(p=>p.key?.name==='bargainPosterCanvas'),utilSource,ctx);
 const state={pinkId:42,userInfo:{uid:7,nickname:'LOCAL'},storeCombination:{image:'LOCAL-ITEM',title:'LOCAL'},$store:{state:{app:{userInfo:{uid:7,nickname:'LOCAL',avatar:'https://local/avatar.png'}}}},$util:tools,$nextTick:fn=>fn(),
  posters:true,posterImage:'',posterImageStatus:false,posterLoading:false,canvasStatus:false,imgHost:'https://local'};
 for(const name of ['setDomain','downloadFilestoreImage','getPosterInfo','posterError','getpreviewImage','savePosterPath','posterImageClose','listenerActionClose','goFriend'])state[name]=compile(methods.properties.find(p=>p.key?.name===name),script,ctx);
 const share=compile(component.properties.find(p=>p.key?.name==='onShareAppMessage'),script,ctx);
 return {state,share,timers,notices,previews,saves,modals,get requests(){return requests;},get draws(){return draws;},get settings(){return settings;}};
}
let f=fixture();const first=f.state.getPosterInfo(),second=f.state.getPosterInfo();assert.equal(first,second);assert.equal(await first,'LOCAL-POSTER.png');assert.equal(f.requests,1);assert.equal(f.draws,1);assert.equal(f.state.posterLoading,false);assert.equal(f.timers.size,0);console.log('PASS shared generation draws once and releases loading');
f=fixture();await f.state.getpreviewImage();await f.state.savePosterPath();assert.deepEqual(f.previews,['LOCAL-POSTER.png']);assert.deepEqual(f.saves,['LOCAL-POSTER.png']);assert.equal(f.requests,1);assert(f.notices.includes('保存成功'));console.log('PASS preview/save use the actual generated image');
for(const options of [{downloadFail:true},{canvasFail:true},{api:()=>Promise.reject(Error('LOCAL API failed'))}]){
 f=fixture(options);await assert.rejects(f.state.getPosterInfo());assert.equal(f.state.posterLoading,false);assert.equal(f.state.canvasStatus,false);assert.equal(f.timers.size,0);assert.equal(f.state._posterPromise,null);
}console.log('PASS download, canvas and API failures settle without stuck loading');
f=fixture({api:()=>new Promise(()=>{})});const timed=f.state.getPosterInfo();[...f.timers.values()][0].fn();await assert.rejects(timed,/超时/);assert.equal(f.state.posterLoading,false);console.log('PASS silent native/network hang has bounded timeout');
let release;f=fixture({api:()=>new Promise(r=>release=r)});const cancelled=f.state.getPosterInfo();f.state.goFriend();await assert.rejects(cancelled,/取消/);assert.equal(f.state.posters,false);assert.equal(f.state.posterLoading,false);assert.equal(f.state._posterPromise,null);release({data:{image:'https://local/item',url:'https://local/code'}});await flush();assert.equal(f.draws,0);assert.equal(f.state.posterImage,'');console.log('PASS friend sharing cancels loading and ignores late poster work');
f=fixture();f.state.posterImage='LOCAL-EXISTING.png';const preview=f.state.getpreviewImage();f.state.posterImageClose();await preview;assert.deepEqual(f.previews,[]);console.log('PASS closing before async preview prevents late popup');
f=fixture({albumFail:'saveImageToPhotosAlbum:fail auth deny'});await f.state.savePosterPath();assert.equal(f.modals.length,1);f.modals[0].success({confirm:false});assert.equal(f.settings,0);f.modals[0].success({confirm:true});assert.equal(f.settings,1);console.log('PASS denied album access offers explicit settings; cancel is respected');
f=fixture({albumFail:'saveImageToPhotosAlbum:fail system error'});await f.state.savePosterPath();assert(f.notices.includes('保存失败，请重试'));console.log('PASS album system failures give visible feedback');
f=fixture({previewFail:true});await f.state.getpreviewImage();assert(f.notices.includes('预览失败，请重试'));console.log('PASS preview failures give visible feedback');
f=fixture();const share=f.share.call(f.state);assert.equal(share.path,'/pages/activity/goods_combination_status/index?id=42&spid=7');assert.equal(share.imageUrl,'LOCAL-ITEM');console.log('PASS friend share always targets current group');
// Hit-test ordering is part of the failure: a full-screen sibling must not
// outrank the actions. Native open-type share remains intact in the template.
const css=page.match(/<style[^>]*>([\s\S]*?)<\/style>/)[1];
const z=selector=>Number(css.slice(css.indexOf(selector+' {')>=0?css.indexOf(selector+' {'):css.indexOf(selector+'{')).match(/z-index:\s*(\d+)/)[1]);
assert(z('.generate-posters')>z('.fixed-center'));assert(z('.generate-posters')>z('.poster-pop'));assert.match(css,/\.fixed-center\s*\{[^}]*pointer-events:\s*none/);assert.match(page,/<button[^>]*open-type="share"[^>]*@tap="goFriend"/);console.log('PASS share actions outrank overlays and retain native share button');
// Existing product/bargain pages do not supply the new optional failure callback.
// Their old success contract and visible failure toast remain intact.
f=fixture();let legacyPath='';f.state.$util.bargainPosterCanvas(['a','b','c','d','e','f','g'],'LOCAL','','',1,{nickname:'LOCAL'},'拼团',p=>legacyPath=p);await flush();assert.equal(legacyPath,'LOCAL-POSTER.png');
f=fixture({canvasFail:true});f.state.$util.bargainPosterCanvas(['a','b','c','d','e','f','g'],'LOCAL','','',1,{nickname:'LOCAL'},'拼团',()=>{});await flush();assert(f.notices.includes('海报生成失败'));console.log('PASS legacy renderer callers keep success and error behavior');


f=fixture();assert.equal(f.state.setDomain('/static/mall/local.png'),'https://local/static/mall/local.png');assert.equal(f.state.setDomain('/pages/activity/static/poster/rmb.png'),'/pages/activity/static/poster/rmb.png');assert.equal(f.state.setDomain('http://127.0.0.1:8080/local.png'),'http://127.0.0.1:8080/local.png');console.log('PASS server media, bundled artwork and local HTTP retain correct origins');

f=fixture({stripRoot:true});assert.equal(await f.state.downloadFilestoreImage('/pages/activity/static/poster/posterBag.png'),'/pages/activity/static/poster/posterBag.png');assert.equal(await f.state.downloadFilestoreImage('/static/images/def_avatar.png'),'/static/images/def_avatar.png');f=fixture({imagePath:'http://tmp/local.png'});assert.equal(await f.state.downloadFilestoreImage('https://local/photo.png'),'http://tmp/local.png');console.log('PASS native packaged paths restore root; temp paths remain unchanged');
