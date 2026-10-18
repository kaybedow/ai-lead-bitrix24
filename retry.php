<?php
// Досилає в Bitrix24 заявки, які не дійшли, поки CRM була недоступна.
// Запуск з cron, наприклад кожні 10 хвилин:
//   */10 * * * * php /path/to/retry.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

$file = $cfg['pending_file'];
if (!is_file($file)) exit;

$fh = fopen($file, 'c+');
if (!$fh || !flock($fh, LOCK_EX)) exit;

$lines = array_filter(explode("\n", (string)stream_get_contents($fh)));
$left = [];
$sent = 0;

foreach ($lines as $line) {
    $lead = json_decode($line, true);
    if (!is_array($lead)) continue;

    [$leadId, $repeat] = save_to_crm($cfg, $lead['name'], $lead['phone'], $lead['text'], $lead['ai']);
    if ($leadId) {
        $sent++;
        notify_manager($cfg, $lead['ai'], $lead['name'], $lead['phone'], $leadId, $repeat, 'Заявка від ' . $lead['time'] . ' дійшла до CRM');
    } else {
        $left[] = $line; // CRM досі недоступна, спробуємо наступного разу
    }
}

ftruncate($fh, 0);
rewind($fh);
fwrite($fh, $left ? implode("\n", $left) . "\n" : '');
flock($fh, LOCK_UN);
fclose($fh);

if ($sent || $left) write_log($cfg, 'retry', "sent $sent, left " . count($left));
