const fs = require('fs');
const path = require('path');
const http = require('http');
const assert = require('assert').strict;
const root = process.cwd();
const app = path.join(root, '前端代码/admin');
const XLSX = require(path.join(app, 'node_modules/xlsx'));
const compiler = require(path.join(app, 'node_modules/vue-template-compiler'));
const { chromium } = require(process.env.PLAYWRIGHT_PATH || '/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const output = path.join(root, 'tests/target-scheme/evidence/R95-excel-names-2026-10-24');
fs.mkdirSync(output, { recursive: true });
const component = compiler.parseComponent(fs.readFileSync(path.join(app, 'src/pages/target/scheme/Edit.vue'), 'utf8'));
const helpers = fs.readFileSync(path.join(app, 'src/utils/targetSchemeExcel.js'), 'utf8').replace(/export /g, '');
const script = component.script.content.replace(/^import .*;$/gm, '').replace('export default', 'window.component =');
const catalog = { stores: [{ id: 23, name: '扬州欧尚店' }], metrics: [{ code: 'actual_performance', name: '实际业绩', unit: 'fen', scopes: ['store', 'category', 'product'] }], categories: [{ id: 1, pid: 0, cate_name: '生美' }], reports: { group_dashboard: '集团管理看板' } };
// 页面使用正本SFC和真实文件控件。网络在此测试替换为可刷新读回的草稿夹具；真实DB由PHP测试独立覆盖。
const html = `<!doctype html><html lang="zh-CN"><meta charset="utf-8"><style>body{margin:0;background:#f5f7fa;font:14px Arial}*{box-sizing:border-box}${component.styles.map(s=>s.content).join('\n')}</style><div id="app"></div><script src="/vue.js"></script><script src="/xlsx.js"></script><script>
const catalog = ${JSON.stringify(catalog)};
const OrganizationStoreScopePicker = { template: '<span>当前权限范围</span>' };
const schemeCatalog = async () => ({data: catalog});
const schemeProducts = async p => ({data: p.exact_name === '测试产品' && p.store_id === 23 ? [{id:103,store_name:'测试产品'}] : []});
window.saveCalls = 0;
const initial = {scheme:{id:95,name:'Excel导入测试草稿',status:'draft',version:1},lines:[],report_codes:[]};
const schemeDetail = async () => ({data:JSON.parse(localStorage.getItem('r95-draft') || JSON.stringify(initial))});
const schemeSaveLines = async payload => {window.saveCalls++; const current = (await schemeDetail()).data; if(payload.expected_version !== current.scheme.version) throw Error('版本不符'); current.scheme.version++; current.lines=payload.lines.map(l=>({...l,unit_code:'fen'}));localStorage.setItem('r95-draft',JSON.stringify(current));};
${helpers}
${script}
Vue.component('Modal',{template:'<div v-if="value"><slot/><slot name="footer"/></div>',props:['value']});
Vue.component('Button',{render(h){return h('button',{on:{click:()=>this.$emit('click')}},this.$slots.default);}});
Vue.prototype.$route={name:'target_scheme_edit',params:{id:95}};
Vue.prototype.$router={push(){},replace(){}};
Vue.prototype.$Message={success(message){window.lastMessage=message;}};
window.component.template=${JSON.stringify(component.template.content)};
window.page=new Vue(window.component).$mount('#app');
</script></html>`;
const server = http.createServer((req,res) => {
    if(req.url === '/vue.js') return fs.createReadStream(path.join(app,'node_modules/vue/dist/vue.js')).pipe(res);
    if(req.url === '/xlsx.js') return fs.createReadStream(path.join(app,'node_modules/xlsx/dist/xlsx.full.min.js')).pipe(res);
    res.setHeader('Content-Type','text/html;charset=utf-8'); res.end(html);
});
function file(rows, legacy = false) {
    const book=XLSX.utils.book_new();XLSX.utils.book_append_sheet(book,XLSX.utils.aoa_to_sheet([
        legacy ? ['月份','门店ID','指标代码','对象类型','对象ID','目标值（元或个）'] : ['月份','门店名称','指标名称','目标范围','分类或商品名称','目标值'],...rows]),'目标明细');
    return {name:legacy?'旧模板.xls':'中文目标.xlsx',mimeType:'application/octet-stream',buffer:XLSX.write(book,{type:'buffer',bookType:legacy?'biff8':'xlsx'})};
}
(async()=>{
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const browser=await chromium.launch({channel:'chrome',headless:true});
    const checks=[]; const errors=[];
    try {
        const page=await browser.newPage({viewport:{width:1579,height:1076}});
        page.on('pageerror',e=>{errors.push(e.message);console.error('BROWSER_ERROR',e.message);});
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        await page.getByRole('button',{name:'下载 Excel 模板'}).waitFor();
        const downloadPromise=page.waitForEvent('download');
        await page.getByRole('button',{name:'下载 Excel 模板'}).click();
        const download=await downloadPromise;
        const downloadPath=path.join(output,'downloaded-template.xlsx');await download.saveAs(downloadPath);
        const downloaded=XLSX.readFile(downloadPath);
        assert.equal(downloaded.Sheets['目标明细'].B1.v,'门店名称');checks.push('真实下载按钮生成中文模板');
        // 直接填写刚下载的文件再导入，覆盖导出/导入双向契约。
        XLSX.utils.sheet_add_aoa(downloaded.Sheets['目标明细'],[['2026-10','扬州欧尚店','实际业绩','全店','',12345.67],['2026-10','扬州欧尚店','实际业绩','商品分类','生美',0],['2026-11','扬州欧尚店','实际业绩','商品','测试产品',100]],{origin:'A2'});
        await page.locator('input[type=file]').setInputFiles({name:'已填写模板.xlsx',mimeType:'application/octet-stream',buffer:XLSX.write(downloaded,{type:'buffer',bookType:'xlsx'})});
        await page.waitForFunction(()=>window.page.lines.length===3 && !window.page.busy);
        assert.equal(await page.locator('.scheme-error').count(),0);
        assert.equal(await page.locator('input[aria-label="第1行目标值"]').inputValue(),'12345.67');
        assert.equal(await page.locator('input[aria-label="第2行目标值"]').inputValue(),'0');
        assert.equal(await page.evaluate(()=>window.saveCalls),0);checks.push('原生文件选择成功导入三类目标，不自动保存');
        await page.screenshot({path:path.join(output,'imported-desktop.png'),fullPage:true});
        await page.getByRole('button',{name:'保存草稿',exact:true}).click();
        await page.waitForFunction(()=>window.page.detail.scheme.version===2);
        await page.reload();await page.waitForFunction(()=>window.page && window.page.lines.length===3);
        assert.equal(await page.locator('input[aria-label="第1行目标值"]').inputValue(),'12345.67');checks.push('页面保存请求/元分转换及刷新回显正确（接口夹具）');
        const before=await page.evaluate(()=>JSON.stringify(window.page.lines));
        await page.locator('input[type=file]').setInputFiles(file([['2026-10','不存在的门店','实际业绩','全店','',1]]));
        await page.getByRole('alert').waitFor();assert.match(await page.getByRole('alert').innerText(),/第2行.*门店/);
        assert.equal(await page.evaluate(()=>JSON.stringify(window.page.lines)),before);checks.push('错误文件在页面显示行号且不覆盖已有草稿');
        await page.screenshot({path:path.join(output,'invalid-import.png'),fullPage:true});
        await page.locator('input[type=file]').setInputFiles(file([['2026-12',23,'actual_performance','store',0,888]],true));
        await page.waitForFunction(()=>window.page.lines.length===1 && !window.page.busy);
        assert.equal(await page.locator('input[aria-label="第1行目标值"]').inputValue(),'888');checks.push('旧XLS文件通过原生文件选择仍可导入');
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:path.join(output,'imported-mobile.png'),fullPage:true});
        const fileBox=await page.locator('.scheme-upload').boundingBox(); assert(fileBox.width>0 && fileBox.x>=0 && fileBox.x+fileBox.width<=390);checks.push('小视口导入入口可见可操作');
        assert.deepEqual(errors,[]);checks.push('浏览器无脚本错误');
        fs.writeFileSync(path.join(output,'browser-results.json'),JSON.stringify({checks,errors,network:'isolated fixture; no production requests'},null,2));
        console.log(JSON.stringify({checks,errors}));
    } finally {await browser.close();server.close();}
})().catch(error=>{console.error(error);server.close();process.exitCode=1;});
