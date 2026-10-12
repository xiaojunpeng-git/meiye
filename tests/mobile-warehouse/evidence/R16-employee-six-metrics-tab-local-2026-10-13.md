# OPT5 R16：员工业绩六项统一指标与底部导航，本地固定版本

## 范围与业务口径

- 员工业绩页恢复现有商家端底部导航，权限过滤沿用 `MerchantPrimaryTabBar`；数据页签为当前页，末行留出导航和安全区空间。
- 指标固定两行、每行三项：现金业绩、劳动业绩、点客数；服务人次、项目数、手工费。页面只展示接口值。
- 六项分别读取注册指标 `staff_sales_yeji`、`staff_labor_yeji`、`staff_point_visit_num`、`staff_service_num`、`staff_project_num`、`staff_labor_fee`。人员现金业绩是销售人分配事实，不是整店收款；服务人次和点客数来自已完成服务的十分之一计数；项目数来自人员劳动分配事实的百万分之一计数；金额按统一函数显示整数元。移除原始事实行数推算服务会员、项目数和独立点客排行值。
- 服务端继续按当前商家权限生成门店与员工配对；日期、权限和明细下钻仍由服务端约束。未改业务写入、事实、统计日期、冲销、导出和数据库结构。

## 固定文件版本

- 基线 HEAD：`29b25bbe6ec68af5f5fc2d437ae46a8516544213`。
- 本闭环补丁 SHA-256：`1266e68386865d5fe9a65be94f040fdbe8a3863a8e7d4b527ee671ed7a6ac87f`，由下列四个跟踪文件的 `git diff --binary` 与一个新增测试文件的 `git diff --no-index --binary` 按表中顺序合并计算；未纳入其他脏工作区改动。

| 类别 | 文件（相对源码正本） | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/mobile-vue3/src/merchant/pages/employee-performance/index.uvue` | `42a8db578fd1fc53c65cd6b00d053b63c5e9f067eaf54b20741cffaf890e7be6` |
| 后端源码 | `后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php` | `2cb61c88b51fa74aebbdd7db4a1feb3e71e8ca70ff0a575d6781491e550461c3` |
| 自动化测试 | `tests/mohe-ai/metric-registry-contract.php` | `573bea6ee3b6924417065b096a3a925527df2ed3cab0b8eb983c5509373c5e57` |
| 自动化测试 | `tests/mobile-warehouse/php/employee-metrics-contract.php` | `e5d20b9d59875eccbace310b0bdec17051bb6ee7fb03a98e04112c675d8a036c` |
| 工程脚本 | `tests/mobile-warehouse/run-contract.sh` | `294b22922e8cd2cd1c33711dcc6ba9ea83389bea1aa67aaf6d4826b849266815` |

数据库迁移、生成物：无。验收证据：本文与本轮浏览器 531px 视口观察。修改业务注释的文件为上述员工业绩前端页与 `MobileWarehouseServices.php`；注释说明导航归属、服务端授权、原始指标单位、身份兜底和金额口径。

## 本地自测

1. `bash tests/mobile-warehouse/run-contract.sh` 通过：组织及门店权限与分页合同、六项注册指标单位、十分之一与百万分之一精确展示。`php tests/mohe-ai/metric-registry-contract.php` 98 项通过，确认员工页不再读取原始明细行数或独立点客排行。PHP 语法、`git diff --check` 通过。
2. 在本地 `mohe-platform-app` / 独立本地库只读运行服务：员工 663 的授权门店 126 查询员工 922，2026 年 10 月返回六项，值依次为 128000、3658、0、14.5、41、14；逐项与同门店、员工、日期的注册 Reader 原始结果核对一致，现金明细总数为 2。
3. 同一授权查询 `2000-01-01` 的无事实日期，六项均为 0、明细总数 0，员工姓名可见；查询仅在其他门店有记录的员工 787 被服务端拒绝。员工 714 本人“今天”接口也返回六项 0 和新版本 `mobile-employee-registered-v2`。
4. 本地 H5 使用现有瑞昊线上旧接口，在 531px 视口直接检查员工业绩页：底部显示经授权过滤的“数据、客户、工作台、我的”四个 Tab，“数据”选中且详情卡未被导航遮盖。点击“我的”到账号页，再点击“数据”回到员工 714 本人业绩页并成功加载。当前 H5 指标仍是线上旧五项，不能将该页面视为新六项的线上验收证据；新六项已由本地服务真实数据验证。

## 状态与边界

- 开发及本地自测完成；产品经理已确认 R16 固定版本验收通过并允许仅提交本闭环。本记录形成时未 commit、未部署、未 push、未上传体验版。
- 工作区同时存在其他任务的已暂存、未暂存和未跟踪文件；提交时必须仅暂存本表五个闭环文件与本文，不能使用 `git add .` 或 `git add -A`。
