/* 原生旧格式与异常文件走真实上传/转换链路，失败资源不可发布。 */
const { assert, admin, base } = require('./local-client.cjs');
const fs = require('node:fs'), path = require('node:path');
const out = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据/媒体样例');
(async () => {
  const a = await admin(), checks = [];
  async function upload(name, bytes) {
    const data = new FormData(); data.append('file', new Blob([bytes]), name);
    const response = await fetch(base + '/adminapi/growth/asset/upload', { method: 'POST', headers: a.headers, body: data });
    if (response.status === 413) return { status: 413, msg: '网关拒绝超限上传' };
    return response.json();
  }
  for (const [name, state] of [['服务准备.doc', 'READY'], ['服务准备.ppt', 'READY'], ['可读样例.pdf', 'READY'], ['加密样例.pdf', 'FAILED'], ['超页样例.pdf', 'FAILED']]) {
    const r = await upload(name, fs.readFileSync(path.join(out, name)));
    assert.equal(r.status, 200, JSON.stringify(r)); assert.equal(r.data.state, state, JSON.stringify(r));
    if (state === 'READY') {
      assert.ok(Number(r.data.page_count) > 0);
      const preview = await fetch(base + '/adminapi/growth/asset?id=' + r.data.id, { headers: a.headers });
      assert.equal(Buffer.from(await preview.arrayBuffer()).subarray(0, 4).toString(), '%PDF');
    } else {
      assert.ok(r.data.error_message);
      const body = { title: '失败资源禁止发布', chapters: [{ key: 'main', title: name, type: 'pdf', asset_id: r.data.id }] };
      assert.notEqual((await a.call('content/publish', body, true)).status, 200);
    }
    checks.push(name + '：' + state);
  }
  for (const ext of ['docx', 'pptx', 'mp4', 'png']) {
    const r = await upload('损坏文件.' + ext, Buffer.from('not a valid media file'));
    assert.equal(r.status, 200); assert.equal(r.data.state, 'FAILED', JSON.stringify(r));
    checks.push('损坏' + ext + '明确失败');
  }
  assert.notEqual((await upload('超限.png', Buffer.alloc(50 * 1024 * 1024 + 1))).status, 200);
  checks.push('超过50MB真实上传被拒绝');
  console.log(JSON.stringify({ passed: checks }, null, 2));
})().catch(e => { console.error(e); process.exitCode = 1; });
