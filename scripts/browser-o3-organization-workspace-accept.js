/**
 * O3 workspace browser accept — result assertions @ 1440/1024/820
 * Login: createToken payload (/tmp/o3_login_payload.json). Never changes passwords.
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const BASE = process.env.O3_BASE || 'http://127.0.0.1:18081';
const PAGE = `${BASE}/admin/store/region/prototype`;
const PAYLOAD = JSON.parse(fs.readFileSync(process.env.O3_PAYLOAD || '/tmp/o3_login_payload.json', 'utf8'));
const OUT = process.env.O3_OUT || '/tmp/o3_browser_accept_r2';
const SNAP_BEFORE = process.env.O3_SNAP_BEFORE || '';
const SNAP_AFTER = process.env.O3_SNAP_AFTER || '';
const VIEWPORTS = [
  { name: '1440', width: 1440, height: 900 },
  { name: '1024', width: 1024, height: 768 },
  { name: '820', width: 820, height: 1180 },
];

fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function push(result, name, pass, detail) {
  result.checks.push({ name, pass, detail: detail || '' });
  if (!pass) result.ok = false;
}

async function injectAuth(page) {
  const menus = JSON.parse(JSON.stringify(PAYLOAD.menus || []));
  const walk = (arr) => {
    for (const m of arr || []) {
      if (m.path && !String(m.path).startsWith('/admin')) m.path = `/admin${m.path}`;
      if (m.children) walk(m.children);
    }
  };
  walk(menus);
  const uniqueAuth = Array.isArray(PAYLOAD.unique_auth)
    ? PAYLOAD.unique_auth.join(',')
    : String(PAYLOAD.unique_auth || '');
  const expires = PAYLOAD.expires_time;
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 120000 });
  await page.setCookie(
    { name: 'admin-token', value: PAYLOAD.token, domain: '127.0.0.1', path: '/', expires },
    { name: 'admin-uuid', value: String(PAYLOAD.user_info.id), domain: '127.0.0.1', path: '/', expires },
    { name: 'admin-expires_time', value: String(expires), domain: '127.0.0.1', path: '/', expires }
  );
  await page.evaluate(
    ({ menus, uniqueAuth, userInfo, expires, token }) => {
      localStorage.setItem('unique_auth', uniqueAuth);
      localStorage.setItem('menuList', JSON.stringify(menus));
      localStorage.setItem('roterPre', '/admin');
      const exp = new Date(expires * 1000).toUTCString();
      document.cookie = `admin-token=${token}; path=/; expires=${exp}`;
      document.cookie = `admin-uuid=${userInfo.id}; path=/; expires=${exp}`;
      document.cookie = `admin-expires_time=${expires}; path=/; expires=${exp}`;
    },
    { menus, uniqueAuth, userInfo: PAYLOAD.user_info, expires, token: PAYLOAD.token }
  );
}

async function waitTitle(page, expected, timeoutMs = 15000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const title = await page.evaluate(() => {
      const el = document.querySelector('[data-testid="org-title"]');
      return el ? el.textContent.trim() : '';
    });
    if (!expected) {
      if (title) return title;
    } else if (title === expected) {
      return title;
    }
    await sleep(300);
  }
  return page.evaluate(() => {
    const el = document.querySelector('[data-testid="org-title"]');
    return el ? el.textContent.trim() : '';
  });
}

async function ensureTreeVisible(page) {
  await page.evaluate(() => {
    const openBtn = Array.from(document.querySelectorAll('button')).find((b) =>
      /组织树|展开组织树/.test(b.innerText || '')
    );
    if (openBtn) openBtn.click();
    const expand = Array.from(document.querySelectorAll('button')).find((b) => (b.innerText || '').trim() === '展开全部');
    if (expand) expand.click();
  });
  await sleep(400);
}

async function selectOrg(page, orgId) {
  await ensureTreeVisible(page);
  await page.evaluate((id) => {
    const row = document.querySelector(`.tree-row[data-org-id="${id}"]`);
    if (row) row.click();
  }, String(orgId));
  await sleep(1200);
}

async function clickTab(page, label) {
  await page.evaluate((lab) => {
    const tabs = Array.from(document.querySelectorAll('button, [role="tab"]'));
    const t = tabs.find((x) => (x.innerText || '').includes(lab));
    if (t) t.click();
  }, label);
  await sleep(1000);
}

async function readMetrics(page) {
  return page.evaluate(() => {
    const title = ((document.querySelector('[data-testid="org-title"]') || {}).textContent || '').trim();
    const metric = (label) => {
      const card = Array.from(document.querySelectorAll('[data-metric]')).find((c) => c.getAttribute('data-metric') === label);
      const v = card && card.querySelector('[data-metric-value]');
      return v ? parseInt(String(v.textContent).replace(/[^\d]/g, ''), 10) : null;
    };
    return { title, stores: metric('全部门店'), employees: metric('在职人员') };
  });
}

async function storeRows(page) {
  return page.evaluate(() =>
    Array.from(document.querySelectorAll('tr[data-store-id]')).map((tr) => ({
      id: tr.getAttribute('data-store-id'),
      name: tr.getAttribute('data-store-name'),
    }))
  );
}

async function runViewport(browser, vp) {
  const page = await browser.newPage();
  await page.setViewport({ width: vp.width, height: vp.height });
  const result = { viewport: vp.name, checks: [], ok: true, apiHits: [] };
  let abortNextStores = false;
  let abortedStores = false;

  await page.setRequestInterception(true);
  page.on('request', (req) => {
    const u = req.url();
    if (abortNextStores && u.includes('/region/organization/stores')) {
      abortNextStores = false;
      abortedStores = true;
      return req.abort('failed');
    }
    return req.continue();
  });
  page.on('response', (res) => {
    const u = res.url();
    if (u.includes('/adminapi/region/organization/')) {
      result.apiHits.push({ url: u, status: res.status() });
    }
  });

  try {
    await injectAuth(page);
    await page.goto(PAGE, { waitUntil: 'networkidle0', timeout: 180000 }).catch(() => {});
    const title0 = await waitTitle(page, '厦门');
    push(result, 'page_loaded', title0 === '厦门', `title=${title0}`);

    // 1) 事业一部
    await selectOrg(page, 2);
    const dept1 = await readMetrics(page);
    push(
      result,
      'org_switch_dept1',
      dept1.title === '事业一部' && dept1.stores === 5 && dept1.employees === 49,
      JSON.stringify(dept1)
    );

    // 2) 厦门门店分页：page1 vs page2 无重叠
    await selectOrg(page, 1);
    await clickTab(page, '门店');
    await page.evaluate(() => {
      const byStore = Array.from(document.querySelectorAll('button')).find((b) => (b.innerText || '').includes('按门店'));
      if (byStore) byStore.click();
      const listBtn = document.querySelector('.view-switch button');
      if (listBtn) listBtn.click();
    });
    await sleep(1200);
    const page1 = await storeRows(page);
    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('.pagination button')).find(
        (b) => (b.innerText || '').trim() === '下一页' && !b.disabled
      );
      if (btn) btn.click();
    });
    await sleep(1500);
    const page2 = await storeRows(page);
    const ids1 = new Set(page1.map((x) => x.id));
    const overlap = page2.filter((x) => ids1.has(x.id));
    push(
      result,
      'store_page_no_overlap',
      page1.length > 0 && page2.length > 0 && overlap.length === 0,
      JSON.stringify({ page1: page1.map((x) => x.name), page2: page2.map((x) => x.name), overlap })
    );

    // 3) 关键词
    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('.pagination button')).find((b) => (b.innerText || '').trim() === '上一页' && !b.disabled);
      if (btn) btn.click();
    });
    await sleep(800);
    const searchSel = '.list-toolbar input[type="search"]';
    await page.waitForSelector(searchSel, { timeout: 10000 });
    await page.click(searchSel, { clickCount: 3 });
    await page.type(searchSel, '万象', { delay: 40 });
    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('.list-toolbar button')).find((b) => /查询/.test(b.innerText || ''));
      if (btn) btn.click();
    });
    await sleep(1500);
    const filtered = await page.evaluate(() => {
      const names = Array.from(document.querySelectorAll('tr[data-store-id]')).map((tr) => tr.getAttribute('data-store-name') || '');
      return { count: names.length, names, allMatch: names.length > 0 && names.every((n) => n.includes('万象')) };
    });
    push(result, 'keyword_match', filtered.allMatch, JSON.stringify(filtered));

    // 4) 快速切换后停在事业二部
    await page.evaluate(() => {
      const input = document.querySelector('.list-toolbar input[type="search"]');
      if (input) {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    await selectOrg(page, 2);
    await clickTab(page, '人员');
    await selectOrg(page, 3);
    await clickTab(page, '权限');
    await clickTab(page, '门店');
    await clickTab(page, '人员');
    await sleep(1500);
    const raceEnd = await readMetrics(page);
    push(
      result,
      'tab_race_final_org',
      raceEnd.title === '事业二部' && raceEnd.stores === 5 && raceEnd.employees === 50,
      JSON.stringify(raceEnd)
    );

    // 5) 查看范围
    await clickTab(page, '权限');
    await sleep(800);
    const scopeBtn = await page.evaluate(() => {
      const btn = document.querySelector('[data-action="view-scope"]');
      if (!btn) return { found: false };
      const style = window.getComputedStyle(btn);
      btn.click();
      return {
        found: true,
        ariaDisabled: btn.getAttribute('aria-disabled'),
        hasReadonlyClass: (btn.className || '').includes('is-readonly-disabled'),
        cursor: style.cursor,
      };
    });
    await sleep(700);
    const drawerOpen = await page.evaluate(() => {
      const d = document.querySelector('[data-drawer="permission"]');
      return {
        hasDrawer: !!d,
        title: d ? ((d.querySelector('h3') || {}).textContent || '').trim() : '',
        layerOpen: !!document.querySelector('.drawer-layer.open'),
      };
    });
    push(
      result,
      'view_scope_enabled',
      scopeBtn.found && scopeBtn.ariaDisabled !== 'true' && !scopeBtn.hasReadonlyClass && drawerOpen.hasDrawer && drawerOpen.layerOpen,
      JSON.stringify({ scopeBtn, drawerOpen })
    );
    await page.evaluate(() => {
      const close = document.querySelector('.drawer-head .icon-button');
      if (close) close.click();
    });
    await sleep(300);

    // 6) 写入口 O4 提示（排除「不可保存」）
    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('button.is-readonly-disabled')).find(
        (b) => /新增组织|编辑组织|设置负责人/.test(b.innerText || '') && !(b.innerText || '').includes('不可保存')
      );
      if (btn) btn.click();
    });
    await sleep(700);
    const tip = await page.evaluate(() => {
      const toast = ((document.querySelector('.toast-stack .toast') || {}).textContent || '').trim();
      return { toast, hasO4: /O4|只读验收|修改功能将在/.test(toast) };
    });
    push(result, 'write_o4_tip', tip.hasO4, JSON.stringify(tip));

    // 7) 失败重试
    await selectOrg(page, 1);
    await clickTab(page, '门店');
    abortNextStores = true;
    await page.evaluate(() => {
      const btn = Array.from(document.querySelectorAll('.list-toolbar button')).find((b) => /查询/.test(b.innerText || ''));
      if (btn) btn.click();
    });
    await sleep(1500);
    const retryUi = await page.evaluate(() => {
      const retry = Array.from(document.querySelectorAll('button')).find((b) => (b.innerText || '').trim() === '重试');
      if (retry) retry.click();
      return { hasRetry: !!retry };
    });
    await sleep(2000);
    const afterRetry = await storeRows(page);
    push(
      result,
      'failure_retry',
      abortedStores && retryUi.hasRetry && afterRetry.length > 0,
      JSON.stringify({ abortedStores, retryUi, afterRows: afterRetry.map((x) => x.name) })
    );

    const bad = result.apiHits.filter((h) => h.status >= 400);
    push(result, 'api_no_4xx', bad.length === 0, JSON.stringify(bad.slice(0, 5)));
    await page.screenshot({ path: path.join(OUT, `${vp.name}.png`) });
  } catch (e) {
    push(result, 'exception', false, String(e && e.message ? e.message : e));
    try {
      await page.screenshot({ path: path.join(OUT, `${vp.name}_error.png`) });
    } catch (_) {}
  } finally {
    await page.close().catch(() => {});
  }
  return result;
}

(async () => {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  const results = [];
  for (const vp of VIEWPORTS) {
    results.push(await runViewport(browser, vp));
  }
  await browser.close();

  let snapOk = true;
  let snapDetail = 'skipped';
  if (SNAP_BEFORE && SNAP_AFTER && fs.existsSync(SNAP_BEFORE) && fs.existsSync(SNAP_AFTER)) {
    const b = JSON.parse(fs.readFileSync(SNAP_BEFORE, 'utf8'));
    const a = JSON.parse(fs.readFileSync(SNAP_AFTER, 'utf8'));
    snapOk =
      JSON.stringify(b.counts) === JSON.stringify(a.counts) &&
      JSON.stringify(b.hashes) === JSON.stringify(a.hashes) &&
      b.source_mode === a.source_mode &&
      b.runtime === a.runtime;
    snapDetail = {
      counts_equal: JSON.stringify(b.counts) === JSON.stringify(a.counts),
      hashes_equal: JSON.stringify(b.hashes) === JSON.stringify(a.hashes),
      source_mode: [b.source_mode, a.source_mode],
      runtime: [b.runtime, a.runtime],
    };
  }

  const summary = {
    allOk: results.every((r) => r.ok) && snapOk,
    snapOk,
    snapDetail,
    results,
  };
  fs.writeFileSync(path.join(OUT, 'summary.json'), JSON.stringify(summary, null, 2));
  console.log(JSON.stringify(summary, null, 2));
  process.exit(summary.allOk ? 0 : 2);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
