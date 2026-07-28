# mobile-vue3 permanent checks

These checks use only Node built-ins and offline fixtures. They do not call a
backend, send an SMS message, use a real account, or claim that server-side
concurrency and revocation behavior has been implemented.

Run:

```sh
sh run-all.sh
```

The script uses HBuilderX's bundled Node 22 runtime by default. The source
contracts are authoritative; all golden examples remain under `fixtures/`.
