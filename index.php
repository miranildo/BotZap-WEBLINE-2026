<?php
require __DIR__ . '/auth.php';

$configPath = '/opt/whatsapp-bot/config.json';
$statusPath = '/opt/whatsapp-bot/status.json';

$mensagem = '';
$erro = '';

$config = [
    'empresa' => '',
    'menu' => '',
    'boleto_url' => '',
    'atendente_numero' => '',
    'tempo_atendimento_humano' => '',
    'feriados_ativos' => 'Sim',
    // Novos campos para MK-Auth
    'mkauth_url' => 'https://www.SEU_DOMINIO.com.br/api',
    'mkauth_client_id' => '',
    'mkauth_client_secret' => ''
];

/* ========= CARREGA CONFIG ========= */
if (file_exists($configPath)) {
    $json = file_get_contents($configPath);
    $data = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $config = array_merge($config, $data);
    }
}

/* ========= POST / REDIRECT ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['empresa'] = trim($_POST['empresa'] ?? '');
    $config['menu'] = trim($_POST['menu'] ?? '');
    $config['boleto_url'] = trim($_POST['boleto_url'] ?? '');
    $config['atendente_numero'] = trim($_POST['atendente_numero'] ?? '');
    $config['tempo_atendimento_humano'] =
        intval($_POST['tempo_atendimento_humano'] ?? '');
    $config['feriados_ativos'] = trim($_POST['feriados_ativos'] ?? 'Sim');
    
    // Novos campos MK-Auth
    $config['mkauth_url'] = trim($_POST['mkauth_url'] ?? '');
    $config['mkauth_client_id'] = trim($_POST['mkauth_client_id'] ?? '');
    $config['mkauth_client_secret'] = trim($_POST['mkauth_client_secret'] ?? '');

    if ($config['tempo_atendimento_humano'] <= 0) {
        $config['tempo_atendimento_humano'] = 30;
    }

    // Validar valor do feriado
    $config['feriados_ativos'] = in_array($config['feriados_ativos'], ['Sim', 'Não']) 
        ? $config['feriados_ativos'] 
        : 'Sim';

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($configPath, $json) !== false) {
        header('Location: index.php?salvo=1');
        exit;
    } else {
        $erro = 'Erro ao salvar configurações';
    }
}

/* ========= MENSAGEM ========= */
if (isset($_GET['salvo']) && $_GET['salvo'] == 1) {
    $mensagem = 'Configurações salvas com sucesso!';
}

/* ========= STATUS ========= */
$status = 'offline';
if (file_exists($statusPath)) {
    $st = json_decode(file_get_contents($statusPath), true);
    if (!empty($st['status'])) {
        $status = $st['status'];
    }
}

/* ========= API SIMPLES PARA VERIFICAÇÃO ========= */
if (isset($_GET['check_status']) || isset($_GET['api_status'])) {
    // Retornar apenas o status em formato simples
    header('Content-Type: text/plain');
    echo $status;
    exit;
}

/* ========= IMAGEM CONFORME STATUS ========= */
$imgSrc = 'qrcode_view.php';

if ($status === 'online') {
    $imgSrc = '/qrcode_online.png';
} elseif ($status === 'offline') {
    $imgSrc = '/qrcode_wait.png';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Bot WhatsApp – Painel</title>

<style>
body {
    margin:0;
    font-family: Inter, Arial, sans-serif;
    background:#f4f6f8;
    color:#1f2937;
}
header {
    background:#111827;
    color:#fff;
    padding:18px 30px;
    font-size:20px;
    font-weight:600;
}
.container {
    max-width:1100px;
    margin:30px auto;
    padding:0 20px;
    display:grid;
    grid-template-columns:360px 1fr;
    gap:30px;
}
.card {
    background:#fff;
    border-radius:14px;
    padding:25px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
}
.card h2 {
    margin-top:0;
    font-size:18px;
}
.qr-box {
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:15px;
}
.qr-box img {
    width:320px;
    height:320px;
    border-radius:14px;
    background:#f1f3f4;
    object-fit:contain;
}
.status {
    padding:8px 14px;
    border-radius:999px;
    font-weight:600;
    font-size:14px;
}
.status.online { background:#dcfce7; color:#166534; }
.status.offline { background:#fee2e2; color:#991b1b; }
.status.qr { background:#e0f2fe; color:#075985; }

.alert {
    padding:12px 16px;
    border-radius:10px;
    margin-bottom:15px;
    font-weight:600;
}
.alert.success { background:#dcfce7; color:#166534; }
.alert.error { background:#fee2e2; color:#991b1b; }

form label {
    display:block;
    margin-top:15px;
    font-weight:600;
}
form input, form textarea, form select {
    width:100%;
    padding:10px 12px;
    margin-top:6px;
    border-radius:8px;
    border:1px solid #d1d5db;
    font-family: inherit;
    font-size: inherit;
}
textarea { height:110px; resize:vertical; }

.radio-group {
    display: flex;
    gap: 20px;
    margin-top: 10px;
}

.radio-option {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.radio-option input[type="radio"] {
    width: auto;
    margin: 0;
}

.feriado-info {
    background: #f0f9ff;
    border-left: 4px solid #0ea5e9;
    padding: 12px 15px;
    margin-top: 15px;
    border-radius: 8px;
    font-size: 14px;
}

.feriado-info p {
    margin: 5px 0;
}

.config-section {
    background: #f9fafb;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
    border-left: 4px solid #3b82f6;
}

.config-section h3 {
    margin-top: 0;
    color: #1f2937;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.config-section h3 i {
    font-size: 18px;
}

button {
    margin-top:20px;
    padding:12px 18px;
    border:none;
    border-radius:10px;
    background:#2563eb;
    color:#fff;
    font-weight:600;
    cursor:pointer;
}
</style>
</head>

<body>

<header style="display:flex; justify-content:space-between; align-items:center;">
    <span>🤖 Painel – Bot WhatsApp Atendimento</span>

    <a href="logout.php"
       style="
           background:#dc2626;
           color:#fff;
           padding:8px 14px;
           border-radius:8px;
           font-size:14px;
           font-weight:600;
           text-decoration:none;
       "
       onclick="return confirm('Deseja realmente sair do painel?')">
       Sair
    </a>
</header>

<div class="container">

<div class="card qr-box">
    <h2>Status do WhatsApp</h2>
    <img id="qrImg" src="<?= $imgSrc ?>" alt="Status WhatsApp">
    <div class="status <?= htmlspecialchars($status) ?>">
        <?= strtoupper($status) ?>
    </div>
</div>

<div class="card">
    <h2>Configurações</h2>

    <?php if ($mensagem): ?>
        <div id="msg" class="alert success"><?= $mensagem ?></div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert error"><?= $erro ?></div>
    <?php endif; ?>

    <form method="post">
        <label>Empresa</label>
        <input name="empresa" value="<?= htmlspecialchars($config['empresa']) ?>">

        <label>Mensagem do Menu</label>
        <textarea name="menu"><?= htmlspecialchars($config['menu']) ?></textarea>

        <label>URL do Boleto</label>
        <input name="boleto_url" value="<?= htmlspecialchars($config['boleto_url']) ?>">

        <label>Número do Atendente</label>
        <input name="atendente_numero" value="<?= htmlspecialchars($config['atendente_numero']) ?>">

        <label>⏱️ Tempo máximo de atendimento humano (minutos)</label>
        <input
            type="number"
            min="5"
            step="5"
            name="tempo_atendimento_humano"
            value="<?= (int)$config['tempo_atendimento_humano'] ?>"
        >

        <label>🎯 Considerar feriados no atendimento?</label>
        <div class="radio-group">
            <label class="radio-option">
                <input type="radio" name="feriados_ativos" value="Sim" 
                    <?= ($config['feriados_ativos'] === 'Sim') ? 'checked' : '' ?>>
                Sim
            </label>
            <label class="radio-option">
                <input type="radio" name="feriados_ativos" value="Não"
                    <?= ($config['feriados_ativos'] === 'Não') ? 'checked' : '' ?>>
                Não
            </label>
        </div>

        <div class="feriado-info">
            <p><strong>ℹ️ Informações sobre feriados:</strong></p>
            <p>• <strong>Sim:</strong> O bot não oferece atendimento humano em feriados nacionais</p>
            <p>• <strong>Não:</strong> Atendimento humano funciona mesmo em feriados</p>
            <p>• PIX continua disponível 24/7 independentemente desta configuração</p>
        </div>

        <div class="config-section">
            <h3>🔐 Configurações MK-Auth (Verificação de Clientes)</h3>
            <p style="margin-top: 0; font-size: 14px; color: #6b7280;">
                Configurações para verificação de CPF/CNPJ na base de clientes antes de gerar link PIX
            </p>

            <label>URL do MK-Auth</label>
            <input 
                name="mkauth_url" 
                value="<?= htmlspecialchars($config['mkauth_url']) ?>"
                placeholder="https://www.SEU_DOMINIO.com.br/api"
            >
            <small style="color: #6b7280; font-size: 12px;">
                URL base do sistema MK-Auth (deve terminar com / se for API completa)
            </small>

            <label>Client ID</label>
            <input 
                name="mkauth_client_id" 
                value="<?= htmlspecialchars($config['mkauth_client_id']) ?>"
                placeholder="c582c8ede2c9169c64f29c626fadc38e"
            >
            <small style="color: #6b7280; font-size: 12px;">
                Identificador do cliente para autenticação na API
            </small>

            <label>Client Secret</label>
            <input 
                name="mkauth_client_secret" 
                type="password"
                value="<?= htmlspecialchars($config['mkauth_client_secret']) ?>"
                placeholder="9d2367fbf45d2e89d8ee8cb92ca3c02f13bcb0c1"
            >
            <small style="color: #6b7280; font-size: 12px;">
                Senha de acesso à API (chave secreta)
            </small>

            <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 12px; margin-top: 15px; border-radius: 6px; font-size: 13px;">
                <p style="margin: 0; color: #92400e;"><strong>⚠️ Importante:</strong></p>
                <p style="margin: 5px 0 0 0; color: #92400e;">
                    • Se as credenciais não forem configuradas, o bot NÃO permitirá acesso direto ao PIX<br>
                    • Configure corretamente para filtrar apenas clientes da base
                </p>
            </div>
        </div>

        <button type="submit">Salvar configurações</button>
    </form>
</div>

</div>

<script>
// ⚠️ SOLUÇÃO COMPLETA - monitora QR → ONLINE e ONLINE → QR
const statusDiv = document.querySelector('.status');

if (statusDiv) {
    console.log('🔍 Monitorando status do WhatsApp...');
    
    // Determinar status atual
    let statusAtual;
    if (statusDiv.classList.contains('online')) {
        statusAtual = 'online';
    } else if (statusDiv.classList.contains('qr')) {
        statusAtual = 'qr';
    } else {
        statusAtual = 'offline';
    }
    
    console.log('Status inicial:', statusAtual);
    
    // ⚠️ VERIFICAR MUDANÇAS DE STATUS A CADA 3 SEGUNDOS
    const intervalo = setInterval(() => {
        fetch('?api_status=1&t=' + Date.now())
            .then(r => r.text())
            .then(novoStatus => {
                console.log('Status verificado:', novoStatus, '(atual:', statusAtual, ')');
                
                // Se o status mudou
                if (novoStatus !== statusAtual) {
                    console.log('✅ Status mudou de', statusAtual, 'para', novoStatus, 'Recarregando página...');
                    clearInterval(intervalo);
                    location.reload();
                }
            })
            .catch(() => {
                console.log('Erro na verificação');
            });
    }, 3000); // Verificar a cada 3 segundos
    
    // ⚠️ ATUALIZAR IMAGEM DO QR APENAS SE FOR QR CODE
    if (statusAtual === 'qr') {
        setInterval(() => {
            const img = document.getElementById('qrImg');
            if (img && img.src.includes('qrcode_view.php')) {
                img.src = 'qrcode_view.php?t=' + Date.now();
            }
        }, 3000);
    }
}

// Mensagem de sucesso
if (window.location.search.includes('salvo=1')) {
    window.history.replaceState({}, document.title, window.location.pathname);
}

const msg = document.getElementById('msg');
if (msg) {
    setTimeout(() => {
        msg.style.opacity = '0';
        setTimeout(() => msg.remove(), 500);
    }, 3000);
}

// Mostrar/ocultar senha MK-Auth
const secretField = document.querySelector('input[name="mkauth_client_secret"]');
if (secretField) {
    const showPasswordBtn = document.createElement('button');
    showPasswordBtn.type = 'button';
    showPasswordBtn.innerHTML = '👁️';
    showPasswordBtn.style.cssText = `
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        background: transparent;
        border: none;
        cursor: pointer;
        font-size: 16px;
    `;
    
    const parent = secretField.parentElement;
    parent.style.position = 'relative';
    parent.appendChild(showPasswordBtn);
    
    showPasswordBtn.addEventListener('click', function() {
        if (secretField.type === 'password') {
            secretField.type = 'text';
            this.innerHTML = '🙈';
        } else {
            secretField.type = 'password';
            this.innerHTML = '👁️';
        }
    });
}
</script>

</body>
</html>
