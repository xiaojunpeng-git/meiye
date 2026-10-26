# R8G · 平台 AI 超时提示收敛（本地待验收）

## 固定版本

- 基线 HEAD：`2cadc25c4f4dcc2d8ed8860ac8addf103dc8b8e5`。
- 闭环文件：`前端代码/shared/mohe-ai/browser-entry.mjs`、`tests/mohe-ai/browser-guidance-contract.mjs`、`tests/mohe-ai/opt3-browser-failure-contract.mjs`，SHA-256 分别为 `e7e604e36b8a057b456b2c9ed11706ada6b3838708e007671fccf35312cd6517`、`6b5a49e060286c82c8b3c31ad35a21993080e4f758345ff80d34575aeec05489`、`4968b3ad06964a766d84df9f203fb302dec696bfbbb74655faadb4105c081ccd`。
- 上述三个文件的 Git 二进制补丁 SHA-256：`3601cfbe024966c901c505fb4cda7b753427042bd0543959e06509b3a823e5ae`。其他脏工作区文件不属于本闭环。
- 文件分组：前端源码 1、自动化测试 2、验收证据 1；后端源码、数据库迁移、工程脚本、生成物均无变更。

## 改动和边界

平台工作台在模型结果未知时，不再展示占整块区域的“四种继续方式”问题卡，改为保留准确故障说明并给出单个“重试原问题”按钮。按钮创建新 Run，保留失败问题之前的会话上下文，不把失败回答当作事实；重复点击仅提交一次。历史问题跨营业日时先恢复原文供核对日期，不自动重查。真正缺少业务条件的确认卡与门店抽屉原有引导不变。与本次变更直接相关的四选项工作台构造分支已删除，注释同步说明超时与补充条件的边界。

本轮仅改前端展示与重试入口，不改变经营指标、Reader、权限或模型服务的超时行为。重试仍可能再次超时；不能把此展示修复称为模型稳定性修复。

## 本地自测

- `node tests/mohe-ai/browser-guidance-contract.mjs`：235 项通过，覆盖单按钮、原文、前序历史、双击一次提交与确认卡边界。
- `node tests/mohe-ai/opt3-browser-failure-contract.mjs`：通过，覆盖技术失败、历史跨日只恢复草稿、门店抽屉四选项兼容和旧版历史。
- `node tests/mohe-ai/browser-entry-contract.mjs`：通过。
- `node tests/mohe-ai/browser-mobile-guidance-contract.mjs`：219 项通过，另有相关展示检查通过。
- `node --check 前端代码/shared/mohe-ai/browser-entry.mjs` 与 `git diff --check`：通过。

上述为 DOM／HTTP 夹具测试。本地 18081 页面已刷新确认加载，浏览器当前会话未载入截图中的历史超时记录，故这次没有取得同一页面的真人视觉复核；没有重新向外部模型提交真实问题。当前仅开发与自测完成，待产品经理对固定版本确认验收；未 commit、未部署、未 push。
