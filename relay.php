<?php
/**
 * relay.php — Kern relaylogica voor de AI LLM Relay
 *
 * Dit bestand verwerkt inkomende API-verzoeken en stuurt ze door
 * naar de geconfigureerde upstream LLM-servers op basis van het
 * meegestuurde Bearer-token.
 *
 * Ondersteunde LLM-eindpunten (OpenAI-compatibel):
 *   /v1/models, /v1/chat/completions, /v1/completions,
 *   /v1/embeddings, /api/v1/models, /api/v1/chat/completions, etc.
 */

/* ============================================================
   Laad configuratie uit config.json
   ============================================================ */

// Pad naar het configuratiebestand
define('CONFIG_FILE', __DIR__ . '/config.json');
// Pad naar het authenticatiebestand
define('AUTH_FILE',   __DIR__ . '/auth.json');

/**
 * Lees en decodeer het JSON-configuratiebestand.
 * Geeft een lege array terug als het bestand ontbreekt.
 *
 * @return array
 */
function laad_config(): array {
    if (!file_exists(CONFIG_FILE)) {
        return [];
    }
    $inhoud = file_get_contents(CONFIG_FILE);
    $data   = json_decode($inhoud, true);
    return is_array($data) ? $data : [];
}

/* ============================================================
   Hulpfunctie: haal Bearer-token op uit de request-headers
   ============================================================ */

/**
 * Leest het Bearer-token uit de Authorization-header.
 * Probeert meerdere bronnen om compatibel te zijn met
 * verschillende server- en proxy-configuraties.
 *
 * @return string  Het token zonder "Bearer "-prefix, of lege string
 */
function haal_bearer_token(): string {
    // 1) Standaard HTTP_AUTHORIZATION via Apache/PHP
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // 2) Soms herschreven door Apache RewriteRule via REDIRECT_*
    if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    // 3) Fallback via getallheaders() (werkt op sommige configs)
    if (!$auth && function_exists('getallheaders')) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    // Extraheer het token na "Bearer "
    if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $treffer)) {
        return trim($treffer[1]);
    }
    return '';
}

/* ============================================================
   CORS-headers — vereist voor browser-gebaseerde LLM-clients
   ============================================================ */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, x-api-key, anthropic-version, anthropic-beta');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Verwerk OPTIONS preflight verzoek (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ============================================================
   Laad config en zoek de juiste relay-regel op basis van token
   ============================================================ */
$config = laad_config();
$relays = $config['relays'] ?? [];

// Haal het inkomende token op
$inkomend_token = haal_bearer_token();

// Zoek actieve relay-regel waarvan het inkomend_token overeenkomt
$gevonden_relay = null;
foreach ($relays as $relay) {
    // Sla uitgeschakelde regels over
    if (empty($relay['actief'])) {
        continue;
    }
    // Vergelijk tokens op een timing-veilige manier
    if (!empty($relay['inkomend_token']) && hash_equals($relay['inkomend_token'], $inkomend_token)) {
        $gevonden_relay = $relay;
        break;
    }
}

// Geen geldige relay gevonden → 401 Unauthorized
if ($gevonden_relay === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error'  => 'Unauthorized',
        'detail' => 'Geen geldige relay gevonden voor dit Bearer-token.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   Bouw de upstream URL op
   ============================================================ */

// Bepaal het pad uit de query-parameter (gezet door .htaccess)
$pad   = $_GET['path'] ?? '/';
// Haal de rest van de querystring op, zonder het 'path' onderdeel
$query = $_SERVER['QUERY_STRING'] ?? '';
parse_str($query, $qs_array);
unset($qs_array['path']);
$qs_string = http_build_query($qs_array);

// Stel het upstream-basis-adres samen uit ip + poort
$upstream_ip   = rtrim($gevonden_relay['upstream_ip'] ?? '', '/');
$upstream_port = $gevonden_relay['upstream_poort'] ?? 80;
$protocol      = (!empty($gevonden_relay['gebruik_https'])) ? 'https' : 'http';

// Controleer of de poort al in het IP-adres zit (bijv. "https://host:poort")
if (preg_match('/^https?:\/\//', $upstream_ip)) {
    $upstream_basis = rtrim($upstream_ip, '/');
} else {
    $upstream_basis = $protocol . '://' . $upstream_ip . ':' . $upstream_port;
}

$upstream_url = $upstream_basis . $pad . ($qs_string ? ('?' . $qs_string) : '');

/* ============================================================
   Stel cURL-verzoek in
   ============================================================ */
$methode   = $_SERVER['REQUEST_METHOD'];
$verzoek_body = file_get_contents('php://input');

// Bouw de door te sturen headers op
$headers = [];

// Stuur Content-Type door
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if ($content_type) {
    $headers[] = 'Content-Type: ' . $content_type;
}

// Stuur Accept door als aanwezig
if (!empty($_SERVER['HTTP_ACCEPT'])) {
    $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
}

// Stuur anthropic-specifieke headers door indien aanwezig
foreach (['HTTP_ANTHROPIC_VERSION', 'HTTP_ANTHROPIC_BETA', 'HTTP_X_API_KEY'] as $h) {
    if (!empty($_SERVER[$h])) {
        $naam = str_replace(['HTTP_', '_'], ['', '-'], $h);
        $naam = ucwords(strtolower($naam), '-');
        $headers[] = $naam . ': ' . $_SERVER[$h];
    }
}

// Voeg het upstream-token toe (indien geconfigureerd en ingeschakeld)
$gebruik_upstream_token = !empty($gevonden_relay['gebruik_upstream_token']);
if ($gebruik_upstream_token && !empty($gevonden_relay['upstream_token'])) {
    $headers[] = 'Authorization: Bearer ' . $gevonden_relay['upstream_token'];
} elseif (!$gebruik_upstream_token) {
    // Geen token meesturen naar upstream
} else {
    // Token is leeg maar wel ingeschakeld — stuur door zonder token
}

// Timeout-instellingen
$verbind_timeout = (int)($gevonden_relay['verbind_timeout'] ?? $config['standaard_verbind_timeout'] ?? 10);
$totaal_timeout  = (int)($gevonden_relay['totaal_timeout']  ?? $config['standaard_totaal_timeout']  ?? 0);

// Schakel PHP-outputbuffering uit voor streaming-antwoorden
@ini_set('output_buffering',       'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

/* ============================================================
   Initialiseer cURL en stuur het verzoek door
   ============================================================ */
$ch = curl_init($upstream_url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => $methode,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => in_array($methode, ['GET', 'HEAD']) ? null : $verzoek_body,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => $verbind_timeout,
    CURLOPT_TIMEOUT        => $totaal_timeout,
    CURLOPT_RETURNTRANSFER => false,  // Direct streamen
    CURLOPT_HEADER         => false,
    CURLOPT_SSL_VERIFYPEER => !empty($gevonden_relay['ssl_verify']),
]);

// Stuur relevante response-headers door naar de client
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header_regel) {
    $len  = strlen($header_regel);
    $trim = trim($header_regel);
    if ($trim === '') return $len;

    // Doorsturen van Content-Type, X-Request-ID, en streaming-headers
    $door_te_sturen = [
        'content-type',
        'x-request-id',
        'transfer-encoding',
    ];
    $lower = strtolower(explode(':', $trim, 2)[0] ?? '');
    if (in_array($lower, $door_te_sturen, true)) {
        header($trim, false);
    }

    // HTTP status-regel doorgeven
    if (preg_match('/^HTTP\/\d+(\.\d+)?\s+(\d{3})\s/i', $trim, $m)) {
        http_response_code((int)$m[2]);
    }

    return $len;
});

// Streaminhoud direct doorsturen naar de client
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $stuk) {
    echo $stuk;
    @ob_flush();
    @flush();
    return strlen($stuk);
});

// Voer het cURL-verzoek uit
$geslaagd   = curl_exec($ch);
$curl_fout  = curl_error($ch);
$http_code  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

// Behandel cURL-fouten
if ($geslaagd === false) {
    if (!headers_sent()) {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error'             => 'Bad Gateway',
        'detail'            => 'Verbinding met upstream LLM mislukt.',
        'curl_fout'         => $curl_fout,
        'upstream_http_code'=> $http_code,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Zet de juiste HTTP-statuscode als dat nog niet gebeurd is via de header-callback
if ($http_code && !headers_sent()) {
    http_response_code($http_code);
}
