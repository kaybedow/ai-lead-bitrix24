<?php
// Заявка з сайту: AI розбирає текст, створюємо лід у Bitrix24, пишемо менеджеру в Telegram.
// Якщо клієнт з таким телефоном уже є і лід ще в роботі, не плодимо дубль, а додаємо коментар.
// Якщо Bitrix24 недоступний, заявка йде в чергу (retry.php дошле її пізніше), а менеджер отримує її в Telegram.

declare(strict_types=1);

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
cors($cfg['allowed_origins']);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['error' => 'Method not allowed']);

if (too_many_requests(client_ip($cfg), $cfg['rate_limit'])) {
    reply(429, ['error' => 'Забагато заявок, спробуйте за кілька хвилин']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) $in = $_POST;

// honeypot: людина це поле не бачить
if (!empty($in['website'])) reply(200, ['ok' => true]);

$name  = clean($in['name'] ?? '', 100);
$phone = normalize_phone((string)($in['phone'] ?? ''));
$text  = clean($in['message'] ?? '', 2000);

if ($name === '' || $phone === null || mb_strlen($text) < 5) {
    reply(422, ['error' => 'Перевірте ім\'я, телефон і повідомлення']);
}

// Швидка відповідь: клієнт не чекає AI, Bitrix24 і Telegram, обробка йде після відповіді.
// Для демо-сторінки, якій потрібна відповідь AI, ставимо 'async' => false.
if (!empty($cfg['async'])) {
    respond_now(202, ['ok' => true]);
}

try {
    $ai = ask_ai($text, $cfg);
} catch (Throwable $e) {
    // без AI заявку не втрачаємо, просто створюємо як є
    write_log($cfg, 'ai_error', $e->getMessage());
    $ai = fallback();
}

if ($ai['is_spam']) {
    write_log($cfg, 'spam', mask($phone) . ' ' . mb_substr($text, 0, 80));
    reply(200, ['ok' => true, 'ai' => $ai, 'lead_id' => null]);
}

[$leadId, $repeat] = save_to_crm($cfg, $name, $phone, $text, $ai);

if (!$leadId) {
    // CRM недоступна: зберігаємо заявку повністю, щоб retry.php дослав її пізніше,
    // а менеджер отримує її в Telegram одразу. Для клієнта заявка прийнята.
    queue_pending($cfg, ['time' => date('c'), 'name' => $name, 'phone' => $phone, 'text' => $text, 'ai' => $ai]);
    write_log($cfg, 'b24_error', mask($phone) . ' queued');
    notify_manager($cfg, $ai, $name, $phone, 0, false, 'Bitrix24 недоступний, лід створиться пізніше автоматично');
    reply(200, ['ok' => true, 'ai' => $ai, 'lead_id' => null, 'queued' => true]);
}

notify_manager($cfg, $ai, $name, $phone, $leadId, $repeat);

reply(200, ['ok' => true, 'ai' => $ai, 'lead_id' => $leadId, 'repeat' => $repeat]);


/* ---------- HTTP ---------- */

/** IP клієнта. За Cloudflare чи проксі REMOTE_ADDR у всіх однаковий, тому довіряємо заголовку лише якщо так налаштовано. */
function client_ip(array $cfg): string
{
    $proxy = $cfg['trusted_proxy'] ?? '';
    if ($proxy === 'cloudflare' && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if ($proxy === 'forwarded' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function too_many_requests(string $ip, array $limit): bool
{
    $file = sys_get_temp_dir() . '/lead_rl_' . md5($ip);
    $fh = fopen($file, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX); // читання і запис під одним замком, щоб паралельні запити не обійшли ліміт

    $now  = time();
    $hits = json_decode((string)stream_get_contents($fh), true) ?: [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $limit['seconds']));
    $blocked = count($hits) >= $limit['max'];
    if (!$blocked) $hits[] = $now;

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $blocked;
}

function cors(array $allowed): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return;
    if (!in_array($origin, $allowed, true)) reply(403, ['error' => 'Origin not allowed']);
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

/** Віддати відповідь клієнту і продовжити роботу у фоні. */
function respond_now(int $code, array $data): void
{
    $GLOBALS['responded'] = true;
    ob_start();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) {
        ob_end_flush();
        fastcgi_finish_request();
    } else {
        // без PHP-FPM: закриваємо з'єднання вручну
        header('Connection: close');
        header('Content-Length: ' . ob_get_length());
        @ob_end_flush();
        flush();
    }
    ignore_user_abort(true);
    set_time_limit(90);
}

function reply(int $code, array $data): void
{
    if (empty($GLOBALS['responded'])) {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    exit;
}
