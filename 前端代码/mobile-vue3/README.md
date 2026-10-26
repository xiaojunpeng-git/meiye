# mobile-vue3

Single Vue 3 and uni-app x source project for the member and merchant modes.

For an MP-WEIXIN release, provision the target customer's AppID in the
isolated build copy of `manifest.json` and HTTPS API origin in `.env` as
`VITE_MOBILE_API_ORIGIN` (origin only, no path). A missing origin fails closed
instead of reaching another customer. Before upload, verify the generated
`project.config.json` AppID and `src/shared/platform/mobile-runtime-config.js`
origin. Do not commit customer-specific values to this shared source.

M0-A contains only the application root, machine-readable contracts, root-state
policies, platform boundaries, and one bootstrap page. It does not connect to a
real authentication service, send SMS messages, or implement business pages.

Run the permanent checks with the HBuilderX bundled Node runtime:

```sh
sh ../../tests/mobile-vue3/run-all.sh
```

The old `uniapp` project remains compatibility-frozen until each feature has
been replaced and independently accepted.
