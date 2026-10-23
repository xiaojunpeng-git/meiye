/* 用生成的真实Word/PPT/长图/视频验证转换、私有读回和发布，不使用空文件冒充成功。 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const base = 'http://127.0.0.1:18115';
const root = path.resolve(__dirname, '../../../任务管理/进行中/美业大学成长管理/证据');
const key = () => `media-${randomUUID()}`;
(async () => {
  const l = await (
    await fetch(base + '/adminapi/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ account: 'growth_admin', pwd: 'GrowthLocal!2026' })
    })
  ).json();
  assert.equal(l.status, 200);
  const h = { 'Authori-zation': 'Bearer ' + l.data.token, 'X-Source': 'f76d38d0ee4f854f' };
  async function admin(p, d) {
    const r = await (
      await fetch(base + '/adminapi/growth/' + p, {
        method: 'POST',
        headers: { ...h, 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...d, idempotency_key: key() })
      })
    ).json();
    assert.equal(r.status, 200, p + ': ' + r.msg);
    return r.data;
  }
  const chapters = [];
  const assets = [];
  for (const [filename, type, format] of [
    ['服务准备.docx', 'word', 'docx'],
    ['服务准备.pptx', 'ppt', 'pptx'],
    ['服务准备长图.png', 'image', 'png'],
    ['服务准备.mp4', 'video', 'mp4']
  ]) {
    const data = new FormData();
    data.append('file', new Blob([fs.readFileSync(path.join(root, '媒体样例', filename))]), filename);
    const r = await (
      await fetch(base + '/adminapi/growth/asset/upload', { method: 'POST', headers: h, body: data })
    ).json();
    assert.equal(r.status, 200, JSON.stringify(r));
    assert.equal(r.data.state, 'READY', JSON.stringify(r));
    assert.equal(r.data.format, format);
    if (type === 'word' || type === 'ppt') assert.ok(Number(r.data.page_count) > 0);
    if (type === 'video') assert.ok(Number(r.data.duration_seconds) >= 8);
    const preview = await fetch(base + '/adminapi/growth/asset?id=' + r.data.id, { headers: h });
    assert.equal(preview.status, 200);
    const bytes = Buffer.from(await preview.arrayBuffer());
    assert.ok(bytes.length > 500);
    if (type === 'word' || type === 'ppt') assert.equal(bytes.subarray(0, 4).toString(), '%PDF');
    assets.push(r.data);
    chapters.push({
      key: type,
      title: filename,
      type,
      asset_id: r.data.id,
      required: true,
      completion: type === 'video' ? 'coverage' : 'confirm',
      coverage_percent: 90
    });
  }
  const title = '多形式课程真实样例-' + Date.now();
  const draft = await admin('content/save', { title, chapters });
  const pub = await admin('content/publish', { id: draft.id, title, chapters });
  const task = await admin('task/assign', { publication_id: pub.publication_id, employee_ids: [91002] });
  fs.writeFileSync(
    path.join(root, 'media-result.json'),
    JSON.stringify({ passed: true, title, task_id: task.task_ids[0], assets }, null, 2)
  );
  console.log(
    JSON.stringify(
      {
        passed: [
          '真实Word转PDF',
          '真实PPT转PDF',
          '长图识别',
          '真实MP4时长识别',
          '认证资源二进制读回',
          '四形式课程发布分配'
        ],
        title,
        task_id: task.task_ids[0]
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
