# R43 瑞昊非盘点库存明细滚动发布记录（2026-10-01）

- 范围与验收：产品经理确认本轮测试通过，并明确要求 commit、部署瑞昊和 push。业务提交为 `4eb2f7514213f3b4707fb630a84cdccf85a43c9f`，仅修改 `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue`；入库、出库、请货、调拨、院装及非盘点详情共用受限高度的纵向滚动区和固定表头，盘点继续使用原有独立规则。
- 本地验证：`inventory-vue3` 构建通过（1818 modules），隔离提交构建的 `cashier-v3` 通过（1983 modules），`git diff --check` 通过。真人操作入库页加入 11 条商品后，明细容器 `clientHeight=442`、`scrollHeight=600`、`overflow-y=scroll`、滚动位置可到 `158`，表头为 `position: sticky`；出库、请货、调拨、院装逐页核对均加载同一非盘点规则。盘点容器仍为原 `60vh` 规则，实测高度 `571.2px`。
- 注释：组件模板和样式均补充了“非盘点共享、盘点独立”的职责与边界说明，防止后续公共选择器覆盖已验收的盘点滚动方案。
- 目标实例：仅瑞昊正式生产 `/www/wwwroot/rh.cc3798.com/public/view_cashier_v3/` 与独立 8080 预览 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3/`；`007/008/012` 未操作。无数据库迁移、Redis、队列、计划任务或后端服务变更，前端静态发布无需重启进程。
- 部署前状态：两处旧入口 HTML SHA-256 均为 `00dfffcb0e22f53a4fe52007a1374c7bf633cba9d6a783a33629852a2d7f9ce2`，磁盘剩余约 228 GB；目标均为实际目录而非符号链接。
- 备份与回滚：回滚目录 `/www/backups/rh.cc3798.com/20261001-r43-inventory-scroll-4eb2f751/`。正式旧包 `prod-before.tar.gz` SHA-256 为 `974a048389c88419c40e81b062a0bd5017b7d775ef07b4901244e05376f1fe5f`；预览旧包 `preview-before.tar.gz` SHA-256 为 `d4bec8afd0229e85a7dd817666dcbc648c1617dc4f4502d8389af76557b6328f`，两包均通过 `tar -tzf` 校验。回滚只需分别恢复对应静态目录，不触及数据库和业务数据。
- 发布指纹：两处入口已原子切换到 HTML `4aac2a182fe3adebae5a8c556718b7ea0a712e1c75ec1a72a31c6ac1787e50ae`、主 JS `9b48d0a123a7b1cd9d7ddc4068c03f6d171c9d8e34364ab40db63ecb5d882daa`、CSS `690b9cebc1624c823419ce903bfb8e0c932ddb7a2c7a1bd585c2d1a3c9432d67`。正式与预览 HTTPS 入口均返回 200，线上 JS/CSS 与隔离构建指纹一致，CSS 包含非盘点排除标记、盘点独立类及稳定滚动槽规则；线上登录页正常加载。业务交互已在相同提交的本地 18091 热更新入口完成，线上验证未登录、未保存单据、未变更真实库存。
- 推送：共享开发分支相对远端含其他任务历史，未整体推送。以已推送的 R41 盘点滚动版本 `1967a51659c5f8bfb2f3c1b9e10eb397ecef2fff` 为父提交，精确写入本轮唯一文件 blob `a036a5c39752a4e6629a080d0486b1188c04e4bb`，形成瑞昊独立发布提交 `e5ba076b9595204a152a3bd52c288d94a8eeecca`；该 blob 与业务提交 `4eb2f751` 完全一致。已推送并核对 Gitee `origin/release/r43-inventory-scroll-20261001`，GitHub 未推送。

文件分组：前端源码 1 个；后端源码、数据库迁移、自动化测试、工程脚本均无改动；本文件为发布证据；本地 `cashier-v3/dist/`、`inventory-vue3/dist/` 与瑞昊两处静态目录为生成物。
