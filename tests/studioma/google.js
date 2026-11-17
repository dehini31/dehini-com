/*
 * Faux Google pour les tests : une paire de clés RSA dont la clé publique est écrite là où le serveur
 * de test va la chercher (STM_GOOGLE_CERTS, voir setup.sh), et des jetons d'identité signés comme ceux
 * de Google. Sert à api.js et à e2e.js.
 */
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const CLIENT_ID = 'studioma-test.apps.googleusercontent.com';
const KID = 'test-key-1';
const b64 = (buf) => Buffer.from(buf).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');

/** Crée les clés et écrit la clé publique dans <dossier>/google-certs.json. */
function setup(dir) {
  const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
  fs.writeFileSync(path.join(dir, 'google-certs.json'), JSON.stringify({ [KID]: publicKey.export({ type: 'spki', format: 'pem' }) }));
  /** Jeton d'identité signé. claims : sub, email, name… ; opts.key : autre clé (signature falsifiée). */
  const token = (claims, opts) => {
    opts = opts || {};
    const now = Math.floor(Date.now() / 1000);
    const head = b64(JSON.stringify({ alg: 'RS256', kid: opts.kid || KID, typ: 'JWT' }));
    const body = b64(JSON.stringify(Object.assign({ iss: 'https://accounts.google.com', aud: CLIENT_ID, iat: now, exp: now + 3600, email_verified: true }, claims)));
    const sig = crypto.sign('RSA-SHA256', Buffer.from(head + '.' + body), opts.key || privateKey);
    return head + '.' + body + '.' + b64(sig);
  };
  return { token, otherKey: () => crypto.generateKeyPairSync('rsa', { modulusLength: 2048 }).privateKey };
}

/**
 * Remplace le script de Google dans une page : le bouton dessiné est un simple bouton qui rend
 * window.__gcred (le jeton choisi par le test) comme le ferait Google après le choix du compte.
 */
const STUB = `window.google = { accounts: { id: {
  initialize(cfg) { window.__gcfg = cfg; },
  renderButton(el, opts) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'fake-google'; b.dataset.text = opts.text; b.textContent = 'Google (' + opts.text + ')';
    b.addEventListener('click', () => window.__gcfg.callback({ credential: window.__gcred }));
    el.appendChild(b);
  },
} } };`;

module.exports = { CLIENT_ID, setup, STUB };
