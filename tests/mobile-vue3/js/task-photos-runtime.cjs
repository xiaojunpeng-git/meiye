// 执行页面原方法，模拟相册和上传回调；覆盖上传等待、失败、取消及草稿隔离。
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const {createRequire} = require('node:module');
const root = path.resolve(__dirname, '../../..');
const deps = createRequire(path.join(root,'前端代码/cashier-v3/package.json'));
const parser = deps('@babel/parser');
const {transformSync} = deps('esbuild');
const source = fs.readFileSync(path.join(root,'前端代码/mobile-vue3/src/merchant/pages/customer-care/index.uvue'),'utf8');
const script = source.split('<script setup lang="uts">')[1].split('</script>')[0];
const ast = parser.parse(script,{sourceType:'module',plugins:['typescript']});
const wanted = ['chooseTaskPhotos','uploadTaskPhotos','removeTaskPhoto','previewTaskPhoto','submitCreateTask','openCreateTask','closeCreateTask'];
const selected = ast.program.body.filter(n=>n.type==='FunctionDeclaration'&&wanted.includes(n.id.name)).map(n=>script.slice(n.start,n.end)).join('\n');
const js = transformSync(selected,{loader:'ts',target:'es2020'}).code;
const ref = value=>({value});
const ctx = {taskPhotos:ref([]),taskPhotoUploading:ref(false),creatingTask:ref(false),createFormMessage:ref(''),
 creatingForCustomer:ref({memberId:'501'}),ownerId:ref('101'),plannedAt:ref('2026-11-07 10:00'),taskContent:ref('任务备注'),taskTypeCode:ref('FOLLOWUP'),
 createdTaskMemberId:ref(''),creatingTaskPanel:ref(true),activeTab:ref('tasks'),activeTabLabel:ref(''),activeBucket:ref('today'),taskStatusGroup:ref(''),
 taskMemberKeyword:ref(''),taskMemberCandidates:ref([]),taskMemberSearched:ref(false),taskMemberLoading:ref(false),plannedDate:ref(''),plannedTime:ref(''),showCreateTimePicker:ref(false),assignees:ref([{value:'101'}]),message:ref(''),
 loads:0,loadWorkbench:()=>ctx.loads++, uploads:[],requests:[],previews:[],
 uni:{chooseImage:options=>ctx.picker=options,previewImage:options=>ctx.previews.push(options)},
 uploadMobileCustomerCarePhoto:(file,key,done)=>ctx.uploads.push({file,key,done}),
 createMobileCustomerCareTask:(payload,done)=>ctx.requests.push({payload,done})};
vm.createContext(ctx);vm.runInContext(js,ctx);
ctx.chooseTaskPhotos();assert.equal(ctx.picker.count,9);assert.equal(ctx.taskPhotoUploading.value,true);
ctx.closeCreateTask();assert.equal(ctx.creatingTaskPanel.value,true);
ctx.submitCreateTask();assert.equal(ctx.requests.length,0);
ctx.picker.success({tempFilePaths:['first','second']});assert.equal(ctx.uploads.length,1);
ctx.uploads[0].done({ok:true,body:{photoUrl:'https://example.invalid/first.jpg'}});
assert.equal(ctx.uploads.length,2);assert.equal(ctx.taskPhotoUploading.value,true);
ctx.removeTaskPhoto(0);assert.equal(ctx.taskPhotos.value.length,1);
ctx.uploads[1].done({ok:true,body:{photoUrl:'https://example.invalid/second.jpg'}});
assert.equal(ctx.taskPhotoUploading.value,false);assert.equal(ctx.taskPhotos.value.length,2);
ctx.previewTaskPhoto(ctx.taskPhotos.value,1);assert.equal(ctx.previews[0].current,'https://example.invalid/second.jpg');assert.equal(ctx.previews[0].urls.length,2);
ctx.removeTaskPhoto(0);assert.equal(ctx.taskPhotos.value.length,1);
ctx.chooseTaskPhotos();assert.equal(ctx.picker.count,8);ctx.picker.fail();assert.equal(ctx.taskPhotoUploading.value,false);assert.equal(ctx.taskPhotos.value.length,1);
ctx.chooseTaskPhotos();ctx.picker.success({tempFilePaths:['failed']});ctx.uploads[2].done({ok:false,message:'上传失败'});
assert.equal(ctx.createFormMessage.value,'上传失败');assert.equal(ctx.taskPhotos.value.length,1);assert.equal(ctx.taskPhotoUploading.value,false);
ctx.submitCreateTask();assert.equal(ctx.requests.length,1);assert.equal(ctx.requests[0].payload.taskPhotos.length,1);assert.equal(ctx.requests[0].payload.content,'任务备注');
ctx.removeTaskPhoto(0);assert.equal(ctx.taskPhotos.value.length,1);ctx.requests[0].done({ok:false,message:'保存失败'});assert.equal(ctx.taskPhotos.value.length,1);
ctx.submitCreateTask();ctx.requests[1].done({ok:true});assert.equal(ctx.creatingTaskPanel.value,false);assert.equal(ctx.loads,1);
ctx.openCreateTask({memberId:'502'});assert.equal(ctx.taskPhotos.value.length,0);assert.equal(ctx.taskContent.value,'');
ctx.taskPhotos.value=Array.from({length:9},(_,i)=>'https://example.invalid/'+i+'.jpg');ctx.picker=null;ctx.chooseTaskPhotos();assert.equal(ctx.picker,null);
assert.match(source,/selectedTask\.taskPhotos/);assert.match(source,/@tap="previewTaskPhoto\(selectedTask.taskPhotos, Number\(index\)\)"/);
// 上传入口必须真正导入实例地址函数，避免选图后才报未定义。
const client=fs.readFileSync(path.join(root,'前端代码/mobile-vue3/src/shared/api/mobile-customer-care-client.uts'),'utf8');
const clientAst=parser.parse(client,{sourceType:'module',plugins:['typescript']});
assert.ok(clientAst.program.body.some(n=>n.type==='ImportDeclaration'&&n.specifiers.some(s=>s.local.name==='mobileApiOrigin')));
console.log('PASS 任务多图页面方法：选图、顺序上传、上传中不提交、删除、失败保留、取消、详情预览、提交、重开清空、9张上限、上传实例地址');
