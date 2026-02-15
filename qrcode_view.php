<?php
// qrcode_view.php - VERSÃO FINAL PRODUÇÃO
$qrFile = '/opt/whatsapp-bot/qrcode.txt';

header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Lista de APIs funcionais (em ordem de preferência)
$apis = [
    'QR Server' => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=',
    'GoQR' => 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=', // mesmo do primeiro
    'QuickChart' => 'https://quickchart.io/qr?text=' // fallback adicional
];

function tentarAPIs($texto, $apis) {
    foreach ($apis as $nome => $urlBase) {
        $url = $urlBase . urlencode($texto);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $data = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        if ($info['http_code'] == 200 && $data && strlen($data) > 100) {
            error_log("QR Code gerado com $nome");
            return $data;
        }
    }
    return null;
}

// Verificar arquivo
if (!file_exists($qrFile) || filesize($qrFile) == 0) {
    $img = imagecreatetruecolor(300, 300);
    $bg = imagecolorallocate($img, 240, 240, 240);
    imagefill($img, 0, 0, $bg);
    imagepng($img);
    imagedestroy($img);
    exit;
}

$qrText = trim(file_get_contents($qrFile));

if (empty($qrText)) {
    $img = imagecreatetruecolor(300, 300);
    $bg = imagecolorallocate($img, 240, 240, 240);
    imagefill($img, 0, 0, $bg);
    imagepng($img);
    imagedestroy($img);
    exit;
}

// Tentar APIs
$imageData = tentarAPIs($qrText, $apis);

if ($imageData) {
    echo $imageData;
} else {
    // Imagem de erro amigável
    $img = imagecreatetruecolor(300, 300);
    $bg = imagecolorallocate($img, 255, 200, 200);
    $textColor = imagecolorallocate($img, 200, 0, 0);
    imagefill($img, 0, 0, $bg);
    imagestring($img, 5, 50, 140, "Erro ao gerar QR", $textColor);
    imagestring($img, 3, 30, 180, "Tente novamente", $textColor);
    imagepng($img);
    imagedestroy($img);
}
exit;
?>
