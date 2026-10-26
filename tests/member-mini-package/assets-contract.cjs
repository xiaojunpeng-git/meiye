const fs = require('fs'), path = require('path'), vm = require('vm'), assert = require('assert');
const root = path.resolve(__dirname, '../..');
const read = name => fs.readFileSync(path.join(root, '前端代码/uniapp', name), 'utf8');
const loadHelper = (file, name) => vm.runInNewContext(read(file).replace('export function', 'function') + '\n' + name);
const resolve = loadHelper('utils/memberAssetUrl.js', 'resolveServerAssetUrl');
const loadFont = loadHelper('utils/memberIconFont.js', 'loadMemberIconFont');
async function main() {
  for (const origin of ['https://008.cc3798.com', 'https://rh.cc3798.com', 'http://127.0.0.1:8080']) {
    assert.strictEqual(resolve('/static/mall/a.png?q=1', origin + '/'), origin + '/static/mall/a.png?q=1');
    for (const value of ['', null, undefined, '/static/img/local.png', 'https://cdn.example/a.png',
      'data:image/png;base64,abc', 'wxfile://tmp/a.png', '//cdn.example/a.png', '/pages/index/index']) {
      assert.strictEqual(resolve(value, origin), value);
    }
  }
  for (const origin of ['', undefined, 'javascript:bad', 'https://008.cc3798.com/admin', 'https://host/path']) {
    assert.strictEqual(resolve('/static/mall/a.png', origin), '/static/mall/a.png');
    assert.strictEqual(await loadFont(() => { throw Error('Must not call an invalid host'); }, origin), false);
  }
  for (const origin of ['https://008.cc3798.com', 'https://rh.cc3798.com']) {
    let calls = 0;
    assert.strictEqual(await loadFont(options => {
      calls++;
      assert.strictEqual(options.global, true);
      assert.strictEqual(options.family, 'iconfont');
      assert.strictEqual(options.source, 'url("' + origin + '/static/iconfont/iconfont.woff2")');
      options.success();
    }, origin), true);
    assert.strictEqual(calls, 1);
  }
  assert.strictEqual(await loadFont(undefined, 'https://008.cc3798.com'), false);
  assert.strictEqual(await loadFont(options => options.fail(), 'https://008.cc3798.com'), false);
  assert.strictEqual(await loadFont(() => { throw Error('SDK failure'); }, 'https://008.cc3798.com'), false);
  // Compile actual conditional source for both targets: only Weixin removes
  // package-local @font-face and starts the global customer-scoped loader.
  const preprocess = require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules/@dcloudio/vue-cli-plugin-uni/packages/webpack-preprocess-loader/preprocess/lib/preprocess').preprocess;
  for (const weixin of [true, false]) {
    const flags = weixin ? { MP_WEIXIN: true } : { H5: true };
    const css = preprocess(read('static/iconfont/iconfont.css'), flags, { type: 'css' });
    assert.strictEqual(css.includes('@font-face'), !weixin);
    const appScript = read('App.vue').split('<script>')[1].split('</script>')[0];
    const script = preprocess(appScript, flags, { type: 'js' });
    assert.strictEqual(script.includes('loadMemberIconFont'), weixin);
    const component = read('components/easy-loadimage/easy-loadimage.vue').split('<script>')[1].split('</script>')[0];
    const compiled = preprocess(component, flags, { type: 'js' });
    assert.strictEqual(compiled.includes('return resolveServerAssetUrl(this.imageSrc'), weixin);
    assert.strictEqual(compiled.includes('return this.imageSrc;'), !weixin);
  }
  console.log(JSON.stringify({ result: 'PASS', customerOrigins: 3, unchangedUrlKinds: 9,
    globalFontSuccessAndFailureCovered: true, noCustomerFallback: true, h5FontAndImageDeliveryUnchanged: true }));
}
main().catch(error => { console.error(error); process.exit(1); });
