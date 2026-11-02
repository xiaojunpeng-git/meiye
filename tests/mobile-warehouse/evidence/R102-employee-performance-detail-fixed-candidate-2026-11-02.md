# R102 员工业绩明细固定候选（2026-11-02）

基线 HEAD：`9f20e88a1aa42cd63aaa6d010889b7d0bb93cade`。固定候选采用该 HEAD 已提交的提成和项目数逻辑，加上下列第 102 轮增量；没有纳入共享暂存区对提成逻辑的删除。`git diff HEAD` 的已跟踪文件补丁 SHA-256：`84c7703d53394bcacdc8e5041ed5b9162ded364c531283e98572eb5ab5723bec`。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 商家端前端源码 | `前端代码/mobile-vue3/src/merchant/pages/employee-performance/index.uvue` | `c710c95e9b9f04835d7bbd071087e3d59c50ef8f987a83383d00963d431ac6b5` |
| 后端源码 | `后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php` | `0b059a32ec77838591771c7f93e18b3551d3ef4022ff18867c9323e914f349d5` |
| 后端源码 | `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `be295d8bff5f03b4d8d3bc576f9e7280c7cf204a5604560bf8ceb341bab917c6` |
| 自动化测试 | `tests/mobile-warehouse/php/employee-metrics-contract.php` | `eacbbd5d090d3d1272f9a8f2eb308455db1796895e985f25560af8546ed9d538` |
| 自动化测试 | `tests/mobile-warehouse/php/r102-employee-detail-readonly.php` | `a93b4937932ac8d9406da7d23542d09516c0edd62f91d343c6107ab32b8710f5` |

本轮增量：员工明细增加会员姓名和手机；充值单展示“充值”与原充值金额；劳动业绩明细展示核销数量及该员工保存的点／轮标记、服务对象与客数、参与手艺人的点／轮标记。项目数和提成沿用已提交的注册指标和计算逻辑。数据只从授权的明细页、原充值单和已保存服务事实读取，不改历史记录、指标合计或权限范围。相关业务注释已在上述三个源码文件补充或保留。

自测：PHP 专项合同通过；本地数据库 READ ONLY 抽样命中 1 条充值和 2 条权益完成事实并通过；PHP 7.4 两个后端文件语法通过；JS 页面合同通过；商家 H5 构建通过；`git diff --check HEAD` 通过。构建在 HBuilderX `uniapp-cli-vite` 工作目录执行，生成物在 `/tmp/r102-employee-h5-build-final`，未纳入源码。

限制：浏览器控制接口返回 `browser.user is unavailable`，未完成页面点击复测。手机号取当前会员档案，旧时点手机号快照不存在时不能还原旧号码；旧服务快照缺少点／轮标记时不猜测。未提交、部署、推送或操作线上数据，待产品经理对此固定候选确认验收。
