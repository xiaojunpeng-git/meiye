# R32 退款日期、销售人及员工负向业绩：本地固定版本

- 基线 HEAD：`c606860b4bad45b66f58761198eeb0e10c7eb94a`。
- 本轮跟踪文件补丁 SHA-256：`e6c59e4f8ee6eee0058befcf4da465dffc76451536b7f7be6c62965ce282cfe1`；新文件逐项指纹见下表。工作区还有大量其他任务的改动，本轮未暂存、提交或清理它们。
- 状态：开发与本地自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。生产库没有执行本轮迁移。

## 已确认业务边界

- 卡项与单据退款均可填退款经营日期、独立选择退款销售人及分配；默认草稿取原单销售人。退款日期可在记录表更正，人员可在记录表改派。
- 原单销售人员和原单正向业绩不回写。退款销售人按所分配的负向业绩计入员工薪资，经营侧只按退款金额记一次。独立核算岗位各有 100% 分配组；自动金额为整元，尾差给组内末人。
- 旧退款记录不自动追溯扣薪；旧记录逐笔编辑后形成独立负向事实。旧记录当前人员列无已确认快照时为空，编辑窗口只以原单人员提供草稿。

## 文件指纹

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `029ef32391675ca4edc4abb49ca64b10bd88bd9c8cbd7f4de2c78b886765cc05` |
| 前端源码 | `前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue` | `b27536aca69f284745843b3c1b10000d2786df034d03bb5ec75e8a6c8286f2af` |
| 前端源码 | `前端代码/cashier-v3/src/services/cashierV3ActionManifest.js` | `df7916a9bb462a815f6503cd0d601cce60858c49bc3214155e210f262e840d54` |
| 前端源码 | `前端代码/cashier-v3/src/services/cashierV3Bridge.js` | `d1a39f064dfe16737fdc0637b6c8777e192291fb60c4ee1aaa8e50a1c19e8497` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php` | `d7727080c73729b5e1826f96ac1a316476652e8bda8a149902338829259c80fa` |
| 后端源码 | `后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php` | `5b3dbb8ccc81a8c20d00f97b484cb73e2980232b824a4b92e14e08cb5c7f53b3` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php` | `e93f7f58dba9708b19d84f8f399004d2f175bd2eba489de2bfd37a3dd7e99b22` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderAttributionCorrectionServices.php` | `50c7a492d8033a3022c7e245fe2880abc53afb6faf3bc2de86efeb19fb81ab2f` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `2723e57ef704f0a42d94a88f1558781100b5734a3aaaa79ca90ac066c6281856` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php` | `5e2ff6edfca900f82d92c32e7ac0ef73463860a5dd6a9577a64be4d873fcb8c3` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php` | `7a4363999d01a6f6ed859d991dfd349d909728fa8b05488051e35606ead97c80` |
| 后端源码 | `后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php` | `61915a914535caf2301b431d21264fa2da8def9e27ea8caaa59e6a02994fdc62` |
| 后端源码 | `后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php` | `6f2ed8be19b4012fe33562aa6502738290d4fec03f7519f4b7c265793e1ef077` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `9e60017333e3c7db3df76c3a9245333873118167b895f44118bff9eafdb1a2bc` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-08-退款销售人负向业绩/00-升级清单.md` | `1de8e41bbd932bea8c7b7dff7fff1eb79c8c4479a828128fd792118a1efb16a5` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-08-退款销售人负向业绩/01-升级前检查.sql` | `f12f9384f6acd4893454b70da0101cd1ba2ea948720897de11fc74a0af6d018d` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-08-退款销售人负向业绩/02-正式升级.sql` | `7260b94f5ef6c4fa22a62f9e36bf739139ba30cfa6434611e5a2f21d8afd9fed` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-08-退款销售人负向业绩/03-升级后验证.sql` | `d2ae07d9ac9022ebb40ecb3dba1e3578fc95c86424875ee4726e798c9ae6aa3f` |
| 自动化测试 | `tests/cashier-v3/php/r32-refund-salesperson-rollback.php` | `2e2885b810c0847ae881be7fe5e01dba11ce66a7cf92f6bbff1aaa8b503429ff` |
| 自动化测试 | `tests/cashier-v3/php/idempotent-replay-historical-context-contract.php` | `83aafa3fbe481a074cb72f7c645fb9cb6def6630b740b85eddf420d531d57248` |
| 自动化测试 | `tests/cashier-v3/php/r26-unified-refund-rollback.php` | `d169f5f69e053bd3b088a470a9ef7d25796e1a700d9a3bf7b5cbcc1bd8b3ded4` |
| 自动化测试 | `tests/cashier-v3/js/r32-refund-date-contract.mjs` | `8b1eeae459477afe905b5fc7eaa3fe8435435701d742522836ebe5823ab2ae04` |
| 自动化测试 | `tests/cashier-v3/js/r26-refund-page-gateway-contract.mjs` | `2a11d80564e6d1ce9e7eca9b5338df3b1da2acc3d20c046add61eded6bb505ee` |
| 自动化测试 | `tests/cashier-v3/js/order-attribution-command-context-contract.mjs` | `50ccc9b36d26a0b21511569206af89973a0efa12f50afe9dbecddece1553b854` |
| 工程脚本 | 无。 | - |
| 生成物 | 本地 `npm run build` 产生的 `dist/`，不作为源码或提交文件。 | - |

## 自测与限制

- `npm run build`：通过；仅有 Vite 现存大 chunk 提示。变更源为热更新门店端 `前端代码/cashier-v3/`，此处未做 8080 集成打包。
- 相关 PHP 文件 `php -l`：通过（网关已有三个 PHP 隐式 nullable deprecation 提示，本轮未改其签名）；`git diff --check`：通过。
- `r32-refund-salesperson-rollback.php`：通过卡项/单据退款、101 元 33/68 尾差、手填加自动整元、独立岗位与经营退款不重复、改派冲销、同请求重放、过期版本拒绝、改退款日期、仅人员调整权限读取旧版本、原销售事实不变。测试在事务内回滚；本地 `ruihao` 仅保留四列及审计表的迁移，未保留测试退款。核对结果：4 列、1 审计表、0 条持久退款人员事实。
- `idempotent-replay-historical-context-contract.php`：通过；无上下文退款改派重放必须核验服务端改派审计、原操作员和当前门店权限。
- 五项 JS 合同测试：订单人员展示、R32 日期、R26 退款网关、归属命令上下文、人员分配弹窗，均通过。
- 2026-10-08 退款改派页面补验：前端空上下文允许名单与 `buildCommandContexts` 同时包含 `adjust-refund-personnel`；原因弹窗显示“退款记录”和退款单号，不再显示“补交记录”及内部 `OLO` 标识。R26 网关合同、R32 日期合同、归属上下文合同、分配弹窗合同、Vite 构建及 R32 退款事务回滚测试重新通过。此修复改变了验收文件指纹，须以本表新指纹验收。
- 旧 `r26-unified-refund-rollback.php` 的卡项段跑通，但当前本地库缺少其写死的单据样本，之后返回失败码及 `Local document probe fixture unavailable`；这不构成整个旧脚本通过，新 R32 测试独立覆盖单据路径。
- `frontend-manifest-sync.mjs` 的 action 扫描和前后端 action 映射通过，但完整脚本还需要可重复读取的后端 JSON 导出文件（含 recovery 契约）；本轮未生成该文件，因此完整门禁未宣称通过。
- 本地浏览器当前处于登录页，未做登录后的人工界面验收；产品经理仍需核对页面和业务口径。线上实例及数据未改。

本轮补充或更新业务注释的文件：`PersonnelPerformanceOverlay.vue`、`OrderCenterView.vue`、`CashierV3RefundSalespersonServices.php`、`CashierV3CommandGatewayServices.php`、`CashierV3UnifiedRefundServices.php`、`CashierV3OrderAttributionCorrectionServices.php`、`CashierV3OrderCenterRecordQueryServices.php`、`StoreUnifiedReportPhaseSixServices.php`。
