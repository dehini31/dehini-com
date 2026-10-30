<?php
/**
 * Soutien en ligne par carte CIB / EDAHABIA via SofizPay (passerelle SATIM), sur le
 * même modèle que ecom.dehini.com : même compte SofizPay, même vérification.
 *
 * Flux :
 *   1. sofiz_create() → URL de la page de paiement SATIM
 *   2. la personne paie sur cette page
 *   3. retour navigateur + webhook (support.php) → on ne leur fait JAMAIS confiance :
 *      donation_confirm() revérifie la transaction auprès de SofizPay, puis contrôle
 *      le montant et le compte destinataire.
 *
 * Doc : https://docs.sofizpay.com/api/v1/endpoints/makeCibTransaction
 */
declare(strict_types=1);

const SOFIZ_ACCOUNT_RE = '/^G[A-Z2-7]{55}$/';
const DON_MIN = 100;      // DA (la passerelle accepte à partir de 50 DA)
const DON_MAX = 200000;   // DA
const DON_AMOUNTS = [500, 1000, 2000, 5000];

/** Compte SofizPay qui reçoit les paiements (identifiant « G… » de 56 caractères). */
function sofiz_account(): string
{
    return trim(setting('sofiz_account'));
}
function sofiz_sandbox(): bool
{
    return setting('sofiz_sandbox') === '1';
}
/** Le paiement par carte est-il activé et correctement configuré ? */
function sofiz_active(): bool
{
    return setting('sofiz_on') === '1' && preg_match(SOFIZ_ACCOUNT_RE, sofiz_account()) === 1;
}
function sofiz_base(): string
{
    if (IMPACT_SOFIZPAY_URL !== '') return rtrim(IMPACT_SOFIZPAY_URL, '/');
    return sofiz_sandbox() ? 'https://sofizpay.com/sandbox' : 'https://sofizpay.com';
}

/** Un seul numéro, au format international (0550… → +213550…) : sinon la passerelle refuse. */
function sofiz_phone(string $tel): string
{
    $first = preg_split('/[,;\/\n]+/', trim($tel))[0] ?? '';
    $digits = (string) preg_replace('/\D+/', '', $first);
    if ($digits === '') return '';
    if (strncmp($digits, '213', 3) === 0) return '+' . $digits;
    if (strncmp(ltrim($first), '+', 1) === 0 || strncmp($digits, '00', 2) === 0) return '+' . ltrim($digits, '0');
    return '+213' . ltrim($digits, '0');
}

/**
 * Crée une transaction et renvoie l'URL de paiement.
 * @return array{ok:bool, payment_url?:string, transaction_id?:string, cib_transaction_id?:string, message?:string}
 */
function sofiz_create(array $d): array
{
    $params = [
        'account'         => sofiz_account(),
        'amount'          => number_format((float) $d['amount'], 2, '.', ''),
        'full_name'       => mb_substr((string) $d['name'], 0, 120),
        'phone'           => (string) $d['phone'],
        'email'           => (string) $d['email'],
        'return_url'      => (string) $d['return_url'],
        'webhook_url'     => (string) $d['webhook_url'],
        'invoice_id'      => (string) $d['reference'],
        'language'        => $d['lang'] === 'en' ? 'en' : 'fr',
        'memo'            => mb_strcut((string) $d['memo'], 0, 28), // limite : 28 OCTETS
        'redirect'        => 'no',     // on veut le JSON : la redirection est faite par la page
        'keep_return_url' => 'True',   // conserve nos paramètres au retour
    ];
    try {
        [$code, $body] = http_get(sofiz_base() . '/make-cib-transaction/', $params, 25);
        $j = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($j) || empty($j['payment_url'])) {
            error_log('impact sofizpay create ' . $code . ': ' . mb_substr($body, 0, 500));
            return ['ok' => false, 'message' => is_array($j) ? (string) ($j['message'] ?? '') : ''];
        }
        return [
            'ok'                 => true,
            'payment_url'        => (string) $j['payment_url'],
            'transaction_id'     => (string) ($j['transaction_id'] ?? ''),
            'cib_transaction_id' => (string) ($j['cib_transaction_id'] ?? ''),
        ];
    } catch (Throwable $e) {
        error_log('impact sofizpay create: ' . $e->getMessage());
        return ['ok' => false, 'message' => ''];
    }
}

/**
 * Vérifie une transaction auprès de SofizPay (source de vérité).
 * @return array{ok:bool, paid:bool, status:int, amount:?float, account:?string, message:string}
 */
function sofiz_check(string $order): array
{
    try {
        [, $body] = http_get(sofiz_base() . '/cib-transaction-check/', ['order_number' => $order], 25);
        $j = json_decode($body, true);
        if (!is_array($j) || isset($j['error'])) {
            return ['ok' => false, 'paid' => false, 'status' => -1, 'amount' => null, 'account' => null,
                'message' => is_array($j) ? (string) $j['error'] : 'réponse illisible'];
        }
        $status = (int) ($j['orderStatus'] ?? -1);
        return [
            'ok'      => true,
            // Paiement accepté : orderStatus 2 + errorCode 0 (respCode « 00 »).
            'paid'    => $status === 2 && (int) ($j['errorCode'] ?? -1) === 0,
            'status'  => $status,
            'amount'  => isset($j['Amount']) ? (float) $j['Amount'] : null,
            'account' => isset($j['destination_account']) ? (string) $j['destination_account'] : null,
            'message' => (string) ($j['errorMessage'] ?? ($j['respCode_desc'] ?? '')),
        ];
    } catch (Throwable $e) {
        error_log('impact sofizpay check: ' . $e->getMessage());
        return ['ok' => false, 'paid' => false, 'status' => -1, 'amount' => null, 'account' => null, 'message' => $e->getMessage()];
    }
}

/**
 * Revérifie un don auprès de SofizPay et le marque payé si tout concorde.
 * Idempotent (retour navigateur et webhook arrivent souvent tous les deux).
 * Renvoie le statut final : paid | pending | failed.
 */
function donation_confirm(array $don): string
{
    if ($don['status'] === 'paid') return 'paid';
    if ($don['cib'] === '') return (string) $don['status'];
    $v = sofiz_check((string) $don['cib']);
    if (!$v['ok']) return (string) $don['status']; // passerelle injoignable : on réessaiera
    $set = db()->prepare('UPDATE donations SET status = ?, note = ? WHERE id = ? AND status != \'paid\'');
    if (!$v['paid']) {
        // 0 = commande créée, pas encore payée ; tout autre statut est un refus ou une annulation.
        $st = in_array($v['status'], [0, 1], true) ? 'pending' : 'failed';
        $set->execute([$st, mb_substr($v['message'], 0, 200), $don['id']]);
        return $st;
    }
    // Contrôles recommandés par SofizPay : montant et compte destinataire.
    $amountOk = $v['amount'] === null || abs($v['amount'] - (float) $don['amount']) < 1;
    $accountOk = $v['account'] === null || $v['account'] === sofiz_account();
    if (!$amountOk || !$accountOk) {
        error_log('impact sofizpay incohérence don ' . $don['id'] . ' : ' . json_encode($v));
        $set->execute(['failed', 'Montant ou compte destinataire non conforme.', $don['id']]);
        return 'failed';
    }
    $s = db()->prepare('UPDATE donations SET status = \'paid\', paid = ?, note = \'\' WHERE id = ? AND status != \'paid\'');
    $s->execute([time(), $don['id']]);
    if ($s->rowCount()) {
        $don['status'] = 'paid';
        donation_mails($don);
    }
    return 'paid';
}

/** Remerciement au donateur et avis à l'adresse de notification. */
function donation_mails(array $don): void
{
    try {
        [$subj, $html, $text] = compose_thanks($don);
        send_mail((string) $don['email'], $subj, $html, $text);
    } catch (Throwable $e) {
        error_log('impact thanks: ' . $e->getMessage());
    }
    if (IMPACT_NOTIFY_EMAIL === '') return;
    $amount = number_format((int) $don['amount'], 0, ',', ' ') . ' DA';
    $text = "Nouveau soutien reçu sur le défi Impact : $amount\n\nDe : {$don['name']} <{$don['email']}>, {$don['phone']}\n"
        . ($don['message'] !== '' ? "Message : {$don['message']}\n" : '') . "\nTous les soutiens : " . site_url() . 'admin.html#soutiens';
    $html = mail_layout('fr', 'Nouveau soutien', 'Nouveau soutien : ' . $amount,
        '<p style="margin:0 0 12px;font-size:28px;font-weight:800;color:#079455">' . h($amount) . '</p>'
        . '<p style="margin:0 0 12px">' . h($don['name']) . ' · ' . h($don['email']) . ' · ' . h($don['phone']) . '</p>'
        . ($don['message'] !== '' ? '<p style="margin:0 0 12px">' . ugc((string) $don['message']) . '</p>' : '')
        . mail_button(site_url() . 'admin.html#soutiens', 'Voir les soutiens'));
    try {
        send_mail(IMPACT_NOTIFY_EMAIL, 'Défi Impact — nouveau soutien de ' . $amount, $html, $text);
    } catch (Throwable $e) {
        error_log('impact notify don: ' . $e->getMessage());
    }
}

const SUPPORT_TYPES = ['paypal', 'redotpay', 'ccp', 'baridimob', 'other'];

/**
 * Moyens de soutien saisis à la main dans l'admin (PayPal, RedotPay, CCP…).
 * @return list<array{type:string,holder:string,value:string,link:string,note:string}>
 */
function support_methods(): array
{
    $list = json_decode(setting('support_methods'), true);
    return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
}
