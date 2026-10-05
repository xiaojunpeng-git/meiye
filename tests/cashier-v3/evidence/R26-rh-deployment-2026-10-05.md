# R26 瑞昊市场两表说明发布

- 授权：产品经理确认本轮测试无问题，明确要求commit、部署瑞昊、push。固定验收文件和补丁指纹均与自测记录一致。
- 功能提交：`39a6c82b`，`R26-00001 明确市场两表体验及不计人次说明`。5个文件：后端源码1项、自动化测试1项、验收说明及截图3项。其他任务改动未提交、未部署。
- 目标：SSH `rh-server`，主机 `iZbp1fif4p5s7gj96nnec0Z`，站点 `/www/wwwroot/rh.cc3798.com`，数据库只读核对为 `ruihao`。其他实例不变。
- 范围：仅部署 `app/services/report/StoreUnifiedReportPhaseTwoServices.php`，取自固定提交Git归档。本轮没有前端源码改动，无需重打包；没有SQL迁移、数据库写入或配置变更。门店端、预览端沿用既有静态资源，查询时读取新后端说明。
- 发布前目标文件与父提交一致，SHA-256 `47f9e1b608eafa4b6ad4a515dc62ad285f2c99788a18e654792fd772aed6ae8b`。
- 备份：`/www/backups/rh.cc3798.com/20261005-r26-39a6c82b/StoreUnifiedReportPhaseTwoServices.before.php`，指纹与原文件一致；同目录保留提交导出的候选文件。回滚可恢复该备份文件并重启瑞昊Swoole，无数据库回滚步骤。
- 部署：PHP7.4语法检查通过，候选指纹匹配后原子替换；仅重启 `/etc/init.d/ruihao_swoole`，单manager PID23041、20800正常。新线上文件SHA-256 `b0f357e1df1050e31ed20d13b5cba1fbd9a1d853f0725230e45718ce346bfa6a`，与验收及提交一致。
- 本地复测：体验人次与本人/朋友不计人次两组MySQL回滚测试再次通过，没有遗留测试业务写入。
- 线上只读验证：调用生产统一报表服务，门店165（江苏东台吾悦店）、2026-10-01至2026-10-05；汇总1行、明细49行。汇总新说明列9项、明细新说明列1项。发布前后records/summary_row/totals联合指纹相同：汇总 `8649ac099fa9e6b159ab2147b5c952b2e2c72bdf360fc1c7653a298b113f1aad`，明细 `66121e4a0328eba371d1fc9fd69366e9793ec0a038a8d479c9e3fd213389f5db`。证明此次说明修订未改变该范围数据或合计。
- HTTPS：正式 `/view_cashier_v3/` 及 `/preview-8080/view_cashier_v3/` 入口均200。线上使用服务级只读核验，未操作真实业务或重新登录生产账户；浏览器文字布局已在本地验收。
- 发布验证通过后推送至Gitee `origin/release/store-cashier-c14-main`，包含本功能提交与本发布记录；最终远端指针在推送后复核。
