# mobile-vue3

Single Vue 3 and uni-app x source project for the member and merchant modes.

M0-A contains only the application root, machine-readable contracts, root-state
policies, platform boundaries, and one bootstrap page. It does not connect to a
real authentication service, send SMS messages, or implement business pages.

Run the permanent checks with the HBuilderX bundled Node runtime:

```sh
sh ../../tests/mobile-vue3/run-all.sh
```

The old `uniapp` project remains compatibility-frozen until each feature has
been replaced and independently accepted.
