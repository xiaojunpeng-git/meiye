# R19 新客明细与汇总：来源 A 全路径过滤及同来源合行

> 历史阶段证据：分公司维度后续已对齐明细与汇总。当前固定验收版本见 `R19-new-customer-company-dimension-selftest-2026-10-03.md`；以下文件指纹描述的是维度修正前的阶段事实。

- 固定基线 HEAD：`1e2ceff3bc79aab350c036c040a82888a9440561`。
- 当前四个目标文件相对 HEAD 的补丁 SHA-256：`6be715e7c25e08980ac892ee2c5bc89d2c3914362209f1b5a5a9430d0551a7e2`。这些文件包含 R19 前序尚未提交的变更，以如下文件 SHA 为固定验收版本。
- 后端源码 `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php`：`8e2ce5e04662b0c97de7de60fd23dccfefdf27f5884127d86cd892c0817e5955`。
- 前端源码 `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`：`02a3dc5ec1a60c12d5e480f1fa15fbfa38b4aa4aeff21fc9bc9cc160f244e26d`，仅同步“列名取值来源”的用户解释，避免继续声称来源 A 补交会显示。
- 自动化测试 `tests/cashier-v3/php/new-customer-first-course-report-integration.php`：`4c8b432a4ba3eedd38a36bbe292ccb348eeba99c761dfe769df342f9b88d765d`；`tests/cashier-v3/php/phase-two-report-projection-contract.php`：`1723c08fb7158bf2900732857d56eab649f5c512240c9f8e66fdc533a48c9100`。
- 状态：开发与本地自测完成，待产品经理对固定版本确认验收；未 commit、未部署、未 push、未改本地或线上历史数据。

## 口径与数据架构自检

首次有效疗程卡销售事件决定新客资格；销售商品事实和成功收款分摊事实分别提供原销售行及定金、清款。新客明细、汇总现在对首次销售和后续欠款补交各自的历史来源都排除一级 A：优先核对来源 ID，同时核对发生时名称快照，包含已停用或缺配置的历史来源。来源 B 首单的来源 A 补交不再出现在新客两表的明细、分组、合计、下钻或导出中，也不转算成 B 收款；底层补交事实保留不动。未来如经授权审计修正线上补交事实的真实来源，报表会按修正后的同一事实口径重新展示。

同一销售商品行、业务日、门店及人员/来源分组的补交合入原明细“清款业绩”；不同业务日或归因保持独立。清款仍按收款事实发生的业务日归属，不改变原事实时间。金额在服务端以分累计，用户可见值沿用统一格式化函数；明细与汇总同源，导出复用同一后端查询。权限仍由后端既有门店/组织范围约束，前端不计算或隐藏经营数据。没有新增写事务、幂等路径、迁移、数据回填或索引；内存合行只作用于已查询的授权行集。已作废、退款及反向事实规则保持原样。

本次同步更新了来源 A 全路径过滤、同归因合行和历史快照判定的业务注释。口径版本升至明细 `new-customer-first-sale-v4`、汇总 `new-customer-first-sale-grouped-v5`；其他报表不变。

## 本地只读验证

- 对门店 23、2026-10-01 至 2026-10-03、会员 1136106 的后端查询：新客明细 1 行，来源 B，定金 980 元、清款 200 元；新客汇总 1 行，来源 B，10 月定金 980 元、清款 200 元。原来源 A 的 100 元补交没有进入任一报表，但数据库收款事实未改变。
- `docker exec -i mohe-platform-app php < tests/cashier-v3/php/new-customer-first-course-report-integration.php`：PASS，当前样本 7 张有效首单、7 人；覆盖来源 A 筛选返回空集、两表合计分精度对账、同归因合行、汇总精确下钻及导出一致。
- `php tests/cashier-v3/php/phase-two-report-projection-contract.php`、服务与测试 `php -l`、目标文件 `git diff --check`、Vue 3 `npm run build`：PASS；构建仅有既存大 chunk 提示，忽略的 `dist/` 不作为源码或本次 8080 集成包。
- 本次变更的前端源码仅为对应报表说明文案；数据库迁移、工程脚本均无新增。验收证据为本文件。仍需产品经理在页面复核两表、来源筛选、下钻与导出；本地技术自测不代替验收。
