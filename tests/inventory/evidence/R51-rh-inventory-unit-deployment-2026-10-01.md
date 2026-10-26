# R51 瑞昊库存规格单位与盘点校验部署记录

## 授权与范围

- 产品经理已明确回复“验收通过”，随后授权 `Commit,部署瑞昊，push`。
- 固定业务提交：`29c3d1ef94f6988ed5b172e3328da71fdf0c98ec`（`R51 库存规格单位与盘点校验闭环`）。
- 目标仅为瑞昊独立实例 `rh.cc3798.com`，数据库只读核对为 `ruihao`；`007`、`008`、`012` 未操作。
- 本次发布两处门店端静态包和 `InventoryStockCountDraftServices.php`；没有数据库迁移、配置、Redis、队列、计划任务或真实库存数据写入。

## 固定构建与数据架构自检

- 构建从固定提交 `29c3d1ef` 的隔离发布目录生成，未包含当前工作区其他未提交任务。
- `inventory-vue3` 构建通过（1821 modules），`cashier-v3` 集成构建通过（1986 modules）。
- 发布 HTML SHA-256：`f6eab2b02310c1095282b26bc49e2ee7e109ba23adf36ead8c89c4006eceaf02`。
- 主 JS：`assets/index-74032427.js`，SHA-256 `683009d4e91f4fc1e31fa77aec342c2c2f29d74dc9360c8b0a6b1b9441662145`。
- 主 CSS：`assets/index-1dcd4c8e.css`，SHA-256 `1dcd4c8e12d7552addefe77145ecdff2465e9cf5154d129606fee827b2ec5c11`。
- 后端文件 SHA-256：`7576f764c2fdc2c400282139bfc3be1ebdcc73c75ce13ea0b1867c671ec12ca9`。
- 单位继续来自 SKU 权威库存单位；盘点草稿只保存发生时单位快照，不新建平行数据源，不改变库存事实粒度、生效事件、事务、并发、幂等、冲销、权限或统计口径。

## 备份与回滚点

远端备份目录：`/www/backups/rh.cc3798.com/20261001-r51-inventory-unit-29c3d1ef/`。

- 正式前端旧包：`prod-before.tar.gz`，SHA-256 `23422ac44c48f6592911606ffda16dae671c07111776b308350c386e6063f15b`。
- 预览前端旧包：`preview-before.tar.gz`，SHA-256 `9e0b314fc1c3cdbe6ebc9686a33ffea601a09b91d8e1893ae823e6af9d480121`。
- 后端旧文件包：`backend-before.tar.gz`，SHA-256 `9e33991e53c81c3dd3bf43792d55ae43748cebabf72243ce0c5bb92d40bb62ef`。
- 三个压缩包均通过归档读取检查。回滚只恢复两处 `view_cashier_v3` 和该后端文件并重启瑞昊 Swoole；本次没有数据库变更，不覆盖发布后的业务数据。

## 部署与线上验证

- 首次连续上传结束时 SSH 主动断开；核对确认新资源和三个临时文件已上传，但两处线上 HTML、后端文件及 Swoole 均仍为旧版，未形成用户可见的半切换。随后校验临时文件指纹并完成原子替换。
- 瑞昊 Swoole 重启成功，单一管理进程 PID `29281`，端口 `20800` 正常监听。
- 正式入口 `https://rh.cc3798.com/view_cashier_v3/?release=29c3d1ef` 返回 HTTP 200。
- 预览入口 `https://rh.cc3798.com/preview-8080/view_cashier_v3/?release=29c3d1ef` 返回 HTTP 200。
- 正式和预览线上主 JS、CSS 均与固定构建逐字节一致；两处服务器 HTML 及后端文件也与固定发布文件一致。
- 发布后最近十分钟日志未发现 `Fatal error`、`Parse error` 或 `Uncaught`。本次只读验证未保存盘点草稿、未完成盘点，也未修改真实库存。

## 文件分组

- 前端源码：`前端代码/inventory-vue3/src/App.vue`、`InventoryBusinessModal.vue`、`countWorksheet.js`、`countSubmission.js`。
- 后端源码：`后端代码/app/services/product/inventory/InventoryStockCountDraftServices.php`。
- 自动化测试：提交 `29c3d1ef` 中 7 个库存 JS/PHP 契约文件。
- 数据库迁移、工程脚本：无。
- 生成物：固定提交构建的 `cashier-v3/dist`，未纳入源码提交。
- 验收与部署证据：本文件。

## 隔离推送

- 当前共享开发分支相对 Gitee 同名分支还包含其他任务历史，因此未整体推送，避免扩大本轮范围。
- 以已推送的 R48 库存发布基线为父版，精确叠加尚未推送的 R49 盘点目录校验和本轮 R51 文件，形成隔离发布提交 `3dbaeb14bb84bbb1930dafdd6dbb3599db48b08c`。
- R51 的 12 个业务与测试文件逐个核对 Git blob，均与已验收提交 `29c3d1ef` 完全一致；R49 未被 R51 覆盖的文件也与 `55f0a25d` 一致。
- 隔离分支 `origin/release/r51-inventory-unit-20261001` 已推送至 Gitee，并通过 `git ls-remote` 核对功能发布提交。GitHub 未推送。
