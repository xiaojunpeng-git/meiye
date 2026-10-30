# R15 reservation time display

Two frontend source files, one browser test and one build script. Fixed version is listed in fixed.json. Source baseline 4b23e1f0; previously accepted owned-card grouping and booking fixes are retained. No backend/database or production operations.

The reservation opts into full-height time slots and filled date tabs. Other ChooseTime consumers default to existing fixed-height scrolling/underline tabs. Expanded H5 layout also removes the internal uni-scroll-view height clip. Active date uses selectedTabColor with white text; inactive dates have a light background. Obsolete page-specific green date underline override was removed.

Reservation page business script is byte-identical to its parent. ChooseTime methods, selection/disable logic and events are byte-identical; only opt-in presentation props were added. No new facts, transactions, quotas, permissions, booking timing or request payloads. No source-of-truth change.

Actual Vue template/script/SCSS browser checks at 320/375/430: all 52 slots expanded, no inner clipping, last row clears fixed footer when page scrolled, date/weekday separate lines, theme changes, disabled rejection, valid selection event, legacy default layout. Prior 22 removal checks plus owned/history frontend regressions pass. Fixed-tree H5/WeChat build and all 419 native WXML/WXS compile. An initial unsupported object-style ternary was replaced by the native-compatible string binding before fixing this candidate; initial failure is retained as evidence. No real-device acceptance.

Generated read-only builds live under /private/tmp/r15-booking-time-native-* and are not editable business sources. No commit, push, deploy or mini upload pending product acceptance.
