<?php
// api/whoami.php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");

// On récupère l'IP publique via un service externe (ipify)
$ip = null;
$ch = curl_init("https://api.ipify.org?format=json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(["error" => "Impossible de récupérer IP publique", "curlErr" => $err]);
    exit;
}

$data = json_decode($res, true);
$ip = $data['ip'] ?? null;
echo json_encode(["ip" => $ip]);
