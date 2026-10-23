/* 将仓库固定树只读导出给现有HBuilderX编译器；不改开发工程，不上传任何小程序。 */
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'../..'),e=path.resolve(root,'../任务管理/进行中/美业大学成长管理/证据/发布');
const tree=JSON.parse(fs.readFileSync(path.join(e,'trees.json'))).candidateTree;
const base='/private/tmp/mohe-growth-mobile-'+tree.slice(0,12),input=path.join(base,'前端代码/mobile-vue3'),output=path.join(base,'output');
const git=args=>cp.execFileSync('git',args,{cwd:root,maxBuffer:100*1024*1024});
fs.mkdirSync(base,{recursive:true});cp.execFileSync('tar',['-xf','-','-C',base],{input:git(['archive',tree,'前端代码/mobile-vue3'])});
const files=git(['ls-tree','-r','--name-only',tree,'--','前端代码/mobile-vue3']).toString().trim().split('\n');
for(const file of files)fs.chmodSync(path.join(base,file),0o444);
const plugins='/Applications/HBuilderX.app/Contents/HBuilderX/plugins';
cp.execFileSync(path.join(plugins,'node/node'),[path.join(plugins,'uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js'),'build','-p','h5','--base','/view_mobile_v3/'],{cwd:path.join(plugins,'uniapp-cli-vite'),env:{...process.env,NODE_ENV:'production',UNI_INPUT_DIR:input,UNI_OUTPUT_DIR:output,UNI_HBUILDERX_PLUGINS:plugins,UNI_APP_X:'true',UNI_UTS_PLATFORM:'web'},stdio:'inherit'});
for(const file of files)if(!fs.readFileSync(path.join(base,file)).equals(git(['show',tree+':'+file])))throw Error('Fixed input drift '+file);
const assets={};function scan(dir){for(const n of fs.readdirSync(dir,{withFileTypes:true})){const p=path.join(dir,n.name);if(n.isDirectory())scan(p);else assets[path.relative(output,p)]=crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex')}}scan(output);
fs.writeFileSync(path.join(e,'candidate-mobile-build.json'),JSON.stringify({tree,input,output,assets,result:'PASS'},null,2));console.log(JSON.stringify({tree,files:files.length,assets:Object.keys(assets).length}));
