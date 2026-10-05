# R25 瑞昊旧卡退款发布记录

- 授权：产品经理对第25轮固定版本确认“我测试没有问题,commit,部署瑞昊,push”。仅发布瑞昊正式门店端、同实例8080预览门店端和相关后端，不更新其他客户、平台端、会员端。
- 代码提交：`416af3cd5e050c85c99e5e2439edc88d2a45f0b6`，`R25-00001 旧卡退款及办理门店业绩闭环`，共14个文件。提交包含前端4文件、后端7文件、自动化测试1文件、验收证据2文件；没有数据库迁移或工程脚本变更。CashierShell.vue 中无关的专属服务人多选修改未提交，其他任务脏工作区保留。
- 构建源：从上述提交 Git archive 导出 cashier-v3、inventory-vue3 和 shared，依赖复用本地 node_modules；未采用带其他任务修改的工作区构建。Vite构建1989模块通过，仅有既有分包体积提示。本机不可变发布产物位于 `/tmp/r25-rh-release.Rw6wvT/`。
- 自测：提交后再次运行 `tests/cashier-v3/php/r25-imported-card-refund-mysql.php`，21项PASS，覆盖退款权限、正整数校验、跨店归属、转赠限制、快照失效、退款事实、剩余权益终止、剩余欠款取消、已补交/核销历史保留、退款查询、统一指标对账、幂等重试。所有测试业务写入均已事务回滚。
- 发布前检查：目标 `rh-server`，正式根目录 `/www/wwwroot/rh.cc3798.com`，数据库 `ruihao`。6个既有PHP文件逐一与父提交匹配，新增退款服务原先不存在；相关事实表、权益表、欠款取消/恢复表及生命周期操作表存在，R14的 `idx_debt_latest` 已存在、旧 `uq_debt` 已移除。无迁移和生产业务写入。
- 备份与回滚：`/www/backups/rh.cc3798.com/20261005-r25-416af3cd/` 保存两端完整静态目录、6个既有PHP文件、新发布包与候选目录；3个备份压缩包通过 `gzip -t`。后端备份SHA-256 `f91cad141c2f6082dbb8f1173d4088720e4dc26792d6529124bd2c74a19db5bd`，正式静态备份 `8f7eaa3fb5fb4b303d36e63447965c1a0ea1b5b640ad8c74d18df24bae7c8f91`，预览静态备份 `886473c793f1abad3ab88a0d50d47cd03601d576f14a073c8f9e9a111160402d`。
- 发布包校验：后端包SHA-256 `bb766c3d79f984d3c00c3c72d097ccf55cba51a9830141391ceaf80b097a90f3`；静态包 `7903a9fecb3aef75d127352f3086a0d340ad09a7452ae57c7670298a5d833ad4`，传输后远端一致。7个PHP文件通过线上PHP7.4语法检查，部署后逐文件与候选一致。
- 部署：仅重启 `/etc/init.d/ruihao_swoole`，单manager PID26372，20800启动正常；未重启无关服务。正式和预览静态目录追加新哈希资源并原子替换index，保留旧资源。两端index SHA-256均为 `5fc561eebef8e6a0b803d61d124e43d7ea68fefa45276c3b5471d1700ecc0f50`。
- 线上核验：正式及 `/preview-8080/` HTTPS入口成功返回，新JS/CSS下载内容均与本地构建逐字节一致。JS `index-48996a9a.js` SHA-256 `c4ad950e1151ae2c93dd37c45c751724f7ed47f52b06b53bcbfc3f70e9bef1df`；CSS `index-8383fc36.css` SHA-256 `8383fc3693e340e899fe1e260a08d42d83d24cbcb1f722e1803293459d1b5a8b`。
- 运行时只读验证：新增服务加载PASS，动作清单自检为空；候选查询及退款动作均注册到 `cashier.v3.order.refund`，退款事件与幂等恢复契约正确。日志检查未见本轮部署后的相关Fatal/Parse/Uncaught；17:08:32有发布前只读核验脚本误用 `cashier_v3_order_operation` 的查询错误，已改用代码常量对应的 `cashier_v3_order_lifecycle_operation` 复核通过，不是业务运行错误，也未改库。
- 验证边界：线上没有创建真实退款；真实业务写入由本地事务测试和产品经理本地验收覆盖。本轮不声称已做生产资金退款。若回滚，先核实上线后有无旧卡退款事实；可恢复备份PHP及两端index、移走新增服务并重启瑞昊服务。若已产生退款事实，不可无评估退回旧统计代码，以免遗漏退款指标。
- 推送目标：仅Gitee `origin/release/store-cashier-c14-main`。线上核验通过后推送本功能提交及本发布记录，远端指针另行复核。
