# M008 历史权益预约门店修复：008 发布记录

- 产品经理验收并授权：六文件清单 SHA-256 `0a75081b19dcd9a5208fdb1547ad533af4302e02c1110394d02a5750c86b28aa`，仅向 008 发布。
- 本地源码提交：`83e349f9e414a42b09f95e256f4ed350fbe214e2`（`M008-20261013 历史权益预约门店修复`）。
- 目标：`008.cc3798.com`，`/www/wwwroot/008.cc3798.com`，数据库实例 `mohe_008`。未触及其他实例或数据库数据。
- 首次静态包 `84b099286cefd303a8613f4d615f508b95b36fdec82af7ad1ba37a084c64a8d4` 虽生成文件，实际浏览器出现 `Module parse failed`。立即恢复 `historical-reservation-83e349f9-20261013_101621` 文件回滚点，确认旧入口、旧 PHP 哈希和 008 服务状态恢复。该包未保留在线上。
- 使用 HBuilderX 5.26 随附传统 Vue2 CLI 的 `uni-build` 正式入口，从同一提交重新生成 503 文件静态包；包 SHA-256 `5b445d4dbf1b9431ddd49df774f99b884e3b9d8456332c6d04112493d74b0eb9`。构建日志无 `Module parse failed`，全部生成的 JS 文件通过 `node --check`。
- 正式发布前文件回滚点：`/www/backups/008.cc3798.com/historical-reservation-83e349f9-clean-20261013_102309`。部署 3 个后端 PHP 文件和 503 个 H5 文件；逐文件核验与静态包一致，008 Swoole 服务重启后为 `active`。在线 `public/index.html` SHA-256 `01a424139708accf13f90efc074b4bd4bb831f7384db1dfb9496e75936522e9a`。
- 线上真人路径：已登录的 008 会员预览中打开 `orderId=5338&cartInfoId=13967`；预约页显示“请选择门店”；点开列出渼约营业门店；点击“金宸店”后门店名称回填。原用户标签重新打开该预约页，浏览器无新加载错误。
- 未点击“立即预约”，未创建正式环境预约。预约写入、幂等及重复预约拦截已在 MySQL 5.7 隔离库验证；生产端只做选店与页面验证。
- 未执行 `git push`。
