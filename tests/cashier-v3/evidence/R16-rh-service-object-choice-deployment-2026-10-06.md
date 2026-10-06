# R16 收银服务对象选中态瑞昊发布记录

- 授权与版本：产品经理确认 `cb2a2ebb085caf44383f04ec96846b28249e8df61559a3c7582a78276041d1b7` 对应的修复测试无问题，并授权 commit、部署瑞昊、push。业务提交为 `51c336f5`，仅含收银前端、回归测试和本地自测证据。
- 范围：只发布瑞昊正式门店端和同一实例的 8080 预览门店端。后端、数据库、其他客户实例均未改动；本次无迁移，沿用部署前瑞昊业务环境。
- 构建：从 `51c336f5` 归档提取 `cashier-v3`、`inventory-vue3`、`shared`，离线安装依赖并构建通过，Vite 1989 模块。入口 HTML SHA-256 为 `e4321a6f1469de36a2baa66951e50c98b5656ae0a492c32ce2f7b5b83d9a8d99`，主 JS 为 `7144578f303bd1ee73e775d18ab393246b18275fd8cb503a5a5370ecec1aebf2`，主 CSS 为 `db963faf73f79e01d7283fd98fa3ee4770cccdbf8b141533dd4bb33aa7b2bd59`。未从脏工作区构建。
- 部署前检查：两套旧入口 HTML SHA-256 均为 `bb0a55e0b53a3af133a99223d648e79d8514b55860ff736a1839b075bc328eee`，与上一版 R15 发布记录一致；Nginx 配置测试通过，磁盘空间充足。
- 备份与回滚：`/www/backups/rh.cc3798.com/20261006-r16-service-choice-51c336f5/` 保存正式及预览静态目录完整压缩包，`gzip -t` 均通过；压缩包 SHA-256 分别为 `b6e44e1addb0c6ea8885d084cd5b7ff16ee65777d86aa6c9da86de7914330b7c` 和 `80f28fbaee9ea84eb9bea1fcb1a9fac1e7bbd53e8f2091557b484990ddd24f5c`。出现异常时从该批次分别恢复对应目录；无需回滚后端或数据库。
- 部署方式：候选包 SHA-256 为 `f729e7fa0be5b60fdf53652b6c8d853c7df8acd3f8ba2d82abd0b6967cce1779`，服务器解包核验后，两套目录仅追加缺失的哈希资源，逐一原子切换入口 HTML，旧资源保留。部署后两套入口及主 JS 均与候选指纹一致。
- 线上验证：从本机严格校验证书访问 `https://rh.cc3798.com/view_cashier_v3/` 及 `https://rh.cc3798.com/preview-8080/view_cashier_v3/`，均返回 HTTP 200、TLS 验证结果 0；两端页面都引用本次主 JS，外网下载 SHA-256 均为 `7144578f303bd1ee73e775d18ab393246b18275fd8cb503a5a5370ecec1aebf2`。预览浏览器停在登录页，本次没有登录或创建线上收银单；三态互斥点击验证见同批次本地自测证据，不能冒称已在线上完成登录后的点击验收。
- 已知限制：独立旧测试 `business-source-checkout-frontend-contract.mjs` 的充值来源断言在基线同样失败，与本项按钮高亮无关；本次未扩大修改范围。
- 推送目标：仅 Gitee `origin/release/store-cashier-c14-main`，线上验证后推送并复核远端指针。
