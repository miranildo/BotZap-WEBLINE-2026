<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Teste de IP</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .ip { font-size: 24px; color: #00b894; font-weight: bold; }
        .info { background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .ipv4 { color: #3498db; }
        .ipv6 { color: #9b59b6; }
    </style>
</head>
<body>
    <h1>🔍 Diagnóstico de IP</h1>
    
    <div class="info">
        <h3>IP Detectado pela função:</h3>
        <?php
        function getIpCliente() {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = $_SERVER['HTTP_X_REAL_IP'];
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'desconhecido';
        }
        
        $ipDetectado = getIpCliente();
        $tipoIP = filter_var($ipDetectado, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : 
                 (filter_var($ipDetectado, FILTER_VALIDATE_IP) ? 'IPv4' : 'Desconhecido');
        $classeIP = $tipoIP === 'IPv6' ? 'ipv6' : 'ipv4';
        ?>
        <div class="ip <?= $classeIP ?>">
            <?= htmlspecialchars($ipDetectado) ?> 
            <span style="font-size: 14px;">(<?= $tipoIP ?>)</span>
        </div>
    </div>
    
    <div class="info">
        <h3>Headers recebidos:</h3>
        <table border="1" cellpadding="5">
            <tr><th>Header</th><th>Valor</th></tr>
            <tr><td>REMOTE_ADDR</td><td><?= $_SERVER['REMOTE_ADDR'] ?? 'N/A' ?></td></tr>
            <tr><td>HTTP_X_FORWARDED_FOR</td><td><?= $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'N/A' ?></td></tr>
            <tr><td>HTTP_X_REAL_IP</td><td><?= $_SERVER['HTTP_X_REAL_IP'] ?? 'N/A' ?></td></tr>
            <tr><td>HTTP_CF_CONNECTING_IP</td><td><?= $_SERVER['HTTP_CF_CONNECTING_IP'] ?? 'N/A' ?></td></tr>
        </table>
    </div>
    
    <div class="info">
        <h3>Informações da conexão:</h3>
        <p><strong>Protocolo:</strong> <?= $_SERVER['HTTPS'] ? 'HTTPS' : 'HTTP' ?></p>
        <p><strong>Server Addr:</strong> <?= $_SERVER['SERVER_ADDR'] ?? 'N/A' ?></p>
        <p><strong>Server Name:</strong> <?= $_SERVER['SERVER_NAME'] ?? 'N/A' ?></p>
    </div>
    
    <p><small>Teste realizado em: <?= date('d/m/Y H:i:s') ?></small></p>
</body>
</html>
