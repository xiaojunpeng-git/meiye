// Shared reward modal executes both survey and activity draft modes; no network or business writes.
const fs = require('fs'), path = require('path'), vm = require('vm');
const root = path.resolve(__dirname, '../..');
const compiler = require(path.join(root, '前端代码/admin/node_modules/vue-template-compiler'));
const parser = require(path.join(root, '前端代码/admin/node_modules/@babel/parser'));
const files = ['src/components/memberSurveyReward/index.vue', 'src/pages/marketing/storeCombination/create.vue', 'src/pages/marketing/storeCombination/combinaList.vue'];
function check(ok, label) { if (!ok) throw Error(label); console.log('PASS ' + label); }
for (const relative of files) {
  const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, '前端代码/admin', relative), 'utf8'));
  const result = compiler.compile(sfc.template.content);
  check(!result.errors.length, relative + ' template ' + result.errors.join('; '));
  parser.parse(sfc.script.content, { sourceType: 'module', plugins: ['objectRestSpread'] });
  check(true, relative + ' script');
}
const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, '前端代码/admin', files[0]), 'utf8'));
let surveyReads = 0, surveyWrites = 0, payload;
const context = { module: { exports: {} }, goodsAttr: {}, couponList: {},
  surveyGifts: async id => { surveyReads++; return { data: { product: [], coupon: [{ id: 2, num: 1, write_days: 30 }] } }; },
  surveyUpdateGifts: async (id, data) => { surveyWrites++; payload = data; }, surveyGiftCatalog: async (store, filters) => ({ store, filters }),
  guideRequired: () => { throw Error('unexpected field guidance'); }, clearRequiredGuidance() {},
};
vm.createContext(context);
vm.runInContext(sfc.script.content.replace(/^import .*?;\s*$/gm, '').replace('export default', 'module.exports ='), context);
const component = context.module.exports;
function instance(props) {
  const emitted = [];
  const value = { ...component.data(), visible: true, ...props, $emit: (...args) => emitted.push(args) };
  Object.entries(component.methods).forEach(([name, fn]) => value[name] = fn.bind(value));
  Object.defineProperty(value, 'rows', { get: () => component.computed.rows.call(value) });
  value.emitted = emitted; return value;
}
(async () => {
  const input = { enabled: true, product: [{ id: 11, num: 1, write_days: 7, product_type: 6 }], coupon: [] };
  const activity = instance({ draftMode: true, projectOnly: true, configuration: input });
  await activity.load(); activity.form.product[0].num = 2;
  check(input.product[0].num === 1 && surveyReads === 0, '活动奖励编辑草稿不改父表单、不请求调查接口');
  activity.addProducts([{ id: 10, product_type: 0, attrValue: [{ id: 12 }] }]);
  check(activity.form.product.length === 1, '拼团仅接收项目与优惠券');
  await activity.save();
  check(surveyWrites === 0 && activity.emitted.some(([name, data]) => name === 'configured' && data.product[0].num === 2), '活动赠送配置只交给活动保存事务');
  const survey = instance({ surveyId: 33, draftMode: false, projectOnly: false });
  await survey.load(); survey.addProducts([{ id: 10, product_type: 0, store_name: '旧调查实物奖励', attrValue: [{ id: 12 }] }]); await survey.save();
  check(surveyReads === 1 && surveyWrites === 1 && payload.sendAll.product[0].product_type === 0, '调查原读写接口与实物奖励保持可用');
  check(payload.sendAll.store_policy === 'member' && payload.sendAll.reward_each_round === true, '调查每轮奖励与会员门店策略保持');
  const provider = instance({ catalogProvider: async filters => ({ custom: true, filters }) });
  check((await provider.fetchProducts({ page: 2 })).filters.page === 2, '活动选品复用组件并保持分页查询');
  // 执行真实活动页加载方法，复现接口 500/网络错误缺少 msg 时的用户提示，防止 iView 再抛异常。
  const activityScript = compiler.parseComponent(fs.readFileSync(path.join(root, '前端代码/admin', files[1]), 'utf8')).script.content;
  const activityAst = parser.parse(activityScript, { sourceType: 'module', plugins: ['objectRestSpread'] });
  const activityOptions = activityAst.program.body.find(node => node.type === 'ExportDefaultDeclaration').declaration;
  const loadNode = activityOptions.properties.find(node => node.key.name === 'methods').value.properties.find(node => node.key.name === 'getInfo');
  for (const rejection of [undefined, new Error('网络连接失败'), { msg: '拼团不存在' }]) {
    let shown;
    const page = { spinShow: false, $route: { params: { id: 476 } }, $Message: { error(message) {
      if (typeof message !== 'string' || !message) throw Error('提示必须为非空字符串'); shown = message;
    } } };
    const getInfo = vm.runInNewContext('({' + activityScript.slice(loadNode.start, loadNode.end) + '}).getInfo', {
      combinationInfoApi: () => Promise.reject(rejection),
    });
    getInfo.call(page);
    await new Promise(resolve => setImmediate(resolve));
    check(page.spinShow === false && !!shown, '活动加载失败结束加载状态并显示明确原因：' + (shown || 'missing'));
  }
  console.log('PASS R44 reward UI contracts');
})().catch(error => { console.error(error); process.exitCode = 1; });
