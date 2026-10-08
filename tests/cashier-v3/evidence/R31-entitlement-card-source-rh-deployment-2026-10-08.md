# R31 权益消费卡来源瑞昊发布记录

- 授权：产品经理确认本轮测试通过，并要求 commit、部署瑞昊、push。
- 功能提交：`c87116edfa3912b4ad68ef5e4125525ba0aff0bb`，`R31-00001 权益消费明细显示卡来源`，父版本 `94b5fdbc`。仅提交销售订单详情前端、订单查询后端及两份合同测试；并行任务的暂存和未提交文件未纳入。
- 自测：PHP 语法、订单服务快照合同、JS 卡来源合同、Vue 构建及本地订单 `FW100801710` 页面通过。PHP 8.5 的旧有隐式 nullable 提示不影响语法检查；本次业务注释更新于上述前端、后端及两份测试。
- 构建：从固定提交 `c87116ed` 归档 cashier-v3、inventory-vue3、unified-query-vue3，安装锁定依赖并构建 1991 模块通过；主 JS `index-83e566ae.js` SHA-256 `a25357ed56193f818e2f3a2ee8bee0deeeccb81c38bcc5352c38afce0e2e61b7`，CSS `index-301f8cdc.css` SHA-256 `301f8cdc2e6e0ae3d6b6cbe6e41d00ae19d4be7c5012177ee2147b61e853391d`。父提交独立构建的旧入口、JS 与瑞昊线上逐文件一致，排除未提交工作区污染和功能回退。
- 实例：仅瑞昊 `rh.cc3798.com`，站点 `/www/wwwroot/rh.cc3798.com/`，独立数据库 `ruihao`；其他客户未操作。本次无数据库迁移、配置、密钥或业务数据变更。后端仅替换 `app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php`；前端仅更新正式与 8080 预览两套收银入口，保留旧哈希资源。
- 备份与回滚点：`/www/backups/rh.cc3798.com/20261008-r31-c87116ed/`。旧后端 `backend-before.php` SHA-256 `07f620c6ea4ddac6d43e334b5235b28ce24098d23293291d3c8df38223bc207d`；正式包 `frontend-formal-before.tar.gz` SHA-256 `0153a8617ca5442b086a006803d6086052b2b43a9bdfb77912e41eb68da3df48`；预览包 `frontend-preview-before.tar.gz` SHA-256 `b4125741e7adde19098e445cb3c2fcb721ca04c3419710cd55a60b0158c2b199`。两包均通过 gzip 校验。回滚须先核对无后续发布，再恢复对应文件及旧入口并重启瑞昊单实例 Swoole；不改数据库。
- 部署：候选 PHP 经服务器 PHP 7.4 语法检查，候选与本地 SHA-256 一致。先追加两套新哈希资源，再原子替换后端、重启 `/etc/init.d/ruihao_swoole`，最后原子切换两套 `index.html`。新后端 SHA-256 `acf796b37213c35a854a67d3503460526e5d2c8d6653d2c1ce5af23c24f9dd4c`，两套新入口磁盘 SHA-256 均为 `103b1df3531bc882fec39bf8e7014c3509ee967ee6a966d6ab11eeb17aebf951`。
- 线上验证：正式及预览 HTTPS 入口均返回 200；正式入口内容与构建 SHA-256 相同，预览入口由代理加 `/preview-8080` 路径前缀，主 JS 两路 HTTP 响应 SHA-256 均与构建相同。Swoole 单 manager、20800 监听正常。已登录的瑞昊订单中心只读打开 `FW100801710`，桃花灸权益明细显示 `卡名称：1980随心挑`、`卡号：2355612`，单价及权益金额保持 `¥66`。
- 推送：Gitee `origin/release/store-cashier-c14-main` 快进到功能提交，`git ls-remote` 核对远端 SHA 完全一致。本记录随后作为纯证据提交和推送，不改变已部署业务版本。未推送 GitHub 镜像。
