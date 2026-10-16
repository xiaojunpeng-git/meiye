# R9D 服务项目双指标补入 R9 手机体验包：本地固定候选

- 日期：2026-10-16（Asia/Shanghai）。基线 HEAD：`9666e6566369f0b7a12b71c3f0670d27491e86ca`。
- 问题：R8K 服务项目双指标提交 `728516d7131632809cb78d90839781e7ce1c0351` 位于并行发布分支，不在 R9 HEAD 的祖先链中。上一轮从 R9 HEAD 导出的两个小程序包，其数仓 WXML 只有单一 `ranking-value`，无“服务数／现金业绩”及两种排序入口；因此体验版上无法看到已验收的 R8K 页面。两实例的 R8K 后端先前已单独部署并只读对账，问题位于手机包源码选择。
- 候选组合：在 R9 HEAD 上精确纳入 R8K 的七个文件，保留 R9B 手机 AI 拖动组件。七个文件当前工作树内容与 R8K 提交逐字节相同；文件名与 SHA-256 列表的指纹为 `e137686c88d520bab9b31fd766ed9bfc83ba04d2cf8705f84997ea0f30a8d4e0`。不纳入其他工作树脏文件。

## 文件边界

- 前端源码：`前端代码/mobile-vue3/src/merchant/pages/warehouse/index.uvue`（`e4592f78`）。前端契约：`前端代码/mobile-vue3/src/shared/contracts/mobile-merchant-v1.contract.json`（`856bb95a`）。
- 后端源码：`后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php`（`4f7d858b`），与瑞昊和 008 已部署的 R8K 文件相同。本候选无需再次远程部署该文件。
- 自动化测试：`tests/mobile-warehouse/js/page-contract.mjs`（`9e024fd4`）、`tests/mobile-warehouse/php/project-ranking-contract.php`（`e1bb095b`）、`tests/mobile-warehouse/run-contract.sh`（`cbfa45d0`）。
- 原 R8K 验收证据：`tests/mobile-warehouse/evidence/R8K-service-project-category-name-ranking-local-2026-10-16.md`（`11a70289`）。本交付证据：本文。无数据库迁移或工程脚本变更；临时 HBuilderX 生成物不纳入 Git。
- 本次未新增或改写业务逻辑；R8K 原注释覆盖合并口径、权限及排序失效边界，R9B 注释覆盖触控与位置恢复边界。已对 R8K 替代分支按原闭环做引用扫描与删除，本次仅集成两项已验收实现。

## 自测

1. `bash tests/mobile-warehouse/run-contract.sh` 通过：组织层级、范围、服务项目分组排序、员工指标与页面合同。`node tests/mohe-ai/mobile-entry-drag-contract.mjs` 与 `node tests/mohe-ai/browser-mobile-guidance-contract.mjs` 通过。`git diff --check` 对本闭环已跟踪文件通过。
2. 从 R8K 固定提交导出手机源码，只将 R9 HEAD 的已验收 AI 入口组件覆盖进隔离候选目录；瑞昊和 008 分别配置自己的 AppID 与 API origin。HBuilderX 5.26 两套各 20 页均编译成功。
3. 瑞昊生成 WXML 包含“按现金业绩”“按服务数”“服务数”“现金业绩 ¥”，生成 JS 包含 `projectSortMetric`、`selectProjectSortMetric`、`cashDisplay` 和 `categoryPath`；008 包同样通过。两包只包含各自 API origin，不包含对方域名；生成的 AI 入口 JS SHA-256 均为 `5544dbbc31b64d453e42eaf46387965d282cc96d79ba625f6541dedea37819d7`。
4. 隔离候选构建目录：`/private/tmp/r9-r8k-mini-candidate-20261016/{rh,008}`。当前只编译，未上传新版本、未提交新 commit、未 push、未执行远程写操作。

## 边界

- R8K 的瑞昊生产只读对账已见 `tests/mobile-warehouse/evidence/R8K-rh-008-release-2026-10-16.md`；008 原有服务项目事实为空，无法在 008 对非空排行做页面业务对账。
- 尚无已登录的实体手机体验版页面复核。上一轮 `0.16.2`／`0.15.18` 只取得 CLI 开发版本上传回执，体验版绑定未获独立确认。本候选须产品经理对组合固定版本验收后按精准文件清单提交，再以新 commit 打包上传；新包的体验版绑定也须取得明确回执或在微信公众平台核对。
