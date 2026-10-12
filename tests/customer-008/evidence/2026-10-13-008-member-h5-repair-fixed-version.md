# 008 会员 H5 入口修复：固定版本交接

状态：Codex 本地开发与自测完成，待产品经理确认本固定版本验收；尚未提交、部署或推送。目标实例仅 `008.cc3798.com`，不写数据库。本批源码与证据逐文件 SHA-256 见同目录 `2026-10-13-008-member-h5-repair-files.sha256`。

## 根因与修复范围

- 线上只读实测 `https://008.cc3798.com/pages/user/index` 返回 HTTP 500，响应为 `template not exists: /www/wwwroot/008.cc3798.com/public/index.html`。008 `public/` 中确无会员 H5 `index.html`，平台 `/admin/login` 返回 200，`mohe-008-swoole.service` 正常。
- Vue2 会员端唯一源码是 `前端代码/uniapp/`。从此源码构建完整 H5 静态包，保留现有同源接口选择和后端会员鉴权；不修改收银页面、会员数据、积分、权益或 008 PHP 服务。
- 旧构建含瑞昊专属 OSS 字体和预览品牌。源码改为随包发布通用 iconfont 字体，并将仅用于视觉预览的固定瑞昊名称改成中性名称；共享 `manifest.json` 的旧“邻邻柒”应用名改为“会员服务”，具体站点品牌继续由后端站点配置提供。员工中心旧渼约域名上的入口小图标也随包发布，避免旧站停用后缺图。

## 本地构建与验证

- 源码基线 HEAD、逐文件 SHA 与补丁指纹以正式交验时的清单和消息为准；本次业务源码仅 6 文件：`manifest.json`、`pages/design_preview/index.vue`、`pages/admin/staff_center/index.vue`、`static/iconfont/iconfont.css`、新增 `static/iconfont/iconfont.woff2` 和 `static/staff-center/edit-introduction.png`。其中字体来自统一源码原先引用的阿里图标字体，员工入口图标来自同一渼约旧站；未复制瑞昊客户文件。
- 使用本机 HBuilderX 5.26 的 Vue2 `uniapp-cli` 做 `NODE_ENV=production`、`UNI_PLATFORM=h5` 净构建，产物目录 `/tmp/m008-h5-release-fixed-20261013/`。构建完成，仅有体积建议警告；487 个文件、17,854,364 字节。排序后逐文件清单 SHA-256 `74d12736d640e089b021daec475244255538c75e6e810cdf6ab5b9cb9cab85b8`，入口 `index.html` SHA-256 `7da2528dd5e8e14907149996d5a677f420f4ccb535c0d45a7758a1622debcf3c`。供部署的固定包 `/tmp/m008-h5-release-fixed-20261013.tar.gz` SHA-256 `d38b5fde0e65d84e362356ca52ac2829580b91818c7a2d9b392992e814644845`，其中 487 个不含绝对路径与上级路径的文件。
- 入口引用的 3 个 CSS/JS 文件均存在；本地图标字体与员工入口图标均存在。全包 JS/CSS/HTML 扫描未发现 `ruihaoxitong`、`rh.cc3798.com`、`瑞昊美业`、`REHO BEAUTY`、`邻邻柒` 或旧 `meiyue.meiyuekeji.cn` 依赖。`git diff --check` 通过。
- 本地静态服务配合只转发未登录 GET 的 008 API 代理验证：`/pages/user/index` 在浏览器可渲染个人中心，显示“请点击授权”；点击后转到 `/pages/users/login/index`，显示手机号验证码登录入口。代理拒绝 POST；未发送短信、未提交登录、未修改 008 数据。此测试不等于正式站上线后的真人登录。
- 线上静态目录只读交集核对：待部署包与 008 既有 `public/static`、`public/assets` 的唯一同路径文件为 `assets/uniicons.b6d3756e.ttf`，双方 SHA-256 同为 `89ed7d6d732e893a473c6d6b443f854f4dcdf57360a11016306335a2048895ff`。008 没有 `public/pages/`；包不会覆盖现有平台、门店与收银入口。

## 部署边界与上线验证

产品经理验收固定版本后，按项目规则精确本地提交。仅在取得本批明确 008 部署授权后，部署该提交构建的上述固定 H5 静态包：先记录 008 当前静态文件与目录快照，重新核对包 SHA 与路径碰撞，再将 `index.html`、`static/`、`assets/`、`pages/` 写入 008 `public/`。无需数据库迁移或其他实例操作。上线后验证 `pages/user/index` HTTP 200、入口资源及登录页可加载、008 API 仍由同源访问，并复核此前已导入的会员余额／积分／权益数据库对账。若要声称真人会员登录和页面三项数值可见，还需在 008 使用已授权的真实会员会话实际完成；本地未做此项。

## 数据架构自检

本批是会员 H5 静态入口和通用资源修复。权威会员、余额、积分和权益仍为 008 后端及其业务表；没有新增业务事件、事实、统计时点、金额计算、事务、并发写入、幂等键、冲销或历史迁移。前端仅按既有 API 展示，登录和数据权限继续由后端强制执行。既有 008 业务资产导入对账结果不因本包重算或改写。源码注释更新位于 `manifest.json` 和 `static/iconfont/iconfont.css`；入口图片与视觉预览为简单展示改动。

文件分组：前端源码为上述 6 文件；后端源码、数据库迁移、自动化测试和工程脚本均无新增或修改；生成物为本地 H5 产物与部署 tar（不入 Git）；验收证据为本说明与逐文件 SHA 清单。
