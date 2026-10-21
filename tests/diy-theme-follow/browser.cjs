// Local-only rendering of the actual editor/member SFCs. The adapter supplies
// uni-app tags/rpx and API stubs, never a second implementation of color logic.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { root, dependencies, component, config, mapState } = require('./source-harness.cjs');
const plugins = process.env.HBUILDERX_PLUGINS || '/Applications/HBuilderX.app/Contents/HBuilderX/plugins';
const sass = require(path.join(plugins, 'compile-dart-sass/node_modules/sass'));
const stylus = require(path.join(dependencies, 'stylus'));
const postcss = require(path.join(dependencies, 'postcss'));
const serveOnly = process.argv.includes('--serve');
const sources = Object.fromEntries(['preview', 'member'].map(key => {
  const source = component(key);
  let css = key === 'member'
    ? sass.renderSync({ data: source.sfc.styles[0].content }).css.toString()
    : stylus.render(source.sfc.styles[0].content);
  // SFC scoped-style isolation must also apply in this side-by-side fixture.
  const ast = postcss.parse(css);
  ast.walkRules(rule => { rule.selectors = rule.selectors.map(selector => (key === 'member' ? '.member ' : '.editor ') + (key === 'member' ? selector.replace(/\bimage\b/g, 'img') : selector)); });
  css = ast.toString();
  if (key === 'member') css = css.replace(/(-?[\d.]+)rpx/g, (_, value) => `calc(${value} * var(--rpx))`);
  return [key, { script: source.script, template: source.sfc.template.content, css }];
}));
const html = `<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>轮播背景与指示器验证</title><style>
*{box-sizing:border-box;letter-spacing:0}body{margin:0;background:#eef1f4;color:#222;font:14px system-ui}main{padding:16px;max-width:860px;margin:auto}h1{font-size:20px;margin:0 0 16px}h2{font-size:14px;margin:12px 0 8px}header{display:flex;gap:16px;flex-wrap:wrap;align-items:center}label{display:flex;gap:8px}select,button{padding:6px}section{margin-top:16px}.pair{display:flex;gap:24px;flex-wrap:wrap}.phone{width:375px;max-width:100%;--rpx:.5px}.member uni-view,.member uni-swiper,.member uni-swiper-item{display:block}.member .flex,.member .acea-row,.editor .acea-row{display:flex}.row-between-wrapper{justify-content:space-between;align-items:center}.row-middle{align-items:center}.row-center{justify-content:center}.row-right{justify-content:flex-end}.member .swiperBg{position:relative}.member uni-swiper{height:160px}.member uni-swiper-item:not(.active){display:none}.member img{object-fit:cover;display:block}.member .slide-image{height:160px}.member .input{display:block}.member .bg-img{pointer-events:none}.editor .homeComb{width:100%}
</style><style>${sources.preview.css}\n${sources.member.css}</style>
<main id="app"><h1>轮播背景与指示器验证</h1><header>
<label>主题<select id="theme" v-model="theme"><option value="#42CA4D">绿色</option><option value="#1DB0FC">蓝色</option><option value="#7B2941">酒红色</option></select></label>
<label>色调<select id="mode" v-model.number="mode" @change="changeMode"><option :value="0">跟随主题风格</option><option :value="1">自定义</option></select></label>
<label>组件背景<select id="gradient" v-model="gradient" @change="changeGradient"><option value="transparent">透明</option><option value="#ffffff">白色</option><option value="#2468ac">蓝色</option></select></label>
<label>组件宽度<select id="width" v-model.number="width"><option :value="375">375</option><option :value="320">320</option></select></label>
<button id="next-slide" @click="nextSlide">下一张</button><button id="verify" @click="verify">验证</button>
<button id="save" @click="save">保存测试草稿</button></header><output id="result">待验证</output><output id="last-target"></output>
<section v-for="(item,i) in configs" :key="i"><h2>样式 {{i+1}}</h2><div class="pair">
<div class="phone editor" :style="{width:width+'px'}"><editor-preview :num="i" :color-style="{theme:theme}"></editor-preview></div>
<div class="phone member" :style="{'--view-theme':theme,'--rpx':width/750+'px',width:width+'px'}"><member-preview :data-config="item" :special="1"></member-preview></div>
</div></section></main><script src="/vue.js"></script><script>
const sources=${JSON.stringify(sources)};
const uni={getSystemInfoSync:()=>({statusBarHeight:0}),getWindowInfo:()=>({windowWidth:375}),getSystemInfo:({success})=>success({windowWidth:375}),getImageInfo:({success})=>success({width:750,height:320}),setStorageSync(){}};
const locationMixin={props:['storeInfor'],methods:{selfLocation(){},chooseLocation(){}}};
const mapState=${mapState.toString()};
const configs=JSON.parse(localStorage.getItem('diy-indicator-synthetic-draft')||'null')||${JSON.stringify([0, 1, 2, 3].map(index => { const data = config(); data.docConfig.tabVal = index; return data; }))};
Vue.prototype.$store={state:{admin:{mobildConfig:{defaultArray:configs}}}};
Vue.prototype.$util={JumpPath(value){window.lastTarget=value;document.getElementById('last-target').textContent=value;}};
Vue.config.ignoredElements=['uni-view','uni-swiper','uni-swiper-item','block'];
for(const key of ['preview','member']){
  const source=sources[key],options=new Function('uni','location','mapState',source.script)(uni,locationMixin,mapState);
  // Model only uni-app's tag conversion and rpx units, not computed colors.
  let template=source.template;
  if(key==='member'){
    const tags={view:'uni-view',text:'span',swiper:'uni-swiper','swiper-item':'uni-swiper-item',image:'img',navigator:'a'};
    template=template.replace(/(<\\/?)(view|text|swiper-item|swiper|image|navigator)(?=[\\s>])/g,(_,prefix,tag)=>prefix+tags[tag]);
    const convert=value=>typeof value==='string'?value.replace(/(-?[\\d.]+)rpx/g,(_,n)=>'calc('+n+' * var(--rpx))'):value&&typeof value==='object'?Object.fromEntries(Object.entries(value).map(([k,v])=>[k,convert(v)])):value;
    for(const name of ['progressWidth','progressValue','imageStyle']){const fn=options.computed[name];options.computed[name]=function(){return convert(fn.call(this));};}
  }
  Vue.component(key==='preview'?'editor-preview':'member-preview',Object.assign(options,Vue.compile(template)));
}
// Only test controls mutate synthetic local drafts. The renderer, gradients and
// slide changes still execute the actual canonical component implementation.
window.fixture=new Vue({el:'#app',data:{configs,theme:'#42CA4D',mode:configs[0].toneConfig.tabVal,gradient:configs[0].gradientColor.color[0].item,width:375},methods:{
changeMode(){for(const item of this.configs)item.toneConfig.tabVal=this.mode;},
changeGradient(){for(const item of this.configs)item.gradientColor.color[0].item=this.gradient;},
nextSlide(){for(const child of this.$children)if(child.$options.name==='homeComb')child.swiperChange({detail:{current:(child.swiperCur+1)%child.imgUrls.length,source:'touch'}});},
verify(){this.$nextTick(()=>{
const expected=this.mode===1?'rgb(233, 51, 35)':{'#42CA4D':'rgb(66, 202, 77)','#1DB0FC':'rgb(29, 176, 252)','#7B2941':'rgb(123, 41, 65)'}[this.theme];
const rows=[...document.querySelectorAll('section')].map((section,index)=>{
const member=section.querySelector('.member .page_count'),editor=section.querySelector('.editor .homeComb');
const selected=member.querySelector(index===2?'.dot3 .active':'.dot.active');
const g=getComputedStyle(member.querySelector('.bag-gradient')),eg=getComputedStyle(editor.querySelector('.bag-gradient'));
return {style:index,width:member.getBoundingClientRect().width,overflow:member.scrollWidth>member.clientWidth,
memberOpacity:getComputedStyle(member.querySelector('.bg-img')).opacity,editorOpacity:getComputedStyle(editor.querySelector('.bgImg')).opacity,
moduleBackground:getComputedStyle(member).backgroundColor,gradient:g.backgroundImage,editorGradient:eg.backgroundImage,
active:getComputedStyle(selected).backgroundColor,searchColor:getComputedStyle(member.querySelector('.title')).color,
image:member.querySelector('.bg-img img').getAttribute('src')};});
const pass=rows.every(row=>row.memberOpacity==='1'&&row.editorOpacity==='1'&&row.moduleBackground==='rgba(0, 0, 0, 0)'&&row.gradient===row.editorGradient&&row.active===expected&&row.width===this.width&&!row.overflow&&row.searchColor==='rgb(92, 61, 69)');
document.getElementById('result').textContent=JSON.stringify({pass,theme:this.theme,mode:this.mode,width:this.width,background:this.gradient,rows});
});},save(){localStorage.setItem('diy-indicator-synthetic-draft',JSON.stringify(this.configs));}}});
</script></html>`;
const server = http.createServer((req, res) => {
  if (req.url === '/vue.js') { res.setHeader('Content-Type', 'text/javascript'); return res.end(fs.readFileSync(path.join(dependencies, 'vue/dist/vue.js'))); }
  if (req.url === '/asset.png') {
    const image = process.env.DIY_THEME_TEST_IMAGE || path.join(root, '前端代码/uniapp/static/img/rank1_icon.png');
    res.setHeader('Content-Type', /\.jpe?g$/i.test(image) ? 'image/jpeg' : 'image/png');
    return res.end(fs.readFileSync(image));
  }
  if (req.url === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(html); }
  if (req.url === '/favicon.ico') { res.writeHead(204); return res.end(); }
  res.writeHead(404); res.end();
});
async function run() {
  const { chromium } = require(process.env.PLAYWRIGHT_MODULE || '/tmp/node_modules/playwright');
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const origin = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
  const evidence = path.join(__dirname, 'evidence'); fs.mkdirSync(evidence, { recursive: true });
  const errors = [], results = [];
  try {
    const page = await browser.newPage({ viewport: { width: 1000, height: 1200 } });
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('pageerror', error => { errors.push(error.message); console.error(error.message); });
    await page.route('**/*', route => route.request().url().startsWith(origin + '/') ? route.continue() : route.abort());
    await page.goto(origin);
    await page.waitForSelector('.member .dot1 .dot.active', { state: 'attached', timeout: 5000 });
    await page.waitForFunction(() => [...document.querySelectorAll('.member .page_count')].every(node => node.__vue__.imageH > 0));
    async function check(active, inactive) {
      const colors = await page.evaluate(() => [...document.querySelectorAll('section')].map((section, index) => {
        const member = section.querySelector('.member');
        const selected = member.querySelector(index === 2 ? '.dot3 .active' : '.dot.active');
        const regular = member.querySelector(index === 2 ? '.dot3 .dot' : '.dot:not(.active)');
        const editor = section.querySelector('.editor .dot-item,.editor .line-dot .item');
        return { active: getComputedStyle(selected).backgroundColor, inactive: getComputedStyle(regular).backgroundColor, editor: getComputedStyle(editor).backgroundColor };
      }));
      for (const row of colors) { assert.equal(row.active, active); assert.equal(row.inactive, inactive); assert.equal(row.editor, active); }
      results.push(colors);
    }
    await check('rgb(66, 202, 77)', 'rgb(221, 221, 221)');
    await page.selectOption('#theme', '#1DB0FC'); await check('rgb(29, 176, 252)', 'rgb(221, 221, 221)');
    await page.selectOption('#mode', '1'); await check('rgb(233, 51, 35)', 'rgb(16, 32, 48)');
    await page.selectOption('#theme', '#7B2941'); await check('rgb(233, 51, 35)', 'rgb(16, 32, 48)');
    await page.click('#save'); await page.reload(); await check('rgb(233, 51, 35)', 'rgb(16, 32, 48)');
    await page.selectOption('#mode', '0'); await page.selectOption('#theme', '#7B2941');
    await check('rgb(123, 41, 65)', 'rgb(221, 221, 221)');
    for (const width of [375, 320]) {
      await page.selectOption('#width', String(width));
      await page.screenshot({ path: path.join(evidence, `theme-${width}.png`), fullPage: true });
      const boxes = await page.locator('.member .page_count').evaluateAll(nodes => nodes.map(node => {
        const rootBox = node.getBoundingClientRect(), dot = node.querySelector('.dot').getBoundingClientRect();
        return { width: rootBox.width, height: rootBox.height, overflow: node.scrollWidth > node.clientWidth, dotVisible: dot.height > 0 && dot.top >= rootBox.top && dot.bottom <= rootBox.bottom };
      }));
      assert(boxes.every(box => box.width === width && box.height > 150 && box.dotVisible && !box.overflow));
      await check('rgb(123, 41, 65)', 'rgb(221, 221, 221)');
    }
    await page.locator('.member .slide-navigator').first().click();
    assert.equal(await page.evaluate(() => window.lastTarget), '/test-link-0');
    assert.deepEqual(errors, []);
    fs.writeFileSync(path.join(evidence, 'browser-result.json'), JSON.stringify({ pass: true, scope: 'canonical component fixture, not full production deployment or mini-program runtime', cases: results.length, indicatorsPerCase: 4, widths: [375, 320], browserErrors: errors }, null, 2) + '\n');
    console.log('PASS: actual editor/member rendering, 3 theme colors, custom colors, reactive switching, saved JSON reload, 4 indicators, 320/375px, slide navigation; no remote API calls.');
  } finally { await browser.close(); }
}
if (serveOnly) {
  // The in-app browser can drive the same fixture without a separate automation
  // browser or production requests; shut down explicitly after verification.
  server.listen(0, '127.0.0.1', () => console.log('FIXTURE_URL=http://127.0.0.1:' + server.address().port));
} else run().catch(error => { console.error(error); process.exitCode = 1; }).finally(() => server.close());
