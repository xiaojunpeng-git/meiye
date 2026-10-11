# R9 库存其他菜单只读兼容自测

日期：2026-10-11。开始基线 HEAD：`dac7aed256a477d375a77726fe71c67c6b5c9e79`；交付固定基线 HEAD：`4a00cf487274dcf88460ac091567a7527ba04679`。

期间其他任务提交了收银源码、迁移脚本和证据；开始基线至交付基线的文件清单与本轮固定清单无交集。本轮没有创建这些提交或回退其他任务改动。

状态：开发与专项自测完成，待产品经理确认验收。未 commit、未部署、未 push、未打包 8080。

## 原因与修复

有效旧库存菜单与新版 API 权限没有衔接，部分只读接口在旧数据库权限目录中还缺少登记；请货筛选目录与调拨对方目录又误依赖仓库写权限，造成页面可见但查询无权。

本次扩展此前调拨修复，按平台路由既有菜单逐项衔接入库、出库、盘点、库存查询、请货、调拨、配方和院装的精确只读接口。原库存首页菜单只兼容既有路由明确接受它的页面，不替代入库、出库、盘点或库存查询菜单。

权限目录缺项采用源码精确 GET 白名单与当前有效菜单共同校验，不写数据库补权；未知路由不放行。此方案替代此前调拨记录中的“兼容接口必须已登记”限制，旧证据保留历史，不代表现版本。统一查询额外检查 pageCode，HTTP 与领域上下文均拒绝跨页面查询。原生库存查看岗位原有读取范围和写能力保留。

菜单兼容不补发通用查看、仓库管理、导入、导出或查询设置能力。新建/导入按钮和单据取消、终止、作废能力消费后端写授权。配方原有独立写接口未改动，本次兼容仅涉及 GET。没有修改真实员工、岗位、角色或菜单数据，没有新建/作废/发货/收货/领退业务数据。

## 本次文件分组

前端源码（含职责与能力边界注释）：
- `前端代码/inventory-vue3/src/App.vue`

后端源码（均补充或更新权限、读写边界注释）：
- `后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php`
- `后端代码/app/services/system/SystemRoleServices.php`
- `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php`
- `后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php`
- `后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php`
- `后端代码/app/services/product/inventory/InventoryStockRequestServices.php`
- `后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php`
- `后端代码/app/services/product/inventory/InventoryPlatformHqInboundServices.php`
- `后端代码/app/services/product/inventory/InventoryPlatformHqOutboundServices.php`
- `后端代码/app/services/product/inventory/InventorySalonUsageServices.php`

自动化测试：
- `tests/inventory/php/r9-platform-transfer-menu-access.php`：在原专项测试中扩展菜单矩阵，替代旧辅助方法调用。
- `tests/inventory/php/platform-operational-query-contract.php`：同步只读旧菜单与页面隔离契约，保留成本可见和数据范围断言。

数据库迁移、工程脚本、构建生成物：无。验收证据为本文、同目录七张库存菜单截图与合并固定清单 `R9-platform-inventory-menus-fixed-version-2026-10-11.json`。此前 R9 登录源码、测试和证据纳入合并清单，但不改写其历史验证方式。

## 数据架构与安全边界

- 权威权限来自当前有效平台账号、启用角色、未删除菜单和原生能力；不信任浏览器提交的角色权限。
- 组织、门店、总部仓和租户继续由原后端范围服务裁剪。没有门店范围不能因有院装菜单就查询任意门店。
- 单据、库存批次、移动事实、数量、金额、时间口径、历史快照、冲销与幂等机制不变；没有新增经营事实、写事务或事实回填。
- 兼容映射是有限路由表；沿用角色与范围查询，不新增前端统计或逐单据权限 SQL。列表和详情动作能力均来自后端。
- 本地合成测试要求配置主机 `mysql` 且 MySQL `@@hostname=de34b8634512`；合成账号、角色、菜单、单据全部在事务中回滚并检查无残留。
- 旧 `allowsLegacyTransferRead` 已由统一方法完整替代，后端、库存前端和库存测试引用扫描无残留。
- 只使用本机源码与本地依赖，未连接远程实例；线上迁移、实例备份与线上回滚点不适用。仅重启本地库存热更新容器以加载新源码；没有建立第二份源码或 worktree。

## 自动化验证

1. `r9-platform-transfer-menu-access.php`：100 项 PASS。覆盖各菜单自身读取、跨页面拒绝、未知/畸形 pageCode 拒绝、统一查询领域校验、读写分离、原生写能力不退化、跨租户拒绝、撤权实时生效、合成数据回滚。
2. `platform-empty-menu-login.php`：26 项 PASS（含回滚）；`platform-empty-menu-login.mjs` PASS。此前登录合成浏览器证据不冒充本轮真实账号登录验收。
3. `platform-operational-query-contract.php`：12 项 PASS。旧静态检查要求仅原生查看授权的文本，已按本次兼容口径更新，并增加页面与写授权边界检查；真实执行仍由第 1 项覆盖。
4. 本次十个后端文件 PHP 7.4 lint PASS；库存 App.vue 的 Vue SFC script/template 编译 PASS；`git diff --check` PASS。
5. `cross-subject-transfer-contract.php`：12 PASS、1 FAIL。既有失败要求未改动的 `InventoryPlatformHqCatalogServices.php` 存在固定文本 `->where('p.type', 0)`，实际代码按来源使用 `->where('p.type', $source['type'] === 'HQ' ? 0 : 1)`。本次不修改该商品目录服务或此旧断言，不宣称全套回归通过。本次入库服务已有权限动作修改，不能再沿用此前“入库服务也未修改”的历史描述。

## 真实浏览器验证

入口为本地 `127.0.0.1:18081` 平台热更新页面，使用用户当前已登录账号；不拦截或伪造业务接口响应，不修改账号授权。结果如下：

| 页面 | 实际结果 | 证据 |
| --- | --- | --- |
| 入库 | 库位及列表业务状态 200；本月 0 条，空表正常、无无权提示；新建/导入禁用 | R9-inbound-menu-fixed-2026-10-11.png |
| 出库 | 实际接口 HTTP 200，空表正常、无无权提示；新建/导入禁用 | R9-outbound-menu-fixed-2026-10-11.png |
| 盘点 | 统一查询业务状态 200；空表正常；新建禁用 | R9-count-menu-fixed-2026-10-11.png |
| 库存查询 | 商品汇总业务状态 200；空表正常、无无权提示 | R9-stock-menu-fixed-2026-10-11.png |
| 请货 | 受理部门和请货列表业务状态 200；空表正常；新建禁用 | R9-request-menu-fixed-2026-10-11.png |
| 配方 | 原配方列表业务状态 200；空表正常；原独立写入口未改动 | R9-recipe-menu-fixed-2026-10-11.png |
| 院装 | 当前账号没有授权门店，明确提示“当前岗位无可操作门店库存仓。”，查询和新建禁用；未请求院装数据列表 | R9-usage-scope-2026-10-11.png |

此前真实调拨月列表 16 条及详情成功证据继续保留；本次专项测试复测该路径。院装精确路由兼容和无门店拒绝已自动化验证，但没有冒充完成具有门店范围账号的院装真实数据查询。当前大多数页面是空表，不能据此声称所有非空单据状态或业务写操作都已经真人验收。

结束时浏览器返回入库管理，页面可正常查询，网络诊断已关闭，保留用户标签页。其余脏工作区与既有暂存改动保持不动。

## 固定验收

合并清单记录 R9 登录、调拨及其他库存菜单修复的基线、源码/测试/证据 SHA-256 与补丁指纹。只交付本地开发与自测结果，待产品经理对该清单范围确认验收；不执行提交、部署或 push。
