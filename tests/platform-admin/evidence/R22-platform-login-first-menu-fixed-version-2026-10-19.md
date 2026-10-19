# R22 平台登录默认进入首个菜单固定版本

- 状态：开发与本地自测完成，待产品经理确认验收；尚未 commit、部署或 push。
- 基线 HEAD：`836a4a7f09128d0b37a5cd28a69b943ec87a46e4`。
- 需求：访问平台旧首页并完成登录后，按当前账号的权限菜单顺序进入第一个大类下的第一个可访问功能，不再停留在 `/admin/home/`。

## 实现范围

1. 默认落地路径按服务端返回的菜单顺序逐层寻找第一个站内叶子功能。
2. 外链和数据大屏不作为登录默认页面；当前大类没有站内功能时继续检查后续大类。
3. 登录守卫带回的 `/admin/home/` 回跳地址不再覆盖默认菜单计算结果。
4. 其他真实业务深链继续原样回跳；空菜单账号继续进入空工作区。

## 固定文件

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/admin/src/libs/util.js` | `41c6d58217b94e16873db8b784f781b041d11892e695769951e0e89fc653990a` |
| `前端代码/admin/src/libs/platformLogin.js` | `ab7c23c650a7b3408bdc3f6ab7481f44f000c4ce967cc122e57d64f38b0e330f` |
| `tests/organization-store-v3/js/platform-empty-menu-login.mjs` | `9749f0c727d7d5dbfed96e16a465b44bcb88c6e35303712abdfcc43c03ebf848` |

- 上述三文件补丁 SHA-256：`59859db2e273abfc6976910b7a122b8c932b917163bbd4d7d1360fd54611ff53`。

## 自测结果

- `node tests/organization-store-v3/js/platform-empty-menu-login.mjs`：PASS。
- `node tests/organization-store-v3/js/report-header-navigation.mjs`：PASS。
- `git diff --check`：PASS。
- 平台端 `npm run build`：完成；构建保留项目原有 ESLint 与体积警告，无编译失败。
- `http://127.0.0.1:18081/admin/login`：HTTP 200。

## 注释更新

- `util.js`：说明菜单顺序、站内叶子功能以及外链/数据大屏排除边界。
- `platformLogin.js`：说明旧首页 redirect 为什么不能覆盖当前权限菜单的默认落地。
