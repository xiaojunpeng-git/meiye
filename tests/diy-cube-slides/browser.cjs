// Render the full compiled member app. APIs are isolated synthetic fixtures; no production writes.
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert/strict');
const {root,loaded,withCubeSlides}=require('./contract.cjs');
const appRoot=process.env.CUBE_H5_ROOT||'/private/tmp/m008-cube-slides-20261022/member/member-candidate';
const evidence=path.join(__dirname,'evidence');fs.mkdirSync(evidence,{recursive:true});
const counts=[2,2,3,3,3,3,3,4,5,4,1,2];
function config(style){
 const c=loaded.preview.options.data.call({num:style}).defaultConfig;c.timestamp=style;c.styleConfig.tabVal=style;
 c.prConfig.val=12;c.imgConfig.val=6;c.filletImg.val=8;c.mbConfig.val=12;
 c.picStyle.picList=Array.from({length:counts[style]},(_,i)=>withCubeSlides({},[
  {image:'/cube-a.png',link:'/pages/users/user_coupon/index?cube='+style+'&frame='+i+'a',objectFit:'cover'},
  {image:'/cube-b.png',link:'/pages/users/user_coupon/index?cube='+style+'&frame='+i+'b',objectFit:'contain'}
 ]));
 c.picStyle.docPicList=[{doc:{startX:0,startY:0,w:187.5,h:375}},{doc:{startX:187.5,startY:0,w:187.5,h:375}}];
 return c;
}
const configs=Array.from({length:12},(_,i)=>config(i));
const fixture={title:'魔方验证',is_show:1,is_bg_color:1,color_picker:'#fff',is_bg_pic:0,value:Object.fromEntries(configs.map((c,i)=>['cube'+String(i).padStart(2,'0'),c]))};
fs.writeFileSync(path.join(evidence,'decoration-fixture.json'),JSON.stringify(fixture,null,2));
function api(url){
 let data={};
 if(url.includes('/diy/get_diy/')){
  data=process.env.CUBE_API_FIXTURE?JSON.parse(fs.readFileSync(process.env.CUBE_API_FIXTURE,'utf8')).data:JSON.parse(JSON.stringify(fixture));
  if(process.env.CUBE_LEGACY)Object.values(data.value).forEach(c=>c.picStyle.picList.forEach(region=>delete region.slides));
 }
 else if(url.includes('diy_version'))data={version:'cube-local'};
 else if(url.includes('color_change'))data={status:7,navigation:0};
 else if(url.endsWith('/navigation'))data={name:'pageFoot',effectConfig:{tabVal:1},navConfig:{tabVal:0},navStyleConfig:{tabVal:0},toneConfig:{tabVal:0},topConfig:{val:0},bottomConfig:{val:0},prConfig:{val:0},mbConfig:{val:0},bgColor:{color:[{item:'#fff'}]},bgColor2:{color:[{item:'#fff'}]},menuList:[]};
 else if(url.endsWith('/get_entry_store'))data={store_id:0,user_entry_rules:[],shop_operation_type:1};
 else if(url.includes('nearby'))data={info:{id:0,name:'本地验证',is_alone:0}};
 else if(url.includes('spread/'))data=[];
 return {status:200,msg:'ok',data};
}
const server=http.createServer((req,res)=>{
 const url=new URL(req.url,'http://localhost'),p=url.pathname;
 if(p.startsWith('/api/')){if(p==='/api/get_script'){res.setHeader('Content-Type','application/javascript');res.end('');return;}res.setHeader('Content-Type','application/json');return res.end(JSON.stringify(api(p)));}
 if(p==='/cube-a.png'||p==='/cube-b.png'){res.setHeader('Content-Type','image/png');return res.end(fs.readFileSync(path.join(root,'前端代码/uniapp/static/design-system',p==='/cube-a.png'?'beauty-carousel-spa.png':'beauty-carousel-care.png')));}
 if(p==='/favicon.ico'){res.writeHead(204);return res.end();}
 const file=p.startsWith('/pages/')?path.join(appRoot,'index.html'):path.join(appRoot,p);
 if(!file.startsWith(appRoot+'/')||!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404);return res.end();}
 res.setHeader('Content-Type',({'.html':'text/html','.js':'application/javascript','.css':'text/css','.png':'image/png','.woff':'font/woff','.ttf':'font/ttf'})[path.extname(file)]||'application/octet-stream');fs.createReadStream(file).pipe(res);
});
async function run(){
 const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin='http://127.0.0.1:'+server.address().port;
 if(process.argv.includes('--serve')){console.log('CUBE_URL='+origin+'/pages/index/index');return;}
 const browser=await chromium.launch({channel:'chrome',headless:true}),page=await browser.newPage();const errors=[],results=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',route=>{
   const url=route.request().url();
   if(new URL(url).pathname.startsWith('/api/')) {
     assert.equal(route.request().method(),'GET','No business writes during rendering');
     if(new URL(url).pathname==='/api/get_script')return route.fulfill({contentType:'application/javascript',body:''});
     return route.fulfill({json:api(new URL(url).pathname)});
   }
   if(url==='https://res.wx.qq.com/open/js/jweixin-1.2.0.js')return route.fulfill({contentType:'application/javascript',body:'window.jWeixin={};'});
   return url.startsWith(origin+'/')?route.continue():route.abort();
 });
 try{
 for(const width of [375,320]){
 await page.setViewportSize({width,height:844});await page.goto(origin+'/pages/index/index');await page.locator(process.env.CUBE_LEGACY?'.pictureCube .easy-loadimage':'.cube-region-swiper').first().waitFor({state:'attached',timeout:30000});
 await page.waitForFunction(()=>document.querySelectorAll('.pictureCube').length===12);
 const regions=page.locator(process.env.CUBE_LEGACY?'.pictureCube .easy-loadimage':'.cube-region-image');
 // Scroll each real component into view so the existing lazy loader resolves its anchor.
 for(let i=0;i<await regions.count();i++){await regions.nth(i).evaluate(el=>el.scrollIntoView());}
 await page.waitForTimeout(1000);
 const before=await regions.evaluateAll(nodes=>nodes.map(el=>{const r=el.getBoundingClientRect();return {width:r.width,height:r.height,left:r.left,right:r.right};}));
 assert(before.every(r=>r.width>5&&r.height>5&&r.left>=0&&r.right<=width+1),'Zero-size/overflow region '+JSON.stringify(before));
 await page.waitForTimeout(3300);
 const after=await regions.evaluateAll(nodes=>nodes.map(el=>({width:el.getBoundingClientRect().width,height:el.getBoundingClientRect().height})));
 before.forEach((r,i)=>{assert(Math.abs(r.height-after[i].height)<1,'Slide resized region');assert(Math.abs(r.width-after[i].width)<1);});
 results.push({width,regions:before.length,layoutStable:true,...(process.env.CUBE_LEGACY?{bounds:before}:{})});
 await regions.nth(0).evaluate(el=>el.scrollIntoView());await page.screenshot({path:path.join(evidence,(process.env.CUBE_LEGACY||'member')+'-'+width+'.png')});
 }
 if(!process.env.CUBE_LEGACY){
 await page.evaluate(()=>{window.__cubeNav=[];window.uni.navigateTo=options=>{window.__cubeNav.push(options.url);};});
 const first=page.locator('.cube-region-swiper').first();
 await first.evaluate(el=>el.scrollIntoView());
 const active=await first.evaluate(el=>{const box=el.getBoundingClientRect(),x=box.left+box.width/2;const slide=[...el.querySelectorAll('uni-swiper-item')].find(item=>{const b=item.getBoundingClientRect();return b.left<=x&&b.right>x;});return slide&&slide.querySelector('img')&&slide.querySelector('img').getAttribute('src');});
 assert(active,'Current slide must have a loaded image');
 await first.click();const nav=await page.evaluate(()=>window.__cubeNav);
 assert.equal(nav.length,1,'One tap must not also trigger the region first-frame link');
 assert.equal(nav[0],'/pages/users/user_coupon/index?cube=0&frame=0'+(active.includes('cube-b.png')?'b':'a'));
 results.push({currentFrameLink:nav[0],oneNavigationPerTap:true});
 }
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(evidence,(process.env.CUBE_LEGACY||'browser')+'-result.json'),JSON.stringify({result:'PASS',results,errors,productionRequests:0},null,2));console.log(JSON.stringify(results.map(({bounds,...r})=>r)));
 }catch(error){await page.screenshot({path:path.join(evidence,'failure.png')});console.error('PAGE_ERRORS',errors);console.error((await page.locator('body').innerText()).slice(0,1200));throw error;}finally{await browser.close();server.close();}
}
if(require.main===module)run().catch(e=>{console.error(e);server.close();process.exitCode=1;});
module.exports={server,configs,fixture,api};
