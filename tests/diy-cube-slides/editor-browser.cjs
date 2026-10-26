// Actual editor and preview SFCs, with only the unchanged media/link dialogs replaced by fixtures.
const fs=require('fs'),path=require('path'),http=require('http'),assert=require('assert/strict');
const {root,deps,loaded}=require('./contract.cjs'),{configs}=require('./browser.cjs');
const less=require(path.join(deps,'less'));
async function run(){
 const css=[];for(const key of ['editor','preview','previewImage'])for(const style of loaded[key].sfc.styles){css.push(style.lang==='less'?(await less.render(style.content)).css:style.content);}
 const modules=Object.fromEntries(['editor','preview','previewImage'].map(key=>[key,{script:loaded[key].source,template:loaded[key].sfc.template.content}]));
 const html=`<!doctype html><meta charset="utf-8"><style>*{box-sizing:border-box}body{font:14px Arial;margin:24px;color:#333}.layout{display:flex;gap:28px;align-items:flex-start}.preview{width:375px;flex-shrink:0}.editor{width:350px;flex-shrink:0}.grid-column-2{display:grid;grid-template-columns:repeat(2,1fr)}.grid-column-3{display:grid;grid-template-columns:repeat(3,1fr)}.flex-1{flex:1;min-width:0}.flex-between-center{display:flex;justify-content:space-between;align-items:center}.h-full{height:100%}.w-full{width:100%}button{cursor:pointer}${css.join('\n')}</style><link rel="stylesheet" href="/iview.css"><link rel="stylesheet" href="/swiper.css"><div id="app"><div class="layout"><div class="preview"><cube-preview :num="0" /></div><div class="editor"><div>区域 <button v-for="(item,i) in config.picStyle.picList" :id="'region-'+i" @click="config.picStyle.tabVal=i">{{i+1}}</button></div><cube-editor :config-obj="config" /></div></div></div><script src="/vue.js"></script><script src="/iview.js"></script><script src="/sortable.js"></script><script src="/draggable.js"></script><script src="/swiper.js"></script><script src="/awesome.js"></script><script>
 const shared=${JSON.stringify(fs.readFileSync(path.join(root,'前端代码/shared/diyCubeSlides.js'),'utf8'))};
 const {cubeSlides,withCubeSlides}=new Function(shared.replace(/export function/g,'function')+';return {cubeSlides,withCubeSlides}')();
 const modules=${JSON.stringify(modules)};
 const config=${JSON.stringify(configs[9])};
 const store=Vue.observable({state:{admin:{mobildConfig:{defaultArray:[config]}}}});Vue.prototype.$store=store;
 const bindings={cubeSlides,withCubeSlides,cubePreviewImage:{},draggable:window.vuedraggable,uploadPictures:{render(h){return h('button',{on:{click:()=>this.$emit('getPic',{att_dir:'/cube-b.png'})}},'选择验证图片');}},linkaddress:{data:()=>({modals:false}),template:'<span></span>'},swiper:VueAwesomeSwiper.swiper,swiperSlide:VueAwesomeSwiper.swiperSlide,mapState:()=>({defaultArray(){return this.$store.state.admin.mobildConfig.defaultArray}})};
 for(const [key,name] of [['previewImage','cubePreviewImage'],['preview','cube-preview'],['editor','cube-editor']]){const m=modules[key];const options=new Function(...Object.keys(bindings),m.script)(...Object.values(bindings));if(key==='preview')options.components.cubePreviewImage=Vue.component('cubePreviewImage');Vue.component(name,Object.assign(options,Vue.compile(m.template)));}
 window.fixture=new Vue({el:'#app',data:{config}});
 </script>`;
 const resources={'/vue.js':'vue/dist/vue.js','/iview.js':'view-design/dist/iview.js','/iview.css':'view-design/dist/styles/iview.css','/sortable.js':'sortablejs/Sortable.js','/draggable.js':'vuedraggable/dist/vuedraggable.umd.js','/swiper.js':'swiper/dist/js/swiper.js','/swiper.css':'swiper/dist/css/swiper.css','/awesome.js':'vue-awesome-swiper/dist/vue-awesome-swiper.js'};
 const server=http.createServer((req,res)=>{const p=new URL(req.url,'http://localhost').pathname;if(p==='/'){res.setHeader('Content-Type','text/html;charset=utf-8');return res.end(html);}if(resources[p]){res.setHeader('Content-Type',p.endsWith('.css')?'text/css':'application/javascript');return res.end(fs.readFileSync(path.join(deps,resources[p])));}if(p.startsWith('/cube-')){res.setHeader('Content-Type','image/png');return res.end(fs.readFileSync(path.join(root,'前端代码/uniapp/static/design-system',p==='/cube-a.png'?'beauty-carousel-spa.png':'beauty-carousel-care.png')));}res.writeHead(204);res.end();});
 await new Promise(r=>server.listen(0,'127.0.0.1',r));const origin='http://127.0.0.1:'+server.address().port;
 const {chromium}=require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');const browser=await chromium.launch({channel:'chrome',headless:true}),page=await browser.newPage({viewport:{width:900,height:900}}),errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 try{
 await page.goto(origin);await page.locator('.cube-frame').nth(1).waitFor();assert.equal(await page.locator('.cube-frame').count(),2);
 const image=await page.locator('.preview .swiper-slide-active img').first().evaluate(el=>({width:el.getBoundingClientRect().width,height:el.getBoundingClientRect().height,top:el.getBoundingClientRect().top,rootTop:el.closest('.cube-preview-image').getBoundingClientRect().top,naturalWidth:el.naturalWidth}));
 assert(image.naturalWidth>0&&image.width>0&&Math.abs(image.top-image.rootTop)<1,'Preview image must stay inside its region');
 await page.locator('.cube-frame input.ivu-input').nth(1).fill('/pages/users/user_coupon/index?edited=1');
 await page.getByText('添加图片',{exact:true}).click();assert.equal(await page.locator('.cube-frame').count(),3);
 await page.locator('.cube-picture').nth(2).click();await page.getByRole('button',{name:'选择验证图片'}).click();
 await page.locator('.ivu-modal-wrap').waitFor({state:'hidden'});
 await page.locator('#region-1').click();assert.equal(await page.locator('.cube-frame').count(),2);
 await page.locator('#region-0').click();assert.equal(await page.locator('.cube-frame').count(),3);
 assert.equal(await page.locator('.cube-frame input.ivu-input').nth(1).inputValue(),'/pages/users/user_coupon/index?edited=1');
 await page.locator('.cube-frame button[title="删除图片"]').nth(2).click();assert.equal(await page.locator('.cube-frame').count(),2);
 const size=await page.locator('.preview .mobile-page').boundingBox();assert(size.width===375&&size.height>50);
 await page.screenshot({path:path.join(__dirname,'evidence/editor.png')});assert.deepEqual(errors,[]);
 fs.writeFileSync(path.join(__dirname,'evidence/editor-result.json'),JSON.stringify({result:'PASS',realComponents:true,checks:['selected-region','add-image','picker-callback','edit-link','switch-preserves-content','delete','preview'],errors},null,2));console.log('PASS: real editor controls and preview.');
 }catch(error){console.error('EDITOR_ERRORS',errors);console.error((await page.locator('body').innerText()).slice(0,900));await page.screenshot({path:path.join(__dirname,'evidence/editor-failure.png')});throw error;}finally{await browser.close();server.close();}
}
run().catch(e=>{console.error(e);process.exitCode=1;});
