# R19 新客两表分公司组织维度一致性：本地自测

- 当前固定 HEAD：`2a82bc8c8e7275ac93425265dbe155f99da6ab7b`；并行任务在本轮期间提交了其他闭环，以下目标文件仍未提交。
- 四个目标文件相对当前 HEAD 的补丁 SHA-256：`3203933f140111cb8937cf5c95916f3884ba30690ebde70e366bae68b15a7514`。
- 后端源码 `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php`：SHA-256 `7ca5c7eb7e690443a1d99f8bdc42f940e5a3eb04f1390b82bbcb7593778bd484`。
- 前端源码 `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`：SHA-256 `02a3dc5ec1a60c12d5e480f1fa15fbfa38b4aa4aeff21fc9bc9cc160f244e26d`；该文件延续同轮来源 A 说明文字，本次未新增前端改动。
- 自动化测试 `tests/cashier-v3/php/new-customer-first-course-report-integration.php`：SHA-256 `58aac7a8f5639970dd6b9ef1b9d6690c94993148f89907b16c01c1b5bb1ed986`；`tests/cashier-v3/php/phase-two-report-projection-contract.php`：SHA-256 `ff75c83e9ed47a7a725aeb13702a1ab60dda3ad30b66cd0450e06b2150194198`。
- 状态：开发和本地自测完成，待产品经理对固定版本确认验收；未 commit 本闭环、未部署、未 push、未修改历史数据。

## 原因和修复

此前新客明细在统一报表出口按业务门店的组织统计维度解析“分公司”，而汇总先按收款事实中的 `organization_name_snapshot` 分组；该快照在扬州欧尚店为“严玲经理”，并非分公司。汇总行没有业务日期及组织 ID，最终出口不能再纠正已聚合的错误分组。

现在原销售商品行、补交行及汇总的收款、人次三类输入都先用同一个组织维度服务解析分公司，再按分公司维度 ID、门店、人员及来源形成同一分组键。组织维度筛选在汇总累加前生效；汇总行保留维度 ID，使下钻精确落到同组明细。口径版本升至明细 `new-customer-first-sale-v5`、汇总 `new-customer-first-sale-grouped-v6`。此前确认的来源 A 过滤、同日同归因补交合行、定金与清款时间口径保持不变。

## 数据架构自检与验证

- 权威源仍为首次有效疗程卡销售事件、销售事实、成功收款事实及分摊事实；分公司由统一组织维度服务按业务门店及其组织树解析，不从人员姓名或交易时普通组织名称猜测。
- 仅改变后端读模型的分组和展示，不新增业务命令、事务、幂等键、事实、迁移或历史回填；退款/作废与权限仍沿用统一事实及后端门店范围。内存分组前完成维度筛选，避免先混合再过滤造成金额错配。报表查询、合计、导出、下钻走同一后端口径。
- 门店 23、2026-10-01 至 2026-10-03 的本地只读查询：新客明细和汇总均显示“江苏分公司”，维度 ID 均为 `131`，同组键一致；汇总仍包含清款 200 元，不出现来源 A。分公司 ID `131` 有结果，错误 ID `999999` 无结果。
- `docker exec -i mohe-platform-app php < tests/cashier-v3/php/new-customer-first-course-report-integration.php`：PASS，当前样本 7 单/7 人；新增汇总每组分公司名称及维度 ID须与精确下钻明细相同的断言。
- `php tests/cashier-v3/php/phase-two-report-projection-contract.php`、PHP 语法、范围 `git diff --check`：PASS。仍需产品经理核对页面、筛选、导出和下钻；自测不代替验收。

文件分组：前端源码延续同轮原有文案改动；后端源码为统一报表服务，新增“维度须先于分组”注释；自动化测试为上述两个 PHP 文件。数据库迁移、工程脚本、生成物无新增；本文件为验收证据。
