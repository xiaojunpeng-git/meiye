// Generate a disposable visual fixture from the real header/picker templates,
// styles and Vue methods. No production API or customer data is used.
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const cli = process.env.UNIAPP_CLI_ROOT || '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli';
const bundled = createRequire(path.join(cli, 'package.json'));
const compiler = bundled('vue-template-compiler');
const parser = bundled('@babel/parser');
const generate = bundled('@babel/generator').default;
const sass = require(path.join(cli, '../compile-dart-sass/node_modules/sass'));
const source = fs.readFileSync(path.resolve(__dirname, '../../前端代码/uniapp/pages/activity/reservation/index.vue'), 'utf8');
const sfc = compiler.parseComponent(source);
const templateSource = sfc.template.content.trim();
const templateAst = compiler.compile(templateSource, { outputSourceRange: true }).ast;
const header = templateAst.children.find(node => node.staticClass === '"booking-header"');
const picker = templateAst.children.find(node => node.if === 'addonPickerVisible');
if (!header || !picker) throw new Error('Real reservation header or add-on picker missing');
const template = '<view class="booking-page">' + [header, picker]
  .map(node => templateSource.slice(node.start, node.end)).join('') + '</view>';
// uni-app's H5 compiler maps platform tags to HTML custom elements. Mirror
// only that tag adaptation here so Vue does not treat <view> as an SVG node.
const compiled = compiler.compile(template, { modules: [{ preTransformNode(node) {
  if (['view', 'text', 'scroll-view'].includes(node.tag)) node.tag = `uni-${node.tag}`;
} }] });
if (compiled.errors.length) throw new Error(compiled.errors.join('\n'));
const ast = parser.parse(sfc.script.content, { sourceType: 'module', plugins: ['optionalChaining', 'nullishCoalescingOperator'] });
const component = ast.program.body.find(node => node.type === 'ExportDefaultDeclaration').declaration;
const select = (section, names) => {
  const properties = component.properties.find(node => node.key.name === section).value.properties;
  return names.map(name => generate(properties.find(node => node.key.name === name)).code).join(',');
};
const methods = select('methods', ['openAddonPicker', 'loadAddonPickerOptions', 'parsePurchasedAddonOptions',
  'parseCartAddonRows', 'buildServiceLabel', 'confirmAddonPicker', 'toggleAddonItem', 'isAddonSelected']);
const computed = select('computed', ['selectedItems', 'totalServiceDuration', 'filteredAddonPickerOptions']);
const css = sass.renderSync({ data: sfc.styles.map(style => style.content).join('\n') }).css.toString()
  .replace(/(-?\d*\.?\d+)rpx\b/g, (_, number) => `${Number(number) / 2}px`);
const vue = fs.readFileSync(path.join(cli, 'node_modules/vue/dist/vue.min.js'), 'utf8');
const output = '/tmp/m008-historical-addon-visual-20261019.html';
fs.writeFileSync(output, `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>历史权益加项本地隔离预览</title>
<style>body{margin:0;background:#fbf8f6;font-family:Arial,sans-serif}.booking-page{max-width:375px;margin:24px auto}uni-view,uni-scroll-view{display:block}uni-text{display:inline}.acea-row{display:flex}.row-between-wrapper{justify-content:space-between;align-items:center}${css}</style>
</head><body><div id="app"></div><script>${vue}</script><script>
const resolveProjectDuration = value => Number(value) > 0 ? Number(value) : 60;
const resolveAddonDuration = value => Number(value) > 0 ? Number(value) : 30;
const getUserPurchasedRemainItems = () => Promise.resolve({data:{list:[{order:{id:1,type:0},cart_info:[
 {id:2,product_id:2,product_type:6,is_writeoff:0,write_surplus_times:2,cart_info:{productInfo:{store_name:'本地测试加项',addon_service_duration:30,attrInfo:{}}}}
]}]}});
new Vue({el:'#app',data(){return {historicalStorePicker:true,storeId:11,orderId:1,cartInfoId:1,
mainItemLabel:'本地测试主项目',projectServiceDuration:60,addonServiceDuration:30,addonItems:[],
addonPickerVisible:false,addonPickerLoading:false,addonPickerOptions:[],addonPickerSelected:[],
addonPickerSearch:'',addonPickerTab:1,selectedTimeRange:null};},computed:{${computed}},
methods:{${methods},buildBookUidParams(value){return value},loadStaffList(){return Promise.resolve()}},
render:new Function(${JSON.stringify(compiled.render)}),staticRenderFns:${JSON.stringify(compiled.staticRenderFns)}.map(code=>new Function(code))});
</script></body></html>`);
console.log(output);
