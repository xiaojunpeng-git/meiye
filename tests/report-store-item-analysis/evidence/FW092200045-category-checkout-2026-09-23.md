# FW092200045 分类与结账回归（2026-09-23）

## 固定本地版本

- 基线 HEAD：`7fe1bebfcbc34dd9bd8bee2af1d2995c196a09ee`。
- 本闭环文件：
  - `后端代码/app/services/cashier/v3/checkout/CashierV3ServiceProjectCategorySnapshotServices.php`：`1eaf7a2c156f152b8ad856f3e297c6583f23c93b65439ef5e4c064eaee03e66c`
  - `后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php`：`5140655f011270c5ff94f68b92d5cff35496f5edd81a9fe36fa2bc73f1295f6e`
  - `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php`：`614736cad54191244752654facc12415d1fc183dfd66bb6ae6f9e201a922c620`
  - `后端代码/app/services/report/StoreUnifiedReportServices.php`：`7148237b935430923202b0c7ce05451fa69bfadfd3e19bbfa39f61f89a48cd0c`
  - `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`：`501f5182a9e1c462d9042e26a3595720a06fd9e566d116dfc13c09366db4f296`
  - `tests/report-store-item-analysis/php/category-checkout-integration.php`：`e8f70b833df302003c0cd09bee13e7750818397e88e5ed515ec5e78a28ed786e`
  - `后端代码/database/upgrades/2026-09-23-瑞昊新背部SPA现行分类修正/00-执行说明.md`：`f7145d1d92ff76c026af5ac56e686069fee5ac844b412f6eaac12d996ed53f6e`
  - `后端代码/database/upgrades/2026-09-23-瑞昊新背部SPA现行分类修正/01-修正.php`：`9c171e5aa9ee9b5788bc5573538d1c0ae81ca12a8a7924a096c817e975c76fca`
- 本闭环未提交。`DirectSnapshotEntitlementSettlementServices.php` 还包含另一任务的 `serviceObject` 修改，`StoreUnifiedReportServices.php` 还包含另一任务的 `project_count_decimal` 修改；提交时必须只暂存本闭环补丁，不得整文件夹带。
- 四个已跟踪修改文件的当前工作区补丁 SHA-256 为 `a9128b3ce7b2d7e6f8ff96f7db9ab83417e10740630194849a5704cce45a0208`；该指纹包括上述两处预存重叠改动，不可误作本闭环独占补丁。各闭环文件的精确字节指纹见上表。

## 事实与边界

- 瑞昊生产只读核对：FW092200045 对应项目 372012，2026-09-22 完成服务并记消耗 7100 分；其服务事实冻结的分类 ID 为 0，路径“未分类”。这笔金额并未丢失，但无法归到现行“生美/卡项”。
- 项目资料引用的旧分类 ID 21560 已不存在。结账准备原代码把卡内服务项目分类固定写 0；本次改为在结账事务中按实际服务项目冻结现行有效分类，最终落账对旧准备单再补冻。
- 产品经理已确认该项目业务上应归“生美/卡项”，同时明确“不改历史数据”，并确认修正项目当前配置、只影响后续结账。本次不改生产历史服务事实；既有快照仍如实显示在“未分类”动态列，不把业务确认倒填为当时已冻结的分类。
- 已备妥瑞昊单实例的配置修正脚本；默认演练、只有显式 `--apply` 才提交。尚未对瑞昊生产运行 `--apply`。

## 自测结果

- 5 个变更 PHP 文件 `php -l` 通过；`git diff --check` 通过。`CheckoutPreparationServices.php` 中既有构造函数产生 PHP Deprecated 警告，不影响语法结果。
- `docker exec -i mohe-app php < tests/report-store-item-analysis/php/category-checkout-integration.php`：13 项 PASS。覆盖事务门禁、有效分类、已删除分类、跨店项目、浏览器伪造分类、结账准备快照、最终落账分类继承、旧准备单补冻、服务事实分类路径、报表查询与导出同列、用户实际可见的取值来源说明，以及将当前项目配置更正为“生美/卡项”后的结账结果。使用本地瑞昊副本的真实项目资料，更正仅在测试事务内模拟并回滚；复核后项目分类仍为 21560，未发起生产结账。
- `php tests/report-store-item-analysis/php/contract.php`：8 项 PASS。
- `php tests/cashier-v3/php/c2-checkout-settlement-contract.php`：79 项 PASS、0 FAIL。
- 本地 `[133]`、2026-09-22 的 `store_item_analysis` 真实查询与导出成功，均含“未分类”列；本地副本无 FW092200045 服务事实，因此不把此结果冒充瑞昊生产 71 元的线上验收。
- 本地副本运行配置脚本默认演练：事务内 `21560 → 26332` 影响 1 行，随后回滚；复查项目配置仍为 `21560`。脚本 `php -l` 通过；未对本地或线上实例运行 `--apply`。
- 取值来源文案补验：`npm run build` 通过。刷新本地 18091 页面后，实际弹窗有 43 项说明，重复列名显示为“现金业绩/当日”等完整分组名；逐项文字不含“口径”，并说明未分类、历史不改和冲销按发生日扣回。通用固定列表原先覆盖业务文案的问题已在本报表内修复，没有改其他报表。
- 较广的旧套件未通过：`run-entitlement-completion-persistence-focused.sh` 在原有测试夹具 `service_snapshot_incomplete` 停止；`checkout-snapshot-direct-authority-contract.php` 为 42 PASS/4 FAIL；`c2-entitlement-completion-contract.php` 为 74 PASS/1 FAIL。失败项不在本闭环改动代码中，不能据此声称整套结账回归通过；需独立治理旧套件。

## 注释与交付分组

- 前端源码：上述 `StoreBusinessReportView.vue`；已为弹窗列名补全和导语的展示边界添加注释。
- 后端源码：上述两个 checkout 文件、一个 settlement 文件、一个 report 文件；已为新增分类冻结、旧单补冻、未分类报表分支及本报表说明优先级添加边界注释。
- 自动化测试：上述 `category-checkout-integration.php`。
- 数据配置修正：上述 `00-执行说明.md` 和 `01-修正.php`，尚未执行生产写入。
- 数据库结构迁移、工程脚本：无。
- 生成物：无。验收证据：本文件。
- 验收状态：产品经理于 2026-09-23 确认上述固定版本验收通过，并要求仅提交本闭环。此证据形成时未部署、未 push、未改瑞昊生产库；提交结果以 Git 日志为准。
