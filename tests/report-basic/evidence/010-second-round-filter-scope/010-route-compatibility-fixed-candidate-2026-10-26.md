# 010 基础报表路由兼容补验候选（2026-10-26）

## 现网复现与处置

- 已验收、已提交基线：`06be6bf002ed739a9c0f57936276dfd34f1faa9b`。010 现网第二轮后端、门店端入口及主 JS/CSS 与该候选一致；仅 010 配置 `platform_menu_all_stores = true`。
- 角色 36、37、38 原有 24 个分组/单表菜单授权仍在，但岗位编辑保存时移除了已隐藏的旧目录菜单 ID 2320。旧平台 Vue 路由仍检查 `admin-report-basic`，因此 A0751 点“报表”进入 403。后端单表接口按精确叶子授权，未误放行。
- 已备份 010 角色规则至 `/www/backups/010/20261026-basic-report-scope-06be6bf0/roles-before.json`（SHA-256 `69bde9b62d0a536c87af7a507ee9e6ccf9fef9944f6bbb1ea2b68995afc307fa`），并只为这三角色恢复其原有旧目录 ID 2320。恢复后 A0751 浏览器点“报表”进入门店统计表，查询 10 个门店；员工项目销售数分析查询 331 行、合计 90,079 元。此恢复是应急处置，后续岗位编辑仍可能再次移除该隐藏 ID。
- 线上浏览器截图：`010-online-employee-item-sales-2026-10-26.png`，其中显示“全部门店”、单行筛选区、331 条分页及实际查询结果。截图只作为已上线候选及应急恢复的验证，不能冒充本补丁已上线。
- 010 发布前备份 `/www/backups/010/20261026-basic-report-scope-06be6bf0/before/`，清单 SHA-256 `5cacee80f7e6f01d8c080a00faacb1eb0e374842f0276c1bd7a46af00bf30058`。现网 `SystemMenusServices.php` 原件与源码均为 `b151e99e17b1534fa0b2e8a2a20a3b204715e7c400365b1a26cd495f00384fe2`。

## 固定补验范围

- 后端源码 `后端代码/app/services/system/SystemMenusServices.php`：只在 010 功能开关开启、平台普通账号且拥有 21 张基础报表中至少一张精确叶子授权时，在返回给旧 Vue 路由的 `unique_auth` 展示投影中补 `admin-report-basic`。不写角色规则，不改变后端单表读授权、刷单编辑授权、门店端或其他客户实例。
- 自动化测试 `tests/report-basic/php/platform-route-compatibility.php`：验证 21 张表逐个可获得旧路由令牌；空权限、目录权限、未知权限不获令牌；已有令牌不重复。
- 前端源码、数据库迁移、工程脚本：本补验候选无改动。平台完整构建的本地资源指纹与 010 现网不同，故不以整包覆盖 010。保留现有 010 平台 JS/CSS、单列菜单样式及其他已上线功能。
- 验收证据：本文。文件 SHA-256：服务 `31dc628ca78dd58af3bbe30089207fab38560d5b4b5bc07ce35e22df3af280de`；测试 `05429b63290f52214ef6d5e763beca2d108576319b0b29da717031e22890ecf8`；服务补丁指纹 `1ef86ed941dd5478f1e8bc64e0d673ded8148652429f05c10ac8c2036850ac38`。

## 自测与上线门禁

- `php -d error_reporting=0 tests/report-basic/php/platform-route-compatibility.php`：PASS（21 张表及拒绝场景）。
- `php -d error_reporting=0 tests/report-basic/php/platform-menu-plan-contract.php`：PASS（三组 21 个精确菜单）。
- 两个本轮文件 `php -l`：PASS；`git diff --check`：PASS。补充的业务注释位于菜单服务的旧路由令牌边界及测试文件首部。
- `tests/organization-store-v3/php/platform-menu-ancestor-integration.php` 需容器内 `/var/www/html/vendor/autoload.php`，本机直接运行未能启动，不能计为通过。
- 现网服务文件未发布本补验候选；当前浏览器成功仅验证已验收版本及应急角色恢复。产品经理对本固定补验候选明确验收后，先单独本地提交，再重核 010 现网指纹、备份该文件、只部署 010、重启 010 服务并做浏览器与权限回归。未点名其他实例不操作。
