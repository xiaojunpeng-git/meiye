// Execute canonical component methods and shared normalization, not copied business logic.
const assert = require('assert/strict'), fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '../..'), deps = path.join(root, '前端代码/admin/node_modules');
const compiler = require(path.join(deps, 'vue-template-compiler'));
const parser = require(path.join(deps, '@babel/parser'));
const Vue = require(path.join(deps, 'vue'));
const shared = fs.readFileSync(path.join(root, '前端代码/shared/diyCubeSlides.js'), 'utf8');
const {cubeSlides, withCubeSlides} = new Function(shared.replace(/export function/g, 'function') + '\nreturn {cubeSlides,withCubeSlides};')();
const bindings = {cubeSlides, withCubeSlides, cubeRegionImage: {}, cubePreviewImage: {}, draggable: {}, vuedraggable: {}, uploadPictures: {}, linkaddress: {}, swiper: {}, swiperSlide: {},
  mapState: () => ({defaultArray() {return [];}}), uni: {getWindowInfo: () => ({windowWidth: 375}),getImageInfo: ({success}) => success({width: 750, height: 750})}};
function load(file) {
  const sfc = compiler.parseComponent(fs.readFileSync(path.join(root,file),'utf8'));
  const result = compiler.compile(sfc.template.content);
  assert.deepEqual(result.errors, [], file + ' template');
  let source = sfc.script.content;
  const edits = parser.parse(source, {sourceType:'module'}).program.body.filter(n => ['ImportDeclaration','ExportDefaultDeclaration'].includes(n.type));
  for (const n of edits.reverse()) source = source.slice(0,n.start) + (n.type==='ImportDeclaration'?'':'return '+source.slice(n.declaration.start,n.end)) + source.slice(n.end);
  const options = new Function(...Object.keys(bindings),source)(...Object.values(bindings));
  return {sfc, options, source};
}
const paths = {
  regionPicker:'前端代码/admin/src/components/mobileConfigRight/c_pictrue.vue',
  sharedMenu:'前端代码/admin/src/components/mobileConfigRight/c_menu_list.vue',
  editor:'前端代码/admin/src/components/mobileConfigRight/c_cube_slides.vue',
  preview:'前端代码/admin/src/components/mobilePage/picture_cube.vue',
  previewImage:'前端代码/admin/src/components/mobilePage/cubePreviewImage.vue',
  member:'前端代码/uniapp/pages/index/components/pictureCube.vue',
  memberImage:'前端代码/uniapp/pages/index/components/cubeRegionImage.vue'
};
const loaded = Object.fromEntries(Object.entries(paths).map(([k,v]) => [k,load(v)]));
if (require.main === module) {
  const legacy = {image:'/a.png',link:'/old',objectFit:'contain'};
  assert.deepEqual(cubeSlides(legacy),[legacy]);
  assert.deepEqual(cubeSlides(withCubeSlides(legacy,[])),[],'Deleted last image must not resurrect legacy content');
  const next = withCubeSlides(legacy,[{image:'',link:'/empty'},{image:'/b.png',link:'/b',objectFit:'fill'}]);
  assert.equal(next.image,'/b.png'); assert.equal(next.link,'/b');
  assert.equal(legacy.image,'/a.png','Reading/editing must not mutate legacy source');
  const cfg = Vue.observable({styleConfig:{tabVal:9},picStyle:{tabVal:0,picList:[legacy,{image:'/other.png',link:'/other'}],docPicList:[{doc:{w:100,h:100}}]}});
  const editor = new Vue({...loaded.editor.options,propsData:{configObj:cfg}});
  editor.addFrame(); editor.updateFrame(1,'image','/second.png');editor.updateFrame(1,'link','/second');
  assert.equal(editor.frames.length,2);assert.equal(editor.frames[1].link,'/second');
  editor.saveFrames(editor.frames.slice().reverse());
  assert.equal(cfg.picStyle.picList[0].image,'/second.png');assert.equal(cfg.picStyle.docPicList[0].img,'/second.png');
  editor.editingIndex=0;editor.editingRegion=editor.region;cfg.picStyle.tabVal=1;
  editor.receiveImage({att_dir:'/must-not-leak.png'});assert.equal(editor.region.image,'/other.png');
  cfg.picStyle.tabVal=0;editor.removeFrame(0);assert.equal(editor.frames.length,1);editor.removeFrame(0);assert.equal(editor.region.image,'');
  assert.equal(cfg.picStyle.picList[1].link,'/other','Region edits leaked');
  const saved=JSON.parse(JSON.stringify(cfg));assert.equal(JSON.stringify(saved.picStyle),JSON.stringify(cfg.picStyle),'JSON roundtrip');
  const image = new Vue({...loaded.memberImage.options,propsData:{region:next}});
  const events=[];image.$on('navigate',frame=>events.push(frame.link));image.navigate(image.slides[0]);assert.deepEqual(events,['/b']);
  assert.equal(image.slideMode(image.slides[0]),'scaleToFill');
  const config = loaded.preview.options.data.call({num:0}).defaultConfig;
  config.picStyle.picList=[{image:'/a.png',link:'/a',slides:[{image:'/a.png',link:'/a'},{image:'/b.png',link:'/b'}]}];
  config.picStyle.docPicList=[{doc:{startX:0,startY:0,w:375,h:375},img:'/stale.png'}];config.styleConfig.tabVal=11;
  const member=new Vue({...loaded.member.options,propsData:{dataConfig:config}});
  assert.equal(member.docPicList[0].slides[1].link,'/b');assert.equal(member.docPicList[0].doc.w,375);
  const menu=loaded.sharedMenu.options;
  const menuData={list:[{img:'old',info:[{value:'/old'}]}]};
  const ctx={configData:menuData,activeIndex:0,indexLast:0,$nextTick:fn=>fn(),modalPic:true};
  menu.methods.linkUrl.call(ctx,'/nav');menu.methods.getPic.call(ctx,{att_dir:'/nav.png'});
  assert.equal(menuData.list[0].info[0].value,'/nav');assert.equal(menuData.list[0].img,'/nav.png');assert.equal(ctx.modalPic,false);
  assert(!loaded.sharedMenu.sfc.script.content.includes('picStyle'),'Retired cube editor branch remains');
  const picker=loaded.regionPicker.options;
  const pickCtx={arrayObj:{image:'',link:''},configData:{picList:[]}};
  picker.methods.picArrayConcat.call(pickCtx,2);
  assert.notEqual(pickCtx.configData.picList[0],pickCtx.configData.picList[1],'Regions share their placeholder');
  picker.methods.currentTab.call(pickCtx,11,{});assert.equal(pickCtx.configData.tabVal,11,'Custom region index 11 must not enter the old single-image branch');
  console.log('PASS: legacy, add/edit/reorder/delete, no resurrection, independent regions, asynchronous picker guard, JSON roundtrip, current-frame links, custom-grid geometry, shared navigation editor regression, 7 SFC templates.');
}
module.exports={root,deps,loaded,paths,bindings,cubeSlides,withCubeSlides};
