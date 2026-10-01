# R50 取消欠款列位置调整

用户明确要求“取消欠款放到欠款的后面”。只调整列顺序与对应宽度、父子行对齐，不改业务金额、可用次数、按钮事件、权限或数据库。

顺序：操作按钮、卡名称、余次、剩余余额、欠款、取消欠款、购买金额、有效期。

## 文件和固定指纹

- 前端源码：前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue；SHA-256 a3a4b95d0b842b9cde0fbef8cb0852ac7e87b1a91513789f5f17c34374d56fa9。同步移动卡级金额，项目行对应空位同步移动，补充列顺序与卡级金额注释。
- 前端样式：前端代码/cashier-v3/src/styles/base.css；SHA-256 1d125e0c36470b37aa5e5179473f57b8c2b0ebad3eab23637b42b9316c186ba4。有效期宽度移到最后一列，不扩大表格。
- 自动化测试：tests/cashier-v3/js/c2-entitlement-frontend-contract.mjs；SHA-256 a009c347d84b33393f700d71425256a69c769a7035b2a6d2f77b72615dfcbddf。新增明确相邻顺序断言与父子行对齐说明。
- 后端、数据库迁移、工程脚本：无变更。
- 生成物：本地cashier-v3 dist，不提交、不部署。
- 验收证据：本文件。

## 验证和限制

本次三个相关断言通过：八列、取消紧随欠款、项目行留空对齐。综合合同102通过/6失败；六项与前一闭环已记录失败相同，不声称全套通过。
源码diff检查通过。本地Vite服务曾未更新，已重启mohe-cashier-v3-src；18091组件HTTP响应可读取新列内容。
浏览器刷新后连续两次连接超时（运行与焦点命令），未取得调整后的页面截图，不把源码/编译验证冒充真实页面验收。

开发完成，待产品经理在18091刷新并重新打开使用权益验收。未提交、未部署、未push。前一轮发布授权不扩展到该新增页面调整。

## 产品经理确认

产品经理随后明确“我测试没有问题,commit,部署瑞昊,push”，本次三个源码/测试指纹核对一致。按此确认完成本地提交与瑞昊范围发布；上方浏览器工具受限及待验收记录保留历史，不冒充代理已完成截图验收。

## 瑞昊发布结果

业务提交b91a663c，仅4文件。代码发布提交bbfdb185aa04b01bdfc6e4a58032f8c90e306a98，父版41665b9c；发布分支release/r50-entitlement-20261002。仅叠加上述4文件，cashier-v3及inventory-vue3与唯一工作区HEAD比较零差异，shared/AI保留现网。主开发分支及其他任务改动不推送。

目标正式/www/wwwroot/rh.cc3798.com/public/view_cashier_v3与预览/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3，数据库ruihao沿前一次只读定位；本次纯前端，无迁移、数据库写入、后端变更或进程重启。Swoole仍manager_count=1,pid=29223。其他客户未操作。

独立备份/www/backups/rh.cc3798.com/20261002-r50-column-bbfdb185/，prod-before.tar.gz及preview-before.tar.gz均为643adcab4d79d401e45e12dae9353a4f7eab6fd5a7e1e64494c4b67fd2aec507，归档读取验证通过。回滚恢复备份入口与静态内容，不操作数据库。

固定Git归档派生构建1986模块通过，无第二份可编辑源码。构建HTML 8f2e50f1e3a5ec91db7c090bcfb3bc0538979ebe8fc3d24d99683c71e999fc6f；index-12bf6ff8.js 513d224aff8f8c987e1637c85ee1a5dcb4b8a4d9f99f437a5c8fbda4bb419481；index-06f5627f.css 06f5627f1d75c1b0750417482a4bada8caabdafd8ab60bc57d162d4c60295c31。

上传曾遇SSH连接重置；首次入口切换因新资源尚不存在被指纹守卫拒绝，旧入口保持原状，该次curl的旧内容/502不算通过。后用SCP上传两个新资源及HTML，核对新资源指纹后才原子替换两处入口，旧资源保留。

完成后的正式HTTPS HTML/JS/CSS与构建指纹一致；预览HTML依既有代理改写资源前缀，引用同一新JS/CSS，预览JS下载指纹一致。构建JS表头确认为“欠款、取消欠款、购买金额、有效期”；近期日志尾部未检出Fatal/Parse/Uncaught。本次线上验证为部署文件、资源、编译顺序与运行状态，不宣称浏览器代理补验通过；页面业务验收依据为产品经理上述明确确认。

线上核验完成后按授权push Gitee origin的release/r50-entitlement-20261002并核对远端ref；实际回执由工具结果记录，不push GitHub或主开发分支。
