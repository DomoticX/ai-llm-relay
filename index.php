<?php
/**
 * index.php — Beheerpagina voor de AI LLM Relay
 *
 * Functionaliteit:
 *  - Inlogscherm met wachtwoord-hash (bcrypt) + anti-brute-force rekensom
 *  - Relay-regels beheren (toevoegen, bewerken, verwijderen, activeren)
 *  - Verbindingen testen
 *  - Instellingen exporteren (config.json)
 *  - Wachtwoord wijzigen
 *  - Meertalige interface (NL / EN / DE / FR) via translation.json
 *
 * Codetaal : PHP 7.4+
 * Interface : XHTML 1.0 Strict
 * Stijlen   : relay.css (extern bestand, embedded in <style>)
 */

/* ============================================================
   Constanten voor bestandspaden
   ============================================================ */
define('CONFIG_FILE',         __DIR__ . '/config.json');
define('CONFIG_DEFAULT_FILE', __DIR__ . '/config_default.json');
define('AUTH_FILE',           __DIR__ . '/auth.json');
define('TRANSLATION_FILE',    __DIR__ . '/translation.json');
define('APP_VERSIE',          '1.0');

// Bij ontbrekende config.json: kopieer config_default.json als startpunt
if (!file_exists(CONFIG_FILE) && file_exists(CONFIG_DEFAULT_FILE)) {
    copy(CONFIG_DEFAULT_FILE, CONFIG_FILE);
}

/* ============================================================
   Sessie starten en beveiligen
   ============================================================ */
session_start();

// Stel sessie-cookie-opties in voor maximale veiligheid
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/* ============================================================
   Taalinstelling verwerken
   ============================================================ */

// Beschikbare talen in de interface
$beschikbare_talen = ['nl', 'en', 'de', 'fr'];

// Lees opgeslagen taalvoorkeur uit config.json (sessie-onafhankelijk)
$_taal_uit_config = null;
if (file_exists(CONFIG_FILE)) {
    $_tmp_cfg = json_decode(file_get_contents(CONFIG_FILE), true);
    if (is_array($_tmp_cfg) && isset($_tmp_cfg['taal'])) {
        $_taal_uit_config = (string)$_tmp_cfg['taal'];
    }
    unset($_tmp_cfg);
}

// Sla taalvoorkeur op in sessie én config.json, redirect zonder ?lang= in de URL
if (isset($_GET['lang']) && in_array($_GET['lang'], $beschikbare_talen, true)) {
    $_SESSION['taal'] = $_GET['lang'];
    // Bewaar ook in config.json zodat de voorkeur sessie-onafhankelijk is
    if (file_exists(CONFIG_FILE)) {
        $_tmp_cfg = json_decode(file_get_contents(CONFIG_FILE), true) ?? [];
        $_tmp_cfg['taal'] = $_GET['lang'];
        file_put_contents(CONFIG_FILE, json_encode($_tmp_cfg,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        unset($_tmp_cfg);
    }
    $params = $_GET;
    unset($params['lang']);
    $redirect = basename($_SERVER['SCRIPT_NAME']);
    if (!empty($params)) {
        $redirect .= '?' . http_build_query($params);
    }
    header('Location: ' . $redirect);
    exit;
}

// Bepaal de actieve taal: sessie > config.json > standaard (nl)
$actieve_taal = $_SESSION['taal'] ?? $_taal_uit_config ?? 'nl';
if (!in_array($actieve_taal, $beschikbare_talen, true)) {
    $actieve_taal = 'nl';
}

/* ============================================================
   Vertalingen laden
   ============================================================ */

/**
 * Laad alle vertalingen uit translation.json.
 *
 * @return array  Geneste array [taalcode => [sleutel => waarde]]
 */
function laad_vertalingen(): array {
    if (!file_exists(TRANSLATION_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(TRANSLATION_FILE), true);
    return is_array($data) ? $data : [];
}

$vertalingen = laad_vertalingen();

// De actieve vertalingstabel (voor de gekozen taal)
$t = $vertalingen[$actieve_taal] ?? $vertalingen['nl'] ?? [];

/**
 * Geeft een vertaalde string terug, HTML-geëscaped voor veilige uitvoer.
 * Valt terug op Nederlands, dan op de sleutelnaam zelf.
 *
 * @param string $sleutel    Vertalingssleutel
 * @param string $standaard  Standaardwaarde als sleutel ontbreekt
 * @return string            HTML-veilige vertaalde string
 */
function t(string $sleutel, string $standaard = ''): string {
    global $t, $vertalingen;
    $val = $t[$sleutel]
        ?? $vertalingen['nl'][$sleutel]
        ?? ($standaard ?: $sleutel);
    return htmlspecialchars($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Geeft een vertaalde string terug zonder HTML-escaping (voor JS-output).
 *
 * @param string $sleutel    Vertalingssleutel
 * @param string $standaard  Standaardwaarde als sleutel ontbreekt
 * @return string            Rauwe vertaalde string
 */
function tr(string $sleutel, string $standaard = ''): string {
    global $t, $vertalingen;
    return $t[$sleutel]
        ?? $vertalingen['nl'][$sleutel]
        ?? ($standaard ?: $sleutel);
}

/* ============================================================
   Hulpfuncties: config lezen/schrijven
   ============================================================ */

/**
 * Lees config.json en geef als array terug.
 * Levert standaard-waarden als het bestand ontbreekt.
 */
function laad_config(): array {
    if (!file_exists(CONFIG_FILE)) {
        return standaard_config();
    }
    $data = json_decode(file_get_contents(CONFIG_FILE), true);
    return is_array($data) ? $data : standaard_config();
}

/**
 * Schrijf de configuratie-array naar config.json.
 */
function sla_config_op(array $config): bool {
    return (bool) file_put_contents(
        CONFIG_FILE,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * Standaardconfiguratie voor een nieuwe installatie.
 */
function standaard_config(): array {
    return [
        '_commentaar' => 'AI LLM Relay configuratie',
        'standaard_verbind_timeout' => 10,
        'standaard_totaal_timeout'  => 0,
        'relays' => [],
    ];
}

/**
 * Lees auth.json (wachtwoord-hash).
 */
function laad_auth(): array {
    if (!file_exists(AUTH_FILE)) {
        return [];
    }
    $data = json_decode(file_get_contents(AUTH_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * Schrijf auth.json.
 */
function sla_auth_op(array $auth): bool {
    return (bool) file_put_contents(
        AUTH_FILE,
        json_encode($auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Ontsnap HTML-tekens veilig voor uitvoer in HTML.
 */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/* ============================================================
   AJAX-verzoeken verwerken (JSON API voor de frontend)
   ============================================================ */
if (isset($_GET['actie'])) {
    header('Content-Type: application/json; charset=utf-8');

    $actie = $_GET['actie'];

    // Alle AJAX-verzoeken vereisen dat de gebruiker is ingelogd
    if (empty($_SESSION['ingelogd'])) {
        echo json_encode(['ok' => false, 'bericht' => tr('ajax_niet_ingelogd')]);
        exit;
    }

    // --- Relay-regels opslaan ---
    if ($actie === 'sla_relays_op') {
        $config = laad_config();
        $invoer = json_decode(file_get_contents('php://input'), true);
        if (!is_array($invoer)) {
            echo json_encode(['ok' => false, 'bericht' => tr('ajax_ongeldige_invoer')]);
            exit;
        }
        $config['relays'] = $invoer;
        sla_config_op($config);
        echo json_encode(['ok' => true, 'bericht' => tr('ajax_relays_opgeslagen')]);
        exit;
    }

    // --- Globale instellingen opslaan ---
    if ($actie === 'sla_instellingen_op') {
        $config = laad_config();
        $invoer = json_decode(file_get_contents('php://input'), true);
        if (!is_array($invoer)) {
            echo json_encode(['ok' => false, 'bericht' => tr('ajax_ongeldige_invoer')]);
            exit;
        }
        $config['standaard_verbind_timeout'] = (int)($invoer['standaard_verbind_timeout'] ?? 10);
        $config['standaard_totaal_timeout']  = (int)($invoer['standaard_totaal_timeout']  ?? 0);
        sla_config_op($config);
        echo json_encode(['ok' => true, 'bericht' => tr('ajax_instellingen_opgeslagen')]);
        exit;
    }

    // --- Verbinding testen ---
    if ($actie === 'verbinding_testen') {
        $invoer  = json_decode(file_get_contents('php://input'), true);
        $ip      = trim($invoer['upstream_ip']    ?? '');
        $poort   = (int)($invoer['upstream_poort'] ?? 80);
        $https   = !empty($invoer['gebruik_https']);
        $timeout = (int)($invoer['verbind_timeout'] ?? 10);

        if (empty($ip)) {
            echo json_encode(['ok' => false, 'bericht' => tr('ajax_geen_ip')]);
            exit;
        }

        // Stel de test-URL samen
        if (preg_match('/^https?:\/\//', $ip)) {
            $basis_url_test = rtrim($ip, '/');
        } else {
            $protocol_test  = $https ? 'https' : 'http';
            $basis_url_test = $protocol_test . '://' . $ip . ':' . $poort;
        }
        $test_url = $basis_url_test . '/v1/models';

        // Bouw test-headers op
        $headers = ['Accept: application/json'];
        if (!empty($invoer['gebruik_upstream_token']) && !empty($invoer['upstream_token'])) {
            $headers[] = 'Authorization: Bearer ' . $invoer['upstream_token'];
        }

        // Voer test cURL-verzoek uit
        $ch = curl_init($test_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout + 5,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $reactie   = curl_exec($ch);
        $fout      = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $tijd_ms   = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        curl_close($ch);

        if ($fout) {
            echo json_encode([
                'ok'      => false,
                'bericht' => tr('ajax_verbinding_mislukt') . ' ' . $fout,
                'url'     => $test_url,
            ]);
        } else {
            echo json_encode([
                'ok'      => ($http_code >= 200 && $http_code < 500),
                'bericht' => "HTTP {$http_code} — {$tijd_ms}ms",
                'url'     => $test_url,
                'reactie' => mb_substr((string)$reactie, 0, 400),
            ]);
        }
        exit;
    }

    // --- Config exporteren ---
    if ($actie === 'exporteer_config') {
        $config = laad_config();
        header('Content-Disposition: attachment; filename="relay-config-export.json"');
        echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // --- Config importeren ---
    if ($actie === 'importeer_config') {
        $invoer = json_decode(file_get_contents('php://input'), true);
        if (!is_array($invoer)) {
            echo json_encode(['ok' => false, 'bericht' => tr('ajax_ongeldige_invoer')]);
            exit;
        }
        $config = laad_config();
        if (isset($invoer['standaard_verbind_timeout'])) {
            $config['standaard_verbind_timeout'] = (int)$invoer['standaard_verbind_timeout'];
        }
        if (isset($invoer['standaard_totaal_timeout'])) {
            $config['standaard_totaal_timeout'] = (int)$invoer['standaard_totaal_timeout'];
        }
        if (isset($invoer['relays']) && is_array($invoer['relays'])) {
            $config['relays'] = $invoer['relays'];
        }
        sla_config_op($config);
        echo json_encode(['ok' => true, 'bericht' => tr('ajax_config_geimporteerd')]);
        exit;
    }

    // --- Wachtwoord wijzigen ---
    if ($actie === 'wijzig_wachtwoord') {
        $invoer   = json_decode(file_get_contents('php://input'), true);
        $huidig   = $invoer['huidig_wachtwoord']   ?? '';
        $nieuw    = $invoer['nieuw_wachtwoord']     ?? '';
        $bevestig = $invoer['bevestig_wachtwoord']  ?? '';

        $auth = laad_auth();
        if (!password_verify($huidig, $auth['wachtwoord_hash'] ?? '')) {
            echo json_encode(['ok' => false, 'bericht' => tr('ajax_huidig_ww_fout')]);
            exit;
        }
        if (strlen($nieuw) < 8) {
            echo json_encode(['ok' => false, 'bericht' => tr('ajax_nieuw_ww_te_kort')]);
            exit;
        }
        if ($nieuw !== $bevestig) {
            echo json_encode(['ok' => false, 'bericht' => tr('ajax_wachtwoorden_niet_gelijk')]);
            exit;
        }
        $auth['wachtwoord_hash'] = password_hash($nieuw, PASSWORD_BCRYPT, ['cost' => 12]);
        sla_auth_op($auth);
        echo json_encode(['ok' => true, 'bericht' => tr('ajax_wachtwoord_gewijzigd')]);
        exit;
    }

    echo json_encode(['ok' => false, 'bericht' => tr('ajax_onbekende_actie')]);
    exit;
}

/* ============================================================
   Eerste installatie: maak standaard auth.json aan
   ============================================================ */
$auth = laad_auth();
$wachtwoord_moet_ingesteld = empty($auth['wachtwoord_hash']);

/* ============================================================
   Uitloggen verwerken
   ============================================================ */
if (isset($_GET['uitloggen'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

/* ============================================================
   Anti-brute-force uitdaging genereren
   ============================================================ */

/**
 * Genereer een willekeurige rekensom voor de inloguitdaging.
 * Alleen optellen en aftrekken, getallen 0–20. Sla het antwoord op in de sessie.
 */
function genereer_uitdaging(): array {
    $a = random_int(0, 20);
    $b = random_int(0, 20);
    $bewerkingen = ['+', '-'];
    $bewerking   = $bewerkingen[array_rand($bewerkingen)];

    // Bij aftrekken: zorg dat het resultaat niet negatief is
    if ($bewerking === '-' && $b > $a) {
        [$a, $b] = [$b, $a];
    }
    $antwoord = ($bewerking === '+') ? ($a + $b) : ($a - $b);

    $_SESSION['uitdaging_antwoord'] = $antwoord;
    $_SESSION['uitdaging_tijd']     = time();

    return ['vraag' => "{$a} {$bewerking} {$b}", 'antwoord' => $antwoord];
}

/* ============================================================
   Loginformulier verwerken
   ============================================================ */
$login_fout = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inloggen'])) {
    $wachtwoord    = $_POST['wachtwoord']      ?? '';
    $uitdaging_ant = (int)($_POST['uitdaging'] ?? -999);

    $sessie_antwoord = (int)($_SESSION['uitdaging_antwoord'] ?? null);
    $uitdaging_tijd  = (int)($_SESSION['uitdaging_tijd']     ?? 0);
    $uitdaging_ok    = (
        $uitdaging_ant === $sessie_antwoord &&
        (time() - $uitdaging_tijd) < 300
    );

    // Eenmalig gebruik van de uitdaging
    unset($_SESSION['uitdaging_antwoord'], $_SESSION['uitdaging_tijd']);

    if (!$uitdaging_ok) {
        $login_fout = tr('login_err_uitdaging');
    } elseif (!password_verify($wachtwoord, $auth['wachtwoord_hash'] ?? '')) {
        sleep(1); // Kleine vertraging tegen brute-force
        $login_fout = tr('login_err_wachtwoord');
    } else {
        $_SESSION['ingelogd']   = true;
        $_SESSION['login_tijd'] = time();
        session_regenerate_id(true); // Voorkomt session fixation
        header('Location: index.php');
        exit;
    }
}

// Genereer nieuwe uitdaging voor het inlogformulier
$uitdaging = genereer_uitdaging();

/* ============================================================
   CSS laden voor embedding in <style>
   ============================================================ */
$css_inhoud = '';
if (file_exists(__DIR__ . '/relay.css')) {
    $css_inhoud = file_get_contents(__DIR__ . '/relay.css');
}

/* ============================================================
   Hulpfunctie: taalschakelaar URL bouwen
   ============================================================ */

/**
 * Bouw een URL voor de taalschakelaar met behoud van huidige tab-parameter.
 */
function taal_url(string $taal, string $huidig_tab = 'relays'): string {
    $params = ['lang' => $taal];
    if ($huidig_tab !== 'relays') {
        $params['tab'] = $huidig_tab;
    }
    return 'index.php?' . http_build_query($params);
}

/* ============================================================
   EERSTE INSTALLATIE: wachtwoord aanmaken (nog geen hash ingesteld)
   ============================================================ */
$wachtwoord_instellen_fout = '';
if ($wachtwoord_moet_ingesteld) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wachtwoord_aanmaken'])) {
        $nieuw    = $_POST['nieuw_wachtwoord']    ?? '';
        $bevestig = $_POST['bevestig_wachtwoord'] ?? '';
        if (strlen($nieuw) < 8) {
            $wachtwoord_instellen_fout = tr('ajax_nieuw_ww_te_kort');
        } elseif ($nieuw !== $bevestig) {
            $wachtwoord_instellen_fout = tr('ajax_wachtwoorden_niet_gelijk');
        } else {
            sla_auth_op(['wachtwoord_hash' => password_hash($nieuw, PASSWORD_BCRYPT, ['cost' => 12])]);
            header('Location: index.php');
            exit;
        }
    }
    ?>
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
  "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="<?php echo h($actieve_taal); ?>" lang="<?php echo h($actieve_taal); ?>">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo t('app_naam'); ?> &#x2014; <?php echo t('ww_instellen_titel'); ?></title>
  <link rel="icon" type="image/x-icon" href="favicon.ico" />
  <style type="text/css">
<?php echo $css_inhoud; ?>
  </style>
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">

    <div class="taal-schakelaar taal-schakelaar-login">
      <?php foreach ($beschikbare_talen as $tl): ?>
      <a href="<?php echo h(taal_url($tl)); ?>"
         class="<?php echo $actieve_taal === $tl ? 'actief' : ''; ?>"
         title="<?php echo h($vertalingen[$tl]['taal_naam'] ?? strtoupper($tl)); ?>"
         ><?php echo strtoupper($tl); ?></a>
      <?php endforeach; ?>
    </div>

    <h1>
      <img src="favicon.ico" width="28" height="28" alt=""
           onerror="this.style.display='none'" />
      <?php echo t('app_naam'); ?>
    </h1>
    <p class="subtitle"><?php echo t('beheerpaneel'); ?>
      <span class="version-tag">v<?php echo h(APP_VERSIE); ?></span>
    </p>

    <p class="text-muted" style="margin-bottom:1em"><?php echo t('ww_instellen_uitleg'); ?></p>

    <?php if ($wachtwoord_instellen_fout): ?>
    <div class="alert alert-error"><?php echo h($wachtwoord_instellen_fout); ?></div>
    <?php endif; ?>

    <form method="post" action="index.php">
      <div class="form-group">
        <label for="nieuw-ww"><?php echo t('nieuw_wachtwoord_label'); ?></label>
        <input type="password" id="nieuw-ww" name="nieuw_wachtwoord"
               autocomplete="new-password" required="required" minlength="8" />
      </div>
      <div class="form-group">
        <label for="bevestig-ww"><?php echo t('bevestig_wachtwoord_label'); ?></label>
        <input type="password" id="bevestig-ww" name="bevestig_wachtwoord"
               autocomplete="new-password" required="required" minlength="8" />
      </div>
      <button type="submit" name="wachtwoord_aanmaken" class="btn btn-primary btn-full">
        <?php echo t('ww_instellen_knop'); ?>
      </button>
    </form>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

/* ============================================================
   LOGINPAGINA (niet ingelogd)
   ============================================================ */
if (empty($_SESSION['ingelogd'])) {
    ?>
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
  "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="<?php echo h($actieve_taal); ?>" lang="<?php echo h($actieve_taal); ?>">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo t('app_naam'); ?> &#x2014; <?php echo t('inloggen'); ?></title>
  <link rel="icon" type="image/x-icon" href="favicon.ico" />
  <style type="text/css">
<?php echo $css_inhoud; ?>
  </style>
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">

    <!-- Taalschakelaar op het inlogscherm -->
    <div class="taal-schakelaar taal-schakelaar-login">
      <?php foreach ($beschikbare_talen as $tl): ?>
      <a href="<?php echo h(taal_url($tl)); ?>"
         class="<?php echo $actieve_taal === $tl ? 'actief' : ''; ?>"
         title="<?php echo h($vertalingen[$tl]['taal_naam'] ?? strtoupper($tl)); ?>"
         ><?php echo strtoupper($tl); ?></a>
      <?php endforeach; ?>
    </div>

    <!-- Logo en titel -->
    <h1>
      <img src="favicon.ico" width="28" height="28" alt=""
           onerror="this.style.display='none'" />
      <?php echo t('app_naam'); ?>
    </h1>
    <p class="subtitle"><?php echo t('beheerpaneel'); ?>
      <span class="version-tag">v<?php echo h(APP_VERSIE); ?></span>
    </p>

    <?php if ($login_fout): ?>
    <div class="alert alert-error"><?php echo h($login_fout); ?></div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['eerste_installatie'])): ?>
    <div class="alert alert-info">
      <?php echo t('eerste_install_voor'); ?>
      <strong>relay2024</strong><br />
      <?php echo t('eerste_install_na'); ?>
    </div>
    <?php endif; ?>

    <form method="post" action="index.php">
      <div class="form-group">
        <label for="wachtwoord"><?php echo t('wachtwoord_label'); ?></label>
        <input type="password" id="wachtwoord" name="wachtwoord"
               autocomplete="current-password" required="required" />
      </div>

      <!-- Anti-brute-force uitdaging: simpele rekensom -->
      <div class="challenge-box">
        <div class="challenge-question">
          <?php echo t('uitdaging_prompt'); ?> <?php echo h($uitdaging['vraag']); ?> = ?
        </div>
        <input type="number" name="uitdaging" id="uitdaging"
               placeholder="<?php echo t('uitdaging_placeholder'); ?>"
               required="required" autocomplete="off" />
      </div>

      <button type="submit" name="inloggen" class="btn btn-primary btn-full">
        <?php echo t('inloggen'); ?>
      </button>
    </form>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

/* ============================================================
   BEHEERPAGINA (ingelogd)
   ============================================================ */

// Laad configuratie
$config              = laad_config();
$relays              = $config['relays'] ?? [];
$std_verbind_timeout = (int)($config['standaard_verbind_timeout'] ?? 10);
$std_totaal_timeout  = (int)($config['standaard_totaal_timeout']  ?? 0);

// Bepaal het basis-URL voor de Info-tab (eindpunten met volledig adres)
$ui_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$ui_host     = $_SERVER['HTTP_HOST'] ?? 'uwdomein.nl';
$ui_dir      = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$basis_url   = $ui_protocol . '://' . $ui_host . $ui_dir;

// Huidige tab
$tab = $_GET['tab'] ?? 'relays';
$geldige_tabs = ['relays', 'instellingen', 'wachtwoord', 'info'];
if (!in_array($tab, $geldige_tabs, true)) {
    $tab = 'relays';
}

?>
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
  "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="<?php echo h($actieve_taal); ?>" lang="<?php echo h($actieve_taal); ?>">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo t('app_naam'); ?> &#x2014; <?php echo t('beheerpaneel'); ?></title>
  <link rel="icon" type="image/x-icon" href="favicon.ico" />
  <style type="text/css">
<?php echo $css_inhoud; ?>
  </style>
</head>
<body>

<!-- ===== NAVIGATIEBALK ===== -->
<nav class="navbar">

  <!-- Merk: favicon-icoon + naam -->
  <a class="navbar-brand" href="index.php">
    <img src="favicon.ico" width="22" height="22" alt=""
         onerror="this.style.display='none'" />
    <?php echo t('app_naam'); ?>
    <span class="version-tag">v<?php echo h(APP_VERSIE); ?></span>
  </a>

  <!-- Navigatielinks en taalschakelaar -->
  <div class="navbar-rechts">
    <ul class="navbar-links">
      <li><a href="index.php?tab=relays"
             class="<?php echo $tab === 'relays' ? 'active' : ''; ?>">
        <?php echo t('tab_relays'); ?></a></li>
      <li><a href="index.php?tab=instellingen"
             class="<?php echo $tab === 'instellingen' ? 'active' : ''; ?>">
        <?php echo t('tab_instellingen'); ?></a></li>
      <li><a href="index.php?tab=wachtwoord"
             class="<?php echo $tab === 'wachtwoord' ? 'active' : ''; ?>">
        <?php echo t('tab_wachtwoord'); ?></a></li>
      <li><a href="index.php?tab=info"
             class="<?php echo $tab === 'info' ? 'active' : ''; ?>">
        <?php echo t('tab_info'); ?></a></li>
      <li><a href="index.php?uitloggen=1" class="btn btn-danger btn-sm">
        <?php echo t('uitloggen'); ?></a></li>
    </ul>

    <!-- Taalschakelaar -->
    <div class="taal-schakelaar">
      <?php foreach ($beschikbare_talen as $tl): ?>
      <a href="<?php echo h(taal_url($tl, $tab)); ?>"
         class="<?php echo $actieve_taal === $tl ? 'actief' : ''; ?>"
         title="<?php echo h($vertalingen[$tl]['taal_naam'] ?? strtoupper($tl)); ?>"
         ><?php echo strtoupper($tl); ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</nav>

<!-- ===== HOOFD INHOUD ===== -->
<div class="main-content">

<!-- Meldingengebied (gevuld via JavaScript) -->
<div id="melding-container"></div>


<!-- ============================================================
     TAB: RELAY-REGELS
     ============================================================ -->
<?php if ($tab === 'relays'): ?>
<div class="card">
  <div class="card-header">
    <h2>&#x1F517; <?php echo t('relay_titel'); ?></h2>
    <div class="flex gap-1">
      <button class="btn btn-success btn-sm" onclick="voegRegelToe()">
        <?php echo t('regel_toevoegen'); ?>
      </button>
      <button class="btn btn-primary btn-sm" onclick="slaRelaysOp()">
        &#x1F4BE; <?php echo t('opslaan'); ?>
      </button>
      <button class="btn btn-info btn-sm" onclick="exporteerConfig()">
        &#x2B07; <?php echo t('export_knop'); ?>
      </button>
      <button class="btn btn-warning btn-sm" onclick="triggerImport()">
        &#x2B06; <?php echo t('import_knop'); ?>
      </button>
      <input type="file" id="import-bestand" accept=".json"
             style="display:none" onchange="importeerConfig(this)" />
    </div>
  </div>

  <p class="text-muted mb-1" style="font-size:0.85em">
    <?php echo t('relay_uitleg'); ?>
  </p>

  <div style="overflow-x:auto">
  <table class="relay-table" id="relay-tabel">
    <thead>
      <tr>
        <th class="col-active"><?php echo t('col_aan'); ?></th>
        <th class="col-name"><?php echo t('col_naam'); ?></th>
        <th class="col-token"><?php echo t('col_inkomend_token'); ?></th>
        <th class="col-target"><?php echo t('col_upstream_ip'); ?></th>
        <th class="col-port"><?php echo t('col_poort'); ?></th>
        <th class="col-timeout"><?php echo t('col_timeout'); ?></th>
        <th class="col-utoken"><?php
          // Twee-regelige koptekst voor de token-upstream-checkbox kolom
          $hoofd = explode("\n", tr('col_token_upstream_hoofd'));
          echo h($hoofd[0]);
          if (isset($hoofd[1])) echo '<br />' . h($hoofd[1]);
        ?></th>
        <th class="col-token"><?php echo t('col_upstream_token'); ?></th>
        <th class="col-comment"><?php echo t('col_commentaar'); ?></th>
        <th class="col-actions"><?php echo t('col_acties'); ?></th>
      </tr>
    </thead>
    <tbody id="relay-tbody">
      <!-- Rijen worden via JavaScript ingevuld -->
    </tbody>
  </table>
  </div>

  <p class="text-muted mt-1" style="font-size:0.82em">
    <?php echo t('opslaan_waarschuwing'); ?>
  </p>
</div>

<!-- Verbindingstest sectie (verborgen totdat een test wordt uitgevoerd) -->
<div class="card" id="test-sectie" style="display:none">
  <h2>&#x1F50C; <?php echo t('test_titel'); ?></h2>
  <p class="text-muted mb-1" style="font-size:0.85em">
    <?php echo t('test_uitleg'); ?>
  </p>
  <div class="test-output" id="test-uitvoer"></div>
</div>

<?php endif; ?>

<!-- ============================================================
     TAB: INSTELLINGEN
     ============================================================ -->
<?php if ($tab === 'instellingen'): ?>
<div class="card">
  <div class="card-header">
    <h2>&#x2699;&#xFE0F; <?php echo t('instellingen_titel'); ?></h2>
  </div>

  <div class="settings-grid">
    <div>
      <div class="form-group">
        <label for="std-verbind-timeout">
          <?php echo t('verbind_timeout_label'); ?>
        </label>
        <input type="number" id="std-verbind-timeout" min="1" max="120"
               value="<?php echo h($std_verbind_timeout); ?>" />
        <p class="setting-comment"><?php echo t('verbind_timeout_comment'); ?></p>
      </div>
    </div>
    <div>
      <div class="form-group">
        <label for="std-totaal-timeout">
          <?php echo t('totaal_timeout_label'); ?>
        </label>
        <input type="number" id="std-totaal-timeout" min="0" max="3600"
               value="<?php echo h($std_totaal_timeout); ?>" />
        <p class="setting-comment"><?php echo t('totaal_timeout_comment'); ?></p>
      </div>
    </div>
  </div>

  <div class="mt-2">
    <button class="btn btn-primary" onclick="slaInstellingenOp()">
      &#x1F4BE; <?php echo t('instellingen_opslaan_knop'); ?>
    </button>
  </div>
</div>

<?php endif; ?>

<!-- ============================================================
     TAB: WACHTWOORD WIJZIGEN
     ============================================================ -->
<?php if ($tab === 'wachtwoord'): ?>
<div class="card">
  <div class="card-header">
    <h2>&#x1F511; <?php echo t('wachtwoord_wijzigen_titel'); ?></h2>
  </div>

  <div class="password-form">
    <div class="form-group">
      <label for="huidig-wachtwoord">
        <?php echo t('huidig_wachtwoord_label'); ?>
      </label>
      <input type="password" id="huidig-wachtwoord"
             autocomplete="current-password" />
    </div>
    <div class="form-group">
      <label for="nieuw-wachtwoord">
        <?php echo t('nieuw_wachtwoord_label'); ?>
      </label>
      <input type="password" id="nieuw-wachtwoord"
             autocomplete="new-password" />
    </div>
    <div class="form-group">
      <label for="bevestig-wachtwoord">
        <?php echo t('bevestig_wachtwoord_label'); ?>
      </label>
      <input type="password" id="bevestig-wachtwoord"
             autocomplete="new-password" />
    </div>
    <button class="btn btn-primary" onclick="wijzigWachtwoord()">
      &#x1F511; <?php echo t('wachtwoord_wijzigen_knop'); ?>
    </button>
  </div>
</div>
<?php endif; ?>

<!-- ============================================================
     TAB: INFO
     ============================================================ -->
<?php if ($tab === 'info'): ?>
<div class="card">
  <h2>&#x2139;&#xFE0F; <?php echo t('info_titel'); ?></h2>
  <p class="mb-1">
    <strong><?php echo t('app_naam'); ?></strong>
    <?php echo t('beheerpaneel'); ?> v<?php echo h(APP_VERSIE); ?> &#x2014;
    <?php echo t('info_beschrijving'); ?>
  </p>

  <h3 class="mt-2"><?php echo t('info_eindpunten_titel'); ?></h3>
  <p class="text-muted mb-1" style="font-size:0.82em">
    <?php echo t('info_huidig_adres'); ?>
    <code style="color:#7ec8e3"><?php echo h($basis_url); ?></code>
  </p>
  <table class="relay-table mt-1">
    <thead>
      <tr>
        <th><?php echo t('info_col_pad'); ?></th>
        <th><?php echo t('info_col_url'); ?></th>
        <th><?php echo t('info_col_methode'); ?></th>
        <th><?php echo t('info_col_beschrijving'); ?></th>
      </tr>
    </thead>
    <tbody>
<?php
// Eindpunten-lijst met vertalingssleutel voor de beschrijving
$eindpunten = [
    ['/v1/models',              'GET',  'info_ep_models'],
    ['/v1/chat/completions',    'POST', 'info_ep_chat'],
    ['/v1/completions',         'POST', 'info_ep_completions'],
    ['/v1/embeddings',          'POST', 'info_ep_embeddings'],
    ['/api/v1/models',          'GET',  'info_ep_lm_models'],
    ['/api/v1/chat/completions','POST', 'info_ep_lm_chat'],
    ['/anthropic/v1/messages',  'POST', 'info_ep_anthropic'],
];
foreach ($eindpunten as $ep):
    $vol_url = $basis_url . $ep[0];
?>
      <tr>
        <td><code><?php echo h($ep[0]); ?></code></td>
        <td>
          <span class="endpoint-url-wrap">
            <code class="endpoint-url"><?php echo h($vol_url); ?></code>
            <button type="button" class="btn btn-info btn-sm"
                    onclick="kopieerNaarKlembord('<?php echo h(addslashes($vol_url)); ?>', this)"
                    title="<?php echo t('kopieer_url_title'); ?>">&#x2398;</button>
          </span>
        </td>
        <td>
          <span class="badge <?php echo $ep[1] === 'GET' ? 'badge-on' : 'badge-ok'; ?>">
            <?php echo h($ep[1]); ?>
          </span>
        </td>
        <td><?php echo t($ep[2]); ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>

  <h3 class="mt-2"><?php echo t('info_curl_titel'); ?></h3>
  <div class="test-output" style="max-height:none">curl -sS \
  -H "Authorization: Bearer JOUW_INKOMEND_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"model":"gpt-3.5-turbo","messages":[{"role":"user","content":"Hello"}]}' \
  <?php echo h($basis_url); ?>/v1/chat/completions</div>

  <h3 class="mt-2"><?php echo t('info_bestanden_titel'); ?></h3>
  <div class="test-output" style="max-height:none">index.php         — <?php echo t('beheerpaneel'); ?>

relay.php         — Relay-logica (verwerkt API-verzoeken)
relay.css         — Stijlblad voor de beheerpagina
translation.json  — Vertalingen (NL/EN/DE/FR)
config.json       — Relay-configuratie (relays, timeouts)
auth.json         — Wachtwoord-hash voor de beheerpagina
.htaccess         — URL-herschrijving en beveiliging
favicon.ico       — Icoon (32x32) voor browser en navbar</div>
</div>
<?php endif; ?>

</div><!-- /.main-content -->

<!-- ===== VOETTEKST ===== -->
<div class="footer">
  <?php echo t('app_naam'); ?> v<?php echo h(APP_VERSIE); ?>
  &#x2014; <?php echo t('footer_beheerpaneel'); ?><br />
  <?php echo t('footer_gemaakt_door'); ?> <a href="https://domoticx.net" target="_blank" rel="noopener noreferrer" style="color:inherit">DomoticX</a> &#x2014; <?php echo t('footer_licentie'); ?>
</div>

<!-- ============================================================
     JAVASCRIPT — Beheerpagina functionaliteit
     ============================================================ -->
<script type="text/javascript">
/* jshint esversion: 6 */

/**
 * Relay-configuratie vanuit PHP (huidige staat van alle regels).
 */
var relayConfig = <?php echo json_encode($relays,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

/**
 * Vertalingstabel voor de actieve taal (voor JS-strings).
 * Bevat alle sleutels die in JavaScript gebruikt worden.
 */
var STRINGS = <?php echo json_encode($t,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

/**
 * Teller voor unieke rij-ID's (lokaal gebruik).
 */
var rijTeller = relayConfig.length;

/* ============================================================
   Initialisatie: vul de relay-tabel bij paginaladen
   ============================================================ */
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('relay-tbody')) {
        for (var i = 0; i < relayConfig.length; i++) {
            voegRijToe(relayConfig[i]);
        }
    }
});

/* ============================================================
   Hulpfunctie: toon melding
   ============================================================ */
/**
 * Toon een tijdelijke melding bovenaan de pagina.
 *
 * @param {string} bericht  Tekst van de melding
 * @param {string} type     'success', 'error', of 'info'
 */
function toonMelding(bericht, type) {
    var container = document.getElementById('melding-container');
    if (!container) return;
    var div = document.createElement('div');
    div.className   = 'alert alert-' + (type || 'info');
    div.textContent = bericht;
    container.innerHTML = '';
    container.appendChild(div);
    // Automatisch verbergen na 4 seconden
    setTimeout(function() {
        if (div.parentNode) div.parentNode.removeChild(div);
    }, 4000);
}

/* ============================================================
   Relay-tabel: rij aanmaken
   ============================================================ */
/**
 * Maak een nieuwe rij aan in de relay-tabel.
 *
 * @param {Object} relay  Relay-regelobject (leeg object voor nieuwe regel)
 */
function voegRijToe(relay) {
    relay = relay || {};
    var tr  = document.createElement('tr');
    tr.id   = 'rij-' + (rijTeller++);
    if (!relay.actief) tr.className = 'disabled-row';

    // --- Cel: Aan/uit checkbox ---
    var tdActief = document.createElement('td');
    tdActief.className = 'col-active';
    tdActief.setAttribute('data-label', STRINGS.col_aan || 'Aan');
    var cbActief = document.createElement('input');
    cbActief.type    = 'checkbox';
    cbActief.checked = !!relay.actief;
    cbActief.title   = STRINGS.regel_schakelaar_title || '';
    cbActief.addEventListener('change', function() {
        tr.className = this.checked ? '' : 'disabled-row';
    });
    tdActief.appendChild(cbActief);
    tr.appendChild(tdActief);

    // --- Cel: Naam ---
    var tdNaam = maakInvoerCel('text', relay.naam || '',
        STRINGS.ph_naam || 'Naam', 'col-name');
    tdNaam.setAttribute('data-label', STRINGS.col_naam || 'Naam');
    tr.appendChild(tdNaam);

    // --- Cel: Inkomend token (met genereer- en kopieerknop) ---
    var tdInToken = maakTokenCelMetKnoppen(
        relay.inkomend_token || '', STRINGS.ph_inkomend_token || '');
    tdInToken.setAttribute('data-label', STRINGS.col_inkomend_token || 'Token');
    tr.appendChild(tdInToken);

    // --- Cel: Upstream IP/host ---
    var tdIp = maakInvoerCel('text', relay.upstream_ip || '',
        STRINGS.ph_ip || 'bijv. 192.168.1.10', 'col-target');
    tdIp.setAttribute('data-label', STRINGS.col_upstream_ip || 'IP/host');
    tr.appendChild(tdIp);

    // --- Cel: Poort ---
    var tdPoort = maakInvoerCel('number',
        relay.upstream_poort !== undefined ? relay.upstream_poort : '1234',
        'Port', 'col-port');
    tdPoort.setAttribute('data-label', STRINGS.col_poort || 'Port');
    tr.appendChild(tdPoort);

    // --- Cel: Verbindingstimeout ---
    var tdTimeout = maakInvoerCel('number',
        relay.verbind_timeout !== undefined ? relay.verbind_timeout : '10',
        STRINGS.ph_timeout || 'Sec.', 'col-timeout');
    tdTimeout.setAttribute('data-label', STRINGS.col_timeout || 'Timeout');
    tr.appendChild(tdTimeout);

    // --- Cel: Token upstream (checkbox: sturen of niet) ---
    var tdUToken = document.createElement('td');
    tdUToken.className = 'col-utoken';
    tdUToken.setAttribute('data-label', STRINGS.col_token_upstream_hoofd || 'Token upstream');
    var cbUToken = document.createElement('input');
    cbUToken.type    = 'checkbox';
    cbUToken.checked = !!relay.gebruik_upstream_token;
    cbUToken.title   = STRINGS.token_upstream_title || '';
    tdUToken.appendChild(cbUToken);
    tr.appendChild(tdUToken);

    // --- Cel: Upstream token waarde (met kopieerknop) ---
    var tdUpToken = maakTokenCelMetKnoppen(
        relay.upstream_token || '', STRINGS.ph_upstream_token || '');
    tdUpToken.setAttribute('data-label', STRINGS.col_upstream_token || 'Upstream token');
    tr.appendChild(tdUpToken);

    // --- Cel: Commentaar ---
    var tdComm = maakInvoerCel('text', relay.commentaar || '',
        STRINGS.ph_commentaar || '', 'col-comment');
    tdComm.setAttribute('data-label', STRINGS.col_commentaar || 'Commentaar');
    tr.appendChild(tdComm);

    // --- Cel: Acties (testen + verwijderen) ---
    var tdActies = document.createElement('td');
    tdActies.className = 'col-actions';
    tdActies.setAttribute('data-label', STRINGS.col_acties || 'Acties');

    // Testknop
    var btnTest = document.createElement('button');
    btnTest.className   = 'btn btn-info btn-sm';
    btnTest.textContent = STRINGS.testen || 'Test';
    btnTest.title       = STRINGS.test_verbinding_title || '';
    btnTest.onclick     = (function(rij) {
        return function() { testVerbinding(rij); };
    })(tr);

    // Verwijderknop
    var btnDel = document.createElement('button');
    btnDel.className = 'btn btn-danger btn-sm';
    btnDel.title     = STRINGS.verwijder_regel_title || '';
    btnDel.innerHTML = STRINGS.verwijderen_knop || '&#x2716; Del.';
    btnDel.onclick   = (function(rij) {
        return function() {
            if (confirm(STRINGS.verwijder_bevestiging || 'Delete?')) {
                rij.parentNode.removeChild(rij);
            }
        };
    })(tr);

    tdActies.appendChild(btnTest);
    tdActies.appendChild(document.createTextNode(' '));
    tdActies.appendChild(btnDel);
    tr.appendChild(tdActies);

    document.getElementById('relay-tbody').appendChild(tr);
}

/* ============================================================
   Hulpfuncties voor tabelcellen
   ============================================================ */

/**
 * Maak een tabelcel met een invoerveld aan.
 */
function maakInvoerCel(type, waarde, placeholder, klasse) {
    var td    = document.createElement('td');
    td.className = klasse || '';
    var input = document.createElement('input');
    input.type        = type;
    input.value       = waarde;
    input.placeholder = placeholder;
    td.appendChild(input);
    return td;
}

/**
 * Maak een token-cel met invoerveld, genereer-knop (⚡) en kopieer-knop (⊘).
 */
function maakTokenCelMetKnoppen(waarde, placeholder) {
    var td      = document.createElement('td');
    td.className = 'col-token';

    // Flex-wrapper voor input + knoppen naast elkaar
    var wrapper     = document.createElement('div');
    wrapper.className = 'token-invoer-wrap';

    var input       = document.createElement('input');
    input.type        = 'text';
    input.value       = waarde;
    input.placeholder = placeholder;

    // ⚡ Genereer nieuw OpenAI-stijl token
    var btnGen      = document.createElement('button');
    btnGen.type      = 'button';
    btnGen.className = 'btn btn-warning btn-sm';
    btnGen.title     = STRINGS.genereer_token_title || 'Generate token';
    btnGen.innerHTML = '&#x26A1;';
    btnGen.onclick   = function() { input.value = genereerToken(); };

    // ⊘ Kopieer waarde naar klembord
    var btnCopy     = document.createElement('button');
    btnCopy.type      = 'button';
    btnCopy.className = 'btn btn-info btn-sm';
    btnCopy.title     = STRINGS.kopieer_klembord_title || 'Copy';
    btnCopy.innerHTML = '&#x2398;';
    btnCopy.onclick   = function() { kopieerNaarKlembord(input.value, btnCopy); };

    wrapper.appendChild(input);
    wrapper.appendChild(btnGen);
    wrapper.appendChild(btnCopy);
    td.appendChild(wrapper);
    return td;
}

/* ============================================================
   Relay-tabel: nieuwe lege regel toevoegen
   ============================================================ */
function voegRegelToe() {
    voegRijToe({
        actief:                true,
        naam:                  '',
        inkomend_token:        '',
        upstream_ip:           '',
        upstream_poort:        1234,
        verbind_timeout:       10,
        gebruik_upstream_token:true,
        upstream_token:        '',
        commentaar:            '',
    });
    // Scroll naar de nieuwe rij
    var tbody = document.getElementById('relay-tbody');
    if (tbody && tbody.lastChild) {
        tbody.lastChild.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

/* ============================================================
   Relay-tabel: gegevens uitlezen
   ============================================================ */
/**
 * Lees alle relay-regels uit de tabel en geef ze als array terug.
 * De volgorde van invoervelden per rij is:
 *  [0] actief (checkbox), [1] naam, [2] inkomend_token, [3] upstream_ip,
 *  [4] upstream_poort, [5] verbind_timeout, [6] gebruik_upstream_token (checkbox),
 *  [7] upstream_token, [8] commentaar
 */
function leesRelaysUitTabel() {
    var regels = [];
    document.querySelectorAll('#relay-tbody tr').forEach(function(tr) {
        var inv = tr.querySelectorAll('input');
        regels.push({
            actief:                !!inv[0].checked,
            naam:                  inv[1].value.trim(),
            inkomend_token:        inv[2].value.trim(),
            upstream_ip:           inv[3].value.trim(),
            upstream_poort:        parseInt(inv[4].value, 10) || 1234,
            verbind_timeout:       parseInt(inv[5].value, 10) || 10,
            gebruik_upstream_token:!!inv[6].checked,
            upstream_token:        inv[7].value.trim(),
            commentaar:            inv[8].value.trim(),
        });
    });
    return regels;
}

/* ============================================================
   Relay-regels opslaan via AJAX
   ============================================================ */
function slaRelaysOp() {
    fetch('index.php?actie=sla_relays_op', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(leesRelaysUitTabel()),
    })
    .then(function(r) { return r.json(); })
    .then(function(d) { toonMelding(d.bericht, d.ok ? 'success' : 'error'); })
    .catch(function(e) {
        toonMelding((STRINGS.js_fout_opslaan || 'Fout:') + ' ' + e.message, 'error');
    });
}

/* ============================================================
   Verbinding testen
   ============================================================ */
/**
 * Test de verbinding met de upstream LLM voor een tabelrij.
 *
 * @param {HTMLElement} tr  De te testen tabelrij
 */
function testVerbinding(tr) {
    var inv  = tr.querySelectorAll('input');
    var data = {
        upstream_ip:            inv[3].value.trim(),
        upstream_poort:         parseInt(inv[4].value, 10) || 1234,
        verbind_timeout:        parseInt(inv[5].value, 10) || 10,
        gebruik_upstream_token: !!inv[6].checked,
        upstream_token:         inv[7].value.trim(),
    };

    var sectie  = document.getElementById('test-sectie');
    var uitvoer = document.getElementById('test-uitvoer');
    if (sectie)  sectie.style.display = 'block';
    if (uitvoer) uitvoer.textContent  =
        (STRINGS.test_bezig || 'Testen') + ' ' + data.upstream_ip + '...\n';

    fetch('index.php?actie=verbinding_testen', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(data),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (uitvoer) {
            uitvoer.textContent =
                (STRINGS.test_url_label    || 'URL:')     + ' ' + res.url    + '\n' +
                (STRINGS.test_status_label || 'Status:')  + ' ' +
                    (res.ok ? STRINGS.test_ok || 'OK ✓' : STRINGS.test_fout || 'FOUT ✗') + '\n' +
                (STRINGS.test_resultaat_label || 'Resultaat:') + ' ' + res.bericht +
                (res.reactie
                    ? '\n\n' + (STRINGS.test_antwoord_label || 'Antwoord:') + '\n' + res.reactie
                    : '');
        }
        if (sectie) sectie.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    })
    .catch(function(e) {
        if (uitvoer) uitvoer.textContent =
            (STRINGS.js_fout_js || 'JS-fout:') + ' ' + e.message;
    });
}

/* ============================================================
   Globale instellingen opslaan
   ============================================================ */
function slaInstellingenOp() {
    var data = {
        standaard_verbind_timeout: parseInt(
            document.getElementById('std-verbind-timeout').value, 10) || 10,
        standaard_totaal_timeout:  parseInt(
            document.getElementById('std-totaal-timeout').value, 10) || 0,
    };
    fetch('index.php?actie=sla_instellingen_op', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(data),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) { toonMelding(res.bericht, res.ok ? 'success' : 'error'); })
    .catch(function(e) {
        toonMelding((STRINGS.js_fout_algemeen || 'Fout:') + ' ' + e.message, 'error');
    });
}

/* ============================================================
   Wachtwoord wijzigen
   ============================================================ */
function wijzigWachtwoord() {
    var data = {
        huidig_wachtwoord:   document.getElementById('huidig-wachtwoord').value,
        nieuw_wachtwoord:    document.getElementById('nieuw-wachtwoord').value,
        bevestig_wachtwoord: document.getElementById('bevestig-wachtwoord').value,
    };
    fetch('index.php?actie=wijzig_wachtwoord', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(data),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        toonMelding(res.bericht, res.ok ? 'success' : 'error');
        if (res.ok) {
            document.getElementById('huidig-wachtwoord').value   = '';
            document.getElementById('nieuw-wachtwoord').value    = '';
            document.getElementById('bevestig-wachtwoord').value = '';
        }
    })
    .catch(function(e) {
        toonMelding((STRINGS.js_fout_algemeen || 'Fout:') + ' ' + e.message, 'error');
    });
}

/* ============================================================
   Token genereren (OpenAI-stijl: sk-relay-XXXX...)
   ============================================================ */
/**
 * Genereer een cryptografisch willekeurig Bearer-token.
 * Gebruikt window.crypto.getRandomValues voor veiligheid.
 *
 * @return {string}  Token in formaat sk-relay-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
 */
function genereerToken() {
    var tekens = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    var token  = 'sk-relay-';
    if (window.crypto && window.crypto.getRandomValues) {
        var buf = new Uint8Array(32);
        window.crypto.getRandomValues(buf);
        for (var i = 0; i < 32; i++) {
            token += tekens[buf[i] % tekens.length];
        }
    } else {
        // Fallback voor oudere browsers
        for (var j = 0; j < 32; j++) {
            token += tekens.charAt(Math.floor(Math.random() * tekens.length));
        }
    }
    return token;
}

/* ============================================================
   Kopieer naar klembord (met visuele bevestiging)
   ============================================================ */
/**
 * Kopieer een tekst naar het klembord.
 * Toont ✓ op de knop ter bevestiging.
 *
 * @param {string}      tekst  De te kopiëren tekst
 * @param {HTMLElement} knop   De knop voor visuele feedback
 */
function kopieerNaarKlembord(tekst, knop) {
    if (!tekst) return;
    var origHTML = knop.innerHTML;

    function bevestig() {
        knop.innerHTML   = '&#x2713;';
        knop.style.color = '#5cffa0';
        setTimeout(function() {
            knop.innerHTML   = origHTML;
            knop.style.color = '';
        }, 1600);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(tekst).then(bevestig).catch(function() {
            kopieerViaExecCommand(tekst);
            bevestig();
        });
    } else {
        kopieerViaExecCommand(tekst);
        bevestig();
    }
}

/**
 * Fallback via execCommand voor oudere browsers.
 */
function kopieerViaExecCommand(tekst) {
    var el = document.createElement('textarea');
    el.value          = tekst;
    el.style.position = 'fixed';
    el.style.opacity  = '0';
    document.body.appendChild(el);
    el.select();
    try { document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(el);
}

/* ============================================================
   Export / Import configuratie
   ============================================================ */

/**
 * Start de download van de huidige config.json via de server.
 */
function exporteerConfig() {
    window.location.href = 'index.php?actie=exporteer_config';
}

/**
 * Open het bestandskeuze-dialoogvenster voor import.
 */
function triggerImport() {
    var el = document.getElementById('import-bestand');
    if (el) el.click();
}

/**
 * Lees het geselecteerde JSON-bestand en importeer het via de server.
 *
 * @param {HTMLInputElement} input  Het verborgen bestandsinvoerveld
 */
function importeerConfig(input) {
    var bestand = input.files[0];
    if (!bestand) return;
    var lezer = new FileReader();
    lezer.onload = function(e) {
        var data;
        try {
            data = JSON.parse(e.target.result);
        } catch(err) {
            toonMelding((STRINGS.js_fout_algemeen || 'Error:') + ' ' + err.message, 'error');
            return;
        }
        fetch('index.php?actie=importeer_config', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify(data),
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            toonMelding(res.bericht, res.ok ? 'success' : 'error');
            if (res.ok) {
                setTimeout(function() { window.location.reload(); }, 1200);
            }
        })
        .catch(function(e) {
            toonMelding((STRINGS.js_fout_algemeen || 'Error:') + ' ' + e.message, 'error');
        });
        input.value = ''; // reset zodat hetzelfde bestand opnieuw geselecteerd kan worden
    };
    lezer.readAsText(bestand);
}
</script>

</body>
</html>
