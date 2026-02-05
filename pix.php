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
        // Adicione outros IPs de bots aqui
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
// Verificar se IP está na lista de bloqueados
function ipEstaBloqueado($ip) {
    global $FILTRO_CONFIG;
    
    // Se não tem IP, não bloqueia
    if (empty($ip) || $ip === 'desconhecido') {
        return false;
    }
    
    foreach ($FILTRO_CONFIG['ips_bloqueados'] as $ipBloqueado) {
        if (strpos($ipBloqueado, '/') !== false) {
            // É uma faixa CIDR
            if (ipNaFaixaCidr($ip, $ipBloqueado)) {
                return true;
            }
        } else {
            // É um IP específico
            if ($ip === $ipBloqueado) {
                return true;
            }
        }
    }
    
    return false;
}

// Verificar se IP está dentro de uma faixa CIDR
function ipNaFaixaCidr($ip, $cidr) {
    list($rede, $mascara) = explode('/', $cidr);
    
    $ipDecimal = ip2long($ip);
    $redeDecimal = ip2long($rede);
    $mascaraDecimal = ~((1 << (32 - $mascara)) - 1);
    
    return ($ipDecimal & $mascaraDecimal) === ($redeDecimal & $mascaraDecimal);
}

// Verificar User-Agent bloqueado
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

// Verificar se é um acesso recente do mesmo CPF
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
            // Extrair CPF da linha de log
            if (preg_match('/CPF:\s*([0-9]+)/', $linha, $matches)) {
                $cpfLog = trim($matches[1]);
                
                if ($cpfLog === $cpfProcurado) {
                    // Extrair timestamp da linha
                    if (preg_match('/(\d{4}-\d{2}-\d{2})\s+\|\s+(\d{2}:\d{2}:\d{2})/', $linha, $timeMatch)) {
                        $timestampLog = strtotime($timeMatch[1] . ' ' . $timeMatch[2]);
                        
                        // Se encontrou um acesso recente dentro do intervalo mínimo
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

// Verificar se é refresh/duplicação (mesmo IP + CPF em pouco tempo)
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
        $linhas = file($arquivoHoje, FILE_IGNORE_NEW_LINES);
        // Ler de trás para frente (acessos mais recentes primeiro)
        $linhas = array_reverse($linhas);
        
        foreach ($linhas as $linha) {
            // Extrair CPF e IP da linha
            if (preg_match('/CPF:\s*([0-9]+).*IP:\s*([0-9\.]+)/', $linha, $matches)) {
                $cpfLog = trim($matches[1]);
                $ipLog = trim($matches[2]);
                
                if ($cpfLog === $cpfProcurado && $ipLog === $ipProcurado) {
                    // Extrair timestamp
                    if (preg_match('/(\d{4}-\d{2}-\d{2})\s+\|\s+(\d{2}:\d{2}:\d{2})/', $linha, $timeMatch)) {
                        $timestampLog = strtotime($timeMatch[1] . ' ' . $timeMatch[2]);
                        
                        // Se encontrou acesso do mesmo IP+CPF dentro do intervalo
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
        // Validar IP
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
    
    // Garantir que o diretório existe
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    
    $dataAcesso = date('Y-m-d');
    $hora = date('H:i:s');
    $ip = getIpCliente();
    
    // DEBUG: Log para monitoramento dos filtros
    $debugLog = $logDir . 'pix_filtros.log';
    $debugInfo = date('Y-m-d H:i:s') . " | IP: $ip | CPF: $cpf | User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A');
    
    // 1. VERIFICAR IP BLOQUEADO
    if (ipEstaBloqueado($ip)) {
        $debugInfo .= " | STATUS: IP BLOQUEADO\n";
        @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
        return false; // Não registra log
    }
    
    // 2. VERIFICAR USER-AGENT BLOQUEADO
    if (userAgentBloqueado()) {
        $debugInfo .= " | STATUS: USER-AGENT BLOQUEADO\n";
        @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
        return false; // Não registra log
    }
    
    // 3. VERIFICAR ACESSO RECENTE DO MESMO CPF
    if (acessoRecente($cpf)) {
        $debugInfo .= " | STATUS: ACESSO RECENTE DO CPF\n";
        @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
        return false; // Não registra log (já tem acesso recente)
    }
    
    // 4. VERIFICAR DUPLICAÇÃO (MESMO IP + CPF)
    if (verificarDuplicacao($cpf, $ip)) {
        $debugInfo .= " | STATUS: DUPLICAÇÃO DETECTADA\n";
        @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
        return false; // Não registra log (duplicação detectada)
    }
    
    // 5. SE PASSOU POR TODOS OS FILTROS, REGISTRAR LOG
    $arquivoLog = $logDir . "pix_log_$dataAcesso.log";
    $linha = "$dataAcesso | $hora | VENC: $vencimento | IP: $ip | NOME: $nome | CPF: $cpf | TITULO: $titulo\n";
    
    // Log de sucesso no filtro
    $debugInfo .= " | STATUS: LOG REGISTRADO\n";
    @file_put_contents($debugLog, $debugInfo, FILE_APPEND);
    
    // Registrar no log principal
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
        // Foca automaticamente no campo de CPF/CNPJ
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('docInput').focus();
        });

        // Validação enquanto digita (apenas números)
        document.getElementById('docInput').addEventListener('input', function(e) {
            this.value = this.value.replace(/\D/g, '');
        });

        // Função para desabilitar o botão e mostrar mensagem
        function desabilitarBotao() {
            const botao = document.getElementById('submitBtn');
            const mensagem = document.getElementById('loadingMsg');
            const input = document.getElementById('docInput');
            
            // Validação básica do CPF/CNPJ
            const docValue = input.value.replace(/\D/g, '');
            if (docValue.length < 11) {
                alert('Por favor, digite um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.');
                input.focus();
                return false;
            }
            
            // Desabilita o botão
            botao.disabled = true;
            botao.innerHTML = '⏳ Processando...';
            
            // Mostra mensagem de aguarde
            mensagem.style.display = 'block';
            
            // Adiciona classe de loading
            botao.classList.add('loading');
            
            // Impede múltiplos cliques
            setTimeout(function() {
                botao.disabled = false;
                botao.innerHTML = 'Continuar';
                mensagem.style.display = 'none';
                botao.classList.remove('loading');
            }, 5000); // Timeout de segurança (5 segundos)
            
            return true; // Permite o envio do formulário
        }

        // Previne envio duplo por Enter
        let formEnviado = false;
        document.getElementById('consultaForm').addEventListener('submit', function(e) {
            if (formEnviado) {
                e.preventDefault();
                return false;
            }
            formEnviado = true;
        });

        // Permite reenvio se houver erro (quando a página recarregar)
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

// Se houver mais de um nome diferente, mostrar tela de seleção
if (count($nomesUnicos) > 1) {
    // Verificar se já foi selecionado um nome específico
    if (isset($_GET['selecionar_nome']) && isset($_GET['nome'])) {
        $nomeSelecionado = urldecode($_GET['nome']);
        
        // Filtrar títulos apenas do nome selecionado
        $titulosFiltrados = [];
        foreach ($titulos as $titulo) {
            if (trim($titulo['nome'] ?? '') === $nomeSelecionado) {
                $titulosFiltrados[] = $titulo;
            }
        }
        
        if (empty($titulosFiltrados)) {
            erro("❌ Nome selecionado não encontrado.");
        }
        
        // Substituir o array de títulos pelos filtrados
        $titulos = $titulosFiltrados;
    } else {
        // Mostrar tela de seleção de nome
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
                    
                    // Remover classe selected de todas as opções
                    document.querySelectorAll('.nome-option').forEach(opt => {
                        opt.classList.remove('selected');
                    });
                    
                    // Adicionar classe selected à opção clicada
                    const opcoes = document.querySelectorAll('.nome-option');
                    for (let i = 0; i < opcoes.length; i++) {
                        if (opcoes[i].textContent.includes(nome)) {
                            opcoes[i].classList.add('selected');
                            break;
                        }
                    }
                    
                    // Habilitar botão
                    document.getElementById('btnContinuar').disabled = false;
                }
                
                // Função para desabilitar botão na seleção
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
                    
                    // Timeout de segurança
                    setTimeout(function() {
                        botao.disabled = false;
                        botao.innerHTML = 'Continuar com o cadastro selecionado';
                        loading.style.display = 'none';
                        formSelecaoEnviado = false;
                    }, 5000);
                    
                    return true;
                }
                
                // Selecionar a primeira opção por padrão
                document.addEventListener('DOMContentLoaded', function() {
                    const primeiroNome = '" . addslashes($nomesUnicos[0]) . "';
                    selecionarNome(primeiroNome);
                });
                
                // Permite reenvio se houver erro
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
 * REGRA DE NEGÓCIO (INALTERADA)
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
 * LOG + CONTADOR (CONSULTA OK)
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
    'u' => $uuid_boleto
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
        /* Estilo para o botão de boleto - mantendo o mesmo design */
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
        /* Estilo para botão de voltar à seleção */
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
        
        <!-- DADOS SERÃO PREENCHIDOS VIA JAVASCRIPT -->
        <p><b>Cliente:</b> <span id="clienteNome">Carregando...</span></p>
        <p><b>Valor:</b> <span id="valorFatura">Carregando...</span></p>
        <p><b>Vencimento:</b> <span id="vencimentoFatura">Carregando...</span></p>
        
        <!-- BOTÃO PARA ABRIR BOLETO COM AUTENTICAÇÃO AUTOMÁTICA -->
        <div class="info-boleto">
            <p style="margin: 0 0 10px 0;"><b>Boleto Bancário</b></p>
            <p style="margin: 0 0 15px 0; font-size: 13px;">
                Abra o boleto para visualização ou impressão.
            </p>
            <!-- Formulário será preenchido via JavaScript -->
            <form id="formBoleto" action="<?php echo $URL_PROV; ?>/central/executar_login.php" method="POST" target="_blank" style="display: none;">
                <input type="hidden" name="txt_cpf" id="cpfCliente">
                <input type="hidden" name="ttoken_central" id="csrf_token">
            </form>
            <button class="btn-boleto" onclick="abrirBoletoComLogin()" id="btnBoleto">
                Abrir Boleto
            </button>
            <div id="boletoStatus"></div>
        </div>
        
        <div id="qrcode"></div>
        
        <!-- Campos serão preenchidos via JavaScript -->
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
        // Função para decodificar dados
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
                    uuidBoleto: dados.u
                };
            } catch (error) {
                console.error('Erro ao decodificar dados:', error);
                return null;
            }
        }
        
        // Função para preencher os dados na página
        function preencherDados() {
            const dadosPagamento = decodificarDados();
            if (!dadosPagamento) {
                document.getElementById('clienteNome').textContent = 'Erro ao carregar';
                document.getElementById('valorFatura').textContent = 'Erro ao carregar';
                document.getElementById('vencimentoFatura').textContent = 'Erro ao carregar';
                return;
            }
            
            // Preenche os elementos HTML
            document.getElementById('clienteNome').textContent = dadosPagamento.cliente;
            document.getElementById('valorFatura').textContent = dadosPagamento.valor;
            document.getElementById('vencimentoFatura').textContent = dadosPagamento.vencimento;
            document.getElementById('cpfCliente').value = dadosPagamento.cpf;
            document.getElementById('pix').value = dadosPagamento.pix;
            
            // Preenche linha digitável se existir
            if (dadosPagamento.linhaDigitavel && document.getElementById('linha')) {
                document.getElementById('linha').value = dadosPagamento.linhaDigitavel;
            }
            
            // Ajusta altura dos textareas
            document.querySelectorAll('textarea').forEach(el => {
                el.style.height = 'auto';
                el.style.height = el.scrollHeight + 'px';
            });
            
            // Gera QR Code
            if (document.getElementById('qrcode')) {
                new QRCode(document.getElementById("qrcode"), {
                    text: dadosPagamento.pix,
                    width: 220,
                    height: 220
                });
            }
            
            // Retorna dados para uso em outras funções
            return dadosPagamento;
        }
        
        // Função para abrir boleto com login automático
        async function abrirBoletoComLogin() {
            const dadosPagamento = decodificarDados();
            if (!dadosPagamento) {
                alert('Erro ao carregar dados do boleto');
                return;
            }
            
            const statusDiv = document.getElementById('boletoStatus');
            const btnBoleto = document.getElementById('btnBoleto');
            const boletoUrl = '<?php echo $URL_PROV; ?>/central/prepara_boleto.php?titulo=' + dadosPagamento.uuidBoleto;
            
            // Desabilita botão para evitar múltiplos cliques
            btnBoleto.disabled = true;
            btnBoleto.innerHTML = '⏳ Processando...';
            
            try {
                statusDiv.innerHTML = '🔄 Iniciando processamento...';
                
                // 1. Primeiro obtém o token CSRF
                statusDiv.innerHTML = '🔄 Obtendo token de segurança...';
                const response = await fetch('<?php echo $URL_PROV; ?>/central/login.hhvm');
                const html = await response.text();
                
                // 2. Extrai o token CSRF
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const tokenInput = doc.querySelector('input[name="ttoken_central"]');
                if (!tokenInput || !tokenInput.value) {
                    throw new Error('Não foi possível obter token de segurança');
                }
                const csrfToken = tokenInput.value;
                
                // 3. Preenche o formulário
                document.getElementById('csrf_token').value = csrfToken;
                
                // 4. Faz login via fetch (SEM abrir janela)
                statusDiv.innerHTML = '🔄 Fazendo login automático...';
                const form = document.getElementById('formBoleto');
                
                // Cria um iframe invisível MUITO pequeno
                const iframe = document.createElement('iframe');
                iframe.name = 'loginFrame';
                iframe.style.width = '1px';
                iframe.style.height = '1px';
                iframe.style.position = 'absolute';
                iframe.style.left = '-1000px';
                iframe.style.top = '-1000px';
                iframe.style.border = 'none';
                document.body.appendChild(iframe);
                
                // Envia formulário para o iframe
                form.target = 'loginFrame';
                form.submit();
                
                // 5. Aguarda login processar
                statusDiv.innerHTML = '⏳ Processando login...';
                await new Promise(resolve => setTimeout(resolve, 2500));
                
                // 6. PRÉ-CARREGA o boleto (sem abrir janela)
                statusDiv.innerHTML = '⏳ Pré-carregando boleto...';
                // Usa fetch para pré-carregar (acordar o gerador)
                try {
                    await fetch(boletoUrl, {
                        method: 'GET',
                        mode: 'no-cors',
                        credentials: 'include'
                    });
                } catch (e) {
                    console.log('Pré-carregamento feito (erro CORS ignorado)');
                }
                
                // Aguarda 1.5 segundos para o gerador processar
                await new Promise(resolve => setTimeout(resolve, 1500));
                
                // 7. Agora abre o boleto para o usuário
                statusDiv.innerHTML = '✅ Abrindo boleto...';
                
                // Abre em nova aba
                const novaAba = window.open(boletoUrl, '_blank');
                
                if (!novaAba || novaAba.closed) {
                    // Se bloqueado, mostra opção manual
                    statusDiv.innerHTML = '⚠️ Navegador bloqueou a nova aba.<br>' +
                        'Clique <a href="' + boletoUrl + '" target="_blank" ' +
                        'style="color:#3498db;text-decoration:underline;font-weight:bold;">' +
                        'AQUI</a> para abrir manualmente.';
                    btnBoleto.innerHTML = 'Abrir Boleto';
                    btnBoleto.disabled = false;
                } else {
                    // Foco na nova aba
                    novaAba.focus();
                    
                    // Aguarda um pouco e verifica
                    setTimeout(() => {
                        try {
                            if (!novaAba.closed) {
                                statusDiv.innerHTML = '✅ Boleto aberto com sucesso!<br>' +
                                    'Verifique a nova aba do seu navegador.';
                            }
                        } catch (e) {
                            statusDiv.innerHTML = '✅ Processo concluído!';
                        }
                        
                        btnBoleto.innerHTML = 'Abrir Boleto';
                        btnBoleto.disabled = false;
                        
                        // Remove iframe após alguns segundos
                        setTimeout(() => {
                            if (iframe.parentNode) {
                                iframe.parentNode.removeChild(iframe);
                            }
                        }, 3000);
                    }, 1000);
                }
                
            } catch (error) {
                console.error('Erro:', error);
                statusDiv.innerHTML = '❌ ' + error.message + '<br>Clique <a href="' + boletoUrl + '" target="_blank" ' +
                    'style="color:#3498db;text-decoration:underline;font-weight:bold;">' +
                    'AQUI</a> para abrir manualmente.';
                btnBoleto.innerHTML = 'Abrir Boleto';
                btnBoleto.disabled = false;
            }
        }
        
        // Funções de cópia com feedback visual
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
        
        // Função para desabilitar botão ao voltar para seleção
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
        
        // Inicializa a página quando carregada
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', preencherDados);
        } else {
            preencherDados();
        }
    </script>
</body>
</html>
