// Native canvas proof in a disposable local 008 build. Requests and album saves
// are mocked; image loading, drawing and PNG export remain native. No real QR
// issuance, message, customer credential, upload or production mutation occurs.
import fs from 'node:fs';
import cp from 'node:child_process';
import assert from 'node:assert/strict';
const project=process.argv[2];
if(!/^\/private\/tmp\/r66-[^/]+\/build\/008-member$/.test(project||''))throw Error('Disposable R66 build required');
const cli='/Applications/wechatwebdevtools.app/Contents/MacOS/wechatide';
function call(tool,...args){const out=cp.execFileSync(cli,['-c','Codex',tool,'--project',project,...args],{encoding:'utf8',maxBuffer:4e6});const reply=JSON.parse(out.slice(out.indexOf('{')));assert(reply.ok,JSON.stringify(reply));return reply.result;}
const evaluate=fn=>{const result=call('automation_evaluate','--fn-source',fn);assert(result.success);return result.result?.result;};
const mock="function(options){var data={};if(options.url.indexOf('user/spread_info')>=0)data={spread:[{pic:'/static/images/posterbackgd.png'},{pic:'https://myqiniu.cc3798.com/attach/2026/09/a13a7202609141848355696.jpg'}],avatar:'/static/images/def_avatar.png',nickname:'R66 LOCAL',site_name:'LOCAL TEST'};else if(options.url.indexOf('user/routine_code')>=0)data={url:'/static/images/def_avatar.png'};else if(options.url.indexOf('/user')>=0)data={uid:7,nickname:'R66 LOCAL',avatar:'/static/images/def_avatar.png'};return {statusCode:200,data:{status:200,data:data,msg:'LOCAL ONLY'}};}";
call('open_project_window','--window-mode','liteMode');
let authenticated=false;
try{
 call('automation_wx_api','--action','mock','--method','request','--function-declaration',mock);
 evaluate('function(){var s=getApp().$vm.$store;wx.__r66HomeOriginal={token:s.state.app.token,user:s.state.app.userInfo};s.state.app.token="LOCAL-R66-ONLY";s.state.app.userInfo={uid:7,nickname:"R66 LOCAL",avatar:"/static/images/def_avatar.png"};return true;}');authenticated=true;
 call('automation_navigate','--action','navigateTo','--url','/pages/users/user_spread_code/index');
 const proof=evaluate('function(){var p=getCurrentPages().slice(-1)[0].$vm;return Promise.resolve(p._spreadPromise).then(function(){return Promise.all(p.posterImage.map(function(path){return new Promise(function(resolve,reject){wx.getImageInfo({src:path,success:function(r){wx.getFileSystemManager().stat({path:path,success:function(s){resolve({width:r.width,height:r.height,bytes:s.stats.size});},fail:reject});},fail:reject});});}));}).then(function(images){return {route:getCurrentPages().slice(-1)[0].route,images:images,pending:!!p._spreadPromise};});}');
 assert.equal(proof.images.length,2);assert(!proof.pending);for(const image of proof.images){assert(image.width>0);assert.equal(image.height,473);assert(image.bytes>1000);}
 call('automation_wx_api','--action','mock','--method','saveImageToPhotosAlbum','--function-declaration','function(options){getApp().globalData.__r66HomeSaved=options.filePath;return {};}');
 call('automation_element_action','--action','tap','--selector','.picList .item');
 const saved=evaluate('function(){var p=getCurrentPages().slice(-1)[0].$vm;return {sameImage:getApp().globalData.__r66HomeSaved===p.posterImage[p.swiperIndex]};}');assert(saved.sameImage);
 call('simulator_screenshot','--path','/private/tmp/r66-home-final/home-poster.png','--optimize','false');
 fs.copyFileSync('/private/tmp/r66-home-final/home-poster.png','tests/combination-r66/evidence/home-native-screenshot-local-fixture.png');
 fs.writeFileSync('tests/combination-r66/evidence/home-native-proof.json',JSON.stringify({project,proof,saved,mocks:['request','saveImageToPhotosAlbum'],native:['getImageInfo','createCanvasContext','canvasToTempFilePath'],qr:'Local avatar stand-in, not a real/scannable customer QR'},null,2)+'\n');
 console.log('PASS native home two PNG exports, loading settled, save button uses actual image');
} finally {
 if(authenticated)evaluate('function(){var s=getApp().$vm.$store,o=wx.__r66HomeOriginal;s.state.app.token=o.token;s.state.app.userInfo=o.user;delete wx.__r66HomeOriginal;delete getApp().globalData.__r66HomeSaved;return true;}');
 for(const method of ['request','saveImageToPhotosAlbum'])call('automation_wx_api','--action','restore','--method',method);
}
