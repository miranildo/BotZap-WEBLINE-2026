<?php
require __DIR__ . '/auth.php';

$configPath = '/opt/whatsapp-bot/config.json';
$statusPath = '/opt/whatsapp-bot/status.json';
$pixPath = '/var/www/botzap/pix.php';
$logPath = '/var/log/botzap.log';

$mensagem = '';
$erro = '';
$abaAtiva = isset($_GET['aba']) ? $_GET['aba'] : 'config';

$config = [
    'empresa' => '',
    'menu' => '',
    'boleto_url' => '',
    'atendente_numero' => '',
    'tempo_atendimento_humano' => 30,
    'tempo_inatividade_global' => 30,
    'feriados_ativos' => 'Sim',
    // NOVOS CAMPOS: Feriado Local
    'feriado_local_ativado' => 'Não',
    'feriado_local_mensagem' => "📅 *Comunicado importante:*\nHoje é feriado local e não estamos funcionando.\nRetornaremos amanhã em horário comercial.\n\nO acesso a faturas PIX continua disponível 24/7! 😊",
    // NOVOS CAMPOS: Telegram
    'telegram_ativado' => 'Não',
    'telegram_token' => '',
    'telegram_chat_id' => '',
    'telegram_notificar_conexao' => 'Sim',
    'telegram_notificar_desconexao' => 'Sim',
    'telegram_notificar_qr' => 'Sim',
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_config'])) {
    $config['empresa'] = trim($_POST['empresa'] ?? '');
    $config['menu'] = trim($_POST['menu'] ?? '');
    $config['boleto_url'] = trim($_POST['boleto_url'] ?? '');
    $config['atendente_numero'] = trim($_POST['atendente_numero'] ?? '');
    $config['tempo_atendimento_humano'] = intval($_POST['tempo_atendimento_humano'] ?? 30);
    $config['tempo_inatividade_global'] = intval($_POST['tempo_inatividade_global'] ?? 30);
    $config['feriados_ativos'] = trim($_POST['feriados_ativos'] ?? 'Sim');
    
    // 🔥 NOVOS CAMPOS: Feriado Local
    $config['feriado_local_ativado'] = trim($_POST['feriado_local_ativado'] ?? 'Não');
    $config['feriado_local_mensagem'] = trim($_POST['feriado_local_mensagem'] ?? '');
    
    // 🔥 NOVOS CAMPOS: Telegram
    $config['telegram_ativado'] = trim($_POST['telegram_ativado'] ?? 'Não');
    $config['telegram_token'] = trim($_POST['telegram_token'] ?? '');
    $config['telegram_chat_id'] = trim($_POST['telegram_chat_id'] ?? '');
    $config['telegram_notificar_conexao'] = trim($_POST['telegram_notificar_conexao'] ?? 'Sim');
    $config['telegram_notificar_desconexao'] = trim($_POST['telegram_notificar_desconexao'] ?? 'Sim');
    $config['telegram_notificar_qr'] = trim($_POST['telegram_notificar_qr'] ?? 'Sim');
    
    // Novos campos MK-Auth
    $config['mkauth_url'] = trim($_POST['mkauth_url'] ?? '');
    $config['mkauth_client_id'] = trim($_POST['mkauth_client_id'] ?? '');
    $config['mkauth_client_secret'] = trim($_POST['mkauth_client_secret'] ?? '');

    // Validações
    if ($config['tempo_atendimento_humano'] <= 0) {
        $config['tempo_atendimento_humano'] = 30;
    }
    
    if ($config['tempo_inatividade_global'] <= 0) {
        $config['tempo_inatividade_global'] = 30;
    }

    // Validar valor do feriado nacional
    $config['feriados_ativos'] = in_array($config['feriados_ativos'], ['Sim', 'Não']) 
        ? $config['feriados_ativos'] 
        : 'Sim';
    
    // Validar valor do feriado local
    $config['feriado_local_ativado'] = in_array($config['feriado_local_ativado'], ['Sim', 'Não']) 
        ? $config['feriado_local_ativado'] 
        : 'Não';
    
    // Validar valores do Telegram
    $config['telegram_ativado'] = in_array($config['telegram_ativado'], ['Sim', 'Não']) 
        ? $config['telegram_ativado'] 
        : 'Não';
    $config['telegram_notificar_conexao'] = in_array($config['telegram_notificar_conexao'], ['Sim', 'Não']) 
        ? $config['telegram_notificar_conexao'] 
        : 'Sim';
    $config['telegram_notificar_desconexao'] = in_array($config['telegram_notificar_desconexao'], ['Sim', 'Não']) 
        ? $config['telegram_notificar_desconexao'] 
        : 'Sim';
    $config['telegram_notificar_qr'] = in_array($config['telegram_notificar_qr'], ['Sim', 'Não']) 
        ? $config['telegram_notificar_qr'] 
        : 'Sim';
    
    // Se a mensagem do feriado local estiver vazia, usar padrão
    if (empty($config['feriado_local_mensagem'])) {
        $config['feriado_local_mensagem'] = "📅 *Comunicado importante:*\nHoje é feriado local e não estamos funcionando.\nRetornaremos amanhã em horário comercial.\n\nO acesso a faturas PIX continua disponível 24/7! 😊";
    }

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($configPath, $json) !== false) {
        // ============ SINCRONIZAR COM PIX.PHP ============
        if (file_exists($pixPath)) {
            $pixContent = file_get_contents($pixPath);
            
            // Extrair domínio da URL do MK-Auth para $URL_PROV
            $urlProvedor = "https://www.SEU_PROVEDOR.com.br";
            $apiBase = "https://www.SEU_PROVEDOR.com.br/api/";
            
            if (!empty($config['mkauth_url'])) {
                // Extrair o domínio da URL do MK-Auth
                $urlParts = parse_url($config['mkauth_url']);
                if (isset($urlParts['host'])) {
                    $dominio = $urlParts['host'];
                    // Remover www. se existir
                    $dominio = preg_replace('/^www\./', '', $dominio);
                    $urlProvedor = "https://www." . $dominio;
                    $apiBase = rtrim($config['mkauth_url'], '/') . '/';
                }
            }
            
            // Preparar valores para substituição
            $clientId = !empty($config['mkauth_client_id']) ? 
                addslashes($config['mkauth_client_id']) : 'SEU_ID_API';
            
            $clientSecret = !empty($config['mkauth_client_secret']) ? 
                addslashes($config['mkauth_client_secret']) : 'SEU_SECRET_API';
            
            // Substituir variáveis no pix.php
            $pixContent = preg_replace(
                '/\$URL_PROV\s*=\s*"[^"]*"/',
                '$URL_PROV = "' . $urlProvedor . '"',
                $pixContent
            );
            
            $pixContent = preg_replace(
                '/\$API_BASE\s*=\s*"[^"]*"/',
                '$API_BASE = "' . $apiBase . '"',
                $pixContent
            );
            
            $pixContent = preg_replace(
                '/\$CLIENT_ID\s*=\s*"[^"]*"/',
                '$CLIENT_ID = "' . $clientId . '"',
                $pixContent
            );
            
            $pixContent = preg_replace(
                '/\$CLIENT_SECRET\s*=\s*"[^"]*"/',
                '$CLIENT_SECRET = "' . $clientSecret . '"',
                $pixContent
            );
            
            // Salvar alterações no pix.php
            if (file_put_contents($pixPath, $pixContent) !== false) {
                $mensagem = 'Configurações salvas com sucesso e pix.php atualizado!';
            } else {
                $mensagem = 'Configurações salvas, mas erro ao atualizar pix.php!';
            }
        } else {
            $mensagem = 'Configurações salvas, mas pix.php não encontrado!';
        }
        // ============ FIM DA SINCRONIZAÇÃO ============
        
        header('Location: index.php?salvo=1&aba=' . $abaAtiva);
        exit;
    } else {
        $erro = 'Erro ao salvar configurações';
    }
}

// ============================================================================
// INÍCIO - TESTE DE CONEXÃO TELEGRAM
// ============================================================================
if (isset($_GET['testar_telegram'])) {
    header('Content-Type: application/json');
    
    $token = $config['telegram_token'] ?? '';
    $chatId = $config['telegram_chat_id'] ?? '';
    
    if (empty($token) || empty($chatId)) {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Token ou Chat ID não configurados']);
        exit;
    }
    
    $mensagem = "🔔 *TESTE DE CONFIGURAÇÃO*\n\n";
    $mensagem .= "✅ Suas configurações do Telegram estão funcionando!\n";
    $mensagem .= "📱 Bot WhatsApp: " . ($config['empresa'] ?: 'Não configurado') . "\n";
    $mensagem .= "⏰ " . date('d/m/Y H:i:s');
    
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $mensagem,
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        echo json_encode(['sucesso' => true, 'mensagem' => 'Mensagem de teste enviada com sucesso!']);
    } else {
        echo json_encode(['sucesso' => false, 'mensagem' => 'Erro ao enviar mensagem. Verifique o Token e Chat ID.']);
    }
    exit;
}
// ============================================================================
// FIM - TESTE DE CONEXÃO TELEGRAM
// ============================================================================

// ============================================================================
// INÍCIO - API PARA LOG (GET_LOG)
// ============================================================================
if (isset($_GET['get_log'])) {
    header('Content-Type: text/plain');
    
    if (!file_exists($logPath)) {
        echo "Arquivo de log não encontrado: {$logPath}";
        exit;
    }
    
    if (!is_readable($logPath)) {
        echo "Arquivo de log sem permissão de leitura";
        exit;
    }
    
    $ultimoTamanho = isset($_GET['ultimo_tamanho']) ? intval($_GET['ultimo_tamanho']) : 0;
    $linhas = isset($_GET['linhas']) ? intval($_GET['linhas']) : 500;
    $buscar = isset($_GET['buscar']) ? $_GET['buscar'] : '';
    
    if (isset($_GET['tail']) && $ultimoTamanho > 0) {
        $tamanhoAtual = filesize($logPath);
        
        if ($tamanhoAtual < $ultimoTamanho) {
            echo "=== LOG_RESET ===";
            exit;
        }
        
        if ($tamanhoAtual <= $ultimoTamanho) {
            echo "=== NO_UPDATE ===";
            exit;
        }
        
        $handle = fopen($logPath, 'r');
        fseek($handle, $ultimoTamanho);
        $novasLinhas = '';
        while (!feof($handle)) {
            $novasLinhas .= fgets($handle);
        }
        fclose($handle);
        
        echo $novasLinhas;
        exit;
    }
    
    $conteudo = file_get_contents($logPath);
    if ($conteudo === false) {
        echo "Erro ao ler arquivo de log";
        exit;
    }
    
    $linhasArray = explode("\n", $conteudo);
    
    if (!empty($buscar)) {
        $linhasArray = array_filter($linhasArray, function($linha) use ($buscar) {
            return stripos($linha, $buscar) !== false;
        });
        $linhasArray = array_values($linhasArray);
    }
    
    if ($linhas > 0) {
        $linhasArray = array_slice($linhasArray, -$linhas);
    }
    
    $tamanho = filesize($logPath);
    $ultimaModificacao = filemtime($logPath);
    
    echo "=== METADATA:{$tamanho}:{$ultimaModificacao} ===\n";
    echo implode("\n", $linhasArray);
    exit;
}
// ============================================================================
// FIM - API PARA LOG (GET_LOG)
// ============================================================================

// ============================================================================
// INÍCIO - MANIPULAÇÃO DO LOG (LIMPAR/ATUALIZAR)
// ============================================================================
if (isset($_POST['acao_log'])) {
    if ($_POST['acao_log'] === 'limpar' && file_exists($logPath)) {
        if (is_writable($logPath)) {
            if (file_put_contents($logPath, "=== Log reiniciado em " . date('d/m/Y H:i:s') . " ===\n") !== false) {
                $mensagem = 'Log limpo com sucesso!';
            } else {
                $erro = 'Erro ao limpar o arquivo de log!';
            }
        } else {
            $erro = 'Arquivo de log sem permissão de escrita!';
        }
    } elseif ($_POST['acao_log'] === 'atualizar') {
        header('Location: index.php?aba=log');
        exit;
    }
}
// ============================================================================
// FIM - MANIPULAÇÃO DO LOG (LIMPAR/ATUALIZAR)
// ============================================================================

/* ========= MENSAGEM ========= */
if (isset($_GET['salvo']) && $_GET['salvo'] == 1) {
    if (empty($mensagem)) {
        $mensagem = 'Configurações salvas com sucesso!';
    }
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

// ============================================================================
// INÍCIO - FORMATAÇÃO DO TELEFONE PARA EXIBIÇÃO DINÂMICA
// ============================================================================
$telefone_formatado = '(xx)xxxx-xxxx'; // valor padrão

if (!empty($config['atendente_numero'])) {
    // Extrair apenas os dígitos do número
    $numero_limpo = preg_replace('/[^0-9]/', '', $config['atendente_numero']);
    
    // ===== DETECTAR CÓDIGO DO PAÍS =====
    $codigo_pais = '';
    $codigo_pais_numerico = '';
    $numero_sem_pais = $numero_limpo;
    
    // Verificar se tem código do país (2 primeiros dígitos)
    if (strlen($numero_limpo) >= 12) {
        $codigo_pais_numerico = substr($numero_limpo, 0, 2);
        $codigo_pais = '+' . $codigo_pais_numerico;
        $numero_sem_pais = substr($numero_limpo, 2);
    }
    
    // ===== VERIFICAR SE É BRASIL (55) =====
    if ($codigo_pais_numerico == '55') {
        // ===== É BRASIL - APLICAR REGRAS BRASILEIRAS =====
        
        // ===== EXTRAIR DDD =====
        $ddd = '';
        $numero_local = $numero_sem_pais;
        
        if (strlen($numero_sem_pais) >= 10) {
            $ddd = substr($numero_sem_pais, 0, 2);
            $numero_local = substr($numero_sem_pais, 2);
        }
        
        // ===== VERIFICAR PRIMEIRO DÍGITO APÓS DDD =====
        if (!empty($numero_local)) {
            $primeiro_digito = (int) substr($numero_local, 0, 1);
            
            // REGRA BRASIL:
            // Fixo: 2, 3, 4, 5 → NÃO adiciona 9
            // Celular: 6, 7, 8, 9 → adiciona 9 (se já não tiver)
            
            if (in_array($primeiro_digito, [6, 7, 8, 9])) {
                // É CELULAR - garantir que tenha o 9 na frente
                if ($primeiro_digito != 9) {
                    // Se começa com 6,7,8 → adicionar 9 na frente
                    $numero_local = '9' . $numero_local;
                }
                // Se já começa com 9, mantém como está
            }
            // Se for 2,3,4,5 → é FIXO - mantém o número original sem adicionar 9
        }
        
        // ===== FORMATAR (BRASIL) =====
        if (!empty($ddd) && !empty($numero_local)) {
            // Verificar o primeiro dígito final para decidir o formato
            $primeiro_digito_final = (int) substr($numero_local, 0, 1);
            
            if (in_array($primeiro_digito_final, [6, 7, 8, 9])) {
                // FORMATO CELULAR BRASIL
                if (strlen($numero_local) >= 8) {
                    if (strlen($numero_local) == 9) {
                        $parte1 = substr($numero_local, 0, 5);
                        $parte2 = substr($numero_local, 5, 4);
                    } else {
                        $parte1 = substr($numero_local, 0, 4);
                        $parte2 = substr($numero_local, 4, 4);
                    }
                    
                    $telefone_formatado = $codigo_pais . '(' . $ddd . ')' . $parte1 . '-' . $parte2;
                }
            } else {
                // FORMATO FIXO BRASIL
                if (strlen($numero_local) >= 8) {
                    $parte1 = substr($numero_local, 0, 4);
                    $parte2 = substr($numero_local, 4, 4);
                    $telefone_formatado = $codigo_pais . '(' . $ddd . ')' . $parte1 . '-' . $parte2;
                }
            }
        }
    } else {
        // ===== OUTRO PAÍS - MANTER FORMATO ORIGINAL =====
        // Apenas formata de forma simples, sem regras especiais
        if (!empty($codigo_pais)) {
            // Tem código de país, mostra com +
            $telefone_formatado = $codigo_pais . ' ' . $numero_sem_pais;
        } else {
            // Não tem código de país, mostra como veio
            $telefone_formatado = $numero_limpo;
        }
    }
}

// Nome da empresa
$nome_empresa = !empty($config['empresa']) ? $config['empresa'] : 'PROVEDOR';
// ============================================================================
// FIM - FORMATAÇÃO DO TELEFONE PARA EXIBIÇÃO DINÂMICA
// ============================================================================
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Bot WhatsApp – <?= htmlspecialchars($nome_empresa) ?>  <?= htmlspecialchars($telefone_formatado) ?></title>

<style>
/* ============================================================================
   INÍCIO - ESTILOS GLOBAIS (ORIGINAIS)
   ============================================================================ */
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

/* Estilo específico para campo somente leitura */
.atendente-readonly {
    background-color: #f9fafb !important;
    border-color: #d1d5db !important;
    color: #6b7280 !important;
    cursor: not-allowed !important;
}

.atendente-info-box {
    background: #f0f9ff;
    border-left: 3px solid #0ea5e9;
    padding: 10px 14px;
    margin-top: 8px;
    border-radius: 6px;
    font-size: 13px;
    color: #0369a1;
}

.timeout-info {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    padding: 12px 15px;
    margin-top: 10px;
    border-radius: 8px;
    font-size: 14px;
    color: #92400e;
}

.timeout-info p {
    margin: 5px 0;
}

.feriado-local-box {
    background: #fff3e0;
    border-left: 4px solid #f97316;
    padding: 20px;
    margin: 20px 0;
    border-radius: 8px;
}

.feriado-local-box h3 {
    margin-top: 0;
    color: #c2410c;
    display: flex;
    align-items: center;
    gap: 8px;
}
/* ============================================================================
   FIM - ESTILOS GLOBAIS (ORIGINAIS)
   ============================================================================ */

/* ============================================================================
   INÍCIO - ESTILOS DO TERMINAL (APENAS PARA ABA LOG)
   ============================================================================ */
.container.aba-log {
    display: block;
    max-width: 1200px;
}

.terminal-container {
    background: #0C0C0C;
    border-radius: 14px;
    padding: 20px;
    box-shadow: 0 10px 25px rgba(124,252,0,0.1);
    border: 1px solid #2d3748;
    font-family: 'Courier New', monospace;
}

.terminal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #2d3748;
}

.terminal-title {
    color: #7CFC00;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 1px;
    text-shadow: 0 0 5px rgba(124,252,0,0.5);
    font-family: 'Courier New', monospace;
}

.terminal-title span {
    color: #7CFC00;
    margin-right: 10px;
    text-shadow: 0 0 8px rgba(124,252,0,0.8);
}

.terminal-controls {
    display: flex;
    gap: 10px;
}

.terminal-btn {
    background: #1e293b;
    color: #7CFC00;
    border: 1px solid #334155;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    cursor: pointer;
    transition: all 0.2s;
    text-shadow: 0 0 3px rgba(124,252,0,0.3);
}

.terminal-btn:hover {
    background: #334155;
    border-color: #7CFC00;
    color: #ffffff;
    box-shadow: 0 0 8px rgba(124,252,0,0.3);
}

.terminal-btn.warning {
    background: #dc2626;
    border-color: #f59e0b;
    color: #fff;
}

.terminal-search {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
    background: #111827;
    padding: 10px;
    border-radius: 8px;
    border: 1px solid #2d3748;
    flex-wrap: wrap;
}

.terminal-search input {
    flex: 1;
    min-width: 200px;
    background: #1e293b;
    border: 1px solid #334155;
    color: #7CFC00;
    padding: 8px 12px;
    border-radius: 6px;
    font-family: 'Courier New', monospace;
    font-size: 13px;
}

.terminal-search input:focus {
    outline: none;
    border-color: #7CFC00;
    box-shadow: 0 0 8px rgba(124,252,0,0.3);
}

.terminal-search input::placeholder {
    color: #4a5568;
}

.terminal-content {
    background: #0C0C0C;
    border-radius: 8px;
    padding: 20px;
    min-height: 280px;
    max-height: 280px;
    overflow-y: auto;
    font-family: 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.6;
    color: #CCCCCC;
    border: 1px solid #1e293b;
    box-shadow: inset 0 0 20px rgba(0,0,0,0.9);
    scroll-behavior: smooth;
}

.terminal-content .log-line {
    white-space: pre-wrap;
    word-wrap: break-word;
    border-bottom: 1px solid #1a1a1a;
    padding: 2px 0;
    font-family: 'Courier New', monospace;
    transition: background-color 0.2s;
}

.terminal-content .log-line:nth-child(even) {
    background: rgba(124, 252, 0, 0.03);
}

.terminal-content .log-line:hover {
    background: #1e293b;
}

.terminal-content .timestamp {
    color: #7CFC00 !important;
    font-weight: bold;
    text-shadow: 0 0 5px rgba(124,252,0,0.8);
}

.terminal-content .phone {
    color: #5C5CFF !important;
    text-shadow: 0 0 3px rgba(92,92,255,0.5);
}

.terminal-content .success {
    color: #7CFC00 !important;
    font-weight: bold;
    text-shadow: 0 0 5px rgba(124,252,0,0.8);
}

.terminal-content .emoji {
    color: #FFFF00 !important;
    text-shadow: 0 0 5px rgba(255,255,0,0.5);
}

.terminal-content .info {
    color: #00FFFF !important;
    text-shadow: 0 0 3px rgba(0,255,255,0.5);
}

.terminal-content .error {
    color: #FF5F5F !important;
    font-weight: bold;
    text-shadow: 0 0 3px rgba(255,95,95,0.5);
}

.terminal-content::-webkit-scrollbar {
    width: 10px;
}

.terminal-content::-webkit-scrollbar-track {
    background: #0C0C0C;
}

.terminal-content::-webkit-scrollbar-thumb {
    background: #7CFC00;
    border-radius: 5px;
    box-shadow: 0 0 5px #7CFC00;
}

.terminal-content::-webkit-scrollbar-thumb:hover {
    background: #90EE90;
}

.terminal-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 15px;
    color: #7CFC00;
    font-size: 12px;
    font-family: 'Courier New', monospace;
    text-shadow: 0 0 3px rgba(124,252,0,0.5);
    flex-wrap: wrap;
    gap: 10px;
}

.terminal-stats {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.terminal-stats span {
    color: #7CFC00;
    font-weight: bold;
}

.terminal-autorefresh {
    display: flex;
    align-items: center;
    gap: 8px;
}

.terminal-autorefresh input[type="checkbox"] {
    width: auto;
    margin: 0;
    accent-color: #7CFC00;
}

.terminal-loader {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 2px solid #7CFC00;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spin 1s linear infinite;
    margin-left: 5px;
    box-shadow: 0 0 5px #7CFC00;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
/* ============================================================================
   FIM - ESTILOS DO TERMINAL
   ============================================================================ */

/* ============================================================================
   INÍCIO - ABAS DE NAVEGAÇÃO
   ============================================================================ */
.tabs-container {
    max-width: 1100px;
    margin: 0 auto 20px auto;
    padding: 0 20px;
}

.tabs {
    display: flex;
    gap: 5px;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: 0;
}

.tab {
    padding: 12px 24px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-bottom: none;
    border-radius: 8px 8px 0 0;
    cursor: pointer;
    font-weight: 600;
    color: #6b7280;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-block;
    font-family: Inter, Arial, sans-serif;
}

.tab:hover {
    background: #e5e7eb;
    color: #374151;
}

.tab.active {
    background: #ffffff;
    color: #2563eb;
    border-bottom: 2px solid #2563eb;
    margin-bottom: -2px;
}
/* ============================================================================
   FIM - ABAS DE NAVEGAÇÃO
   ============================================================================ */
</style>
</head>

<body>

<header style="display:flex; justify-content:space-between; align-items:center;">
    <span>🤖 Bot WhatsApp - <?= htmlspecialchars($nome_empresa) ?>  <?= htmlspecialchars($telefone_formatado) ?></span>

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

<!-- ============================================================================
     INÍCIO - ABAS DE NAVEGAÇÃO
     ============================================================================ -->
<div class="tabs-container">
    <div class="tabs">
        <a href="?aba=config" class="tab <?= $abaAtiva === 'config' ? 'active' : '' ?>">⚙️ Configurações</a>
        <a href="?aba=log" class="tab <?= $abaAtiva === 'log' ? 'active' : '' ?>">📋 Exibir Log</a>
    </div>
</div>
<!-- ============================================================================
     FIM - ABAS DE NAVEGAÇÃO
     ============================================================================ -->

<?php if ($abaAtiva === 'config'): ?>
<!-- ============================================================================
     INÍCIO - CONFIGURAÇÕES (COM NOVA SEÇÃO TELEGRAM)
     ============================================================================ -->
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
            <input type="hidden" name="salvar_config" value="1">
            
            <label>Empresa</label>
            <input name="empresa" value="<?= htmlspecialchars($config['empresa']) ?>">

            <label>Mensagem do Menu</label>
            <textarea name="menu"><?= htmlspecialchars($config['menu']) ?></textarea>

            <label>URL do Boleto</label>
            <input name="boleto_url" value="<?= htmlspecialchars($config['boleto_url']) ?>">

            <label>Número do Atendente</label>
            <input 
                name="atendente_numero" 
                value="<?= htmlspecialchars($config['atendente_numero']) ?>"
                readonly
                placeholder="Preenchido automaticamente via QR Code"
                class="atendente-readonly"
            >
            <div class="atendente-info-box">
                <p style="margin: 0;">
                    <strong>ℹ️ Informação:</strong> Este número é configurado automaticamente através da leitura do QR Code no WhatsApp e não pode ser editado manualmente.
                </p>
            </div>

            <label>⏱️ Tempo máximo de atendimento humano (minutos)</label>
            <input
                type="number"
                min="5"
                step="5"
                name="tempo_atendimento_humano"
                value="<?= htmlspecialchars($config['tempo_atendimento_humano']) ?>"
            >

            <label>⏱️ Tempo máximo de inatividade global (minutos)</label>
            <input
                type="number"
                min="5"
                step="5"
                name="tempo_inatividade_global"
                value="<?= htmlspecialchars($config['tempo_inatividade_global']) ?>"
            >
            <div class="timeout-info">
                <p><strong>ℹ️ Sobre o tempo de inatividade global:</strong></p>
                <p>• Aplica-se a <strong>TODAS</strong> as etapas do atendimento:</p>
                <p>  - Menu inicial</p>
                <p>  - Aguardando CPF/CNPJ</p>
                <p>  - Após gerar link PIX</p>
                <p>  - Atendimento humano (além do tempo específico)</p>
                <p>• Se o cliente não responder neste período, o atendimento é encerrado automaticamente</p>
                <p>• Ao reiniciar, o cliente volta ao menu inicial</p>
            </div>

            <label>🎯 Considerar feriados nacionais no atendimento?</label>
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
                <p><strong>ℹ️ Informações sobre feriados nacionais:</strong></p>
                <p>• <strong>Sim:</strong> O bot não oferece atendimento humano em feriados nacionais</p>
                <p>• <strong>Não:</strong> Atendimento humano funciona mesmo em feriados</p>
                <p>• PIX continua disponível 24/7 independentemente desta configuração</p>
            </div>

            <!-- 🔥 SEÇÃO: Feriado Local Personalizável -->
            <div class="feriado-local-box">
                <h3>
                    <span>🏮</span> Feriado Local (Personalizável)
                </h3>
                
                <label style="margin-top: 5px;">Ativar feriado local?</label>
                <div class="radio-group" style="margin-bottom: 15px;">
                    <label class="radio-option">
                        <input type="radio" name="feriado_local_ativado" value="Sim" 
                            <?= (isset($config['feriado_local_ativado']) && $config['feriado_local_ativado'] === 'Sim') ? 'checked' : '' ?>>
                        Sim (bloquear atendimento)
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="feriado_local_ativado" value="Não"
                            <?= (!isset($config['feriado_local_ativado']) || $config['feriado_local_ativado'] === 'Não') ? 'checked' : '' ?>>
                        Não (atendimento normal)
                    </label>
                </div>

                <label>Mensagem para feriado local:</label>
                <textarea 
                    name="feriado_local_mensagem" 
                    placeholder="Digite a mensagem que será enviada quando cliente tentar atendimento em feriado local..."
                    style="height: 120px;"
                ><?= htmlspecialchars($config['feriado_local_mensagem'] ?? '📅 *Comunicado importante:*\nHoje é feriado local e não estamos funcionando.\nRetornaremos amanhã em horário comercial.\n\nO acesso a faturas PIX continua disponível 24/7! 😊') ?></textarea>
                
                <div style="background: #fef9e7; padding: 10px; border-radius: 6px; margin-top: 10px; font-size: 13px;">
                    <p style="margin: 0;"><strong>ℹ️ Como funciona:</strong></p>
                    <p style="margin: 5px 0 0 0;">• Quando ativado, o bot NÃO oferecerá atendimento humano</p>
                    <p style="margin: 2px 0 0 0;">• Clientes que tentarem falar com atendente receberão esta mensagem personalizada</p>
                    <p style="margin: 2px 0 0 0;">• PIX continua funcionando normalmente 24/7</p>
                    <p style="margin: 2px 0 0 0;">• Ideal para feriados locais (carnaval, ponto facultativo, etc.)</p>
                </div>
            </div>

            <!-- 🔥 NOVA SEÇÃO: Configurações do Telegram -->
            <div class="config-section" style="border-left-color: #0088cc;">
                <h3>
                    <span>📱</span> Configurações do Telegram
                </h3>
                <p style="margin-top: 0; font-size: 14px; color: #6b7280;">
                    Receba notificações sobre o status do bot diretamente no Telegram
                </p>
                
                <label>🤖 Ativar notificações por Telegram?</label>
                <div class="radio-group" style="margin-bottom: 15px;">
                    <label class="radio-option">
                        <input type="radio" name="telegram_ativado" value="Sim" 
                            <?= (isset($config['telegram_ativado']) && $config['telegram_ativado'] === 'Sim') ? 'checked' : '' ?>>
                        Sim (ativar notificações)
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="telegram_ativado" value="Não"
                            <?= (!isset($config['telegram_ativado']) || $config['telegram_ativado'] === 'Não') ? 'checked' : '' ?>>
                        Não (desativado)
                    </label>
                </div>

                <label>🤖 Token do Bot:</label>
                <input 
                    type="text" 
                    name="telegram_token" 
                    value="<?= htmlspecialchars($config['telegram_token'] ?? '1234567890:AAHZa04CU4s_PJqYxR07ztrQafvaE1Ov_Dk') ?>"
                    placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz"
                >
                <small style="color: #6b7280; font-size: 12px;">
                    Obtenha com <a href="https://t.me/BotFather" target="_blank">@BotFather</a> no Telegram
                </small>

                <label>👤 Chat ID (ou Canal):</label>
                <input 
                    type="text" 
                    name="telegram_chat_id" 
                    value="<?= htmlspecialchars($config['telegram_chat_id'] ?? '-1003032257081') ?>"
                    placeholder="-1001234567890 ou 123456789"
                >
                <small style="color: #6b7280; font-size: 12px;">
                    Obtenha com <a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> no Telegram
                </small>

<div style="margin-top: 15px; background: #f0f9ff; padding: 15px; border-radius: 8px;">
    <label style="margin-top: 0; margin-bottom: 10px; display: block; font-weight: 600;">🔔 Notificações a enviar:</label>
    
    <div style="display: flex; flex-direction: column; gap: 8px;">
        <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
            <input type="checkbox" name="telegram_notificar_conexao" value="Sim" 
                <?= (isset($config['telegram_notificar_conexao']) && $config['telegram_notificar_conexao'] === 'Sim') ? 'checked' : '' ?>
                style="margin: 0; width: 16px; height: 16px;">
            <span>✅ Quando conectar</span>
        </label>
        
        <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
            <input type="checkbox" name="telegram_notificar_desconexao" value="Sim" 
                <?= (isset($config['telegram_notificar_desconexao']) && $config['telegram_notificar_desconexao'] === 'Sim') ? 'checked' : '' ?>
                style="margin: 0; width: 16px; height: 16px;">
            <span>❌ Quando desconectar</span>
        </label>
        
        <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
            <input type="checkbox" name="telegram_notificar_qr" value="Sim" 
                <?= (isset($config['telegram_notificar_qr']) && $config['telegram_notificar_qr'] === 'Sim') ? 'checked' : '' ?>
                style="margin: 0; width: 16px; height: 16px;">
            <span>📱 Quando QR Code for gerado</span>
        </label>
    </div>
</div>
                <button type="button" onclick="testarTelegram()" style="margin-top: 15px; background: #28a745;">
                    📤 Testar Conexão Telegram
                </button>
                <div id="telegramTestResult" style="margin-top: 10px; font-size: 13px;"></div>
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
                    placeholder="c582c8ede2c9169c64f29cxxxxxxxxxx"
                >
                <small style="color: #6b7280; font-size: 12px;">
                    Identificador do cliente para autenticação na API
                </small>

                <label>Client Secret</label>
                <input 
                    name="mkauth_client_secret" 
                    type="password"
                    value="<?= htmlspecialchars($config['mkauth_client_secret']) ?>"
                    placeholder="9d2367fbf45d2e89d8ee8cb92ca3c0xxxxxxxxxx"
                >
                <small style="color: #6b7280; font-size: 12px;">
                    Senha de acesso à API (chave secreta)
                </small>

                <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 12px; margin-top: 15px; border-radius: 6px; font-size: 13px;">
                    <p style="margin: 0; color: #92400e;"><strong>⚠️ Importante:</strong></p>
                    <p style="margin: 5px 0 0 0; color: #92400e;">
                        • As credenciais MK-Auth serão sincronizadas automaticamente com o arquivo pix.php<br>
                        • Se as credenciais não forem configuradas, o bot NÃO permitirá acesso direto ao PIX<br>
                        • Configure corretamente para filtrar apenas clientes da base
                    </p>
                </div>
            </div>

            <button type="submit">Salvar configurações</button>
        </form>
    </div>

</div>
<!-- ============================================================================
     FIM - CONFIGURAÇÕES
     ============================================================================ -->

<?php else: ?>
<!-- ============================================================================
     INÍCIO - ABA DE LOG (COM FONTE COURIER NEW)
     ============================================================================ -->
<div class="tabs-container">
    <div class="terminal-container">
        <div class="terminal-header">
            <div class="terminal-title">
                <span>📋</span> botzap.log @ /var/log/botzap.log
            </div>
            <div class="terminal-controls">
                <button class="terminal-btn warning" onclick="confirmarLimparLog()">🗑️ Limpar Log</button>
                <button class="terminal-btn" onclick="atualizarLog()">🔄 Atualizar</button>
            </div>
        </div>

        <div class="terminal-search">
            <input type="text" id="buscarLog" placeholder="🔍 Buscar no log...">
            <select id="linhasLog" class="terminal-btn" style="width: auto;">
                <option value="100">Últimas 100 linhas</option>
                <option value="250">Últimas 250 linhas</option>
                <option value="500" selected>Últimas 500 linhas</option>
                <option value="1000">Últimas 1000 linhas</option>
                <option value="5000">Últimas 5000 linhas</option>
                <option value="0">Todas as linhas</option>
            </select>
        </div>

        <div id="terminalContent" class="terminal-content">
            <div style="color: #7CFC00; text-align: center; padding: 20px;">
                <span class="terminal-loader"></span> Carregando log...
            </div>
        </div>

        <div class="terminal-footer">
            <div class="terminal-stats">
                <span>Linhas: <span id="linhasCount">0</span></span>
                <span>Tamanho: <span id="tamanhoLog">0 KB</span></span>
                <span>Atualizado: <span id="dataAtualizacao">-</span></span>
            </div>
            <div class="terminal-autorefresh">
                <input type="checkbox" id="autoRefresh" checked>
                <label for="autoRefresh">Auto-atualizar (2s)</label>
            </div>
        </div>
    </div>
</div>
<!-- ============================================================================
     FIM - ABA DE LOG
     ============================================================================ -->
<?php endif; ?>

<script>
// ============================================================================
// INÍCIO - VARIÁVEIS GLOBAIS DO LOG
// ============================================================================
let autoRefreshInterval;
let ultimoTamanhoArquivo = 0;
let buscandoAtivo = false;
let linhasSelecionadas = 500;
let buscaAtiva = '';

const coresTerminal = {
    fundo: '#0C0C0C',
    texto: '#CCCCCC',
    timestamp: '#7CFC00',
    verdeFluor: '#7CFC00',
    verdeClaro: '#90EE90',
    azul: '#5C5CFF',
    amarelo: '#FFFF00',
    laranja: '#FFA500',
    vermelho: '#FF5F5F',
    ciano: '#00FFFF',
    magenta: '#FF77FF',
    cinza: '#888888'
};
// ============================================================================
// FIM - VARIÁVEIS GLOBAIS DO LOG
// ============================================================================

// ============================================================================
// INÍCIO - FUNÇÃO PARA TESTAR TELEGRAM
// ============================================================================
function testarTelegram() {
    const resultDiv = document.getElementById('telegramTestResult');
    resultDiv.innerHTML = '⏳ Enviando mensagem de teste...';
    resultDiv.style.color = '#666';
    
    fetch('?testar_telegram=1&t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.sucesso) {
                resultDiv.innerHTML = '✅ ' + data.mensagem;
                resultDiv.style.color = '#28a745';
            } else {
                resultDiv.innerHTML = '❌ ' + data.mensagem;
                resultDiv.style.color = '#dc3545';
            }
        })
        .catch(error => {
            resultDiv.innerHTML = '❌ Erro ao testar: ' + error.message;
            resultDiv.style.color = '#dc3545';
        });
}

// Garantir que os checkboxes do Telegram funcionem corretamente
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="telegram_notificar_"]');
    checkboxes.forEach(checkbox => {
        if (!checkbox.checked) {
            checkbox.value = 'Não';
        }
        checkbox.addEventListener('change', function() {
            this.value = this.checked ? 'Sim' : 'Não';
        });
    });
});
// ============================================================================
// FIM - FUNÇÃO PARA TESTAR TELEGRAM
// ============================================================================

// ============================================================================
// INÍCIO - FUNÇÃO PARA MANTER TELA ATIVA NO CELULAR
// ============================================================================
let wakeLock = null;

async function ativarWakeLock() {
    if ('wakeLock' in navigator) {
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            console.log('✅ Tela será mantida ativa');
            
            wakeLock.addEventListener('release', () => {
                console.log('📴 Wake lock liberado - tela pode desligar');
            });
        } catch (err) {
            console.error(`❌ Erro ao ativar wake lock: ${err.message}`);
        }
    } else {
        console.log('⚠️ Navegador não suporta manter tela ativa');
    }
}

function desativarWakeLock() {
    if (wakeLock !== null) {
        wakeLock.release()
            .then(() => {
                wakeLock = null;
                console.log('✅ Wake lock desativado');
            });
    }
}
// ============================================================================
// FIM - FUNÇÃO PARA MANTER TELA ATIVA NO CELULAR
// ============================================================================

// ============================================================================
// INÍCIO - FUNÇÃO FORMATAR LOG
// ============================================================================
function formatarLog(conteudo) {
    if (!conteudo || conteudo.includes('Nenhuma entrada')) {
        return '<div style="color: ' + coresTerminal.cinza + '; text-align: center; padding: 20px;">📭 Nenhuma entrada no log</div>';
    }
    
    let linhas = conteudo.split('\n');
    if (linhas[0] && linhas[0].startsWith('=== METADATA:')) {
        linhas = linhas.slice(1);
    }
    
    linhas = linhas.filter(linha => linha.trim() !== '');
    
    return linhas.map(linha => {
        let linhaEscapada = linha
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        
        linhaEscapada = linhaEscapada.replace(
            /\[(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2})\]/g,
            '<span class="timestamp">[$1]</span>'
        );
        
        linhaEscapada = linhaEscapada.replace(
            /\[(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2})\]/g,
            '<span class="timestamp">[$1]</span>'
        );
        
        linhaEscapada = linhaEscapada.replace(
            /(\d{11,13}@s\.whatsapp\.net|\d{10,13})/g,
            '<span class="phone">$1</span>'
        );
        
        linhaEscapada = linhaEscapada
            .replace(/(✅|✔️|sucesso|enviado|válido|encontrado|sucesso\!)/gi, 
                '<span class="success">$1</span>')
            .replace(/(❌|erro|falha|inválido|não encontrado|falhou)/gi, 
                '<span class="error">$1</span>')
            .replace(/(📨|📥|📤|📋|📊|📄|📅)/g, 
                '<span class="emoji">$1</span>')
            .replace(/(🔍|🔢|ℹ️|⚠️|⚡|💠|🔐)/g, 
                '<span class="info">$1</span>')
            .replace(/(✅|✔️)/g, 
                '<span class="success">$1</span>');
        
        linhaEscapada = linhaEscapada
            .replace(/(menu|aguardando_cpf|pos_pix|atendimento_humano)/gi,
                '<span style="color: ' + coresTerminal.magenta + ';">$1</span>')
            .replace(/(PIX|BOLETO|FATURA)/g,
                '<span style="color: ' + coresTerminal.laranja + '; font-weight: bold;">$1</span>');
        
        return `<div class="log-line" style="color: ${coresTerminal.texto};">${linhaEscapada}</div>`;
    }).join('');
}
// ============================================================================
// FIM - FUNÇÃO FORMATAR LOG
// ============================================================================

// ============================================================================
// INÍCIO - FUNÇÃO EXTRAIR METADATA
// ============================================================================
function extrairMetadata(conteudo) {
    const linhas = conteudo.split('\n');
    if (linhas[0] && linhas[0].startsWith('=== METADATA:')) {
        const metadata = linhas[0].match(/=== METADATA:(\d+):(\d+) ===/);
        if (metadata) {
            return {
                tamanho: parseInt(metadata[1]),
                modificacao: parseInt(metadata[2]),
                conteudo: linhas.slice(1).join('\n')
            };
        }
    }
    return {
        tamanho: 0,
        modificacao: 0,
        conteudo: conteudo
    };
}
// ============================================================================
// FIM - FUNÇÃO EXTRAIR METADATA
// ============================================================================

// ============================================================================
// INÍCIO - FUNÇÃO CARREGAR LOG (VERSÃO ESTÁVEL - RECENTES EM BAIXO)
// ============================================================================
function carregarLog(forcarCompleto = false) {
    const terminal = document.getElementById('terminalContent');
    const buscar = document.getElementById('buscarLog')?.value || '';
    const linhas = parseInt(document.getElementById('linhasLog')?.value || 500);
    
    if (!terminal || buscandoAtivo) return;
    
    // Salvar posição do scroll e verificar se está no final
    const scrollPos = terminal.scrollTop;
    const estavaNoFinal = terminal.scrollHeight - terminal.clientHeight <= scrollPos + 50;
    
    if (!forcarCompleto) {
        linhasSelecionadas = linhas;
        buscaAtiva = buscar;
    }
    
    let url = `?get_log=1&linhas=${linhasSelecionadas}`;
    
    // Modo tail: só se não tiver busca e não for forçado
    if (!forcarCompleto && ultimoTamanhoArquivo > 0 && buscaAtiva === '') {
        url += `&tail=1&ultimo_tamanho=${ultimoTamanhoArquivo}`;
    } else if (buscaAtiva !== '') {
        url += `&buscar=${encodeURIComponent(buscaAtiva)}`;
    }
    
    buscandoAtivo = true;
    
    fetch(url + '&t=' + Date.now())
        .then(response => response.text())
        .then(conteudo => {
            buscandoAtivo = false;
            
            // Se não há novas linhas no modo tail, não faz nada
            if (conteudo === '=== NO_UPDATE ===') {
                return;
            }
            
            // Se o log foi resetado/rotacionado
            if (conteudo === '=== LOG_RESET ===' || conteudo.includes('=== METADATA:0:')) {
                ultimoTamanhoArquivo = 0;
                terminal.innerHTML = '';
                carregarLog(true);
                return;
            }
            
            const metadata = extrairMetadata(conteudo);
            const tamanhoAtual = metadata.tamanho;
            const conteudoReal = metadata.conteudo;
            
            // MODO TAIL: adiciona APENAS as linhas novas no final
            if (!forcarCompleto && ultimoTamanhoArquivo > 0 && buscaAtiva === '' && conteudoReal.trim()) {
                const linhasNovas = formatarLog(conteudoReal);
                if (linhasNovas && !linhasNovas.includes('Nenhuma entrada')) {
                    // Verificar se o terminal está vazio
                    if (terminal.innerHTML.includes('Nenhuma entrada') || terminal.innerHTML.trim() === '') {
                        terminal.innerHTML = linhasNovas;
                    } else {
                        terminal.insertAdjacentHTML('beforeend', linhasNovas);
                    }
                    // Só rolar se o usuário já estava no final
                    if (estavaNoFinal) {
                        terminal.scrollTop = terminal.scrollHeight;
                    }
                }
            } 
            // MODO NORMAL OU BUSCA: recarrega tudo
            else {
                if (!conteudoReal.trim() || conteudoReal.includes('Nenhuma entrada')) {
                    terminal.innerHTML = '<div style="color: ' + coresTerminal.cinza + '; text-align: center; padding: 20px;">📭 Nenhuma entrada no log</div>';
                } else {
                    terminal.innerHTML = formatarLog(conteudoReal);
                    // No primeiro carregamento ou busca, vai para o final
                    if (ultimoTamanhoArquivo === 0 || buscaAtiva !== '') {
                        terminal.scrollTop = terminal.scrollHeight;
                    } else {
                        // Tentar manter a posição anterior
                        terminal.scrollTop = scrollPos;
                    }
                }
            }
            
            // Atualizar estatísticas - COM VERIFICAÇÃO DE NULL
            ultimoTamanhoArquivo = tamanhoAtual;
            
            const linhasVisiveis = terminal.querySelectorAll('.log-line').length;
            const linhasCountEl = document.getElementById('linhasCount');
            const tamanhoLogEl = document.getElementById('tamanhoLog');
            const dataAtualizacaoEl = document.getElementById('dataAtualizacao');
            
            if (linhasCountEl) linhasCountEl.textContent = linhasVisiveis;
            if (tamanhoLogEl) tamanhoLogEl.textContent = Math.round(tamanhoAtual / 1024) + ' KB';
            if (dataAtualizacaoEl) dataAtualizacaoEl.textContent = new Date().toLocaleTimeString('pt-BR');
            
            // Atualizar stats com informação de busca se necessário - COM VERIFICAÇÃO
            if (buscaAtiva !== '') {
                const statsDiv = document.querySelector('.terminal-stats');
                if (statsDiv) {
                    statsDiv.innerHTML = `🔍 Busca: "${buscaAtiva}" | Resultados: <span style="color: ${coresTerminal.verdeFluor};">${linhasVisiveis}</span> | Tamanho: <span style="color: ${coresTerminal.verdeFluor};">${Math.round(tamanhoAtual / 1024)} KB</span> | Atualizado: <span>${new Date().toLocaleTimeString('pt-BR')}</span>`;
                }
            } else {
                // Restaurar stats normais apenas se não estiver em modo de busca
                const statsDiv = document.querySelector('.terminal-stats');
                if (statsDiv && !buscaAtiva) {
                    statsDiv.innerHTML = `<span>Linhas: <span id="linhasCount">${linhasVisiveis}</span></span> <span>Tamanho: <span id="tamanhoLog">${Math.round(tamanhoAtual / 1024)} KB</span></span> <span>Atualizado: <span id="dataAtualizacao">${new Date().toLocaleTimeString('pt-BR')}</span></span>`;
                }
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            buscandoAtivo = false;
            terminal.innerHTML = `<div style="color: ${coresTerminal.vermelho}; text-align: center; padding: 20px;">❌ Erro ao carregar log: ${error.message}</div>`;
        });
}
// ============================================================================
// FIM - FUNÇÃO CARREGAR LOG (VERSÃO ESTÁVEL)
// ============================================================================

// ============================================================================
// INÍCIO - FUNÇÃO ATUALIZAR LOG
// ============================================================================
function atualizarLog() {
    carregarLog(true);
}
// ============================================================================
// FIM - FUNÇÃO ATUALIZAR LOG
// ============================================================================

// ============================================================================
// INÍCIO - FUNÇÃO TOGGLE AUTO REFRESH
// ============================================================================
function toggleAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
    
    const autoRefresh = document.getElementById('autoRefresh');
    if (autoRefresh && autoRefresh.checked) {
        autoRefreshInterval = setInterval(() => {
            carregarLog(false);
        }, 2000);
    }
}
// ============================================================================
// FIM - FUNÇÃO TOGGLE AUTO REFRESH
// ============================================================================

// ============================================================================
// INÍCIO - FUNÇÃO CONFIRMAR LIMPAR LOG (COM DUPLA CONFIRMAÇÃO)
// ============================================================================
function confirmarLimparLog() {
    // Primeira confirmação
    if (!confirm('⚠️ ATENÇÃO: Deseja realmente LIMPAR TODO o arquivo de log?\n\nEsta ação é PERMANENTE e não pode ser desfeita!')) {
        return;
    }
    
    // Segunda confirmação
    if (!confirm('🚨 CONFIRMAÇÃO FINAL:\n\nTem CERTEZA ABSOLUTA que deseja APAGAR PERMANENTEMENTE todo o histórico de logs?\n\nArquivo: /var/log/botzap.log\n\nEsta ação IRÁ REMOVER todas as mensagens gravadas!')) {
        return;
    }
    
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
    
    fetch('index.php?aba=log', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'acao_log=limpar'
    }).then(() => {
        ultimoTamanhoArquivo = 0;
        carregarLog(true);
        toggleAutoRefresh();
        alert('✅ Log limpo com sucesso!');
    });
}
// ============================================================================
// FIM - FUNÇÃO CONFIRMAR LIMPAR LOG
// ============================================================================

// ============================================================================
// INÍCIO - INICIALIZAÇÃO
// ============================================================================
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    
    // ========================================================================
    // INÍCIO - INICIALIZAR ABA LOG
    // ========================================================================
    if (params.get('aba') === 'log') {
        // Carregar preferências salvas
        const lsLinhas = localStorage.getItem('log_linhas');
        const lsBusca = localStorage.getItem('log_busca');
        const select = document.getElementById('linhasLog');
        const input = document.getElementById('buscarLog');
        
        if (lsLinhas && select) {
            select.value = lsLinhas;
            linhasSelecionadas = parseInt(lsLinhas);
        }
        
        if (lsBusca && input) {
            input.value = lsBusca;
            buscaAtiva = lsBusca;
        }
        
        // ATIVAR WAKE LOCK PARA CELULAR
        ativarWakeLock();
        
        // Carregar log
        carregarLog(true);
        
        // Event listeners
        document.getElementById('autoRefresh')?.addEventListener('change', toggleAutoRefresh);
        toggleAutoRefresh();
        
        if (input) {
            let timeout;
            input.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    buscaAtiva = this.value;
                    localStorage.setItem('log_busca', buscaAtiva);
                    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
                    carregarLog(true);
                    toggleAutoRefresh();
                }, 500);
            });
        }
        
        if (select) {
            select.addEventListener('change', function() {
                localStorage.setItem('log_linhas', this.value);
                linhasSelecionadas = parseInt(this.value);
                if (autoRefreshInterval) clearInterval(autoRefreshInterval);
                carregarLog(true);
                toggleAutoRefresh();
            });
        }
    }
    // ========================================================================
    // FIM - INICIALIZAR ABA LOG
    // ========================================================================

    // ========================================================================
    // INÍCIO - MONITORAR STATUS DO WHATSAPP (ORIGINAL)
    // ========================================================================
    const statusDiv = document.querySelector('.status');
    if (statusDiv && params.get('aba') !== 'log') {
        console.log('🔍 Monitorando status do WhatsApp...');
        
        let statusAtual;
        if (statusDiv.classList.contains('online')) {
            statusAtual = 'online';
        } else if (statusDiv.classList.contains('qr')) {
            statusAtual = 'qr';
        } else {
            statusAtual = 'offline';
        }
        
        const intervalo = setInterval(() => {
            fetch('?api_status=1&t=' + Date.now())
                .then(r => r.text())
                .then(novoStatus => {
                    if (novoStatus !== statusAtual) {
                        console.log('✅ Status mudou de', statusAtual, 'para', novoStatus);
                        clearInterval(intervalo);
                        location.reload();
                    }
                })
                .catch(() => console.log('Erro na verificação'));
        }, 3000);
        
        if (statusAtual === 'qr') {
            setInterval(() => {
                const img = document.getElementById('qrImg');
                if (img && img.src.includes('qrcode_view.php')) {
                    img.src = 'qrcode_view.php?t=' + Date.now();
                }
            }, 3000);
        }
    }
    // ========================================================================
    // FIM - MONITORAR STATUS DO WHATSAPP
    // ========================================================================

    // ========================================================================
    // INÍCIO - REMOVER PARÂMETRO SALVO DA URL
    // ========================================================================
    if (window.location.search.includes('salvo=1')) {
        window.history.replaceState({}, document.title, window.location.pathname + (params.get('aba') ? '?aba=' + params.get('aba') : ''));
    }
    // ========================================================================
    // FIM - REMOVER PARÂMETRO SALVO DA URL
    // ========================================================================

    // ========================================================================
    // INÍCIO - AUTO-REMOVER MENSAGEM DE SUCESSO
    // ========================================================================
    const msg = document.getElementById('msg');
    if (msg) {
        setTimeout(() => {
            msg.style.opacity = '0';
            setTimeout(() => msg.remove(), 500);
        }, 3000);
    }
    // ========================================================================
    // FIM - AUTO-REMOVER MENSAGEM DE SUCESSO
    // ========================================================================

    // ========================================================================
    // INÍCIO - BOTÃO MOSTRAR/OCULTAR SENHA MK-AUTH (ORIGINAL)
    // ========================================================================
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
            padding: 0;
            margin: 0;
            width: auto;
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
    // ========================================================================
    // FIM - BOTÃO MOSTRAR/OCULTAR SENHA MK-AUTH
    // ========================================================================
});

// Recupera wake lock automaticamente se usuário voltar para a aba
document.addEventListener('visibilitychange', async () => {
    if (document.visibilityState === 'visible' && window.location.search.includes('aba=log')) {
        if (wakeLock === null) {
            await ativarWakeLock();
        }
    }
});
// ============================================================================
// FIM - INICIALIZAÇÃO
// ============================================================================

// ============================================================================
// INÍCIO - LIMPAR INTERVALO AO SAIR DA PÁGINA
// ============================================================================
window.addEventListener('beforeunload', function() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    desativarWakeLock();
});
// ============================================================================
// FIM - LIMPAR INTERVALO AO SAIR DA PÁGINA
// ============================================================================
</script>

</body>
</html>
