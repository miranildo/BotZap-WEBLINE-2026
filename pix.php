<?php
/*************************************************
 * CONFIGURAÇÃO MK-AUTH
 *************************************************/
$URL_PROV = "https://www.SEU_PROVEDOR.com.br";
$API_BASE = "https://www.SEU_PROVEDOR.com.br/api/";
$CLIENT_ID = "SEU_ID_API";
$CLIENT_SECRET = "SEU_SECRET_API";

/*************************************************
 * CONFIGURAÇÃO DE FILTROS DE LOG
 *************************************************/
$FILTRO_CONFIG = [
    // Tempo mínimo entre logs do mesmo CPF (em segundos)
    'intervalo_minimo' => 15,
    
    // IPs para bloquear completamente (bots, crawlers)
    'ips_bloqueados' => [
        '66.249.64.0/19',     // Googlebot
        '66.249.80.0/20',     // Googlebot
        '66.249.85.0/24',     // Googlebot específico
        '66.249.86.0/24',     // Googlebot
        '66.249.89.0/24',     // Googlebot
        '66.249.90.0/24',     // Googlebot
        '66.249.91.0/24',     // Googlebot
        '66.249.92.0/24',     // Googlebot
        '34.64.0.0/10',       // Google Cloud
        '35.184.0.0/13',      // Google Cloud
        '8.8.8.8',            // Google DNS
        '8.8.4.4',            // Google DNS
    ],
    
    // User-Agents para bloquear (crawlers, bots)
    'user_agents_bloqueados' => [
        'googlebot',
        'bingbot',
        'slurp',
        'duckduckbot',
        'baiduspider',
        'yandexbot',
        'sogou',
        'exabot',
        'facebookexternalhit',
        'facebot',
        'ia_archiver',
        'ahrefsbot',
        'semrushbot',
        'mj12bot',
        'rogerbot',
        'dotbot',
        'megaindex',
    ],
];

/*************************************************
 * FUNÇÕES DE FILTRO
 *************************************************/
function ipEstaBloqueado($ip) {
    global $FILTRO_CONFIG;
    
    if (empty($ip) || $ip === 'desconhecido') {
        return false;
    }
    
    foreach ($FILTRO_CONFIG['ips_bloqueados'] as $ipBloqueado) {
        if (strpos($ipBloqueado, '/') !== false) {
            if (ipNaFaixaCidr($ip, $ipBloqueado)) {
                return true;
            }
        } else {
            if ($ip === $ipBloqueado) {
                return true;
            }
        }
    }
    
    return false;
}

function ipNaFaixaCidr($ip, $cidr) {
    list($rede, $mascara) = explode('/', $cidr);
    
    $ipDecimal = ip2long($ip);
    $redeDecimal = ip2long($rede);
    $mascaraDecimal = ~((1 << (32 - $mascara)) - 1);
    
    return ($ipDecimal & $mascaraDecimal) === ($redeDecimal & $mascaraDecimal);
}

function userAgentBloqueado() {
    global $FILTRO_CONFIG;
    
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (empty($userAgent)) {
        return false;
    }
    
    $userAgentLower = strtolower($userAgent);
    
    foreach ($FILTRO_CONFIG['user_agents_bloqueados'] as $bot) {
        if (strpos($userAgentLower, $bot) !== false) {
            return true;
        }
    }
    
    return false;
}

function acessoRecente($cpf) {
    global $FILTRO_CONFIG;
    
    $logDir = '/var/log/pix_acessos/';
    $arquivoHoje = $logDir . 'pix_log_' . date('Y-m-d') . '.log';
    
    if (!file_exists($arquivoHoje)) {
        return false;
    }
    
    $limiteTempo = time() - $FILTRO_CONFIG['intervalo_minimo'];
    $cpfProcurado = trim($cpf);
    
    $handle = fopen($arquivoHoje, 'r');
    if ($handle) {
        while (($linha = fgets($handle)) !== false) {
            if (preg_match('/CPF:\s*([0-9]+)/', $linha, $matches)) {
                $cpfLog = trim($matches[1]);
                
                if ($cpfLog === $cpfProcurado) {
                    if (preg_match('/(\d{4}-\d{2}-\d{2})\s+\|\s+(\d{2}:\d{2}:\d{2})/', $linha, $timeMatch)) {
                        $timestampLog = strtotime($timeMatch[1] . ' ' . $timeMatch[2]);
                        
                        if ($timestampLog >= $limiteTempo) {
                            fclose($handle);
                            return true;
                        }
                    }
                }
            }
        }
        fclose($handle);
    }
    
    return false;
}

function verificarDuplicacao($cpf, $ip) {
    global $FILTRO_CONFIG;
    
    $logDir = '/var/log/pix_acessos/';
    $arquivoHoje = $logDir . 'pix_log_' . date('Y-m-d') . '.log';
    
    if (!file_exists($arquivoHoje)) {
        return false;
    }
    
    $limiteTempo = time() - $FILTRO_CONFIG['intervalo_minimo'];
    $cpfProcurado = trim($cpf);
    $ipProcurado = trim($ip);
    
    $handle = fopen($arquivoHoje, 'r');
    if ($handle) {
        $linhas = file($arquivoHoje, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $linhas = array_reverse($linhas);
        
        foreach ($linhas as $linha) {
            if (preg_match('/CPF:\s*([0-9]+).*IP:\s*([0-9\.]+)/', $linha, $matches)) {
                $cpfLog = trim($matches[1]);
                $ipLog = trim($matches[2]);
                
                if ($cpfLog === $cpfProcurado && $ipLog === $ipProcurado) {
                    if (preg_match('/(\d{4}-\d{2}-\d{2})\s+\|\s+(\d{2}:\d{2}:\d{2})/', $linha, $timeMatch)) {
                        $timestampLog = strtotime($timeMatch[1] . ' ' . $timeMatch[2]);
                        
                        if ($timestampLog >= $limiteTempo) {
                            fclose($handle);
                            return true;
                        }
                    }
                }
            }
        }
        fclose($handle);
    }
    
    return false;
}

/*************************************************
 * FUNÇÕES AUXILIARES
 *************************************************/
function limparDoc($doc) {
    return preg_replace('/\D+/', '', $doc);
}

function getIpCliente() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'desconhecido';
}

function erro($msg) {
    echo "
    <!DOCTYPE html>
    <html lang='pt-br'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>WebLine Telecom - Aviso</title>
        <style>
            *{box-sizing:border-box}
            body{margin:0;padding:0;background:#f2f4f7;font-family:Arial}
            .box{max-width:360px;margin:40px auto;background:#fff;padding:20px;border-radius:10px;text-align:center}
            .msg{font-size:16px;line-height:1.4;margin-bottom:16px}
            a{display:block;width:100%;padding:12px;background:#00b894;color:#fff;
                text-decoration:none;border-radius:6px;font-size:16px;cursor:pointer}
            @media(min-width:768px){.msg{font-size:18px}}
        </style>
    </head>
    <body>
        <div class='box'>
            <div class='msg'>$msg</div>
            <a href='pix.php'>Voltar</a>
        </div>
    </body>
    </html>";
    exit;
}

/*************************************************
 * LOG DE ACESSO COM FILTROS
 *************************************************/
function registrarAcesso($nome, $cpf, $titulo, $vencimento) {
    global $FILTRO_CONFIG;
    
    $logDir = '/var/log/pix_acessos/';
    
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    
    $dataAcesso = date('Y-m-d');
    $hora = date('H:i:s');
    $ip = getIpCliente();
    
    $debugLog = $logDir . 'pix_filtros.log';
    $debugInfo = date('Y-m-d H:i:s') . " | IP: $ip | CPF: $cpf | User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A');
    
    if (ipEstaBloqueado($ip)) {
        $debugInfo .= " | STATUS: IP BLOQUEADO\n";
        @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
        return false;
    }
    
    if (userAgentBloqueado()) {
        $debugInfo .= " | STATUS: USER-AGENT BLOQUEADO\n";
        @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
        return false;
    }
    
    if (acessoRecente($cpf)) {
        $debugInfo .= " | STATUS: ACESSO RECENTE DO CPF\n";
        @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
        return false;
    }
    
    if (verificarDuplicacao($cpf, $ip)) {
        $debugInfo .= " | STATUS: DUPLICAÇÃO DETECTADA\n";
        @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
        return false;
    }
    
    $arquivoLog = $logDir . "pix_log_$dataAcesso.log";
    $linha = "$dataAcesso | $hora | VENC: $vencimento | IP: $ip | NOME: $nome | CPF: $cpf | TITULO: $titulo\n";
    
    $debugInfo .= " | STATUS: LOG REGISTRADO\n";
    @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
    
    file_put_contents($arquivoLog, $linha, FILE_APPEND);
    
    return true;
}

function contadorHoje() {
    $arquivoLog = "/var/log/pix_acessos/pix_log_" . date('Y-m-d') . ".log";
    
    if (!file_exists($arquivoLog)) {
        return 0;
    }
    
    $linhas = file($arquivoLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return $linhas ? count($linhas) : 0;
}

/*************************************************
 * FORMULÁRIO INICIAL COM MENSAGEM DE AGUARDE
 *************************************************/
if (!isset($_GET['doc'])) {
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>WebLine Telecom - Pagamento via Pix</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:0;background:#f2f4f7;font-family:Arial}
        .box{max-width:360px;margin:30px auto;background:#fff;padding:16px;border-radius:10px}
        .logo{text-align:center;margin-bottom:10px}
        .logo img{max-width:180px;width:100%}
        h2{text-align:center}
        input{width:100%;padding:12px;font-size:16px;border:1px solid #ccc;border-radius:6px}
        button{width:100%;padding:12px;margin-top:12px;font-size:16px;background:#00b894;
            color:#fff;border:none;border-radius:6px;cursor:pointer;transition:all 0.3s}
        button:hover{
            background:#009e82;
        }
        button:disabled{
            background:#95a5a6 !important;
            cursor:not-allowed;
            opacity:0.7;
        }
        .loading-message{
            display:none;
            text-align:center;
            color:#00b894;
            margin-top:10px;
            font-weight:bold;
        }
        small{text-align:center;display:block;margin-top:8px;color:#666}
    </style>
</head>
<body>
    <div class="box">
        <div class="logo"><img src="/logo.jpg" alt="WebLine Telecom"></div>
        <h2>Consultar Fatura</h2>
        <form method="get" id="consultaForm" onsubmit="return desabilitarBotao()">
            <input type="tel" name="doc" id="docInput" placeholder="Digite seu CPF ou CNPJ" pattern="[0-9]*" inputmode="numeric" required>
            <button type="submit" id="submitBtn">Continuar</button>
            <div class="loading-message" id="loadingMsg">⏳ Aguarde um momento...</div>
        </form>
        <small>Digite apenas números</small>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('docInput').focus();
        });

        document.getElementById('docInput').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '');
        });

        function desabilitarBotao() {
            const botao = document.getElementById('submitBtn');
            const mensagem = document.getElementById('loadingMsg');
            const input = document.getElementById('docInput');
            
            const docValue = input.value.replace(/\D/g, '');
            if (docValue.length < 11) {
                alert('Por favor, digite um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.');
                input.focus();
                return false;
            }
            
            botao.disabled = true;
            botao.innerHTML = '⏳ Processando...';
            mensagem.style.display = 'block';
            botao.classList.add('loading');
            
            setTimeout(function() {
                botao.disabled = false;
                botao.innerHTML = 'Continuar';
                mensagem.style.display = 'none';
                botao.classList.remove('loading');
            }, 5000);
            
            return true;
        }

        let formEnviado = false;
        document.getElementById('consultaForm').addEventListener('submit', function(e) {
            if (formEnviado) {
                e.preventDefault();
                return false;
            }
            formEnviado = true;
        });

        window.addEventListener('pageshow', function() {
            formEnviado = false;
        });
    </script>
</body>
</html>
<?php
    exit;
}

/*************************************************
 * NORMALIZA CPF/CNPJ
 *************************************************/
$doc = limparDoc($_GET['doc']);
if (!in_array(strlen($doc), [11,14])) {
    erro("❌ CPF ou CNPJ inválido. Verifique os números digitados.");
}

/*************************************************
 * TOKEN
 *************************************************/
$auth = base64_encode("$CLIENT_ID:$CLIENT_SECRET");
$ch = curl_init($API_BASE);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Basic $auth"]
]);
$token = trim(curl_exec($ch));
curl_close($ch);

if (strlen($token) < 20) {
    erro("⚠️ Não foi possível autenticar no momento.");
}

/*************************************************
 * CONSULTA TÍTULOS
 *************************************************/
$ch = curl_init($API_BASE."titulo/titulos/$doc");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"]
]);
$data = json_decode(curl_exec($ch), true);
curl_close($ch);

/*************************************************
 * TRATAMENTO DE ERROS
 *************************************************/
if (isset($data['mensagem']) && stripos($data['mensagem'], 'não encontrado') !== false) {
    erro("❌ CPF ou CNPJ não localizado. Verifique os números digitados.");
}

if (!isset($data['titulos']) || !is_array($data['titulos'])) {
    erro("⚠️ Não foi possível consultar suas faturas agora.");
}

$titulos = $data['titulos'];
if (empty($titulos)) {
    erro("ℹ️ Nenhuma fatura encontrada.");
}

/*************************************************
 * VERIFICAR SE EXISTEM MÚLTIPLOS NOMES PARA O MESMO CPF
 *************************************************/
$nomesUnicos = [];
foreach ($titulos as $titulo) {
    $nome = trim($titulo['nome'] ?? '');
    if ($nome && !in_array($nome, $nomesUnicos)) {
        $nomesUnicos[] = $nome;
    }
}

if (count($nomesUnicos) > 1) {
    if (isset($_GET['selecionar_nome']) && isset($_GET['nome'])) {
        $nomeSelecionado = urldecode($_GET['nome']);
        
        $titulosFiltrados = [];
        foreach ($titulos as $titulo) {
            if (trim($titulo['nome'] ?? '') === $nomeSelecionado) {
                $titulosFiltrados[] = $titulo;
            }
        }
        
        if (empty($titulosFiltrados)) {
            erro("❌ Nome selecionado não encontrado.");
        }
        
        $titulos = $titulosFiltrados;
    } else {
        echo "
        <!DOCTYPE html>
        <html lang='pt-br'>
        <head>
            <meta charset='UTF-8'>
            <title>WebLine Telecom - Selecione o Cadastro</title>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                *{box-sizing:border-box}
                body{margin:0;padding:0;background:#f2f4f7;font-family:Arial}
                .box{max-width:480px;margin:30px auto;background:#fff;padding:20px;border-radius:10px}
                .logo{text-align:center;margin-bottom:15px}
                .logo img{max-width:200px;width:100%}
                h2{text-align:center;color:#333}
                .info-box{padding:12px;background:#e8f8f5;border-radius:6px;margin-bottom:20px;text-align:center}
                .nome-option{
                    padding:15px;
                    margin:10px 0;
                    background:#f8f9fa;
                    border:2px solid #dee2e6;
                    border-radius:8px;
                    cursor:pointer;
                    transition:all 0.3s;
                    text-align:center;
                }
                .nome-option:hover{
                    background:#e9ecef;
                    border-color:#00b894;
                }
                .nome-option.selected{
                    background:#d1f2eb;
                    border-color:#00b894;
                }
                .nome-option b{
                    color:#00b894;
                    display:block;
                    margin-bottom:5px;
                }
                button{
                    width:100%;
                    padding:15px;
                    margin-top:20px;
                    font-size:16px;
                    background:#00b894;
                    color:#fff;
                    border:none;
                    border-radius:6px;
                    cursor:pointer;
                    font-weight:bold;
                    transition:all 0.3s;
                }
                button:hover:not(:disabled){
                    background:#009e82;
                }
                button:disabled{
                    background:#ccc;
                    cursor:not-allowed;
                    opacity:0.7;
                }
                .contador{
                    position:fixed;
                    bottom:6px;
                    right:8px;
                    font-size:11px;
                    color:#999;
                    opacity:0.6;
                    z-index:9999;
                }
                .loading-selecao{
                    display:none;
                    text-align:center;
                    color:#00b894;
                    margin-top:10px;
                    font-weight:bold;
                }
            </style>
        </head>
        <body>
            <div class='box'>
                <div class='logo'><img src='/logo.jpg' alt='WebLine Telecom'></div>
                
                <div class='info-box'>
                    ⚠️ Foram encontrados <b>" . count($nomesUnicos) . " cadastros</b> com o CPF informado.<br>
                    Selecione abaixo qual cadastro deseja visualizar:
                </div>
                
                <h2>Selecione o seu cadastro</h2>
                
                <form id='formSelecao' method='get' onsubmit='return desabilitarBotaoSelecao()'>
                    <input type='hidden' name='doc' value='$doc'>";
        
        foreach ($nomesUnicos as $index => $nome) {
            $numero = $index + 1;
            echo "
                    <div class='nome-option' onclick='selecionarNome(\"$nome\")' id='option-$numero'>
                        <b>Opção $numero</b>
                        $nome
                    </div>";
        }
        
        echo "
                    <input type='hidden' name='selecionar_nome' value='1'>
                    <input type='hidden' name='nome' id='nomeSelecionado'>
                    <button type='submit' id='btnContinuar' disabled>Continuar com o cadastro selecionado</button>
                    <div class='loading-selecao' id='loadingSelecao'>⏳ Aguarde, carregando informações...</div>
                </form>
            </div>
            
            <div class='contador'>
                Acessos Hoje: " . contadorHoje() . "
            </div>
            
            <script>
                let nomeSelecionado = '';
                let formSelecaoEnviado = false;
                
                function selecionarNome(nome) {
                    nomeSelecionado = nome;
                    document.getElementById('nomeSelecionado').value = nome;
                    
                    document.querySelectorAll('.nome-option').forEach(opt => {
                        opt.classList.remove('selected');
                    });
                    
                    const opcoes = document.querySelectorAll('.nome-option');
                    for (let i = 0; i < opcoes.length; i++) {
                        if (opcoes[i].textContent.includes(nome)) {
                            opcoes[i].classList.add('selected');
                            break;
                        }
                    }
                    
                    document.getElementById('btnContinuar').disabled = false;
                }
                
                function desabilitarBotaoSelecao() {
                    const botao = document.getElementById('btnContinuar');
                    const loading = document.getElementById('loadingSelecao');
                    
                    if (formSelecaoEnviado) {
                        return false;
                    }
                    
                    botao.disabled = true;
                    botao.innerHTML = '⏳ Processando...';
                    loading.style.display = 'block';
                    formSelecaoEnviado = true;
                    
                    setTimeout(function() {
                        botao.disabled = false;
                        botao.innerHTML = 'Continuar com o cadastro selecionado';
                        loading.style.display = 'none';
                        formSelecaoEnviado = false;
                    }, 5000);
                    
                    return true;
                }
                
                document.addEventListener('DOMContentLoaded', function() {
                    const primeiroNome = '" . addslashes($nomesUnicos[0]) . "';
                    selecionarNome(primeiroNome);
                });
                
                window.addEventListener('pageshow', function() {
                    formSelecaoEnviado = false;
                });
            </script>
        </body>
        </html>";
        exit;
    }
}

/*************************************************
 * REGRA DE NEGÓCIO - SELECIONAR TÍTULO CORRETO
 *************************************************/
$vencidos=[];
$abertos=[];
$bloqueado=false;

foreach($titulos as $t){
    if(($t['bloqueado']??'')==='sim') $bloqueado=true;
    if(($t['status']??'')==='vencido') $vencidos[]=$t;
    elseif(($t['status']??'')==='aberto') $abertos[]=$t;
}

usort($vencidos, fn($a,$b)=>strtotime($a['datavenc']) <=> strtotime($b['datavenc']));
usort($abertos, fn($a,$b)=>strtotime($a['datavenc']) <=> strtotime($b['datavenc']));

$fatura = $vencidos[0] ?? $abertos[0] ?? null;

if(!$fatura || empty($fatura['pix'])) {
    erro("ℹ️ Não há faturas disponíveis para pagamento via Pix.");
}

/*************************************************
 * EXTRAR DADOS PARA BOLETO DIRETO
 *************************************************/
$numeroTitulo = $fatura['titulo'] ?? '';
$contratoCliente = $fatura['login'] ?? $fatura['contrato'] ?? '';

$boletoDiretoUrl = '';
if (!empty($numeroTitulo) && !empty($contratoCliente)) {
    $boletoDiretoUrl = $URL_PROV . "/boleto/boleto.hhvm?titulo=" . urlencode($numeroTitulo) . 
                       "&contrato=" . urlencode($contratoCliente);
}

/*************************************************
 * LOG + CONTADOR
 *************************************************/
$nomeCliente = $fatura['nome'] ?? 'DESCONHECIDO';
$vencLog = date('d/m/Y', strtotime($fatura['datavenc']));
registrarAcesso($nomeCliente, $doc, $fatura['titulo'], $vencLog);
$contadorHoje = contadorHoje();

/*************************************************
 * DADOS PARA EXIBIÇÃO
 *************************************************/
$pix = htmlspecialchars($fatura['pix']);
$linhadig = $fatura['linhadig'] ?? '';
$linhadigNum = preg_replace('/\D+/', '', $linhadig);
$valor = number_format($fatura['valor'],2,',','.');
$venc = $vencLog;
$uuid_boleto = $fatura['uuid'] ?? '';

/*************************************************
 * OFUSCAR DADOS EM BASE64
 *************************************************/
$dadosInt = base64_encode(json_encode([
    'c' => $nomeCliente,
    'v' => $valor,
    'd' => $venc,
    'p' => $doc,
    'x' => $pix,
    'l' => $linhadig,
    'n' => $linhadigNum,
    'u' => $uuid_boleto,
    'nt' => $numeroTitulo,
    'ct' => $contratoCliente,
    'bd' => $boletoDiretoUrl
]));

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>WebLine Telecom - Pagamento via Pix</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs/qrcode.min.js"></script>
    <style>
        *{box-sizing:border-box}
        body{margin:0;padding:0;background:#f1f3f6;font-family:Arial}
        .box{max-width:420px;margin:30px auto;background:#fff;padding:20px;border-radius:10px}
        textarea{width:100%;resize:none;overflow:hidden;padding:12px;font-size:15px;
            border:1px solid #ccc;border-radius:6px}
        button{width:100%;padding:12px;margin-top:10px;font-size:16px;
            background:#00b894;color:#fff;border:none;border-radius:6px;cursor:pointer;transition:all 0.3s}
        button:hover{
            background:#009e82;
        }
        button:disabled{
            background:#ccc;
            cursor:not-allowed;
            opacity:0.7;
        }
        .alert{padding:12px;border-radius:6px;margin-bottom:12px;font-size:14px}
        .alert-warning{background:#fff3cd;color:#856404}
        .alert-success{background:#e8f8f5;color:#065f46}
        #qrcode{margin:20px auto;width:220px}
        .contador{
            position:fixed;
            bottom:6px;
            right:8px;
            font-size:11px;
            color:#999;
            opacity:0.6;
            z-index:9999;
        }
        .btn-boleto {
            background: #3498db !important;
            margin-top: 15px;
            cursor: pointer;
        }
        .btn-boleto:hover {
            background: #2980b9 !important;
        }
        .btn-boleto:disabled {
            background: #95a5a6 !important;
        }
        .info-boleto {
            margin-top: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            font-size: 14px;
            color: #495057;
            text-align: center;
        }
        #boletoStatus {
            margin-top: 10px;
            padding: 8px;
            background: #fff;
            border-radius: 4px;
            border: 1px solid #e9ecef;
            font-size: 12px;
            min-height: 40px;
        }
        .btn-voltar {
            background: #95a5a6 !important;
            margin-top: 10px;
        }
        .btn-voltar:hover {
            background: #7f8c8d !important;
        }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">Pagamento via Pix</h2>
        
        <?php if(count($nomesUnicos) > 1): ?>
        <div class="alert alert-success" style="text-align:center;">
            📋 <b>Cadastro selecionado:</b> <?= htmlspecialchars($nomeCliente) ?>
            <br><small>CPF: <?= $doc ?></small>
            <button class="btn-voltar" onclick="desabilitarEIrParaSelecao()">
                🔄 Ver outros cadastros
            </button>
        </div>
        <?php endif; ?>
        
        <?php if($bloqueado): ?>
        <div class="alert alert-warning">
            ⚠️ Seu acesso está bloqueado por fatura(s) em atraso.
        </div>
        <?php endif; ?>
        
        <?php if($bloqueado && count($vencidos)>1): ?>
        <div class="alert alert-success">
            💡 Pagando via Pix, o desbloqueio ocorre em até <b>10 minutos</b>.
        </div>
        <?php endif; ?>
        
        <p><b>Cliente:</b> <span id="clienteNome">Carregando...</span></p>
        <p><b>Valor:</b> <span id="valorFatura">Carregando...</span></p>
        <p><b>Vencimento:</b> <span id="vencimentoFatura">Carregando...</span></p>
        
        <div class="info-boleto">
            <p style="margin: 0 0 10px 0;"><b>Boleto Bancário</b></p>
            <p style="margin: 0 0 15px 0; font-size: 13px;">
                Abra o boleto para visualização ou impressão.
            </p>
            <button class="btn-boleto" onclick="abrirBoletoDireto()" id="btnBoleto">
                Abrir Boleto
            </button>
            <div id="boletoStatus"></div>
        </div>
        
        <div id="qrcode"></div>
        
        <small>Código PIX:</small>
        <textarea id="pix" readonly>Carregando código Pix...</textarea>
        <button onclick="copiar('pix')" id="btnCopiarPix">Copiar Código Pix</button>
        
        <?php if($linhadig): ?>
        <small>Código de barras:</small>
        <textarea id="linha" readonly>Carregando código de barras...</textarea>
        <button onclick="copiarNumeros()" id="btnCopiarBarras">Copiar Código de Barras</button>
        <?php endif; ?>
    </div>
    
    <div class="contador">
        Acessos Hoje: <?= $contadorHoje ?>
    </div>
    
    <input type="hidden" id="dadosInt" value="<?= $dadosInt ?>">
    
    <script>
        function decodificarDados() {
            try {
                const dadosCodificados = document.getElementById('dadosInt').value;
                const dadosJson = atob(dadosCodificados);
                const dados = JSON.parse(dadosJson);
                return {
                    cliente: dados.c,
                    valor: "R$ " + dados.v,
                    vencimento: dados.d,
                    cpf: dados.p,
                    pix: dados.x,
                    linhaDigitavel: dados.l,
                    linhaNumeros: dados.n,
                    uuidBoleto: dados.u,
                    numeroTitulo: dados.nt,
                    contratoCliente: dados.ct,
                    boletoDiretoUrl: dados.bd
                };
            } catch (error) {
                console.error('Erro ao decodificar dados:', error);
                return null;
            }
        }
        
        function preencherDados() {
            const dadosPagamento = decodificarDados();
            if (!dadosPagamento) {
                document.getElementById('clienteNome').textContent = 'Erro ao carregar';
                document.getElementById('valorFatura').textContent = 'Erro ao carregar';
                document.getElementById('vencimentoFatura').textContent = 'Erro ao carregar';
                return;
            }
            
            document.getElementById('clienteNome').textContent = dadosPagamento.cliente;
            document.getElementById('valorFatura').textContent = dadosPagamento.valor;
            document.getElementById('vencimentoFatura').textContent = dadosPagamento.vencimento;
            document.getElementById('pix').value = dadosPagamento.pix;
            
            if (dadosPagamento.linhaDigitavel && document.getElementById('linha')) {
                document.getElementById('linha').value = dadosPagamento.linhaDigitavel;
            }
            
            if (!dadosPagamento.boletoDiretoUrl && dadosPagamento.numeroTitulo && dadosPagamento.contratoCliente) {
                dadosPagamento.boletoDiretoUrl = '<?php echo $URL_PROV; ?>/boleto/boleto.hhvm?titulo=' + 
                                   encodeURIComponent(dadosPagamento.numeroTitulo) + 
                                   '&contrato=' + encodeURIComponent(dadosPagamento.contratoCliente);
            }
            
            document.querySelectorAll('textarea').forEach(el => {
                el.style.height = 'auto';
                el.style.height = el.scrollHeight + 'px';
            });
            
            if (document.getElementById('qrcode')) {
                new QRCode(document.getElementById("qrcode"), {
                    text: dadosPagamento.pix,
                    width: 220,
                    height: 220
                });
            }
            
            return dadosPagamento;
        }
        
        function abrirBoletoDireto() {
            const dadosPagamento = decodificarDados();
            if (!dadosPagamento) {
                alert('Erro ao carregar dados do boleto');
                return;
            }
            
            const statusDiv = document.getElementById('boletoStatus');
            const btnBoleto = document.getElementById('btnBoleto');
            
            let boletoUrl = dadosPagamento.boletoDiretoUrl;
            
            if (!boletoUrl && dadosPagamento.numeroTitulo && dadosPagamento.contratoCliente) {
                boletoUrl = '<?php echo $URL_PROV; ?>/boleto/boleto.hhvm?titulo=' + 
                           encodeURIComponent(dadosPagamento.numeroTitulo) + 
                           '&contrato=' + encodeURIComponent(dadosPagamento.contratoCliente);
            }
            
            if (!boletoUrl) {
                statusDiv.innerHTML = '❌ Erro: Não foi possível gerar link do boleto';
                return;
            }
            
            btnBoleto.disabled = true;
            btnBoleto.innerHTML = '⏳ Abrindo...';
            
            if (statusDiv) {
                statusDiv.innerHTML = '🔄 Abrindo boleto...';
            }
            
            const novaAba = window.open(boletoUrl, '_blank');
            
            if (!novaAba || novaAba.closed) {
                if (statusDiv) {
                    statusDiv.innerHTML = '⚠️ Navegador bloqueou a nova aba.<br>' +
                        'Clique <a href="' + boletoUrl + '" target="_blank" ' +
                        'style="color:#3498db;text-decoration:underline;font-weight:bold;">' +
                        'AQUI</a> para abrir boleto.';
                } else {
                    window.location.href = boletoUrl;
                }
            } else {
                novaAba.focus();
                if (statusDiv) {
                    statusDiv.innerHTML = '✅ Boleto aberto! Verifique a nova aba.';
                }
            }
            
            setTimeout(() => {
                btnBoleto.innerHTML = 'Abrir Boleto';
                btnBoleto.disabled = false;
                if (statusDiv) {
                    setTimeout(() => {
                        statusDiv.innerHTML = '';
                    }, 2000);
                }
            }, 3000);
        }
        
        function copiar(id){
            const botao = document.getElementById('btnCopiarPix');
            const textoOriginal = botao.innerHTML;
            
            navigator.clipboard.writeText(document.getElementById(id).value)
                .then(() => {
                    botao.innerHTML = '✓ Copiado!';
                    botao.style.background = '#27ae60';
                    
                    setTimeout(() => {
                        botao.innerHTML = textoOriginal;
                        botao.style.background = '';
                    }, 2000);
                })
                .catch(err => {
                    alert("Erro ao copiar: " + err);
                });
        }
        
        function copiarNumeros(){
            const botao = document.getElementById('btnCopiarBarras');
            const textoOriginal = botao.innerHTML;
            const dadosPagamento = decodificarDados();
            
            if (dadosPagamento && dadosPagamento.linhaNumeros) {
                navigator.clipboard.writeText(dadosPagamento.linhaNumeros)
                    .then(() => {
                        botao.innerHTML = '✓ Copiado!';
                        botao.style.background = '#27ae60';
                        
                        setTimeout(() => {
                            botao.innerHTML = textoOriginal;
                            botao.style.background = '';
                        }, 2000);
                    })
                    .catch(err => {
                        alert("Erro ao copiar código de barras: " + err);
                    });
            } else {
                alert("Erro ao copiar código de barras");
            }
        }
        
        function desabilitarEIrParaSelecao() {
            const botao = document.querySelector('.btn-voltar');
            botao.disabled = true;
            botao.innerHTML = '⏳ Carregando...';
            
            setTimeout(() => {
                botao.disabled = false;
                botao.innerHTML = '🔄 Ver outros cadastros';
            }, 2000);
            
            window.location.href = '?doc=<?= $doc ?>';
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', preencherDados);
        } else {
            preencherDados();
        }
    </script>
</body>
</html>
