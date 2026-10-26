# R60 第16轮盘点自制表导入：瑞昊发布

- 产品经理明确测试通过并授权 commit、部署瑞昊、push。源码与R60自测文件指纹一致，5项JS回归再次PASS，diff check通过。
- 本地业务提交 e0d098a3，准确6文件；前端2、测试2、验收记录/截图2。其他任务脏改动完整保留。无后端、迁移、永久工程脚本修改。
- 固定发布 84339a57ec595584c551afb197e0635720f05316，父版 d682d65e（瑞昊已上线R52员工保存权限发布树，已保留R59商品编辑及R52收银单价）。发布分支 release/r60-count-import-20261002，只叠加本轮6文件。cashier/shared源码保持现网父版，不夹带混合开发分支。
- 唯一源码正本库存Vue3与18091挂载一致；按固定Git archive构建，临时不可变构建目录 /tmp/r60-release.Ts1TE0，不维护第二套编辑源。Node16原生arm64，inventory及cashier构建成功，常规大chunk告警保留。
- 目标仅rh-server：正式 /www/wwwroot/rh.cc3798.com/public/view_inventory_v3、view_cashier_v3；预览 /www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3。不更新admin、后端，不重启生产服务、不改数据库或权限。实例数据库ruihao；最近既有升级记录沿用R52部署核对的20261001-001-mohe-ai-rights-amount-index，本次无迁移。
- 发布前正式/预览cashier HTML均876217f06d05da60493cae2a15892e3ac06156217e61d830f98cdee550f626b2，inventory HTML为3b35f316d2db3248d3eb0512fe688949bd899bc71570378bdf679570f4d2378e，与现网记录一致。
- 备份 /www/backups/rh.cc3798.com/20261002-r60-count-import-e0d098a3：prod-before.tar.gz SHA256 fdb205138fa8104519fa13ec7dd1ef67f40379e51580f2754042211deed34c6e；preview-before.tar.gz SHA256 d368bd412bade6fee11a921c0e7b03c68022ad0367a2537d2e7f9c8d5ed68386。gzip检查通过，包含对应完整静态目录。
- 资源追加上传，不删除旧hash文件；HTML同目录原子替换。回滚仅恢复备份中对应静态入口/资源，不回滚数据库、不覆盖营业数据。
- 线上与构建指纹一致：inventory HTML f7038c583c0019a2cc540077debd8dc1a8cf90a0df3a8c8d9b4af38def100062，cashier正式/预览磁盘HTML 4fa2ddfba4975b396dec8b92e271b8c6b5b8be1326ad37c08a36138a869dcbd9；inventory主JS index-8205194f.js ba69e53a50b98ef0e990cb2f4eee7ed9b21cb9fba36497c83942e5bd7a53b182；cashier主JS index-50f1fe96.js 50c59ea1b2e04e659a7d47ab0d4ba8b9f08789e622417618a7659678dc7c110f，正式/预览HTTPS下载一致。
- 预览HTTPS HTML受既有路径改写，指纹62524e61ee1596b2b8d83b09ced34fcebf9b153396f23a80a4098862775948a5；已核对引用新JS/CSS，不能把该正常路径改写误报成部署不一致。rsync checksum dry-run无内容差异，仅原子替换HTML时间/所有者和目录时间不同。
- 发布后system.html仍abf7ff8fb1bb5581776b555f7f6b6e3e7acd108ece9e88980bb962753fbcb17f，保留上一商品编辑补丁。新打包JS均包含自制文件表头提示，不再含旧工作表限制或多规格提示。
- 真实线上临时页30：admin/inventory/count → 新建盘点，工具栏含导入库存/导出盘点资料/导入盘点/保存草稿等，页面正常；浏览器error日志为空。截图R60-rh-count-import-entry-2026-10-02.png，随后关闭临时页，用户原3页保留。
- 限制：线上未导入用户文件（未提供）、未保存草稿、未完成正式盘点；导入业务以真实组件方法自动化回归及产品经理本地验收为证，不冒充线上库存写入验收。
- 线上核验通过后仅推送Gitee origin本轮隔离发布分支，不推送混合开发分支、GitHub或其他客户版本；远端最终SHA以ls-remote核对。
