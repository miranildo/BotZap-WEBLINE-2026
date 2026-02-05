<?php
$qrFile = '/opt/whatsapp-bot/qrcode.txt';

header('Content-Type: image/png');

if (!file_exists($qrFile)) {
    // imagem vazia
    $img = imagecreatetruecolor(300, 300);
    $bg = imagecolorallocate($img, 240, 240, 240);
    imagefill($img, 0, 0, $bg);
    imagepng($img);
    imagedestroy($img);
    exit;
}

$qrText = trim(file_get_contents($qrFile));

if ($qrText === '') {
    $img = imagecreatetruecolor(300, 300);
    $bg = imagecolorallocate($img, 240, 240, 240);
    imagefill($img, 0, 0, $bg);
    imagepng($img);
    imagedestroy($img);
    exit;
}

// usa API do Google Charts para renderizar QR
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($qrText);

// redireciona a imagem
readfile($qrUrl);
