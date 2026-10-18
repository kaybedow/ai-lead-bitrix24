<?php
// Скопіювати в config.php і заповнити. config.php у .gitignore, в репозиторій не потрапляє.
// Найнадійніше тримати config.php і логи поза public_html: .htaccess працює лише на Apache.
return [
    // сайти, з яких можна слати форму
    'allowed_origins' => ['https://example.com'],

    // не більше 5 заявок з одного IP за 10 хвилин
    'rate_limit' => ['max' => 5, 'seconds' => 600],

    // якщо сайт за Cloudflare: 'cloudflare', за іншим проксі: 'forwarded', інакше порожньо
    'trusted_proxy' => '',

    // true: клієнт одразу отримує відповідь, AI і Bitrix24 обробляються у фоні.
    // Для демо-сторінки, якій потрібна відповідь AI, ставте false.
    'async' => true,

    // краще тримати поза public_html
    'log_file'     => __DIR__ . '/../logs/leads.log',
    // заявки, які не дійшли до Bitrix24; їх досилає retry.php
    'pending_file' => __DIR__ . '/../logs/pending.jsonl',

    'openai' => [
        'key'   => 'sk-...',
        'model' => 'gpt-4o-mini',
        // будь-який OpenAI-сумісний API, наприклад локальна модель; за замовчуванням OpenAI
        // 'url' => 'https://api.openai.com/v1/chat/completions',
    ],

    'bitrix' => [
        // Bitrix24: Розробникам > Інше > Вхідний вебхук, права лише на CRM
        'webhook' => 'https://your-portal.bitrix24.ua/rest/1/xxxxxxxxxxxx/',
        'portal'  => 'https://your-portal.bitrix24.ua',
        // категорія від AI => ID відповідального; default лише для коду, AI його не бачить
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
