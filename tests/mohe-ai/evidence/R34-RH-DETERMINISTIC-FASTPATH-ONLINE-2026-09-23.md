# 第 34 轮魔核 AI 瑞昊发布与线上真人验证

- 目标实例：瑞昊 `rh.cc3798.com`，站点 `/www/wwwroot/rh.cc3798.com/`，仅此实例；其他客户未更新。
- 产品经理已确认本轮固定版本验收，并明确授权 commit、部署瑞昊、线上验证和 push。
- 本地运行源码提交：`f9a1a2b7b0006ca923c492d56254ae59156891f5`。数据库迁移、生产配置变更：无。
- 发布前核实：线上 `AiGatewayServices.php` SHA-256 为 `1288ad1bf573b716dd4ddaf0980fb8ac89a820f2b42a5b490a32c6dcd106f26b`，与该提交的父版本一致；线上指标定义注册表、语义目录和工作流规划器的 SHA-256 均与本地一致。瑞昊站点目录、管理页面、AI Worker 存在；本次没有更改客户库、Redis、支付或其他站点配置。
- 独立回滚备份：`/www/backup/mohe-rh/r34-deterministic-fastpath-WwuIMmSd/before.tar.gz`，SHA-256=`e0cec797cd4d17837121faaac3a8fa5fe01a96a4046eb2341b0d2360e276b6fc`，含旧 AI 网关、`public/system.html` 和旧 `public/view_admin/`（1176 个归档路径）。新增语义类在回滚时需移除这一准确文件，随后重启瑞昊 Swoole、AI Worker 和 Supervisor；不能以服务器历史 Git 状态作回滚依据。
- 平台前端由当前 `18081` 唯一源码 `前端代码/admin/` 在本机原生 arm64 Node 16 生产构建，构建成功（仅既有 bundle 体积与过期 Browserslist 数据警告）。工作区唯一未提交的 admin 配置变更仅涉及开发服务器 `devServer.proxy`，不参与生产运行；本轮共享 AI 前端源文件与提交一致。上传仅使用 `deploy-frontend-dist.sh rh admin --allow-rh`，未上传收银、门店或其他端产物。
- 后端为避免夹带其他任务的未提交文件，仅精准同步本轮两个 PHP 文件，没有运行会同步整个 `app/` 的脚本；线上 PHP 7.4 语法检查通过。瑞昊 Swoole 重启后 `manager_count=1`，`mohe-ai-execution-worker@1.service` 与 `mohe-ai-supervisor.service` 均为 `active`。

## 部署后运行指纹

```text
2598755a5b5b0b16d6b5ec3919b21500863dd1616c90b744a9e49ce843512fd5  app/services/ai/AiGatewayServices.php
c557e4e10178c47ec095bcddefdde4665a99724c9cf8a64ef3dcd4a015bfa4d8  app/services/ai/semantic/AiDeterministicSummaryAdmission.php
d10775f0c82e0586d3f48459d1cd47f6be8aa93c2531c3b48eb67f171e846056  public/system.html
a0b8701b673fdc6ac6c669dccb501d4e505eeef020b0e3a33fd915e6828d85c8  public/view_admin/js/app.cbae0442.js
```

线上 `https://rh.cc3798.com/admin/setting/mohe-ai` 返回 HTTP 200，TLS 证书验证成功。前两项 PHP 指纹与固定运行源码提交一致；后两项与本机构建包一致。

## 同一线上对话窗真人测试

1. `今天现金业绩多少` → `现金业绩为0元`，统计时间 2026-09-23，页面用时 1 秒。
2. `这个月呢` → 保持现金业绩口径，仅切换为 2026-09-01 至 09-23，返回 116,590 元，页面用时 1 秒。
3. `这个月现金业绩和退款业绩分别是多少` → 走复合问题链路，返回现金业绩 116,590 元、退款业绩 0 元，统计时间 2026-09-01 至 09-23，页面用时 26 秒。没有将多指标截成单指标，也未触发指标选择。

上述为瑞昊当前账号范围的页面事实与口径连续性验证，不宣称跨账号权限验收或逐笔财务对账。浏览器会话记录保留在本机，不删除。
