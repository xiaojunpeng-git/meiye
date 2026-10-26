# R9 调拨菜单只读权限修复自测

日期：2026-10-11。基线 HEAD：`dac7aed256a477d375a77726fe71c67c6b5c9e79`。

状态：开发与专项自测完成，待产品经理确认验收。未 commit、未部署瑞昊、未 push、未打包 8080。

## 原因与范围

旧岗位已有 `admin-stock-transfer` 菜单，但没有新版库存 API 授权；总部调拨列表和详情还误用了仓库管理写权限检查，因此页面可见而查询被拒绝。

本次只将有效旧调拨菜单衔接到已登记的 GET 库位、总部调拨列表与详情接口。其他库存 API、导出、新建、发货、收货均不因此获得授权。没有修改真实员工、岗位、菜单或角色数据。

列表和详情改用可信的可读仓库范围；写命令继续使用原有可写范围。新建、发货和收货按钮能力由后端返回，不能仅凭单据状态开放。平台调拨页不再预取未授权的首页指标和请货受理部门。

## 文件分组与注释

前端源码与业务边界注释：
- `前端代码/inventory-vue3/src/App.vue`

后端源码与业务边界注释：
- `后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php`
- `后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php`
- `后端代码/app/services/system/SystemRoleServices.php`（包含前一阶段 R9 登录修复，需按新固定版本验收）

自动化测试：`tests/inventory/php/r9-platform-transfer-menu-access.php`。

数据库迁移、工程脚本、构建生成物：无。

验收证据：本文、同目录 `R9-transfer-menu-list-2026-10-11.png`、`R9-transfer-menu-detail-2026-10-11.png` 及 `R9-platform-login-transfer-fixed-version-2026-10-11.json`。新清单合并此前 R9 登录范围，保留旧清单作为历史记录。

## 数据与权限边界

- 权威来源：数据库中有效平台账号、启用角色与未删除菜单；不信任浏览器提交的角色能力。
- HTTP 兼容仅限精确 GET 白名单，并要求路由已登记；不补发通用库存查看或仓库管理授权。
- 组织、门店、总部仓与租户范围继续由既有后端范围服务决定；跨租户调拨不能查看。
- 写操作保留“原生库存查看 + 仓库管理”条件，不能由旧菜单替代原生查看前提。
- 不改变库存、资金、批次、成本计算、业务事件或历史单据；无迁移、事实回填、冲销、幂等或写事务变更。
- 本地数据库测试要求配置主机为 `mysql` 且 `@@hostname=de34b8634512`；合成角色、账号、菜单和单据在事务中测试并全部回滚。
- 本次未连接远程实例；远程迁移状态、实例备份、共享依赖与线上回滚点不适用。保持当前源码基线，不创建源码副本或 worktree。

## 自测结果

1. 本地 PHP 7.4 真实服务及路由鉴权测试：`docker exec -e MOHE_LOCAL_DB_HOSTNAME=de34b8634512 -i mohe-platform-app php < tests/inventory/php/r9-platform-transfer-menu-access.php`，36 项 PASS。覆盖精确 GET、写接口拒绝、其他库存接口拒绝、组织范围、跨租户拒绝、撤权实时生效、原生写能力不退化及合成数据回滚。
2. R9 登录回归：`platform-empty-menu-login.php`，26 项 PASS（含合成夹具回滚）；`platform-empty-menu-login.mjs` PASS。
3. 本次三个后端文件 PHP 7.4 语法检查 PASS；库存 `App.vue` 使用 Vue SFC 编译器检查 script/template PASS；`git diff --check` PASS。
4. 既有 `tests/inventory/php/cross-subject-transfer-contract.php`：12 PASS、1 FAIL。失败断言要求 `InventoryPlatformHqCatalogServices.php` 存在固定文本 `->where('p.type', 0)`，而未修改的当前代码为按来源区分的 `->where('p.type', $source['type'] === 'HQ' ? 0 : 1)`。该文件与 `InventoryPlatformHqInboundServices.php` 相对 HEAD 均无改动。本轮未扩大范围修订这项旧静态断言，不宣称全套回归通过。

## 真实浏览器验证

入口：`http://127.0.0.1:18081/admin/stock/transfer`，使用用户当前已登录账号，不伪造接口响应、不修改账号授权。

- 库位与总部调拨查询成功，原“您暂无权限访问”消失。
- 筛选“本月”（2026-10-01 至 2026-10-31），实际返回 16 条记录；只显示查看操作，新建按钮禁用。
- 打开 `DB2610100007`，实际详情接口 HTTP 200，页面展示商品及批次明细。
- 未新建、发货或收货；测试结束返回本月列表，保留用户原有标签页，并关闭网络诊断。
- 库存热更新服务曾返回旧代码；仅重启本地 `mohe-inventory-v3-src` 后完成验证。没有停止或部署远程服务。

此前 R9 登录页面截图使用合成响应，其局限仍然有效；本次调拨列表与详情证据是本地真实账号、真实接口和现有数据。

## 待验收

请按新固定清单验收 R9 登录与本次调拨只读兼容范围。其他库存菜单、旧未登记接口兼容策略、调拨写权限配置不在本轮修复范围。其余脏工作区及既有暂存改动保持不动。
