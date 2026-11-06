<?php
/**
 * Commandes abandonnées : formulaire de commande commencé (téléphone valide saisi) mais jamais
 * envoyé. Le navigateur enregistre ce qui a été tapé ; au bout de N minutes sans activité, la
 * demande apparaît dans l'admin (« À rappeler ») pour appeler ou écrire au client sur WhatsApp,
 * puis la transformer en vraie commande en un clic. Effacées au bout de 30 jours.
 */
declare(strict_types=1);

const DRAFT_KEEP_DAYS = 30;

function abandon_after(): int
{
    return max(0, (int) setting('abandon_minutes')) * 60;
}

function draft_decode(array $r): array
{
    $r['attribution'] = json_decode((string) $r['attribution'], true) ?: [];
    foreach (['id', 'pid', 'qty', 'total', 'recovered', 'contacts', 'contacted_at', 'notified', 'created', 'updated'] as $k) $r[$k] = (int) $r[$k];
    $r['order_id'] = $r['order_id'] === null ? null : (int) $r['order_id'];
    return $r;
}

function draft_by_id(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM drafts WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ? draft_decode($r) : null;
}

/** Offre et produit d'une commande abandonnée, en texte (« 2 packs – SWANBIDA – Pack… »). */
function draft_items_text(array $d, string $lang = 'fr'): string
{
    $p = $d['pid'] ? product_by_id($d['pid']) : null;
    if (!$p) return '';
    $t = product_tier($p, $d['qty']);
    return ($t ? tr($t['label'], $lang) : $d['qty'] . '×') . ' – ' . tr($p['name'], $lang);
}

/**
 * Enregistre (ou met à jour) ce que le visiteur a déjà saisi. Rien n'est gardé sans numéro de
 * téléphone valide, ni si la commande a déjà été envoyée avec ce formulaire.
 */
function draft_save(array $in, array $ctx): bool
{
    if (trim((string) ($in['hp'] ?? '')) !== '') return false;
    $key = preg_match('/^[A-Za-z0-9_-]{16,64}$/', (string) ($in['key'] ?? '')) ? (string) $in['key'] : '';
    $phone = normalize_phone((string) ($in['phone'] ?? ''));
    if ($key === '' || $phone === '') return false;
    $p = product_by_id((int) ($in['product'] ?? 0));
    if (!$p || $p['status'] !== 'active') return false;
    $tier = product_tier($p, (int) ($in['qty'] ?? 0)) ?: default_tier($p);
    $w = wilaya_get(preg_replace('/\D/', '', (string) ($in['wilaya'] ?? '')) ?? '');
    $commune = one_line($in['commune'] ?? '', 80);
    if ($w && $commune !== '') $commune = commune_match($w['code'], $commune) ?: $commune;
    $delivery = ($in['delivery'] ?? 'home') === 'desk' && setting('desk_enabled') === '1' ? 'desk' : 'home';
    $attr = clean_attribution($in['attr'] ?? []);
    $lang = in_array($in['lang'] ?? '', SWB_LANGS, true) ? (string) $in['lang'] : SWB_DEFAULT_LANG;
    $pdo = db();
    $now = time();
    $s = $pdo->prepare('SELECT 1 FROM orders WHERE client_key = ?');
    $s->execute([$key]);
    if ($s->fetchColumn()) return true; // commande déjà envoyée avec ce formulaire

    $f = [
        'pid' => $p['id'], 'qty' => $tier['qty'], 'total' => $tier['price'] + ($w ? shipping_fee($w, $delivery) : 0),
        'name' => one_line($in['name'] ?? '', 80), 'phone' => $phone, 'wilaya' => $w ? $w['code'] : '', 'commune' => $commune,
        'note' => str_clean($in['note'] ?? '', 500), 'lang' => $lang,
        'utm_source' => $attr['utm_source'] ?? '', 'utm_campaign' => $attr['utm_campaign'] ?? '', 'campaign_id' => $attr['campaign_id'] ?? '',
        'ad_id' => $attr['ad_id'] ?? '', 'attribution' => json_encode($attr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'fbp' => one_line($ctx['fbp'] ?? '', 120), 'fbc' => one_line($ctx['fbc'] ?? '', 600), 'vid' => one_line($ctx['vid'] ?? '', 64),
        'ip' => (string) ($ctx['ip'] ?? ''), 'ua' => one_line($ctx['ua'] ?? '', 400), 'source_url' => one_line($ctx['url'] ?? '', 500),
        'updated' => $now,
    ];
    // Même formulaire, ou même téléphone pour le même produit dans les dernières 24 h : on met à jour.
    $s = $pdo->prepare("SELECT id, status FROM drafts WHERE ckey = ? OR (phone = ? AND pid = ? AND status <> 'converted' AND updated > ?) ORDER BY (ckey = ?) DESC, id DESC LIMIT 1");
    $s->execute([$key, $phone, $p['id'], $now - 86400, $key]);
    $r = $s->fetch();
    if ($r) {
        if ($r['status'] === 'converted') return true;
        // Le client revient : une demande ignorée redevient à traiter ; la notification repart.
        $f['status'] = $r['status'] === 'dismissed' ? 'open' : $r['status'];
        $f['ckey'] = $key;
        $sql = 'UPDATE drafts SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?';
        try {
            $pdo->prepare($sql)->execute(array_merge(array_values($f), [(int) $r['id']]));
        } catch (PDOException $e) {
            // Clé déjà portée par une autre ligne : on garde l'ancienne clé.
            unset($f['ckey']);
            $sql = 'UPDATE drafts SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute(array_merge(array_values($f), [(int) $r['id']]));
        }
        return true;
    }
    $f['ckey'] = $key;
    $f['created'] = $now;
    $pdo->prepare('INSERT INTO drafts (' . implode(', ', array_keys($f)) . ') VALUES (' . implode(', ', array_fill(0, count($f), '?')) . ')')
        ->execute(array_values($f));
    return true;
}

/** Lien WhatsApp de relance, dans la langue du client. */
function draft_whatsapp_link(array $d): string
{
    $lang = $d['lang'] === 'fr' ? 'fr' : 'ar';
    $msg = strtr(setting('wa_abandon_' . $lang), [
        '{name}' => $d['name'], '{items}' => draft_items_text($d, $lang), '{total}' => money($d['total'], $lang),
        '{phone}' => phone_display($d['phone']),
    ]);
    $msg = trim(preg_replace('/\s+([,،])/u', '$1', preg_replace('/\s{2,}/u', ' ', $msg) ?? $msg) ?? $msg);
    return 'https://wa.me/' . phone_e164($d['phone']) . '?text=' . rawurlencode($msg);
}

/**
 * Transforme une commande abandonnée en vraie commande (depuis l'admin). $fields complète ou
 * corrige ce que le client avait saisi (nom, téléphone, wilaya, commune, offre, remarque).
 * Retourne ['order' => …] ou ['error' => message].
 */
function draft_convert(array $d, array $fields, string $actor): array
{
    if ($d['status'] === 'converted' && $d['order_id']) {
        $o = order_by_id($d['order_id']);
        if ($o) return ['order' => $o];
    }
    $in = [
        'key' => $d['ckey'], 'product' => $d['pid'], 'qty' => (int) ($fields['qty'] ?? $d['qty']), 'name' => $fields['name'] ?? $d['name'],
        'phone' => $fields['phone'] ?? $d['phone'], 'wilaya' => $fields['wilaya'] ?? $d['wilaya'], 'commune' => $fields['commune'] ?? $d['commune'],
        'note' => $fields['note'] ?? $d['note'], 'lang' => $d['lang'], 'attr' => $d['attribution'], 'hp' => '',
    ];
    $ctx = ['ip' => $d['ip'], 'ua' => $d['ua'], 'fbp' => $d['fbp'], 'fbc' => $d['fbc'], 'vid' => $d['vid'], 'url' => $d['source_url'], 'iph' => ''];
    $prev = current_lang();
    current_lang('fr'); // messages d'erreur de l'admin en français
    $r = order_create($in, $ctx);
    $msg = isset($r['error']) ? t($r['error']) : '';
    current_lang($prev);
    if ($msg !== '') return ['error' => $msg];
    $o = $r['order'];
    db()->prepare("UPDATE drafts SET status = 'converted', order_id = ?, recovered = 1, updated = ? WHERE id = ?")->execute([$o['id'], time(), $d['id']]);
    if (!$r['existing']) {
        order_log($o['id'], 'note', '', '', 'Créée depuis une commande abandonnée (relance)', $actor);
        meta_on_order_created($o); // le client avait bien laissé ses coordonnées sur le site : c'est un Lead
    }
    return ['order' => $o];
}

/** Email : une alerte par commande devenue abandonnée (au plus $limit par passage du cron). */
function drafts_notify_abandoned(int $limit = 20): int
{
    $cut = time() - abandon_after();
    $s = db()->prepare("SELECT * FROM drafts WHERE status = 'open' AND notified = 0 AND updated <= ? AND updated > ? ORDER BY id LIMIT " . (int) $limit);
    $s->execute([$cut, time() - 2 * 86400]);
    $n = 0;
    foreach ($s->fetchAll() as $r) {
        $d = draft_decode($r);
        db()->prepare('UPDATE drafts SET notified = 1 WHERE id = ?')->execute([$d['id']]);
        if (setting('notify_abandoned') !== '1') continue;
        notify_abandoned_draft($d);
        $n++;
    }
    return $n;
}

function drafts_purge(): int
{
    $s = db()->prepare('DELETE FROM drafts WHERE updated < ?');
    $s->execute([time() - DRAFT_KEEP_DAYS * 86400]);
    return $s->rowCount();
}

/** Nombre de commandes abandonnées à rappeler (7 derniers jours), pour le menu de l'admin. */
function drafts_todo_count(): int
{
    $s = db()->prepare("SELECT COUNT(*) FROM drafts WHERE status = 'open' AND updated <= ? AND updated > ?");
    $s->execute([time() - abandon_after(), time() - 7 * 86400]);
    return (int) $s->fetchColumn();
}
