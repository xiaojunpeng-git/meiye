# 010 基础报表侧栏单列样式补验

状态：数据库菜单迁移已上线并逐行验证；本样式候选已构建与只读核对，待产品经理确认后单独提交、发布。当前 010 侧栏三组和 21 个链接均存在，但平台既有通用样式把它们分成两列，长名称被裁切。

## 范围与来源

- 基线提交 `f86f4dfb57ec4ee1ab064c5ad2c440695314fbcb`。平台 `前端代码/admin/public/index.html` 增加独立样式链接，`前端代码/admin/public/basic-report-menu.css` 只匹配含 `/admin/report/basic-reports/basic_` 链接的分组，将其功能列表改成单列；其余平台菜单布局不变。
- `scripts/010-basic-report-menu-style-release.php` 从固定 010 线上入口 `1954722ab061671ba2aed07804dce541e63688a0a879c4530aaa5e3fd518a8fe` 生成只加一条样式链接的新入口，预期 SHA-256 `4f7d2bfc39dc030c36ae19f7c584fdc298421a7cb07fe802b17731c76a2c9d1b`；已有两个 JS 资源引用和全部原内容保持不变。它拒绝其他实例、入口指纹变化、CSS 指纹变化或回滚快照不符的执行。
- CSS 与回滚备份只部署到 010；不替换已上线 JS、原 CSS、PHP 或门店端包。010 数据库备份仍为 `/www/backups/010/20261026-basic-menu-c617f86e/before.json`，SHA-256 `4c22d4dff1f0fc59743a1e2d47bfb24c744fe0f13808c6cc8af49a83f11cbbff`。本样式发布另备份当前 `system.html`，可独立回滚。

## 固定文件与自测

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/admin/public/index.html` | `38f7c404bb2c8c52fcc5672cd711b93ccc09bafc89723af3bf1059c0e13afcc1` |
| 前端源码 | `前端代码/admin/public/basic-report-menu.css` | `fe77aa3a7a1e87db095fda5665b77f398be96938e53322b937d6597ee0bd5c9f` |
| 工程脚本 | `scripts/010-basic-report-menu-style-release.php` | `e4b6a9774f19c9f370027c6ee52ce6e9497778777b5bfc10a7010022ff091771` |
| 执行清单 | `后端代码/database/upgrades/2026-10-26-基础报表平台菜单分组/00-执行清单.md` | `c39faf550480a3aa1bb2d73caf5e45756fe37b1fbbbda3c7acbbcbe08205e233` |

- 平台源码构建通过；构建所得 `dist/system.html` 包含该样式链接，`dist/basic-report-menu.css` 与源码 CSS 字节一致。生成物仅用于验证，不覆盖线上完整构建包。
- 在线桌面视口复核：新侧栏正好三组、21 个功能链接；浏览器支持本 CSS 选择器，选择器正好命中这三组和 21 个链接。既有两列样式使部分名称裁切；单列规则仅改变目标分组的 `ul` 与子项宽度。远程发布脚本本地及线上 PHP 7.4 语法通过，`git diff --check` 通过。
- 注释位于新 CSS 和发布脚本，明确保护非报表菜单布局和已有线上包。自动化测试：沿用已提交的菜单权限与路由合同测试；本轮不改后端业务源码、数据库结构或报表口径。

发布后应以实际桌面浏览器截图核对名称完整、21 个链接可用、旧目录直达及其他侧栏布局不变；再复核系统入口及 JS/CSS 文件指纹和 010 升级记录。若验收失败，仅以 010 独立 HTML 备份恢复本次样式补充，数据库菜单迁移保留。
