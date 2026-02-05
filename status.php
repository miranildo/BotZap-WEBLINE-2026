<?php
header('Content-Type: application/json');

$statusFile = '/opt/whatsapp-bot/status.json';
$qrFile = '/opt/whatsapp-bot/qrcode.png';

$status = 'offline';
$updated = null;

if (file_exists($statusFile)) {
    $data = json_decode(file_get_contents($statusFile), true);
    if (is_array($data) && isset($data['status'])) {
        $status = $data['status'];
        $updated = $data['updated'] ?? null;
    }
}

$response = [
    'status' => $status,
    'updated' => $updated
];

if ($status === 'qr' && file_exists($qrFile) && filesize($qrFile) > 0) {
    $response['qr'] = 'qrcode.png?' . time();
}

echo json_encode($response);
