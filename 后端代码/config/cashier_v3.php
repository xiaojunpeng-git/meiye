<?php

use think\facade\Env;

return [
    // Per-instance secret. Never commit a production value into the shared source tree.
    'checkout_namespace_secret' => trim((string)Env::get(
        'cashier_v3.checkout_namespace_secret',
        ''
    )),
];
