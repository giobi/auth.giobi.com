<?php
/**
 * Pagina credenziali — AGNOSTICA.
 *
 * Qualunque provider (Google, Dropbox, X...) a fine consenso costruisce un
 * array associativo CHIAVE => valore e chiama render_credentials_page($vars).
 *
 * L'utente vede un JSON copiabile + bottone. Lo incolla al suo agent,
 * l'agent lo scrive nel proprio .env. Nessun nome di app/dominio nel testo.
 */

function render_credentials_page(array $vars, ?string $note = null) {
    // niente chiavi vuote: se manca un valore non lo si propina
    $vars = array_filter($vars, fn($v) => $v !== null && $v !== '');
    $json = json_encode(
        $vars,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Autorizzazione completata</title>
<style>
  :root { color-scheme: light dark; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    max-width: 640px; margin: 48px auto; padding: 20px; background: #f6f7f9;
  }
  .card {
    background: #fff; padding: 32px; border-radius: 14px;
    box-shadow: 0 2px 16px rgba(0,0,0,.08);
  }
  h1 { font-size: 22px; margin: 0 0 6px; color: #1a7f37; }
  p.lead { color: #555; margin: 0 0 22px; font-size: 15px; line-height: 1.5; }
  pre {
    background: #1e1e1e; color: #d4d4d4; padding: 18px; border-radius: 10px;
    font-size: 13px; line-height: 1.5; overflow-x: auto; margin: 0 0 14px;
    white-space: pre; tab-size: 2;
  }
  button {
    background: #1a7f37; color: #fff; border: 0; padding: 12px 22px;
    border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer;
    transition: background .15s;
  }
  button:hover { background: #15672c; }
  button.done { background: #0969da; }
  .hint { color: #888; font-size: 13px; margin: 18px 0 0; }
</style>
</head>
<body>
  <div class="card">
    <h1>Autorizzazione completata</h1>
    <p class="lead">
      Ecco i valori da incollare dall'altra parte. Copiali e dalli al tuo
      agent &mdash; lui sa cosa farne.
    </p>
    <pre id="payload"><?= htmlspecialchars($json, ENT_QUOTES, 'UTF-8') ?></pre>
    <button id="copyBtn" type="button">Copia</button>
    <?php if ($note): ?>
    <p class="hint"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <p class="hint">Fatto? Puoi chiudere questa finestra.</p>
  </div>
<script>
(function () {
  var btn = document.getElementById('copyBtn');
  var txt = document.getElementById('payload').textContent;
  btn.addEventListener('click', function () {
    var ok = function () {
      btn.textContent = 'Copiato ✓';
      btn.classList.add('done');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(ok, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = txt; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); ok(); } catch (e) {}
      document.body.removeChild(ta);
    }
  });
})();
</script>
</body>
</html>
    <?php
}
