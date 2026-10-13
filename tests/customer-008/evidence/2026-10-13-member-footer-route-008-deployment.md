# 008 会员端底部导航修复：发布与验证

- 产品经理验收及授权：本轮对脚本 SHA-256 `5caa9db49b6b52499c69de14d677a66aa7564b1a30bd63af5d506ae6a0455c99` 明确确认，只允许发布并验证 008。
- 已验收本地 commit：`cbd471b1e33c83fb51b835463cb937d61f9f9d28`，`M008-20261013 会员端底部导航修复`。提交仅含工程脚本与固定版本证据；原工作区其他改动未夹带。
- 实例：`008.cc3798.com`；数据库 `mohe_008`、MySQL 3307；站点现有会员 H5 文件保持原状。007、012、rh 未部署。
- 发布前：远端脚本 SHA-256 与已验收版本相同，PHP 7.4 语法通过，`check` 核对唯一 DIY 行为 r4 且原值摘要为 `6ed38d50208a62ce036b6ceede75466e5b4005deafee54174ef53b8fabc2c203`。
- 执行：获取 008 独占锁，保存唯一 DIY 行的私有回滚快照 `/www/backups/008.cc3798.com/mall-diy-before-20261013_093006.json`，事务内 CAS 更新并只清理 `008:` DIY 缓存。
- 发布后：`verify` 和 `/api/navigation` 均返回 r5；DIY 值摘要 `cbc5a1267e623027e0f51b9367ed2f277d952371d706bd102fa38183de46c592`。五项菜单为“首页 /pages/index/index、美疗师 /pages/activity/therapist_list/index、社区 /pages/discover/discoverIndex/index、项目 /pages/users/user_card_list/index、我的 /pages/user/index”。
- 真人路径：在 008 已登录会员 H5 预览中，从“我的”页点“美疗师”，进入“服务老师”并显示美容师与预约入口；再点“项目”，进入“我的项目”并显示已迁入权益及剩余次数。又点“社区”“我的”“首页”均成功进入目标页。会员积分仍显示 4950；无浏览器错误日志。用户原有预览标签页已刷新以加载新导航。
- 本次未改会员/员工/商品/交易记录，未改静态资源、H5 业务源码或其他客户实例；未执行 Git push。
