# R61 瑞昊盘点导入跳过缺失商品发布

- 产品经理对固定版本明确验收通过并授权 commit、部署瑞昊、push；两项源码/测试 SHA 与自测记录一致，五项 JS 回归和 diff check 再次通过。
- 本地业务提交 d84c11cc，精准三个文件（前端源码、自动化测试、自测证据各一）；其他任务既有脏改动全部保留。无后端、数据库迁移或永久工程脚本变更。
- 已上线 R53 发布树 b482b1be09ac3d67b0c0daf2c455b9681f787648 为父版；只叠加本轮三文件，固定发布 600e44fcbd8651da379b206b07e5b11219adf414，分支 release/r61-count-import-skip-20261002。不推送混合开发分支。
- 唯一正本 inventory-vue3 与 18091 实际挂载目录一致；cashier/shared 沿用刚上线的 R53 基线，不夹带其他未上线改动。固定 Git archive 临时构建目录 /tmp/r61-release.4lfAhu，Node16 arm64 构建 inventory/cashier 成功，保留既有大 chunk 提示；不另建开发源码或 worktree。
- 目标仅 rh-server 正式 /www/wwwroot/rh.cc3798.com/public/view_inventory_v3、view_cashier_v3，及 8080 预览 /www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3。独立库 ruihao 无任何读写或迁移；既有迁移状态沿用 R53 发布核验（升级日志 202 条），本轮无新增迁移依赖。不改 admin、后端、配置、权限，不重启生产进程。
- 发布前 inventory HTML f7038c583c0019a2cc540077debd8dc1a8cf90a0df3a8c8d9b4af38def100062；正式及预览 cashier HTML 67339cc3bff81e3186fb476acf02a37483bb5df46a5abd557fa960fa26094813，保留刚上线 R53 成果。
- 独立备份 /www/backups/rh.cc3798.com/20261002-r61-count-import-d84c11cc：prod-before.tar.gz SHA256 ce91f1f747d53371b0a72bc39f7550ce1b54c9575f4c4509ffa38ef458750720；preview-before.tar.gz 2821df8087d217819d5f11f9657983b529c5f3f906af5dedafe574c6905bd665。gzip 校验通过。仅追加 hash 资源、原子替换 HTML，未删除旧资源。
- 发布后 inventory HTML b80f59c7ee3049a583ad9030da2e2d879e9beb42f784dcca8cab2bfcc63b0901；正式/预览 cashier HTML fa6f04abb18f338e41ce1cc7fe9975db3d6dbb649b1809125c98314f6583ad72，与本地构建一致。
- HTTPS 主 JS：inventory index-b07e3106.js SHA256 98e2b46fb15f92c439765826f67673e2516ded0c4438b61e35b36fa84b2db46a；cashier index-62264d99.js 9c782fb1e1b40e922ecb272a816201f5a7edcde7b0819a1b3fbb29c935ed54f7。正式与预览均与本地一致。system.html 仍为 abf7ff8fb1bb5581776b555f7f6b6e3e7acd108ece9e88980bb962753fbcb17f。
- 真实线上临时页32打开 admin/inventory/count，再打开新建盘点；表格/导入盘点等入口正常，浏览器 error 日志空。截图 R61-rh-count-entry-2026-10-02.png。未上传用户文件到生产，未保存草稿或完成盘点；业务以真实组件方法、原始文件只读自测和产品经理验收为证，不冒充生产库存写入测试。临时页关闭，原用户页保留。
- 回滚：仅恢复本次备份内对应静态入口/资源，先核对仍是本发布版本且无后续更新；不回滚数据库、不覆盖营业数据、其他任务成果或配置。
- 线上核验通过后推送目标为 Gitee origin 的上述隔离发布分支；不推送 GitHub 或其他客户版本，实际远端 SHA 在最终回执记录。
