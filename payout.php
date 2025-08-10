<?php
// api/payout.php
// Proxy backend pour FeexPay Payouts
// ATTENTION : mettre FEEXPAY_API_KEY et SHOP_ID dans les variables d'environnement de ton hébergeur

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST");

// Désactiver affichage d'erreurs vers le client
ini_set('display_errors', 0);
error_reporting(0);

// Helper pour renvoyer JSON et terminer
function respond($data, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Lire le corps JSON
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!$input) {
    respond(["error" => "Invalid JSON body."], 400);
}

// Champs attendus
$country    = $input['country'] ?? null;
$phone      = $input['phoneNumber'] ?? ($input['phone'] ?? null);
$amount     = isset($input['amount']) ? floatval($input['amount']) : null;
$network    = $input['network'] ?? null;
$userId     = $input['userId'] ?? null; // optionnel ici

if (!$country || !$phone || !$amount || !$network) {
    respond(["error" => "Missing required fields. Required: country, phoneNumber, amount, network."], 400);
}

// Vérif montant minimum
if ($amount < 5000) {
    respond(["error" => "Le montant minimum est de 5000."], 400);
}

// Récupérer clé API et shop depuis variables d'environnement
$feexpayKey = getenv('FEEXPAY_API_KEY'); // sans "Bearer"
$shopId     = getenv('SHOP_ID');

if (!$feexpayKey || !$shopId) {
    respond(["error" => "Server not configured. Missing FEEXPAY_API_KEY or SHOP_ID."], 500);
}

// Mapping endpoints (tous ceux fournis)
$payoutUrls = [
    "BENIN" => "https://api.feexpay.me/api/payouts/public/transfer/global",
    "TOGO" => "https://api.feexpay.me/api/payouts/public/togo",
    "COTE_DIVOIRE" => [
        "MTN" => "https://api.feexpay.me/api/payouts/public/mtn_ci",
        "ORANGE" => "https://api.feexpay.me/api/payouts/public/orange_ci",
        "MOOV" => "https://api.feexpay.me/api/payouts/public/moov_ci",
        "WAVE" => "https://api.feexpay.me/api/payouts/public/wave_ci",
        "default" => "https://api.feexpay.me/api/payouts/public/transfer/global"
    ],
    "BURKINA_FASO" => "https://api.feexpay.me/api/payouts/public/transfer/global",
    "SENEGAL" => [
        "ORANGE" => "https://api.feexpay.me/api/payouts/public/orange_sn",
        "FREE" => "https://api.feexpay.me/api/payouts/public/free_sn",
        "default" => "https://api.feexpay.me/api/payouts/public/transfer/global"
    ],
    "CONGO_BRAZZAVILLE" => "https://api.feexpay.me/api/payouts/public/mtn_cg"
];

// Choisir endpoint
function choose_endpoint($country, $network, $mapping) {
    if (!isset($mapping[$country])) return null;
    $m = $mapping[$country];
    if (is_string($m)) return $m;

    // Normalize network key attempts
    $k = strtoupper(preg_replace("/[^A-Z0-9]/", "", $network));
    $tries = [$k, strtoupper($network), explode(' ', strtoupper($network))[0]];
    foreach ($tries as $t) {
        if (isset($m[$t])) return $m[$t];
    }
    return $m['default'] ?? null;
}

$url = choose_endpoint($country, $network, $payoutUrls);
if (!$url) {
    respond(["error" => "Impossible de déterminer l'endpoint FeexPay pour ce pays/réseau."], 400);
}

// Préparer la payload
$postData = [
    "phoneNumber" => $phone,
    "amount"      => $amount,
    "shop"        => $shopId,
    "network"     => $network,
    "motif"       => $input['motif'] ?? "Paiement commission"
];

// Envoi via cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $feexpayKey,
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log (append) — utile pour debug. Assure-toi que dossier logs/ est writable
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/feexpay.log';
$logEntry = date('c') . " | URL: $url | PAYLOAD: " . json_encode($postData) . " | HTTP: $httpCode | CURLERR: $curlErr | RESP: " . substr($response ?? '', 0, 1000) . PHP_EOL;
@file_put_contents($logFile, $logEntry, FILE_APPEND);

// Gestion des erreurs réseau
if ($curlErr) {
    respond(["error" => "Erreur connexion FeexPay: $curlErr"], 502);
}

// Tenter de décoder la réponse
$decoded = json_decode($response, true);
if ($decoded === null) {
    // Réponse non JSON — renvoyer brut pour debug
    respond(["error" => "Réponse invalide de FeexPay", "raw" => $response], 502);
}

// Si FeexPay renvoie erreur
if ($httpCode < 200 || $httpCode >= 300) {
    respond(["error" => $decoded['message'] ?? ($decoded['error'] ?? "Erreur API FeexPay"), "details" => $decoded], $httpCode);
}

// Succès : renvoyer la réponse FeexPay (par ex transactionId, reference, etc.)
respond($decoded, 200);
