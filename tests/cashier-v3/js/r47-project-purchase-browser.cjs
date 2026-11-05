/* Actual local Vue/iView browser flow. Only disposable identities and local config; no order is submitted. */
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), assert = require('assert/strict');
const root = path.resolve(__dirname, '../../..');
const { chromium } = require('/Applications/ChatGPT.app/Contents/Resources/cua_node/lib/node_modules/playwright');
const evidence = path.resolve(root, '../任务管理/验收证据/R47-购买项目自动核销开关-2026-11-05');
const fixture = fs.readFileSync(path.join(root, 'tests/cashier-v3/php/r47-project-purchase-browser-fixture.php'), 'utf8');
const tag = 'R47UI' + crypto.randomBytes(6).toString('hex'), password = crypto.randomBytes(15).toString('hex');
const run = (mode) => cp.execFileSync('docker', ['exec', '-i', '-e', 'R47_LOCAL_BROWSER=1', '-e', 'R47_MODE=' + mode,
  '-e', 'R47_TAG=' + tag, '-e', 'R47_PASSWORD=' + password, 'mohe-cashier-app', 'php'], { input: fixture, encoding: 'utf8' });
const checks = [];
(async () => {
  const created = JSON.parse(run('setup').trim());
  let browser;
  try {
    browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const admin = await context.newPage(); admin.setDefaultTimeout(8000);
    await admin.goto('http://localhost:18081/admin/login', { waitUntil: 'domcontentloaded', timeout: 20000 });
    await admin.getByPlaceholder('请输入用户名', { exact: true }).fill(tag + 'admin');
    await admin.getByPlaceholder('请输入密码', { exact: true }).first().fill(password);
    await admin.getByRole('button', { name: '登录', exact: true }).click();
    await admin.waitForURL(url => !url.pathname.includes('/login'), { timeout: 20000, waitUntil: 'domcontentloaded' });
    await admin.goto('http://localhost:18081/admin/setting/shop/base', { waitUntil: 'domcontentloaded', timeout: 20000 });
    await admin.locator('.ivu-tabs-tab').filter({ hasText: '门店设置' }).click();
    const row = admin.locator('.ivu-form-item').filter({ hasText: '门店端购买项目自动核销' });
    assert.match(await row.locator('.ivu-switch').getAttribute('class'), /ivu-switch-checked/);
    checks.push('native configuration defaults on');
    await row.scrollIntoViewIfNeeded(); await admin.screenshot({ path: path.join(evidence, 'setting-default-on.png') });
    const page = await context.newPage(); page.setDefaultTimeout(8000);
    await page.goto('http://localhost:18091/view_cashier_v3/#/login', { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.locator('input[autocomplete=username]').fill(tag);
    await page.locator('input[autocomplete=current-password]').fill(password);
    await page.getByRole('button', { name: '登录', exact: true }).click();
    await page.waitForURL(url => url.hash.includes('/cashier'), { timeout: 20000, waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: '单次项目', exact: true }).click();
    await page.getByRole('button', { name: /八髎养臀/ }).click();
    await page.screenshot({ path: path.join(evidence, 'store-project-selected.png') });
    fs.writeFileSync(path.join(evidence, 'browser-selection.txt'), await page.locator('body').innerText());
    // Product option dialogs are genuine UI steps; confirm if the catalog asks for one.
    const add = page.getByRole('button', { name: '确认加入购物车', exact: true });
    if (await add.isVisible()) await add.click();
    await page.locator('.cart-line').waitFor();
    assert.match(await page.locator('.cart-line').innerText(), /手艺人/);
    checks.push('enabled mode keeps original service controls');
    await row.locator('.ivu-switch').click();
    const saved = admin.waitForResponse(response => response.url().includes('save_basics') && response.request().method() === 'POST');
    await admin.getByRole('button', { name: '提交', exact: true }).click();
    assert.equal((await (await saved).json()).status, 200);
    await admin.reload({waitUntil:'domcontentloaded'}); await admin.locator('.ivu-tabs-tab').filter({ hasText: '门店设置' }).click();
    assert.doesNotMatch(await row.locator('.ivu-switch').getAttribute('class'), /ivu-switch-checked/);
    checks.push('native switch saves to database and survives reload');
    await page.reload({waitUntil:'domcontentloaded'});
    if (await page.locator('.cart-line').count() === 0) {
      await page.getByRole('button', { name: '单次项目', exact: true }).click();
      await page.getByRole('button', { name: /八髎养臀/ }).click();
      if (await add.isVisible()) await add.click();
    }
    await page.locator('.cart-line').waitFor();
    assert.doesNotMatch(await page.locator('.cart-line').innerText(), /待选择手艺人|手艺人不算人数人次/);
    checks.push('disabled purchase hides only premature service controls');
    await page.getByRole('button', { name: /^(立即结账|确认收款)$/ }).click();
    await page.getByRole('dialog', { name: '选择会员', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: '游客', exact: true }).count(), 0);
    await page.locator('[data-field="required-member"]').waitFor();
    await page.getByRole('status').filter({hasText:'请在这里选择会员，以保存待用项目权益'}).waitFor({timeout:15000});
    await page.screenshot({ path: path.join(evidence, 'member-required.png') });
    checks.push('guest checkout opens member selection and disallows ownerless rights');
    await page.locator('[data-field="required-member"]').fill(tag);
    await page.getByRole('button', { name: /查询/ }).last().click();
    await page.getByRole('button', { name: '选择', exact: true }).first().click();
    await page.getByRole('dialog', { name: '选择会员', exact: true }).waitFor({ state: 'hidden' });
    await page.locator('.cart-line').waitFor();
    assert.match(await page.locator('.cart-line').innerText(), /八髎养臀/);
    await page.screenshot({ path: path.join(evidence, 'deferred-project-cart.png') });
    checks.push('member can be selected without losing the pending purchased project');
    await page.getByRole('button', {name:'销售人',exact:true}).click();
    const personnel = page.getByRole('dialog', {name:'业绩分配',exact:true});
    await personnel.waitFor();
    assert.equal(await personnel.getByRole('button', {name:'手艺人',exact:true}).count(), 0);
    await personnel.getByRole('button', {name:'取消',exact:true}).click();
    checks.push('sales assignment stays available without requiring premature craftsmen');

    fs.writeFileSync(path.join(evidence, 'browser.json'), JSON.stringify({ status: 'PASS', checks, actualCheckoutSubmitted: false }, null, 2));
    console.log(JSON.stringify({ status: 'PASS', checks }));
  } catch (error) {
    if (browser) {
      for (const [i, page] of browser.contexts().flatMap(c => c.pages()).entries()) {
        await page.screenshot({path:path.join(evidence, 'browser-error-'+i+'.png')}).catch(()=>{});
        fs.writeFileSync(path.join(evidence, 'browser-error-'+i+'.txt'), await page.locator('body').innerText().catch(()=>''));
      }
    }
    fs.writeFileSync(path.join(evidence, 'browser.json'), JSON.stringify({ status: 'FAIL', checks, message: error.message }, null, 2));
    throw error;
  } finally {
    if (browser) await browser.close();
    // Restore only the switch touched by this UI test; never restore a whole settings table.
    cp.execFileSync('docker', ['exec', '-i', '-e', 'R47_RESTORE=' + created.switchBefore, 'mohe-cashier-app', 'php'], {
      input: "<?php require '/var/www/html/vendor/autoload.php';$app=new think\\App('/var/www/html/');$app->initialize();$c=think\\facade\\Db::connect()->getConfig();if($c['hostname']!=='mysql'||$c['database']!=='ruihao')throw new RuntimeException('local only');think\\facade\\Db::name('system_config')->where('menu_name','cashier_project_auto_complete_switch')->where('is_store',0)->update(['value'=>getenv('R47_RESTORE')]);mohe\\services\\SystemConfigService::clear();",
      encoding: 'utf8'
    });
    run('cleanup');
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
