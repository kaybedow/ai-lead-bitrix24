<?php
// Скопіювати в config.php і заповнити. config.php у .gitignore, в репозиторій не потрапляє.
return [
    // сайти, з яких можна слати форму
    'allowed_origins' => ['https://example.com'],

    // не більше 5 заявок з одного IP за 10 хвилин
    'rate_limit' => ['max' => 5, 'seconds' => 600],

    // краще тримати поза public_html
    'log_file' => __DIR__ . '/../logs/leads.log',

    'openai' => [
        'key'   => 'sk-...',
        'model' => 'gpt-4o-mini',
    ],

    'bitrix' => [
        // Bitrix24: Розробникам > Інше > Вхідний вебхук, права лише на CRM
        'webhook' => 'https://your-portal.bitrix24.ua/rest/1/xxxxxxxxxxxx/',
        'portal'  => 'https://your-portal.bitrix24.ua',
        // категорія від AI => ID відповідального
        'assigned' => [
            'credit'    => 5,
            'insurance' => 7,
            'consult'   => 5,
            'default'   => 1,
        ],
    ],

    'telegram' => [
        'token'   => '',
        'chat_id' => '',
    ],
];
