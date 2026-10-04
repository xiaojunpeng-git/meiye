# R23 瑞昊后台“指定门店”页面未切换排查与恢复

- 目标仅瑞昊正式站点 `rh.cc3798.com`。已验收并发布的 R18 固定功能提交为 `59c4548b`，隔离发布提交为 `84a20245`；本次未修改业务源码、数据库或配置，也未操作其他实例。
- 2026-10-04 排查发现：正式 `public/system.html` SHA-256 为 `e5c60372b7905a469a0fcf4b4a2a534d9d6dc8ee813f3cac5230a1802c07c2ef`，已引用 R18 新主包 `app.b2072f01.js`；但公网 `/admin/store/region/list` 实际引用旧包 `app.2ede8e9b.js`。定位到瑞昊 `runtime/temp/636c51bc9e3516bb9cc9838f85176980.php` 的旧模板编译缓存，SHA-256 `19b5502ad0307759e2def6b10252792604101bf80bbee77e20c231dc8d67a0bf`。
- 经用户要求线上更新，18:06（北京时间）将该单一旧缓存移入实例独立回滚目录 `/www/backups/rh.cc3798.com/20261004-r23-admin-template-switch/636c51bc9e3516bb9cc9838f85176980.php.stale`，原指纹核对通过。随后正常访问后台，模板自动重编译为新包引用，缓存新 SHA-256 为 `0bfe9f8985113867b619a8d2968c0ac816d181d71e59d6b154b0cc3db1494df7`；未重启服务。
- 公网 `/admin/login` 与 `/admin/store/region/list` 各连续请求 3 次，均引用 `app.b2072f01.js`。公开提供的 R18 业务分块 `chunk-7529b3b0.f1509ea1.js` 含“指定门店”和 `store_selected`；Swoole 保持单 manager，近期日志未见本次页面模板相关 Fatal、Parse 或 Uncaught。登录后人员保存操作未在生产环境执行，仍以原 R18 固定版本业务验收为准。
- 如需回滚页面模板缓存，可将上述 `.stale` 文件恢复到原 `runtime/temp/` 精确路径；正常发布版本仍应保持新 `system.html`，因此不建议无故恢复旧模板。
- 后续在线截图位于 `/admin/setting/staff/index`，并非原本地验收的 `/admin/store/region/list`。两入口共用人员弹窗，但已验收 R18 源码将“指定门店”按钮限定为 `scene === 'organization'`；员工管理列表仅对组织直属人员传入该场景，普通门店任职人员的编辑弹窗按既定权限边界隐藏按钮。故本次模板缓存切换已生效，却不会使普通门店人员出现按钮。产品经理随后明确“在平台端能看到组织选项就要能看到指定门店”，该新增范围的本地实现与自测另见 `R23-platform-selected-store-entry-selftest-2026-10-04.md`；本缓存恢复记录不表示新增范围已上线。
