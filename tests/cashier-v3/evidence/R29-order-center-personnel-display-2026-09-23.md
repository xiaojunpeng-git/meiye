# R29 订单中心人员展示：本地固定版本与自测

- 基线 HEAD：`c10ccdf317cc5cabdd18207a0d0507393e40bc34`，分支 `release/store-cashier-c14-main`。
- 固定范围：销售订单的“销售人（业绩）”及逐人售前/业绩展示；服务记录的手艺人四项合并展示，并从列表移除独立“手工费”“工资项目数”列。详情仍保留原字段。
- 业务来源：销售金额读取订单行已分配的销售业绩事实；服务人员四项优先读取员工劳动业绩事实，历史缺失时只用该条服务的不可变人员快照补展示。不重新分摊订单金额，不改结账或工资事实。
- 本轮新增/更新注释：`OrderCenterView.vue`、`orderCenterPersonnelDisplay.js`、`CashierV3SalesOrderQueryServices.php`、`CashierV3OrderCenterRecordQueryServices.php`。

## 源码与测试文件 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `73f6eb5243d0dea0a0be7f9f80a54e5e6145f1ad779c3c04ccbb07878b130071` |
| 前端源码 | `前端代码/cashier-v3/src/services/orderCenterPersonnelDisplay.js` | `ee25ff972a56779904710ea7e9dedf44c8d4328d0c7bade8e0062b2b2509be7d` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `8be3a28960800b8e08d5f0299cd7dd0536102653f57d38b014cc024aa8853767` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` | `d43d1587330ff852f485bd19cfe5f8ed7b89872ba2a9eb903f2ed854a77b257f` |
| 自动化测试 | `tests/cashier-v3/js/order-center-sales-readonly-contract.mjs` | `493337f7a24560f6d6c94636ef56c07350ae4fe95d41b85afe2bcc8f3064d594` |
| 自动化测试 | `tests/cashier-v3/js/order-center-personnel-display-contract.mjs` | `7967611df18d246cae35e44b056966c96f7cf24166a1985711fafff72cd5a0bb` |
| 自动化测试 | `tests/cashier-v3/php/order-center-personnel-display-contract.php` | `5cfb142b2902131dfa2cfd46a0341a0622658ffb0b3290a8da2a90a750a61f5a` |

已跟踪四个文件的工作区补丁 SHA-256：`439f825282b69c04aae2cec89d8ebe07d41e2d49be9fd77605c13c05b909a054`。其中 `CashierV3OrderCenterRecordQueryServices.php` 在本轮开始前已有其他任务的未提交改动；上述整文件及补丁指纹包含该既有改动，不将其算作本闭环成果，也不能整文件暂存提交。

## 验证

- `node tests/cashier-v3/js/order-center-personnel-display-contract.mjs`：通过；覆盖多人、售前、零金额、未知字段及合并列格式。
- `php tests/cashier-v3/php/order-center-personnel-display-contract.php`：通过；覆盖无事实快照、事实优先、显式零项目数、多人中的全零人员与调整后不复活旧人员。
- `php -l` 两个后端文件、`git diff --check`：通过。
- `npm run build`（`前端代码/cashier-v3/`）：通过，1972 modules；只生成忽略的 `dist/`，未打包 8080 集成包。
- 本地 `18091` 订单中心实际页面：销售订单 `XS26092300001` 显示“汤静静（2980）”；`XS26090300007` 显示多人金额；服务记录列表显示合并列，例如 `鹿欢欢（轮、0、0、1）`，且不再有独立“手工费”“工资项目数”表头。
- 旧的 `order-center-sales-readonly-contract.mjs` 在本次断言之前，因共享工具栏 `selectScope` 旧写法断言失败；`order-center-records-readonly.php` 有一条旧 `business_date` top filter 断言与当前查询规范不符。两处均与本轮展示变更无关，未扩散修复。

数据库迁移：本闭环无新增迁移；依赖已提交的 `20260918-001-cashier-v3-project-count-decimal`。工程脚本：无。生成物：仅本地忽略的 Vite `dist/`。

## 2026-09-23 产品经理补充验收与项目数范围

产品经理明确确认订单中心展示及已存在的项目数修正均无问题，要求一起 commit、部署瑞昊、push。项目数修正保留显式 0、最多六位小数及旧半项目单位兼容；本闭环还包含已改动的收银草稿、服务记录人员调整及门店目标项目数读取，不包含会员权限、服务对象转换或其他任务的工作区变更。补充注释不改变业务行为。

| 分组 | 补充文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php` | `3db4132bbd526e36581aa33ea50b1c2c96b7803cdb54eaf2ad632cdba376b1b3` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3ServiceRecordCraftsmanAdjustmentServices.php` | `4ac782d175931bd7cd89948fa3b364a6f008205f3040a0a60eae86ef90b3dc1b` |
| 后端源码 | `后端代码/app/services/cashier/v3/dashboard/CashierV3StoreTargetDashboardReadModel.php` | `43fd9dd15933c21c84f9bd340542b534da5b4f30733e21f02ba77b532220fd38` |
| 自动化测试 | `tests/cashier-v3/js/personnel-performance-overlay-contract.mjs` | `567818374924080592eef8db38219a7ccefa860f0fc6ce42a493200265582041` |

补充定向自测通过：`checkout-craftsman-project-count-compat.php`、`project-count-fact-registry-contract.php`、`entitlement-manual-performance-contract.php`、`cashier-draft-command-projection-contract.php`、`business-dashboard-fact-contract.php`、`business-dashboard-presentation-contract.mjs`、`personnel-performance-overlay-contract.mjs`、订单中心 JS/PHP 人员展示合同，以及相关 PHP lint。瑞昊只读检查：目标路径存在，待部署的五个后端文件与基线 HEAD 的 SHA-256 相同；`project_count_decimal` 列与升级日志均存在。尚待本地 commit、线上部署与验证、push。
