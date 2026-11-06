/* Actual local cashier UI: one card price, independent times, no business checkout. */
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), assert = require('assert/strict');
const { chromium } = require('/Applications/ChatGPT.app/Contents/Resources/cua_node/lib/node_modules/playwright');
const root = path.resolve(__dirname, '../../..');
const evidence = path.resolve(root, '../任务管理/验收证据/R47-普通卡单一总价-2026-11-05');
const fixture = fs.readFileSync(path.join(root, 'tests/cashier-v3/php/r47-project-purchase-browser-fixture.php'), 'utf8');
const tag = 'R47UI' + crypto.randomBytes(6).toString('hex'), password = crypto.randomBytes(15).toString('hex');
const run = mode => cp.execFileSync('docker', ['exec', '-i', '-e', 'R47_LOCAL_BROWSER=1', '-e', 'R47_MODE=' + mode,
  '-e', 'R47_TAG=' + tag, '-e', 'R47_PASSWORD=' + password, 'mohe-cashier-app', 'php'], { input: fixture, encoding: 'utf8' });
const local = code => cp.execFileSync('docker', ['exec', '-i', 'mohe-cashier-app', 'php'], { encoding: 'utf8',
  input: "<?php require '/var/www/html/vendor/autoload.php';$app=new think\\App('/var/www/html/');$app->initialize();$c=think\\facade\\Db::connect()->getConfig();if($c['hostname']!=='mysql'||$c['database']!=='ruihao')throw new RuntimeException('local only');" + code });
(async () => {
  let created, before, browser, page; const checks = [];
  try {
    created = JSON.parse(run('setup').trim());
    before = JSON.parse(local("$q=think\\facade\\Db::name('system_config')->where('menu_name','cashier_ordinary_card_sale_edit_switch')->where('is_store',0);echo json_encode($q->value('value'));$q->update(['value'=>'1']);mohe\\services\\SystemConfigService::clear();"));
    fs.writeFileSync(path.join(evidence, 'browser-switch-before.json'), JSON.stringify(before));
    // Add only the ordinary-card display capability to this disposable position.
    local("$id=think\\facade\\Db::name('position')->where('name','" + tag + "')->value('id');think\\facade\\Db::name('job_position_channel_rule')->where('position_id',$id)->update(['rules'=>'301010,301012,301006,301032,301053']);");
    browser = await chromium.launch({ headless: true, channel: 'chrome' });
    page = await browser.newPage({ viewport: { width: 1475, height: 994 } }); page.setDefaultTimeout(10000);
    await page.goto('http://127.0.0.1:18091/view_cashier_v3/#/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[autocomplete=username]').fill(tag);
    await page.locator('input[autocomplete=current-password]').fill(password);
    await page.getByRole('button', { name: '登录', exact: true }).click();
    await page.waitForURL(url => url.hash.includes('/cashier'), { waitUntil: 'domcontentloaded' });
    await page.getByTestId('cashier-workflow-member').click();
    const memberDialog = page.getByRole('dialog', {name:'选择会员',exact:true});
    await memberDialog.locator('input').first().fill(tag);
    await memberDialog.getByRole('button', {name:/查询/}).click();
    await memberDialog.getByRole('button', {name:'选择',exact:true}).first().click();
    await memberDialog.waitFor({state:'hidden'});
    await page.getByRole('button', {name:'疗程卡',exact:true}).click();
    const card = page.locator('.product-card').filter({ hasText: '东方熏蒸' }).filter({ hasText: '5,800' });
    await card.click();
    const dialog = page.getByRole('dialog', { name: '确认卡项内容', exact: true });
    await dialog.getByRole('button', { name: '修改', exact: true }).click();
    const price = dialog.getByLabel('整卡售价（元）', { exact: true });
    assert.equal(await price.inputValue(), '5800');
    assert.equal(await dialog.getByText('项目总金额（元）', { exact: true }).count(), 0);
    const rows = dialog.locator('.ordinary-card-sale-editor__row');
    assert.equal(await rows.count(), 3);
    for (const row of await rows.all()) assert.equal(await row.locator('input').count(), 1);
    checks.push('one whole-card price; every project has only a times input');
    await rows.first().getByLabel('次数', { exact: true }).fill('20');
    await price.fill('6000');
    await page.screenshot({ path: path.join(evidence, 'one-price-editor.png') });
    await dialog.getByRole('button', { name: '保存修改', exact: true }).click();
    await dialog.getByText('整卡售价：¥6,000', { exact: true }).waitFor();
    assert.match(await dialog.innerText(), /20 次/);
    checks.push('save keeps one 6000-yuan price and changed project times');
    await dialog.getByRole('button', { name: '修改', exact: true }).click();
    assert.equal(await price.inputValue(), '6000');
    assert.equal(await rows.first().getByLabel('次数', { exact: true }).inputValue(), '20');
    await price.fill('5799');
    await dialog.getByRole('button', { name: '保存修改', exact: true }).click();
    await dialog.getByRole('alert').filter({hasText:'整卡售价不能低于原配置5800元'}).waitFor();
    checks.push('original whole-card price lower bound is still enforced');
    await price.fill('6000.5');
    await dialog.getByRole('button', { name: '保存修改', exact: true }).click();
    await dialog.getByRole('alert').filter({hasText:'金额和次数必须填写整数'}).waitFor();
    checks.push('fractional card total is rejected');
    await dialog.getByRole('button', { name: '取消修改', exact: true }).click();
    await dialog.getByText('整卡售价：¥6,000', { exact: true }).waitFor();
    await dialog.getByRole('button', { name: '修改', exact: true }).click();
    assert.equal(await price.inputValue(), '6000');
    await dialog.getByRole('button', { name: '保存修改', exact: true }).click();
    await dialog.getByRole('button', { name: '确认加入购物车', exact: true }).click();
    await page.locator('.cart-line').waitFor();
    assert.match(await page.locator('.cart-line').innerText(), /6,?000/);
    await page.screenshot({ path: path.join(evidence, 'one-price-cart.png') });
    checks.push('cancel/reopen/repeated save preserve the same total and cart shows 6000');
    fs.writeFileSync(path.join(evidence, 'browser.json'), JSON.stringify({ status: 'PASS', checks, actualCheckoutSubmitted: false }, null, 2));
    console.log(JSON.stringify({status:'PASS',checks}));
  } catch (e) {
    if (page) { await page.screenshot({path:path.join(evidence,'browser-error.png')}).catch(()=>{});fs.writeFileSync(path.join(evidence,'browser-error.txt'), await page.locator('body').innerText().catch(()=>'')); }
    throw e;
  } finally {
    // Restore only the one local switch; no real employee or catalogue is changed.
    if (before !== undefined) local("think\\facade\\Db::name('system_config')->where('menu_name','cashier_ordinary_card_sale_edit_switch')->where('is_store',0)->update(['value'=>" + (before === null ? 'null' : "'" + String(before).replace(/'/g,"''") + "'") + "]);mohe\\services\\SystemConfigService::clear();");
    if (created) run('cleanup');
    if (browser) await Promise.race([browser.close(), new Promise(resolve => setTimeout(resolve, 5000))]);
  }
})().catch(e => { console.error(e.stack); process.exitCode=1; });
