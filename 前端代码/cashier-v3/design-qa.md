# Product Design QA · 收银 V3 整体外框

- source visual truth: `/Users/xiaojunpeng/Desktop/魔核/美业/任务管理/进行中/_local_evidence/20260725-replace-favorite-style/04-full-1366x768.png`
- implementation screenshot: `/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/cashier-v3/_design_qa/shell-cashier-1366x768-final.png`
- combined comparison: `/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/cashier-v3/_design_qa/shell-comparison-1366x768-final.png`
- responsive evidence: `/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/cashier-v3/_design_qa/shell-reservation-960x540-final.png`
- viewport: 1366×768 and 960×540 CSS px
- image density: source and implementation are both 1×, 1366×768 pixels; no density normalization required
- state: local DEV visual fixture; shell, cashier workbench and reservation list

## Full-view comparison

The source and implementation intentionally contain different business content. The comparison is scoped to the shared design language and overall frame: warm light canvas, white task surfaces, low-saturation blue, clear page identity, unique primary action, restrained borders and compact desktop density.

The V3 implementation keeps its required persistent left navigation while matching the source's calm hierarchy. The sidebar now identifies the product as a beauty-store service workspace, separates the brand and daily-business levels, gives the active route a clear blue state, and keeps the operator in a contained bottom card. The header uses a two-line page/store hierarchy without competing with page actions.

## Focused comparison

Focused inspection was made on the sidebar, header, active navigation, operator card and 960px responsive state. No raster imagery or custom visual assets are present in either shell, so image asset fidelity is not applicable. All visible controls remain code-native UI controls; no decorative fake assets were introduced.

## Required fidelity surfaces

- Fonts and typography: existing system Chinese font stack preserved; 21px page title, 18px brand, 12px contextual labels and stable truncation create a clear hierarchy.
- Spacing and layout rhythm: 184px desktop sidebar, 68px header, 4px-based gaps, contained operator card and consistent 9–11px radii align with the reference system. At 960px the sidebar contracts to 148px and the document remains 960px wide.
- Colors and tokens: warm `#f2f1ef` canvas, white task surfaces, navy `#18243a` sidebar, low-saturation blue active state and existing semantic colors preserve the favorite-style palette.
- Image quality and asset fidelity: no image assets are required or present in the shell.
- Copy and content: “美业门店 / 服务工作台 / 日常业务 / 当前账号” uses store-facing language and avoids technical terminology.

## Findings and iteration history

1. Initial P2: the sidebar scrollbar was visually heavy at 960×540.
   - Fix: added a 5px low-opacity scrollbar with a transparent track.
   - Post-fix evidence: `shell-reservation-960x540-final.png`; navigation and operator card remain visible and the document has no horizontal overflow.

No remaining P0, P1 or P2 visual findings in the requested shell scope.

## Follow-up polish

- P3: the DEV-only “开发视觉联调” badge can overlap header actions in screenshots. It is intentionally excluded from production and is not part of the shell.
- The previously audited checkout/action-binding and reservation resource-version failures remain separate P0 functional blockers; this shell-only visual change does not hide or alter them.

## Verification

- `npm run build`: passed, 73 modules transformed.
- Browser console warnings/errors: 0.
- 1366×768 cashier shell: visually accepted.
- 1366×768 reservation shell: visually accepted.
- 960×540 reservation shell: no document horizontal overflow; primary header actions and persistent navigation remain reachable.

final result: passed
