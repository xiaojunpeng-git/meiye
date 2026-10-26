// Preserve the accepted R32 real-rendered DOM fixture only when its source is identical in the R52 tree.
// The deployed CSS is fetched live; synthetic rows remain explicitly synthetic, never production business data.
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto'),Module=require('module');
const old='tests/cashier-v3/evidence/R32-report-scroll/release', next='tests/order-center/evidence/r52-release-v2';
const previous=JSON.parse(fs.readFileSync(path.join(old,'trees.json'))), current=JSON.parse(fs.readFileSync(path.join(next,'trees.json')));
const report='前端代码/cashier-v3/src/views/StoreBusinessReportView.vue';
const blob=tree=>cp.execFileSync('git',['rev-parse',tree+':'+report],{encoding:'utf8'}).trim();
if(blob(previous.candidateTree)!==blob(current.candidateTree))throw Error('R32 report source changed; capture fresh DOM');
let source=fs.readFileSync(path.join(__dirname,'r32-report-scroll-css-browser.cjs'),'utf8');
if(crypto.createHash('sha256').update(source).digest('hex')!=='519627ee8c8e206cf1a6da8167b1bcc1751dc99f874c0d3fd54605e421299ebd')throw Error('R32 regression pipeline changed');
source=source.replace("path.resolve('"+old+"')","path.resolve('"+next+"')")
  .replace('path.join(R,`candidate-browser/dom-${width}.html`)',"path.resolve('"+old+"/candidate-browser',`dom-${width}.html`)");
const child=new Module(__filename,module);child.filename=__filename;child.paths=module.paths;child._compile(source,__filename);
