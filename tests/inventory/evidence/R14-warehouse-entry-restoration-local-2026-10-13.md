# R14 仓库设置入口恢复：本地固定验收版本

基线 HEAD：`b8f74793f68b92955ffdea207551eaba3d679950`。产品经理要求恢复瑞昊平台的仓库设置导航，并要求渼约008同时开启；本记录对应代码与 SQL 的本地自测，尚非线上验收或部署授权结果。

## 变更范围与 SHA-256

| 分类 | 文件 | SHA-256 |
|---|---|---|
| 平台前端源码 | `前端代码/admin/src/store/modules/admin/modules/menu.js` | `38dd7575d6224d71e2f28e7f820d0eb23e66bdb478603a1fbfe5fe787cca6de5` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-13-库存仓库设置入口恢复/01-正式升级.sql` | `74dc26ae0a34fe1edaff37ae2a1da651fd05c6dd36a8fe1acaf5907279236f7c` |
| 迁移检查 | `后端代码/database/upgrades/2026-10-13-库存仓库设置入口恢复/02-升级后检查.sql` | `424512fdc6db3e4e1708acf08cd407e1712a5e69291768288ff212363d5756f8` |
| 自动化测试 | `tests/inventory/js/warehouse-settings-entry-contract.mjs` | `68b1f61cdaceb69a73b32fbfca84ffec6eeed251e893e89d38b20b4229dcda77` |

后端业务源码、工程脚本：无。本轮本地构建生成物在 admin `dist/`，不纳入源码清单；无新增库存事实或生产测试数据。

## 自测

- `node tests/inventory/js/warehouse-settings-entry-contract.mjs`：PASS。菜单不再被前端二次过滤；原有路由权限保留；迁移只针对仓库设置菜单显示标记，不增删角色规则。
- `node tests/inventory/js/hq-multi-warehouse-contract.mjs`：18/18 PASS。
- 本地 Docker `ruihao` 数据库事务内将目标菜单临时设为隐藏，执行正式升级 SQL 后唯一目标菜单恢复可见、岗位规则不变，最终事务回滚：PASS。
- 平台 `npm run build` 完成；仍有既存 `ensureCustomerSiderEntries` 未使用及资源体积警告，不影响构建完成。本次不能把含其他工作区变更的 `dist/` 直接作为两站正式发布包；发布时须从验收后的固定 commit 构建。
- 本地浏览器 `http://127.0.0.1:18081/admin/inventory/count` 的库存侧栏和顶部标签均显示“仓库设置”；点击后到 `/admin/stock/warehouse`，仓库列表、查询、新建按钮可见（本地现有 4 条总部仓）。未执行仓库创建或编辑。
- 线上只读核对：瑞昊 `ruihao:3306` 菜单 id=2288、008 `mohe_008:3307` 菜单 id=2285，均为 `is_show=0`；两个实例要分别执行迁移和验证，不互相复制数据库或配置。

## 数据架构自检与发布边界

- 权威源仍为各实例的 `eb_system_menus` 及现有角色规则；库存、批次、流水和业务单据没有变动。事实粒度、业务时间、事务记账、幂等、冲销、报表口径均不受本次导航恢复影响。
- 前端只展示服务端返回且当前岗位有权限的菜单；后端仍强制检查仓库设置的读写权限和组织范围。迁移不自动给任何岗位追加规则，也不打开其他库存写命令。
- 两站独立备份与回滚：发布前各自备份目标菜单记录、站点代码和静态入口；回滚可将目标菜单显示标记恢复为原值，并恢复各自前端包。无跨客户共享数据修改。
- 状态：本地开发和自测完成，待产品经理确认此固定版本验收；未 commit、未执行两站迁移、未部署、未 push。
