<?php
/** Notifications de l'équipe : email et Telegram (nouvelle commande, stock bas). */
declare(strict_types=1);

function mail_from(): string
{
    if (SWB_MAIL_FROM !== '') return SWB_MAIL_FROM;
    if (SWB_SMTP_USER !== '' && strpos(SWB_SMTP_USER, '@') !== false) return SWB_SMTP_USER;
    $host = parse_url(abs_url(''), PHP_URL_HOST) ?: 'dehini.com';
    return 'swanbida@' . preg_replace('/^www\./', '', (string) $host);
}

function encode_header(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Envoie un email texte + HTML (SMTP si configuré, sinon mail()). Lève une exception en cas d'échec. */
function send_mail(string $to, string $subject, string $html, string $text): void
{
    if (SWB_MAIL_LOG !== '') {
        file_put_contents(SWB_MAIL_LOG, json_encode(['to' => $to, 'subject' => $subject, 'text' => $text], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    $from = mail_from();
    $domain = substr(strrchr($from, '@') ?: '@dehini.com', 1);
    $b = 'b_' . token(12);
    $headers = implode("\r\n", [
        'From: ' . encode_header(SWB_MAIL_FROM_NAME) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Date: ' . date('r'),
        'Message-ID: <' . token(16) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ]);
    $body = '--' . $b . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n--" . $b . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n--" . $b . "--\r\n";
    if (SWB_SMTP_HOST === '') {
        if (!@mail($to, encode_header($subject), $body, $headers, '-f' . $from)) throw new RuntimeException('mail() a échoué');
        return;
    }
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool) SWB_SMTP_VERIFY, 'verify_peer_name' => (bool) SWB_SMTP_VERIFY,
        'allow_self_signed' => !SWB_SMTP_VERIFY, 'peer_name' => SWB_SMTP_HOST,
    ]]);
    $secure = SWB_SMTP_SECURE;
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . SWB_SMTP_HOST . ':' . (int) SWB_SMTP_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP : connexion impossible ($errstr)");
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $out .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function (?string $line, array $codes) use ($fp, $read): void {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array((int) substr($r, 0, 3), $codes, true)) throw new RuntimeException('SMTP : « ' . trim($r) . ' »');
    };
    try {
        $helo = parse_url(abs_url(''), PHP_URL_HOST) ?: 'localhost';
        $cmd(null, [220]);
        $cmd('EHLO ' . $helo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP : STARTTLS a échoué');
            $cmd('EHLO ' . $helo, [250]);
        }
        if (SWB_SMTP_USER !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(SWB_SMTP_USER), [334]);
            $cmd(base64_encode(SWB_SMTP_PASS), [235]);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $data = 'To: <' . $to . ">\r\nSubject: " . encode_header($subject) . "\r\n" . $headers . "\r\n\r\n" . $body;
        fwrite($fp, preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data)) . "\r\n.\r\n");
        $cmd(null, [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        @fclose($fp);
    }
}

function notify_emails(): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', setting('notify_emails')) ?: [] as $m) {
        if (filter_var($m, FILTER_VALIDATE_EMAIL)) $out[] = $m;
    }
    return array_slice(array_unique($out), 0, 5);
}

function telegram_send(string $html): array
{
    $tok = secret_setting('notify_telegram_token', 'SWB_TELEGRAM_TOKEN');
    $chat = setting('notify_telegram_chat');
    if ($tok === '' || $chat === '') return [false, 'Telegram non configuré'];
    try {
        [$code, $res] = http_post_json(rtrim(SWB_TELEGRAM_URL, '/') . '/bot' . $tok . '/sendMessage', [
            'chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
        ], 10);
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
    $j = json_decode($res, true);
    return !empty($j['ok']) ? [true, ''] : [false, (string) ($j['description'] ?? ('HTTP ' . $code))];
}

function admin_order_url(array $o): string
{
    return abs_url('admin/#/commandes/' . $o['id']);
}

/** Prévient l'équipe d'une nouvelle commande (email + Telegram), après la réponse au client. */
function notify_new_order(array $o): void
{
    $items = order_items_text($o, 'fr');
    $lines = [
        'Commande ' . $o['ref'] . ($o['duplicate_of'] ? ' (doublon possible)' : ''),
        'Client : ' . $o['name'] . ' — ' . phone_display($o['phone']),
        'Livraison : ' . $o['commune'] . ', ' . wilaya_label($o['wilaya'], 'fr') . ($o['delivery'] === 'desk' ? ' (bureau)' : ''),
        'Adresse : ' . $o['address'],
        'Produit : ' . $items,
        'Total : ' . money($o['total'], 'fr') . ($o['shipping'] ? ' (dont livraison ' . money($o['shipping'], 'fr') . ')' : ''),
    ];
    if ($o['note'] !== '') $lines[] = 'Remarque : ' . $o['note'];
    $src = array_filter([$o['utm_source'], $o['utm_campaign'], $o['ad_id'] ? 'ad ' . $o['ad_id'] : '']);
    if ($src) $lines[] = 'Source : ' . implode(' · ', $src);
    $url = admin_order_url($o);

    $tg = '<b>🦢 Nouvelle commande ' . e($o['ref']) . '</b>' . ($o['duplicate_of'] ? ' ⚠️ doublon possible' : '') . "\n"
        . e($o['name']) . ' — <a href="tel:' . e($o['phone']) . '">' . e(phone_display($o['phone'])) . "</a>\n"
        . e($o['commune'] . ', ' . wilaya_label($o['wilaya'], 'fr')) . "\n" . e($o['address']) . "\n"
        . e($items) . "\n<b>" . e(money($o['total'], 'fr')) . '</b>' . ($o['note'] !== '' ? "\n📝 " . e($o['note']) : '')
        . ($src ? "\n📣 " . e(implode(' · ', $src)) : '') . "\n" . '<a href="' . e($url) . '">Ouvrir dans l\'admin</a>';
    [$ok, $err] = telegram_send($tg);
    if (!$ok && $err !== 'Telegram non configuré') meta_set('notify_last_error', date('Y-m-d H:i') . ' Telegram : ' . $err);

    $to = notify_emails();
    if (!$to) return;
    $text = implode("\n", $lines) . "\n\n" . $url;
    $rows = '';
    foreach (array_slice($lines, 1) as $l) {
        [$k, $v] = array_pad(explode(' : ', $l, 2), 2, '');
        $rows .= '<tr><td style="padding:6px 12px 6px 0;color:#6b6b63;white-space:nowrap;vertical-align:top">' . e($k) . '</td><td style="padding:6px 0;color:#1f2421">' . e($v) . '</td></tr>';
    }
    $html = '<div style="font-family:Arial,sans-serif;background:#faf7f1;padding:24px"><div style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;padding:24px;border:1px solid #ebe3d6">'
        . '<p style="margin:0 0 4px;color:#2f4a3a;font-weight:bold;letter-spacing:.12em;font-size:12px">SWANBIDA</p>'
        . '<h1 style="margin:0 0 16px;font-size:20px;color:#1f2421">Nouvelle commande ' . e($o['ref']) . ($o['duplicate_of'] ? ' <span style="color:#b45309;font-size:14px">(doublon possible)</span>' : '') . '</h1>'
        . '<table style="border-collapse:collapse;font-size:14px;width:100%">' . $rows . '</table>'
        . '<p style="margin:20px 0 0"><a href="' . e($url) . '" style="display:inline-block;background:#2f4a3a;color:#fff;text-decoration:none;padding:12px 20px;border-radius:999px;font-weight:bold">Ouvrir la commande</a></p>'
        . '</div></div>';
    foreach ($to as $m) {
        try {
            send_mail($m, 'Nouvelle commande ' . $o['ref'] . ' — ' . money($o['total'], 'fr'), $html, $text);
        } catch (Throwable $e) {
            meta_set('notify_last_error', date('Y-m-d H:i') . ' Email : ' . $e->getMessage());
        }
    }
}

/** Alerte stock bas (au plus une fois par produit et par jour). */
function notify_low_stock(array $p): void
{
    $k = 'lowstock.' . $p['id'];
    if ((meta_get($k) ?? '') === date('Y-m-d')) return;
    meta_set($k, date('Y-m-d'));
    $msg = 'Stock bas : ' . tr($p['name'], 'fr') . ' — ' . (int) $p['stock'] . ' restant(s).';
    telegram_send('⚠️ ' . e($msg));
    foreach (notify_emails() as $m) {
        try { send_mail($m, $msg, '<p style="font-family:Arial,sans-serif">' . e($msg) . '</p>', $msg); } catch (Throwable $e) { /* sans effet */ }
    }
}
