<?php

return [
    'enabled' => (bool) env('ACCOUNT_RECOVERY_ENABLED', false),
    'code_minutes' => 15,
    'mailer' => 'account_credentials',
];
