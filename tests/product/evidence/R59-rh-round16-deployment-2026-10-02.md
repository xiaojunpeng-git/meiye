# R59 第16轮商品编辑：瑞昊发布记录

## 授权与提交范围

- 产品经理对既定版本明确“我测试没有问题,commit,部署瑞昊,push”。验收源码 SHA-256 为 5cebcc172467c70acfcab6286a40835da0c925f77826294ae335cc127c689e5e，测试为 e69dd98bf54cfa8a645656c552afdffc7892364ca4243202e01fb0c24bc7f208，复核一致。
- 本地闭环提交：515a0dd939a6587d0b1cd40572a5e31558afe857，R59 第16轮缺失规格商品编辑加载闭环。精准暂存5个文件：Vue源码、JS测试、自测记录及两张本地截图；其他任务脏改动未暂存。
- 固定发布提交：db96261d，分支 release/r59-product-edit-20261002，父版 c08dbc0a。使用独立临时Git索引叠加上述5文件，不切换工作区，不创建worktree；发布树与上一发布树仅这5文件不同。
- 对比确认81665075（上一平台端线上发布记录）与c08dbc0a的admin/shared源码零差异。当前HEAD另有shared未发布变化，不纳入本轮。产品编辑源码与18081唯一源完全一致；未将服务器产物反向当源码。

## 构建、备份和发布边界

- 本机原生arm64 Node16串行生产构建，固定Git archive只读构建暂存 /tmp/r59-build.AJiMHu；链接既有node_modules，无第二套可编辑业务源码。构建退出0，244项存量lint/资源等警告，未宣称零告警。
- 前端源码仅admin/src/pages/product/productAdd/index.vue。后端、数据库迁移、永久工程脚本无变更。自动化测试与证据不上传业务目录。
- 仅目标rh-server、/www/wwwroot/rh.cc3798.com、公网rh.cc3798.com、数据库实际ruihao。发布前后只读核对库名及目标商品；未写数据库。最近升级记录包含20261001-001-mohe-ai-rights-amount-index，本轮无迁移差集。
- 独立备份 /www/backups/rh.cc3798.com/20261002-r59-product-515a0dd9/admin-before.tar.gz，包含system.html与完整view_admin，gzip校验通过，SHA-256 f37314fe41b5a3aff20456c0c976c7d9887ba544e2f65bc2eccf4e41dd6aab1e。
- 原线上HTML SHA-256 1404f751744b70aaf30177fed4f5017f35492d2cb0267b0e52590dee3fac792c，与本地既有平台产物及上一发布记录一致。
- 只追加上传view_admin构建资源，不删除旧资源；system.html同目录原子切换。未发布门店端/库存Vue3、未动8080预览、其他客户、配置、Redis或后端进程。
- 回滚仅恢复备份中的system.html及原平台静态资源；不恢复全库、不改变商品/库存/权限。已有全库回滚备份为上一发布登记的ruihao备份，本轮静态发布不另写库或执行迁移。

## 自测与线上验证

- 提交前再次执行product-edit-missing-spec.cjs、2项编辑加载守卫合同、5项详情只读合同，全部通过；git diff --check通过。
- 上传后rsync checksum dry-run无差异。线上文件及HTTPS下载指纹均与构建一致：
  - system.html：827d4f4d3d60ad8ec994fece60d8c4a18f9af682669c5d562d2d4b5b17b78541。
  - app.4c65a554.js：187ead763c14eecfc9a64be5f03b58649f2ca670a5ca004fbb3d0fcadfb81288。
  - 商品编辑chunk-1692f7e4.d4cefa9f.js：6c6bad4c40e1dcdf81a8ec9966d2a63b908b8293b6e425fd709ed144115301df。
- 真实HTTPS已登录页面打开290295：水漾至美套盒、单位套、商品编码正常回显，明确显示规格资料缺失提示。
- 点击未补全规格时的保存，定位到规格页，售价/成本价/划线价为空，显示补全提示。CDP此次操作后POST请求数组为空，事件未截断、无剩余分页。没有创建SKU或执行正式商品保存。
- 线上前后只读结果相同：is_del=0、主表售价/成本3980.00、type0 SKU数0。线上商品状态与本地is_del=1不同，是发布前已有状态，不将其解释成本轮恢复。
- 线上截图：R59-rh-product-edit-basic-2026-10-02.png、R59-rh-product-edit-save-guard-2026-10-02.png。未在生产补齐规格后执行正式保存；该写入路径不是本次线上验证声称的范围。
- 门店端和库存入口HTTP200；未登录详情API返回HTTP200/status410000“请登录”，无500。

## 推送范围

线上验证完成后，按授权只推送Gitee origin本轮隔离发布分支及发布证据；不推送混合开发分支，不推送GitHub。实际远端tip在完成推送后核对并回传。
