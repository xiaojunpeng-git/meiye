# 010 基础报表第二轮线上发布与路由修复（2026-10-26）

## 发布身份与范围

- 目标只有 `010.cc3798.com`，实例根目录 `/www/wwwroot/010.cc3798.com`，服务 `mohe-010-swoole.service`，数据库 `mohe_010_app`。其他客户实例未操作，未推送远程 Git。
- 已验收第二轮提交：`06be6bf002ed739a9c0f57936276dfd34f1faa9b`。其线上范围为平台 21 张基础报表的单表菜单读授权和全部 010 门店、门店端仍限当前门店，以及 21 张表主筛选区单行排版。
- 补验通过的路由修复提交：`f565477dd3c1fed79a7bcdf96c1914f4ea7ed7b1`。仅部署 `app/services/system/SystemMenusServices.php`；平台前端 JS/CSS、其他路由和门店端包均未替换。

## 备份、防回退及部署结果

- 第二轮发布前文件、配置备份：`/www/backups/010/20261026-basic-report-scope-06be6bf0/before/`；清单 SHA-256 `5cacee80f7e6f01d8c080a00faacb1eb0e374842f0276c1bd7a46af00bf30058`。候选先上传至同目录 `candidate/`，核对哈希和 PHP 语法后切换，失败自动回滚。本次无数据库迁移，010 `.env` 启用 `[BASIC_REPORT] platform_menu_all_stores = true`。
- 010 角色 36、37、38 保留全部 24 个已授权分组/单表菜单；旧隐藏路由菜单 ID 2320 因岗位编辑被移除，导致 A0751 点“报表”进入 403。已先备份原规则到 `/www/backups/010/20261026-basic-report-scope-06be6bf0/roles-before.json`（SHA-256 `69bde9b62d0a536c87af7a507ee9e6ccf9fef9944f6bbb1ea2b68995afc307fa`），事务中仅为这三角色恢复原有 2320，其他规则及角色未改。
- 补验部署前确认 010 现网 `SystemMenusServices.php` 与源码同为 `b151e99e17b1534fa0b2e8a2a20a3b204715e7c400365b1a26cd495f00384fe2`；备份到 `route-compatibility/SystemMenusServices.before.php` 后，上传候选并复核 SHA-256 `31dc628ca78dd58af3bbe30089207fab38560d5b4b5bc07ce35e22df3af280de` 和 PHP 语法。仅替换该文件，重启 010 服务；失败自动恢复备份。
- 部署后服务 `active`，线上服务文件为 `31dc628c…`；原有平台报表控制器 `cbb9147a…`、门店报表控制器 `e91fe22e…`、平台入口 `4f7d2bfc…`、门店端入口 `77bdcb62…` 均与补丁前相同。远端 PHP 对已部署类实测：有单表叶子得旧路由令牌、空权限仍空，PASS。

## 实际业务路径验证

- 用 A0751 登录态从 `/admin/store/statistics` 展开顶部导航，点击“报表”到 `/admin/report/basic-reports/basic_store_summary`，未进 403；“全部门店”范围，实际返回 10 个门店，分页共 10 条。截图 `010-online-store-summary-after-route-2026-10-26.png`。
- “员工项目销售数分析”重载后仍可查询，返回 331 条、17 页，合计显示 90,079 元；旧“当前数据权限不能查看无本人归属的人工登记”提示不再出现。截图 `010-online-employee-item-sales-2026-10-26.png`。
- 本次补丁未授予新后端菜单权限：010 单表读接口仍逐个核对已授权叶子；刷单编辑仍需原写权限；门店端不读取此平台路由投影。前端原有菜单单列样式和门店端包资源指纹未变。
- 本次没有可用的门店端浏览器登录态，因此门店端“仅当前门店”以已验收源码的契约、自测和部署后文件指纹核对为证；不冒充本轮有人机门店查询。

## 归档文件分组

- 前端源码：本补丁无。后端源码：`后端代码/app/services/system/SystemMenusServices.php`。数据库迁移：本补丁无；角色规则仅恢复已验收的 010 原有隐藏路由授权。自动化测试：`tests/report-basic/php/platform-route-compatibility.php`。工程脚本：本补丁无。生成物：010 在线 PHP 文件和已部署的第二轮门店端资源。验收证据：固定候选、本文和两张在线截图。
- 回滚点：补丁文件可由 `route-compatibility/SystemMenusServices.before.php` 恢复并重启 010 服务；第二轮整包可按 `before/` 清单还原。回滚必须同步核对 010 数据权限开关和角色规则，避免出现页面可见而接口拒绝的不一致状态。
