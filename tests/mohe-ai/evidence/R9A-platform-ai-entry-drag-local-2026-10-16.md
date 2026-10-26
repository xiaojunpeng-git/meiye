# R9A 平台魔核 AI 入口拖动：本地固定版本

- 基线 HEAD：`89b1b9e7ff8ec589bb05acdc8c3d4fa33a8f914d`
- 固定范围清单 SHA-256：`cfc93891d00254765cda1f85f20835297525cbaeed2628b5e4ad2b6f1f033ed2`
- 状态：开发与本地自测完成；产品经理已明确确认 R9A 验收通过并授权仅本地提交。未部署或 push。

## 范围与实现

平台端魔核 AI 圆形入口支持按住拖动。拖动与点击由 5px 阈值区分；松手不打开工作台，普通点击仍打开。位置仅存当前浏览器本地存储，刷新后恢复，窗口变小时自动限制在可触及区域。`draggableEntry` 仅由平台端启用，不改变门店端入口或问答业务。

| 分类 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/admin/src/components/MoheAiEntry.vue` | `3ec86a28388a4317bcab96b57bbf251f1d7a9f62c772cec8a367433394d5d228` |
| 前端源码 | `前端代码/shared/mohe-ai/browser-entry.mjs` | `a0394e0749f9c741b3e62893e438bbf22170b649ab1dfada134a16e917143f25` |
| 自动化测试 | `tests/mohe-ai/platform-entry-drag-contract.mjs` | `bdf81540c139afffc12d36f3184242127216e2c7fe3d55c11dff76a62c9a9bb5` |
| 浏览器测试夹具 | `tests/mohe-ai/platform-entry-drag-browser-fixture.html` | `0bcf55d624c72a90077c31758bfbe23300b9e42c563f13121bebd270eb959c8d` |

数据库迁移、工程脚本、生成物：无。验收证据：本文件。源码注释说明了平台专用开关、点击与拖动边界、浏览器存储不可用及窗口缩小时的位置约束；测试夹具注释说明 jsdom 的能力边界。未发现本次替代后需删除的旧业务分支。

## 自测

1. `node --check 前端代码/shared/mohe-ai/browser-entry.mjs`：通过。
2. `node tests/mohe-ai/browser-entry-contract.mjs`：通过，原问答入口回归。
3. `node tests/mohe-ai/platform-entry-drag-contract.mjs`：通过，覆盖拖动不打开、点击仍打开、刷新位置恢复、缩窗边界和平台专用开关。
4. `git diff --check -- 前端代码/admin/src/components/MoheAiEntry.vue 前端代码/shared/mohe-ai/browser-entry.mjs`：通过。
5. 本机 `mohe-admin-src` 从已停止状态启动后，18081 平台源码编译成功，`/admin/report/group-management-dashboard` 返回 200；页面入口含 `entry--draggable` 类。
6. 用真实浏览器打开隔离测试页，图标从约 `(1601,872)` 拖至 `(1268,670)`，工作台未打开；随后点击成功打开，刷新后仍在约 `(1270,671)`。测试页未请求真实业务数据。

18081 看板上的自动化坐标拖动工具在按下时报告目标失效，未完成该页面的直接拖动复核；同一份共享入口代码在真实浏览器隔离页完成了拖动，且 18081 已确认装载平台专用开关。产品经理可在 18081 看板上补做最终手势验收。当前工作区另有多项不属于本闭环的既存改动，提交时只允许逐项暂存上表文件及本证据文件。
