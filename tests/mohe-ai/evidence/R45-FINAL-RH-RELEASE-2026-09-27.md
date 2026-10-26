# R45 导航及输入框调整发布

- 用户固定版本确认后要求commit、部署瑞昊、push。业务提交：51494919（电脑移除加号、Execl左对齐）、37c0ca37（手机居中单行标题与左侧退出）。
- 手机闭环精准提交6文件：组件、控制器测试、自测记录、截图及两个废弃SVG删除。其他任务脏工作区未纳入。
- 实际部署仅瑞昊平台静态资源。小程序源码已提交，未上传体验版，不宣称手机已线上生效。未改后端、数据库、配置或其他客户；本次无数据库迁移，迁移版本保持原状。
- 从18081对应admin正本及shared/mohe-ai构建；相关源码与提交一致。既有vue.config.js未提交差异仅devServer代理，不影响生产构建。平台构建成功，原有构建警告未扩大处理。
- 上线前system.html指纹3413135055f02ddaf727e03c23e6962d745229a1c9a039b7bf9de39a8d1fcb6b，与上次发布记录吻合。
- 备份与回滚点：/www/backups/rh.cc3798.com/20260927-r45-final-51494919/admin-before.tar.gz；SHA-256：cfba9652e833ef9edbad8bb34f6f1e3e31b888c833e3372e6894b4a8768b090d。可恢复其中view_admin及system.html回滚。
- 静态资源先增量上传、保留旧哈希资源，入口最后原子替换。新入口本地/线上SHA-256一致：09a4db9cbb1979da5f1df8d3df6f5d54293ecbcaed78dbcf354d0a03c37ed836。
- 本地浏览器契约与移动168项及R45新增断言PASS。手机模拟器视觉与退出验证见R45-MOBILE-SINGLE-HEADER-2026-09-27.md。
- 线上实际浏览器验证：管理配置加载成功；AI工作台无加号，Execl与输入文字左对齐，点击选中后黑底白字；未提交经营问题/导出任务。截图R45-rh-no-plus-2026-09-27.png。
- push目标origin/release/store-cashier-c14-main，在线验证完成后执行，结果以回执为准。
