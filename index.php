<?php
/***********************************************************
 * SISTEMA UNIFICADO - BOT WHATSAPP + DASHBOARD PIX
 * Versão: 2.0 - Security Hardened + Global Logout
 * Data: 06/05/2026
 ***********************************************************/

session_start();

// =====================================================================
// BLOCO 01 - CONFIGURAÇÕES E CONSTANTES GLOBAIS
// =====================================================================

// Arquivos do sistema
define('ARQUIVO_USUARIOS', '/var/log/pix_acessos/usuarios.json');
define('ARQUIVO_LOG_ACESSOS', '/var/log/pix_acessos/acessos_usuarios.log');
define('ARQUIVO_RATE_LIMIT_DIR', '/var/log/pix_acessos/rate_limit/');
define('ARQUIVO_CSRF_TOKENS', '/var/log/pix_acessos/csrf_tokens.json');

// Configurações de auto-logout
define('TEMPO_SESSAO_PADRAO', 1800); // 30 minutos
define('ARQUIVO_CONFIG_AUTOLOGOUT', '/var/log/pix_acessos/autologout_config.json');
define('TEMPO_SESSAO', carregarTempoAutologout());

// Configurações de Rate Limiting (anti-DDoS)
define('RATE_LIMIT_REQUESTS', 120); // 120 requisições por minuto
define('RATE_LIMIT_WINDOW', 60); // Janela de 60 segundos

// =====================================================================
// BLOCO 02 - FUNÇÕES UTILITÁRIAS BÁSICAS
// =====================================================================

/**
 * Obtém o IP DO CLOUDFLARE do usuário (apenas REMOTE_ADDR por segurança)
 * @return string IP do usuário
 */
//function getRealIp() {
    // Usar apenas REMOTE_ADDR para evitar spoofing
    //return $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
//}

/**
 * Obtém o IP real do usuário considerando proxies
 * @return string IP do usuário
 */
function getRealIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    $headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $ip;
}

/**
 * Rate Limiting - Previne DDoS e ataques de força bruta
 * @return bool True se permitido, False se bloqueado
 */
function checkRateLimit() {
    $ip = getRealIp();
    $sessionId = session_id();
    $timestamp = time();
    $windowStart = $timestamp - RATE_LIMIT_WINDOW;
    
    // EXCEÇÃO PARA HEARTBEAT E LOGS
    $isLogOrHeartbeat = isset($_GET['update_session']) || 
                        isset($_GET['log_session_check']) || 
                        isset($_GET['get_log']) || 
                        isset($_GET['tail']) ||
                        (isset($_GET['aba']) && $_GET['aba'] === 'log');
    
    if ($isLogOrHeartbeat) {
        return true; // Sem limite para requisições da aba de logs
    }
    
    // Criar diretório se não existir
    if (!is_dir(ARQUIVO_RATE_LIMIT_DIR)) {
        @mkdir(ARQUIVO_RATE_LIMIT_DIR, 0755, true);
    }
    
    $ipFile = ARQUIVO_RATE_LIMIT_DIR . md5($ip) . '.json';
    $sessionFile = ARQUIVO_RATE_LIMIT_DIR . 'session_' . md5($sessionId) . '.json';
    
    // Limpar arquivos antigos (a cada 100 requisições)
    if (mt_rand(1, 100) === 1) {
        $files = glob(ARQUIVO_RATE_LIMIT_DIR . '*.json');
        foreach ($files as $file) {
            if (filemtime($file) < $timestamp - 3600) {
                @unlink($file);
            }
        }
    }
    
    // Verificar IP
    $ipData = file_exists($ipFile) ? json_decode(file_get_contents($ipFile), true) : ['requests' => [], 'total' => 0];
    $ipData['requests'][] = $timestamp;
    $ipData['requests'] = array_filter($ipData['requests'], function($time) use ($windowStart) {
        return $time > $windowStart;
    });
    $ipData['total'] = count($ipData['requests']);
    
    // Verificar sessão (mais restritivo para ações críticas)
    $sessionData = file_exists($sessionFile) ? json_decode(file_get_contents($sessionFile), true) : ['requests' => [], 'total' => 0];
    $sessionData['requests'][] = $timestamp;
    $sessionData['requests'] = array_filter($sessionData['requests'], function($time) use ($windowStart) {
        return $time > $windowStart;
    });
    $sessionData['total'] = count($sessionData['requests']);
    
    // Regras de rate limit:
    // - IP: máximo 120 requisições por minuto
    // - Sessão: máximo 60 requisições por minuto (ações críticas)
    $isCritical = isset($_GET['action']) || isset($_POST['alterar_senha_dashboard']) || isset($_POST['adicionar_usuario']);
    
    if ($isCritical) {
        $allowed = ($sessionData['total'] < 60);
    } else {
        $allowed = ($ipData['total'] < RATE_LIMIT_REQUESTS);
    }
    
    // Salvar dados
    file_put_contents($ipFile, json_encode(['requests' => $ipData['requests'], 'total' => $ipData['total']]));
    file_put_contents($sessionFile, json_encode(['requests' => $sessionData['requests'], 'total' => $sessionData['total']]));
    
    if (!$allowed) {
        // Registrar tentativa de violação
        error_log("RATE_LIMIT_VIOLATION: IP={$ip}, Session={$sessionId}, Total={$ipData['total']}");
        header('HTTP/1.0 429 Too Many Requests');
        header('Retry-After: 60');
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            echo json_encode(['error' => 'Muitas requisições. Aguarde 60 segundos.']);
            exit;
        } else {
            die('<h1>429 - Muitas Requisições</h1><p>Por favor, aguarde 60 segundos antes de continuar.</p>');
        }
    }
    
    return true;
}

/**
 * Gera e salva token CSRF
 * @return string Token CSRF
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica token CSRF
 * @param string $token Token recebido
 * @return bool True se válido
 */
function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Carrega o tempo de auto-logout configurado
 * @return int Tempo em segundos
 */
function carregarTempoAutologout() {
    if (file_exists(ARQUIVO_CONFIG_AUTOLOGOUT)) {
        $config = json_decode(file_get_contents(ARQUIVO_CONFIG_AUTOLOGOUT), true);
        if (isset($config['tempo']) && is_numeric($config['tempo']) && $config['tempo'] >= 60) {
            return $config['tempo'];
        }
    }
    return TEMPO_SESSAO_PADRAO;
}

/**
 * Salva o tempo de auto-logout
 * @param int $tempo Tempo em segundos
 * @return bool Sucesso da operação
 */
function salvarTempoAutologout($tempo) {
    $config = ['tempo' => intval($tempo), 'ultima_alteracao' => date('Y-m-d H:i:s'), 'alterado_por' => $_SESSION['usuario'] ?? 'system'];
    return file_put_contents(ARQUIVO_CONFIG_AUTOLOGOUT, json_encode($config)) !== false;
}

// =====================================================================
// BLOCO 03 - FUNÇÕES DE GERENCIAMENTO DE USUÁRIOS
// =====================================================================

/**
 * Carrega a lista de usuários do arquivo JSON
 * @return array Lista de usuários
 */
function carregarUsuarios() {
    if (file_exists(ARQUIVO_USUARIOS)) {
        $conteudo = @file_get_contents(ARQUIVO_USUARIOS);
        if ($conteudo !== false) {
            $usuarios = json_decode($conteudo, true);
            if (is_array($usuarios)) {
                return $usuarios;
            }
        }
    }
    
    // Criar admin padrão se arquivo não existir
    $usuarios = [
        'admin' => [
            'senha_hash' => '$2y$10$WN/a1/7yFMPbsPyfM6.ysuRtFBqG8RpoAF/DwpyxFTu2tnlo1ekde',
            'nome' => 'Administrador',
            'email' => 'admin@sistema.com',
            'nivel' => 'admin',
            'status' => 'ativo',
            'auto_logout' => true,
            'data_criacao' => date('Y-m-d H:i:s'),
            'ip_cadastro' => '127.0.0.1',
            'ultimo_acesso' => null,
            'ip_ultimo_acesso' => null
        ]
    ];
    
    @file_put_contents(ARQUIVO_USUARIOS, json_encode($usuarios, JSON_PRETTY_PRINT));
    return $usuarios;
}

/**
 * Salva a lista de usuários no arquivo JSON
 * @param array $usuarios Lista de usuários
 * @return bool Sucesso da operação
 */
function salvarUsuarios($usuarios) {
    $json = json_encode($usuarios, JSON_PRETTY_PRINT);
    return @file_put_contents(ARQUIVO_USUARIOS, $json) !== false;
}

/**
 * Atualiza o último acesso do usuário
 * @param string $usuario Nome do usuário
 * @return bool Sucesso da operação
 */
function atualizarUltimoAcesso($usuario) {
    $usuarios = carregarUsuarios();
    
    if (isset($usuarios[$usuario])) {
        $usuarios[$usuario]['ultimo_acesso'] = date('Y-m-d H:i:s');
        $usuarios[$usuario]['ip_ultimo_acesso'] = getRealIp();
        return salvarUsuarios($usuarios);
    }
    
    return false;
}

/**
 * Verifica credenciais de login
 * @param string $usuario Nome do usuário
 * @param string $senha Senha fornecida
 * @return array|false Dados do usuário ou false
 */
function verificarLogin($usuario, $senha) {
    $usuarios = carregarUsuarios();
    
    if (isset($usuarios[$usuario]) && $usuarios[$usuario]['status'] === 'ativo') {
        if (password_verify($senha, $usuarios[$usuario]['senha_hash'])) {
            return $usuarios[$usuario];
        }
    }
    return false;
}

/**
 * Adiciona um novo usuário ao sistema
 * @param array $dados Dados do novo usuário
 * @return array Resultado da operação
 */
function adicionarUsuario($dados) {
    $usuarios = carregarUsuarios();
    
    // Validações
    if (empty($dados['usuario']) || empty($dados['nome']) || empty($dados['senha'])) {
        return ['success' => false, 'message' => 'Preencha todos os campos obrigatórios!'];
    }
    
    if (strlen($dados['senha']) < 6) {
        return ['success' => false, 'message' => 'A senha deve ter no mínimo 6 caracteres!'];
    }
    
    if (isset($usuarios[$dados['usuario']])) {
        return ['success' => false, 'message' => 'Usuário já existe!'];
    }
    
    // Criar novo usuário com auto_logout = true (padrão)
    $usuarios[$dados['usuario']] = [
        'senha_hash' => password_hash($dados['senha'], PASSWORD_DEFAULT),
        'nome' => $dados['nome'],
        'email' => $dados['email'] ?? '',
        'nivel' => $dados['nivel'] ?? 'usuario',
        'status' => 'ativo',
        'auto_logout' => true,
        'data_criacao' => date('Y-m-d H:i:s'),
        'ip_cadastro' => getRealIp(),
        'ultimo_acesso' => null,
        'ip_ultimo_acesso' => null
    ];
    
    if (salvarUsuarios($usuarios)) {
        return ['success' => true, 'message' => 'Usuário cadastrado com sucesso!'];
    }
    
    return ['success' => false, 'message' => 'Erro ao salvar usuário!'];
}

/**
 * Altera a senha do próprio usuário
 * @param string $usuario Nome do usuário
 * @param string $senha_atual Senha atual
 * @param string $nova_senha Nova senha
 * @return array Resultado da operação
 */
function alterarSenha($usuario, $senha_atual, $nova_senha) {
    $usuarios = carregarUsuarios();
    
    if (!isset($usuarios[$usuario])) {
        return ['success' => false, 'message' => 'Usuário não encontrado!'];
    }
    
    if (!password_verify($senha_atual, $usuarios[$usuario]['senha_hash'])) {
        return ['success' => false, 'message' => 'Senha atual incorreta!'];
    }
    
    if (strlen($nova_senha) < 6) {
        return ['success' => false, 'message' => 'A nova senha deve ter no mínimo 6 caracteres!'];
    }
    
    $usuarios[$usuario]['senha_hash'] = password_hash($nova_senha, PASSWORD_DEFAULT);
    
    if (salvarUsuarios($usuarios)) {
        return ['success' => true, 'message' => 'Senha alterada com sucesso!'];
    }
    
    return ['success' => false, 'message' => 'Erro ao salvar nova senha!'];
}

/**
 * Altera a senha de outro usuário (apenas admin)
 * @param string $usuario_admin Nome do admin
 * @param string $usuario_alvo Nome do usuário alvo
 * @param string $nova_senha Nova senha
 * @return array Resultado da operação
 */
function alterarSenhaAdmin($usuario_admin, $usuario_alvo, $nova_senha) {
    $usuarios = carregarUsuarios();
    
    if (!isset($usuarios[$usuario_admin]) || $usuarios[$usuario_admin]['nivel'] !== 'admin') {
        return ['success' => false, 'message' => 'Acesso negado! Apenas administradores podem alterar senhas de outros usuários.'];
    }
    
    if (!isset($usuarios[$usuario_alvo])) {
        return ['success' => false, 'message' => 'Usuário alvo não encontrado!'];
    }
    
    if (strlen($nova_senha) < 6) {
        return ['success' => false, 'message' => 'A nova senha deve ter no mínimo 6 caracteres!'];
    }
    
    $usuarios[$usuario_alvo]['senha_hash'] = password_hash($nova_senha, PASSWORD_DEFAULT);
    
    if (salvarUsuarios($usuarios)) {
        return ['success' => true, 'message' => 'Senha do usuário ' . $usuario_alvo . ' alterada com sucesso!'];
    }
    
    return ['success' => false, 'message' => 'Erro ao salvar nova senha!'];
}

// =====================================================================
// BLOCO 04 - FUNÇÕES DE LOG DE ACESSO
// =====================================================================

/**
 * Registra um evento de log de acesso
 * @param string $usuario Nome do usuário
 * @param string $nome Nome completo
 * @param string $nivel Nível de acesso
 * @param string $acao Ação realizada
 * @param string|null $tempo_ativo Tempo ativo (opcional)
 * @param int|null $timestamp Timestamp do evento
 * @param int|null $login_time Timestamp do login
 * @return bool Sucesso da operação
 */
function registrarLogAcesso($usuario, $nome, $nivel, $acao, $tempo_ativo = null, $timestamp = null, $login_time = null) {
    $timestamp = $timestamp ?: time();
    $data_brasileira = date('d/m/Y', $timestamp);
    $hora_brasileira = date('H:i:s', $timestamp);
    $ip = getRealIp();
    
    if ($tempo_ativo === null && $login_time !== null && in_array($acao, ['LOGOUT', 'LOGOUT_AUTO', 'SESSÃO_EXPIRADA', 'LOGOUT_GLOBAL'])) {
        $tempo_ativo_segundos = $timestamp - $login_time;
        $tempo_ativo = $tempo_ativo_segundos > 0 ? gmdate('H:i:s', $tempo_ativo_segundos) : '00:00:00';
    }
    
    $log_entry = sprintf(
        "%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
        $data_brasileira,
        $hora_brasileira,
        $usuario,
        $nome,
        $nivel,
        $ip,
        $acao,
        $tempo_ativo ?? 'N/A'
    );
    
    $diretorio = dirname(ARQUIVO_LOG_ACESSOS);
    if (!is_dir($diretorio)) {
        @mkdir($diretorio, 0777, true);
    }
    
    if (!is_writable($diretorio) && is_dir($diretorio)) {
        @chmod($diretorio, 0777);
    }
    
    if (!file_exists(ARQUIVO_LOG_ACESSOS)) {
        @touch(ARQUIVO_LOG_ACESSOS);
        @chmod(ARQUIVO_LOG_ACESSOS, 0666);
    }
    
    if (file_exists(ARQUIVO_LOG_ACESSOS) && !is_writable(ARQUIVO_LOG_ACESSOS)) {
        @chmod(ARQUIVO_LOG_ACESSOS, 0666);
    }
    
    $resultado = @file_put_contents(ARQUIVO_LOG_ACESSOS, $log_entry, FILE_APPEND | LOCK_EX);
    
    if ($resultado === false) {
        error_log("FALHA ao gravar log de acesso: " . ARQUIVO_LOG_ACESSOS);
        $resultado = @file_put_contents(ARQUIVO_LOG_ACESSOS, $log_entry, FILE_APPEND);
    }
    
    return $resultado !== false;
}

// =====================================================================
// BLOCO 05 - FUNÇÕES DO DASHBOARD PIX
// =====================================================================

/**
 * Conta o número de acessos em um determinado dia
 * @param string $data Data no formato Y-m-d
 * @return int Número de acessos
 */
function contarDia($data) {
    $arquivo = "/var/log/pix_acessos/pix_log_$data.log";
    
    if (!file_exists($arquivo)) {
        return 0;
    }
    
    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return $linhas ? count($linhas) : 0;
}

/**
 * Lista os dias disponíveis nos logs do PIX
 * @return array Lista de datas
 */
function listarDiasDisponiveis() {
    $dias = [];
    $logDir = '/var/log/pix_acessos/';
    
    if (!is_dir($logDir)) {
        return [date('Y-m-d')];
    }
    
    $arquivos = glob($logDir . "pix_log_*.log");
    
    foreach ($arquivos as $arquivo) {
        if (preg_match('/pix_log_(\d{4}-\d{2}-\d{2})\.log$/', $arquivo, $matches)) {
            $dias[] = $matches[1];
        }
    }
    
    $hoje = date('Y-m-d');
    if (!in_array($hoje, $dias)) {
        $dias[] = $hoje;
    }
    
    rsort($dias);
    return $dias;
}

// =====================================================================
// BLOCO 06 - ENDPOINTS AJAX (Heartbeat, Session, etc)
// =====================================================================

// Aplicar rate limiting em todos os endpoints AJAX
if (isset($_GET['heartbeat']) || isset($_GET['check_session']) || isset($_GET['update_session']) || 
    isset($_GET['log_session_check']) || isset($_GET['toggle_log_timeout']) || isset($_GET['get_log']) ||
    isset($_GET['check_status']) || isset($_GET['api_status']) || isset($_GET['testar_telegram'])) {
    checkRateLimit();
}

/**
 * Endpoint: Heartbeat para manter sessão ativa
 * Uso: ?heartbeat=1
 */
if (isset($_GET['heartbeat'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    $response = ['success' => false, 'expired' => false, 'time_left' => 0, 'server_time' => time()];
    
    if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
        $auto_logout = $_SESSION['auto_logout'] ?? true;
        
        if ($auto_logout === true) {
            $ultimo_acesso = $_SESSION['ultimo_acesso'] ?? time();
            $tempo_passado = time() - $ultimo_acesso;
            $timeLeft = max(0, TEMPO_SESSAO - $tempo_passado);
            
            if ($timeLeft <= 0) {
                $response = [
                    'success' => true,
                    'expired' => true,
                    'time_left' => 0,
                    'server_time' => time(),
                    'auto_logout' => true
                ];
            } else {
                $response = [
                    'success' => true,
                    'expired' => false,
                    'time_left' => $timeLeft,
                    'server_time' => time(),
                    'auto_logout' => true
                ];
            }
        } else {
            $response = [
                'success' => true,
                'expired' => false,
                'time_left' => TEMPO_SESSAO,
                'server_time' => time(),
                'auto_logout' => false
            ];
        }
    } else {
        $response['expired'] = true;
        $response['time_left'] = 0;
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Endpoint: Verificar status da sessão
 * Uso: ?check_session=1
 */
if (isset($_GET['check_session'])) {
    header('Content-Type: text/plain');
    
    if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
        $auto_logout = $_SESSION['auto_logout'] ?? true;
        
        if ($auto_logout === true) {
            if (isset($_SESSION['ultimo_acesso']) && (time() - $_SESSION['ultimo_acesso'] > TEMPO_SESSAO)) {
                echo 'expired';
            } else {
                echo 'active';
            }
        } else {
            echo 'active';
        }
    } else {
        echo 'expired';
    }
    exit;
}

/**
 * Endpoint: Atualizar timestamp da sessão (chamado em cada interação do usuário)
 * Uso: ?update_session=1
 */
if (isset($_GET['update_session'])) {
    header('Content-Type: application/json');
    
    if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
        // ===== CORREÇÃO: Se ignore_log_timeout estiver ativo, SEMPRE atualiza =====
        if (isset($_SESSION['ignore_log_timeout']) && $_SESSION['ignore_log_timeout'] === true) {
            $_SESSION['ultimo_acesso'] = time();
            echo json_encode(['success' => true, 'timestamp' => time(), 'csrf_token' => generateCsrfToken(), 'keep_alive' => true]);
            exit;
        }
        // ===== FIM DA CORREÇÃO =====
        
        // Código original continua igual
        if ($_SESSION['auto_logout'] ?? true) {
            $_SESSION['ultimo_acesso'] = time();
        }
        echo json_encode(['success' => true, 'timestamp' => time(), 'csrf_token' => generateCsrfToken()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    }
    exit;
}

// =====================================================================
// BLOCO 07 - ENDPOINTS DA ABA DE LOGS (COM VERIFICAÇÃO FORÇADA)
// =====================================================================

/**
 * Endpoint: Verificação especial para aba de logs (AGORA COM VERIFICAÇÃO NO SERVIDOR)
 * Uso: ?log_session_check=1
 */
if (isset($_GET['log_session_check'])) {
    header('Content-Type: application/json');
    
    $response = ['active' => false, 'time_left' => 0];
    
    if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
        $ignoreLogTimeout = isset($_SESSION['ignore_log_timeout']) ? $_SESSION['ignore_log_timeout'] : false;
        
        // VERIFICAÇÃO OBRIGATÓRIA NO SERVIDOR
        if ($_SESSION['auto_logout'] === true && !$ignoreLogTimeout) {
            $ultimo_acesso = $_SESSION['ultimo_acesso'] ?? time();
            $tempo_passado = time() - $ultimo_acesso;
            
            if ($tempo_passado > TEMPO_SESSAO) {
                // Sessão expirou no servidor
                $response = ['active' => false, 'expired' => true, 'reason' => 'session_expired'];
                echo json_encode($response);
                exit;
            }
            
            $timeLeft = max(0, TEMPO_SESSAO - $tempo_passado);
            $response = [
                'active' => true,
                'ignore_timeout' => $ignoreLogTimeout,
                'time_left' => $timeLeft,
                'expired' => false
            ];
        } else {
            $response = [
                'active' => true,
                'ignore_timeout' => $ignoreLogTimeout,
                'time_left' => TEMPO_SESSAO,
                'auto_logout_disabled' => ($_SESSION['auto_logout'] === false)
            ];
        }
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Endpoint: Alternar ignore timeout na aba de logs (AGORA COM CSRF)
 * Uso: POST com toggle_log_timeout=1 e csrf_token
 */
if (isset($_POST['toggle_log_timeout']) || isset($_GET['toggle_log_timeout'])) {
    header('Content-Type: application/json');
    
    // Verificar CSRF para POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'error' => 'CSRF token inválido']);
            exit;
        }
    }
    
    $newState = isset($_POST['state']) ? ($_POST['state'] === 'true') : (isset($_GET['state']) ? ($_GET['state'] === 'true') : false);
    
    // VALIDAÇÃO NO SERVIDOR: Verificar se o usuário TEM permissão (todos têm, mas registramos)
    $_SESSION['ignore_log_timeout'] = $newState;
    
    if (isset($_SESSION['usuario'])) {
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'] ?? 'N/A',
            $_SESSION['nivel'] ?? 'N/A',
            'LOG_ABA_TIMEOUT: ' . ($newState ? 'IGNORAR' : 'RESPEITAR')
        );
    }
    
    echo json_encode(['success' => true, 'ignore_timeout' => $newState]);
    exit;
}

// =====================================================================
// BLOCO 08 - PROCESSAMENTO DE LOGIN/LOGOUT (COM CSRF E LOGOUT GLOBAL)
// =====================================================================

/**
 * Endpoint: Logout manual (AGORA COM LOGOUT GLOBAL)
 * Uso: POST com action=logout e csrf_token
 */
if ((isset($_GET['action']) && $_GET['action'] == 'logout') || (isset($_POST['action']) && $_POST['action'] == 'logout')) {
    // Verificar CSRF para POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            die('CSRF token inválido');
        }
    }
    
    if (isset($_SESSION['usuario'])) {
        $tempo_ativo = isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : null;
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'] ?? 'N/A',
            $_SESSION['nivel'] ?? 'N/A',
            'LOGOUT_GLOBAL',
            $tempo_ativo ? gmdate('H:i:s', $tempo_ativo) : 'N/A',
            time(),
            $_SESSION['login_time'] ?? null
        );
    }
    
    // Destruir sessão completamente
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    
    // Se for AJAX, retornar JSON para logout global
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'redirect' => 'index.php']);
        exit;
    }
    
    header('Location: index.php');
    exit;
}

/**
 * Endpoint: Logout automático
 * Uso: ?action=auto_logout
 */
if (isset($_GET['action']) && $_GET['action'] == 'auto_logout') {
    if (isset($_SESSION['usuario'])) {
        $tempo_ativo = isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : null;
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'] ?? 'N/A',
            $_SESSION['nivel'] ?? 'N/A',
            'LOGOUT_AUTO',
            $tempo_ativo ? gmdate('H:i:s', $tempo_ativo) : 'N/A',
            time(),
            $_SESSION['login_time'] ?? null
        );
    }
    session_destroy();
    header('Location: index.php?expired=true');
    exit;
}

// =====================================================================
// BLOCO 09 - VERIFICAÇÃO DE SESSÃO E TELA DE LOGIN
// =====================================================================

$isLogAbaRequest = (isset($_GET['aba']) && $_GET['aba'] === 'log') || 
                   (isset($_POST['acao_log'])) ||
                   (isset($_GET['get_log'])) ||
                   (isset($_GET['check_status'])) ||
                   (isset($_GET['api_status']));

if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    
    // Tratamento especial para aba de logs (COM VERIFICAÇÃO NO SERVIDOR)
    if ($isLogAbaRequest) {
        if (isset($_GET['get_log']) || isset($_GET['check_status']) || isset($_GET['api_status'])) {
            header('HTTP/1.0 401 Unauthorized');
            echo json_encode(['error' => 'Sessão expirada']);
            exit;
        }
        
        if (!isset($_GET['get_log']) && !isset($_GET['check_status'])) {
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>Sessão Expirada</title>
                <meta http-equiv="refresh" content="3;url=index.php">
                <style>
                    body { background: #0C0C0C; color: #7CFC00; font-family: 'Courier New', monospace; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
                    .container { text-align: center; padding: 20px; border: 2px solid #7CFC00; border-radius: 10px; background: #1e293b; }
                    h1 { font-size: 24px; margin-bottom: 20px; }
                    p { font-size: 16px; color: #fff; }
                    .loader { border: 4px solid #f3f3f3; border-top: 4px solid #7CFC00; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
                    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>🔒 SESSÃO EXPIRADA</h1>
                    <p>Redirecionando para o login em 3 segundos...</p>
                    <div class="loader"></div>
                    <p style="font-size: 12px; margin-top: 20px;">A aba de logs não pode ser acessada sem login</p>
                </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
    
    // Processar login (com rate limiting)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario']) && isset($_POST['senha'])) {
        checkRateLimit(); // Rate limiting para login
        
        $usuarioDB = verificarLogin($_POST['usuario'], $_POST['senha']);
        
        if ($usuarioDB) {
            $_SESSION['logado'] = true;
            $_SESSION['usuario'] = $_POST['usuario'];
            $_SESSION['nome'] = $usuarioDB['nome'];
            $_SESSION['nivel'] = $usuarioDB['nivel'];
            $_SESSION['login_time'] = time();
            $_SESSION['ultimo_acesso'] = time();
            $_SESSION['ip'] = getRealIp();
            $_SESSION['auto_logout'] = $usuarioDB['auto_logout'] ?? true;
            
            // Gerar CSRF token na sessão
            generateCsrfToken();
            
            atualizarUltimoAcesso($_POST['usuario']);
            registrarLogAcesso($_POST['usuario'], $usuarioDB['nome'], $usuarioDB['nivel'], 'LOGIN');
            
            header('Location: index.php' . (isset($_GET['aba']) ? '?aba=' . $_GET['aba'] : ''));
            exit;
        } else {
            registrarLogAcesso($_POST['usuario'] ?? 'desconhecido', 'N/A', 'N/A', 'LOGIN_FALHOU');
            $erro_login = 'Usuário ou senha incorretos!';
        }
    }
    
    // Mostrar tela de login (mantida original)
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Login - Bot WhatsApp</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            *{margin:0;padding:0;box-sizing:border-box;font-family:'Arial',sans-serif}
            body{
                background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);
                height:100vh;
                display:flex;
                justify-content:center;
                align-items:center;
                padding:20px
            }
            .login-container{
                background:white;
                border-radius:20px;
                box-shadow:0 20px 40px rgba(0,0,0,0.2);
                overflow:hidden;
                width:100%;
                max-width:450px;
                animation:slideUp 0.5s ease-out
            }
            .login-header{
                background:linear-gradient(to right,#00b894,#00a085);
                padding:40px 20px;
                text-align:center;
                color:white;
                position:relative
            }
            .login-header h1{font-size:28px;margin-bottom:10px}
            .login-header p{opacity:0.9;font-size:14px}
            .admin-badge{
                position:absolute;
                top:10px;
                right:10px;
                background:rgba(255,255,255,0.2);
                padding:4px 10px;
                border-radius:12px;
                font-size:11px;
                font-weight:bold
            }
            .login-body{padding:40px}
            .form-group{margin-bottom:25px}
            .form-group label{
                display:block;
                margin-bottom:8px;
                color:#333;
                font-weight:600;
                font-size:14px
            }
            .form-control{
                width:100%;
                padding:15px;
                border:2px solid #e1e5e9;
                border-radius:10px;
                font-size:16px;
                transition:all 0.3s
            }
            .form-control:focus{
                outline:none;
                border-color:#00b894;
                box-shadow:0 0 0 3px rgba(0,184,148,0.1)
            }
            .btn-login{
                width:100%;
                padding:16px;
                background:linear-gradient(to right,#00b894,#00a085);
                color:white;
                border:none;
                border-radius:10px;
                font-size:16px;
                font-weight:600;
                cursor:pointer;
                transition:all 0.3s;
                margin-top:10px
            }
            .btn-login:hover{
                background:linear-gradient(to right,#00a085,#008b74);
                transform:translateY(-2px);
                box-shadow:0 5px 15px rgba(0,184,148,0.3)
            }
            .alert{
                padding:15px;
                border-radius:10px;
                margin-bottom:20px;
                font-size:14px
            }
            .alert-danger{
                background-color:#ffeaea;
                color:#e74c3c;
                border:1px solid #ffc9c9
            }
            .alert-warning{
                background-color:#fff8e1;
                color:#f39c12;
                border:1px solid #ffeaa7
            }
            .user-count{
                display:inline-block;
                background:rgba(255,255,255,0.3);
                color:white;
                padding:4px 12px;
                border-radius:20px;
                font-size:12px;
                margin-top:10px;
                backdrop-filter:blur(5px)
            }
            .login-footer{
                text-align:center;
                padding:20px;
                color:#666;
                font-size:13px;
                border-top:1px solid #eee
            }
            @keyframes slideUp{
                from{opacity:0;transform:translateY(30px)}
                to{opacity:1;transform:translateY(0)}
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-header">
                <div class="admin-badge">Sistema de Usuários</div>
                <h1>🤖 Bot WhatsApp</h1>
                <p>Acesso Restrito</p>
                <div class="user-count"><?= count(carregarUsuarios()) ?> usuário(s) cadastrado(s)</div>
            </div>
            
            <div class="login-body">
                <?php if (isset($_GET['expired'])): ?>
                <div class="alert alert-warning">
                    ⚠️ Sua sessão expirou por inatividade. Faça login novamente.
                </div>
                <?php endif; ?>
                
                <?php if (isset($erro_login)): ?>
                <div class="alert alert-danger">
                    ❌ <?= htmlspecialchars($erro_login) ?>
                </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label>Usuário</label>
                        <input type="text" name="usuario" class="form-control" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label>Senha</label>
                        <input type="password" name="senha" class="form-control" required>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        🔐 Entrar no Sistema
                    </button>
                </form>
                
                <div style="background:#f8f9fa;padding:15px;border-radius:10px;margin-top:20px;font-size:12px;color:#666;text-align:center">
                    <p>🛡️ Acesso restrito à equipe autorizada</p>
                </div>
            </div>
            
            <div class="login-footer">
                Sistema Unificado &copy; <?= date('Y') ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =====================================================================
// BLOCO 10 - VERIFICAÇÃO DE EXPIRAÇÃO DA SESSÃO (COM SEGURANÇA)
// =====================================================================

if (!isset($_SESSION['auto_logout'])) {
    $_SESSION['auto_logout'] = true;
}

// Garantir que ultimo_acesso existe
if (!isset($_SESSION['ultimo_acesso'])) {
    $_SESSION['ultimo_acesso'] = time();
}

// Garantir que CSRF token existe
if (!isset($_SESSION['csrf_token'])) {
    generateCsrfToken();
}

$isLogRequest = (isset($_GET['aba']) && $_GET['aba'] === 'log') ||
                (isset($_GET['get_log'])) ||
                (isset($_GET['check_status'])) ||
                (isset($_GET['api_status']));

// SÓ verificar expiração se NÃO for requisição da aba de logs e se auto-logout estiver ativado
if (!$isLogRequest && $_SESSION['auto_logout']) {
    $tempo_sessao_atual = time() - $_SESSION['ultimo_acesso'];
    
    if ($tempo_sessao_atual > TEMPO_SESSAO) {
        // Sessão expirou
        registrarLogAcesso(
            $_SESSION['usuario'] ?? 'desconhecido',
            $_SESSION['nome'] ?? 'N/A',
            $_SESSION['nivel'] ?? 'N/A',
            'SESSÃO_EXPIRADA',
            gmdate('H:i:s', TEMPO_SESSAO),
            time(),
            $_SESSION['login_time'] ?? null
        );
        session_destroy();
        header('Location: index.php?expired=true');
        exit;
    }
}

// =====================================================================
// BLOCO 11 - PROCESSAMENTO DE AÇÕES DO DASHBOARD (COM CSRF)
// =====================================================================

/**
 * Ação: Toggle auto-logout (AGORA COM CSRF)
 * Uso: POST com toggle_autologout=on|off e csrf_token
 */
if (isset($_POST['toggle_autologout']) || isset($_GET['toggle_autologout'])) {
    // Verificar CSRF para POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            die('CSRF token inválido');
        }
        $novo_status = $_POST['toggle_autologout'] == 'on' ? true : false;
    } else {
        // GET apenas para compatibilidade, mas registramos warning
        error_log("CSRF_WARNING: toggle_autologout via GET de " . getRealIp());
        $novo_status = $_GET['toggle_autologout'] == 'on' ? true : false;
    }
    
    registrarLogAcesso(
        $_SESSION['usuario'],
        $_SESSION['nome'],
        $_SESSION['nivel'],
        'ALTERAR_AUTO_LOGOUT: ' . ($novo_status ? 'ATIVADO' : 'DESATIVADO')
    );
    
    $_SESSION['auto_logout'] = $novo_status;
    
    // Salvar no arquivo usuarios.json
    $usuarios = carregarUsuarios();
    if (isset($usuarios[$_SESSION['usuario']])) {
        $usuarios[$_SESSION['usuario']]['auto_logout'] = $novo_status;
        $usuarios[$_SESSION['usuario']]['ultima_alteracao_autologout'] = date('Y-m-d H:i:s');
        $usuarios[$_SESSION['usuario']]['ip_alteracao_autologout'] = getRealIp();
        salvarUsuarios($usuarios);
        error_log("Auto-logout salvo para {$_SESSION['usuario']}: " . ($novo_status ? 'ON' : 'OFF'));
    }
    
    $abaAtiva = isset($_GET['aba']) ? $_GET['aba'] : 'dashboard';
    header('Location: index.php?aba=' . $abaAtiva);
    exit;
}

/**
 * Ação: Salvar configurações de Auto-Logout (tempo + reset por atividade)
 * Uso: POST com salvar_autologout_config e csrf_token
 */
if (isset($_POST['salvar_autologout_config'])) {
    // Definir o caminho do config.json
    $configPath = '/opt/whatsapp-bot/config.json';
    
    // Verificar CSRF
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die('CSRF token inválido');
    }
    
    // ===== SALVAR TEMPO =====
    $novoTempo = (int)$_POST['tempo_autologout'];
    if ($novoTempo < 60) $novoTempo = 60;
    if ($novoTempo > 86400) $novoTempo = 86400;
    $tempoSalvo = salvarTempoAutologout($novoTempo);
    
    // ===== SALVAR RESET POR ATIVIDADE =====
    $reset_atividade = ($_POST['reset_atividade'] === 'Sim');
    
    // Carregar config atual
    $configAtual = array();
    if (file_exists($configPath)) {
        $configAtual = json_decode(file_get_contents($configPath), true);
        if (!is_array($configAtual)) $configAtual = array();
    }
    
    $configAtual['reset_timer_on_activity'] = $reset_atividade;
    
    // Salvar config
    $json = json_encode($configAtual, JSON_PRETTY_PRINT);
    $resetSalvo = file_put_contents($configPath, $json);
    
    // Mensagem de feedback
    if ($tempoSalvo && $resetSalvo !== false) {
        $_SESSION['mensagem_tempo'] = "Configurações salvas com sucesso! Tempo: " . gmdate("H:i:s", $novoTempo);
        $_SESSION['tipo_mensagem_tempo'] = 'sucesso';
        
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'],
            $_SESSION['nivel'],
            'ALTERAR_CONFIG_AUTOLOGOUT: Tempo=' . gmdate("H:i:s", $novoTempo) . ', Reset=' . ($reset_atividade ? 'ATIVADO' : 'DESATIVADO')
        );
        
        // Atualizar a variável global da configuração
        $config['reset_timer_on_activity'] = $reset_atividade;
    } else {
        $_SESSION['mensagem_tempo'] = "Erro ao salvar configurações!";
        $_SESSION['tipo_mensagem_tempo'] = 'erro';
    }
    
    header('Location: index.php?aba=config');
    exit;
}

/**
 * Ação: Alterar senha (própria) - COM CSRF
 * Uso: POST com alterar_senha_dashboard e csrf_token
 */
if (isset($_POST['alterar_senha_dashboard'])) {
    // Verificar CSRF
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die('CSRF token inválido');
    }
    
    $resultado = alterarSenha(
        $_SESSION['usuario'],
        $_POST['senha_atual'] ?? '',
        $_POST['nova_senha'] ?? ''
    );
    
    $_SESSION['mensagem_senha'] = $resultado['message'];
    $_SESSION['tipo_mensagem_senha'] = $resultado['success'] ? 'sucesso' : 'erro';
    
    if ($resultado['success']) {
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'],
            $_SESSION['nivel'],
            'ALTERAR_SENHA_PRÓPRIA'
        );
    }
    
    header('Location: index.php?aba=dashboard');
    exit;
}

// =====================================================================
// BLOCO 12 - VARIÁVEIS DO WHATSAPP E CONFIGURAÇÕES
// =====================================================================

$configPath = '/opt/whatsapp-bot/config.json';
$statusPath = '/opt/whatsapp-bot/status.json';
$versaoPath = '/opt/whatsapp-bot/ultima_versao.json';
$pixPath = '/var/www/botzap/pix.php';
$logPath = '/var/log/botzap.log';

$mensagem = '';
$erro = '';
$abaAtiva = isset($_GET['aba']) ? $_GET['aba'] : 'config';

// Carregar versão do WhatsApp
$versao_whatsapp = '1033927531';
$versao_detectada = null;
$versao_completa = null;
$fonte_versao = 'desconhecida';

if (file_exists($versaoPath)) {
    $versao_data = json_decode(file_get_contents($versaoPath), true);
    if ($versao_data && is_array($versao_data)) {
        if (isset($versao_data['versao'])) {
            $versao_whatsapp = $versao_data['versao'];
            $versao_completa = $versao_data['versao_completa'] ?? null;
            $fonte_versao = $versao_data['fonte'] ?? 'desconhecida';
        }
        elseif (isset($versao_data['versao_bot'])) {
            $versao_whatsapp = $versao_data['versao_bot'];
            $versao_detectada = $versao_data['versao_detectada'] ?? null;
            $fonte_versao = 'monitor';
        }
        elseif (isset($versao_data['versao_nova'])) {
            $versao_whatsapp = $versao_data['versao_nova'];
            $fonte_versao = 'monitor';
        }
    }
}

// Configurações padrão
$config = [
    'empresa' => '',
    'menu' => '',
    'boleto_url' => '',
    'atendente_numero' => '',
    'tempo_atendimento_humano' => 30,
    'tempo_inatividade_global' => 30,
    'feriados_ativos' => 'Sim',
    'feriado_local_ativado' => 'Não',
    'feriado_local_mensagem' => "📅 *Comunicado importante:*\nHoje é feriado local e não estamos funcionando.\nRetornaremos amanhã em horário comercial.\n\nO acesso a faturas PIX continua disponível 24/7! 😊",
    'telegram_ativado' => 'Não',
    'telegram_token' => '',
    'telegram_chat_id' => '',
    'telegram_notificar_conexao' => 'Sim',
    'telegram_notificar_desconexao' => 'Sim',
    'telegram_notificar_qr' => 'Sim',
    'mkauth_url' => 'https://www.SEU_DOMINIO.com.br/api',
    'mkauth_client_id' => '',
    'mkauth_client_secret' => '',
    'planos_ativos' => 'Sim',
    'planos_mensagem' => "📶 *100 megas* 💰 R$ 59,90 - FIBRA\n📶 *200 megas* 💰 R$ 69,90 - FIBRA\n📶 *300 megas* 💰 R$ 89,90 - FIBRA\n\n*Taxa de instalação* 💰 R$ 50,00 à vista ou R$ 60,00 no cartão em 2x.\n\n*Tá esperando o que?* 😱\n\n2️⃣ Falar com um Atendente    5️⃣ Assine Já!",
    'link_assinatura' => 'https://www.weblinetelecom.com.br/cadastro.hhvm'
];

// Carregar configurações existentes
if (file_exists($configPath)) {
    $json = file_get_contents($configPath);
    $data = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $config = array_merge($config, $data);
    }
}

// =====================================================================
// BLOCO 13 - PROCESSAMENTO DO FORMULÁRIO DE CONFIGURAÇÃO (COM CSRF)
// =====================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_config'])) {
    // Verificar CSRF
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die('CSRF token inválido');
    }
    
    $config['empresa'] = trim($_POST['empresa'] ?? '');
    $config['menu'] = trim($_POST['menu'] ?? '');
    $config['boleto_url'] = trim($_POST['boleto_url'] ?? '');
    $config['atendente_numero'] = trim($_POST['atendente_numero'] ?? '');
    $config['tempo_atendimento_humano'] = intval($_POST['tempo_atendimento_humano'] ?? 30);
    $config['tempo_inatividade_global'] = intval($_POST['tempo_inatividade_global'] ?? 30);
    $config['feriados_ativos'] = trim($_POST['feriados_ativos'] ?? 'Sim');
    $config['feriado_local_ativado'] = trim($_POST['feriado_local_ativado'] ?? 'Não');
    $config['feriado_local_mensagem'] = trim($_POST['feriado_local_mensagem'] ?? '');
    $config['telegram_ativado'] = trim($_POST['telegram_ativado'] ?? 'Não');
    $config['telegram_token'] = trim($_POST['telegram_token'] ?? '');
    $config['telegram_chat_id'] = trim($_POST['telegram_chat_id'] ?? '');
    $config['telegram_notificar_conexao'] = isset($_POST['telegram_notificar_conexao']) ? 'Sim' : 'Não';
    $config['telegram_notificar_desconexao'] = isset($_POST['telegram_notificar_desconexao']) ? 'Sim' : 'Não';
    $config['telegram_notificar_qr'] = isset($_POST['telegram_notificar_qr']) ? 'Sim' : 'Não';
    $config['mkauth_url'] = trim($_POST['mkauth_url'] ?? '');
    $config['mkauth_client_id'] = trim($_POST['mkauth_client_id'] ?? '');
    $config['mkauth_client_secret'] = trim($_POST['mkauth_client_secret'] ?? '');
    $config['planos_ativos'] = trim($_POST['planos_ativos'] ?? 'Sim');
    $config['planos_mensagem'] = trim($_POST['planos_mensagem'] ?? '');
    $config['link_assinatura'] = trim($_POST['link_assinatura'] ?? '');

    // Validações
    if ($config['tempo_atendimento_humano'] <= 0) $config['tempo_atendimento_humano'] = 30;
    if ($config['tempo_inatividade_global'] <= 0) $config['tempo_inatividade_global'] = 30;
    
    foreach (['feriados_ativos', 'feriado_local_ativado', 'telegram_ativado', 'telegram_notificar_conexao', 'telegram_notificar_desconexao', 'telegram_notificar_qr', 'planos_ativos'] as $campo) {
        $config[$campo] = in_array($config[$campo], ['Sim', 'Não']) ? $config[$campo] : 'Sim';
    }
    
    if (empty($config['feriado_local_mensagem'])) {
        $config['feriado_local_mensagem'] = "📅 *Comunicado importante:*\nHoje é feriado local e não estamos funcionando.\nRetornaremos amanhã em horário comercial.\n\nO acesso a faturas PIX continua disponível 24/7! 😊";
    }
    
    if (empty($config['planos_mensagem'])) {
        $config['planos_mensagem'] = "📶 *100 megas* 💰 R$ 59,90 - FIBRA\n📶 *200 megas* 💰 R$ 69,90 - FIBRA\n📶 *300 megas* 💰 R$ 89,90 - FIBRA\n\n*Taxa de instalação* 💰 R$ 50,00 à vista ou R$ 60,00 no cartão em 2x.\n\n*Tá esperando o que?* 😱\n\n2️⃣ Falar com um Atendente    5️⃣ Assine Já!";
    }
    
    if (empty($config['link_assinatura'])) {
        $config['link_assinatura'] = 'https://www.weblinetelecom.com.br/cadastro.hhvm';
    }

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    if (file_put_contents($configPath, $json) !== false) {
        if (file_exists($pixPath)) {
            $pixContent = file_get_contents($pixPath);
            
            $urlProvedor = "https://www.SEU_PROVEDOR.com.br";
            $apiBase = "https://www.SEU_PROVEDOR.com.br/api/";
            
            if (!empty($config['mkauth_url'])) {
                $urlParts = parse_url($config['mkauth_url']);
                if (isset($urlParts['host'])) {
                    $dominio = $urlParts['host'];
                    $dominio = preg_replace('/^www\./', '', $dominio);
                    $urlProvedor = "https://www." . $dominio;
                    $apiBase = rtrim($config['mkauth_url'], '/') . '/';
                }
            }
            
            $clientId = !empty($config['mkauth_client_id']) ? addslashes($config['mkauth_client_id']) : 'SEU_ID_API';
            $clientSecret = !empty($config['mkauth_client_secret']) ? addslashes($config['mkauth_client_secret']) : 'SEU_SECRET_API';
            
            $pixContent = preg_replace('/\$URL_PROV\s*=\s*"[^"]*"/', '$URL_PROV = "' . $urlProvedor . '"', $pixContent);
            $pixContent = preg_replace('/\$API_BASE\s*=\s*"[^"]*"/', '$API_BASE = "' . $apiBase . '"', $pixContent);
            $pixContent = preg_replace('/\$CLIENT_ID\s*=\s*"[^"]*"/', '$CLIENT_ID = "' . $clientId . '"', $pixContent);
            $pixContent = preg_replace('/\$CLIENT_SECRET\s*=\s*"[^"]*"/', '$CLIENT_SECRET = "' . $clientSecret . '"', $pixContent);
            
            file_put_contents($pixPath, $pixContent);
            $mensagem = 'Configurações salvas com sucesso e pix.php atualizado!';
        } else {
            $mensagem = 'Configurações salvas, mas pix.php não encontrado!';
        }
        
        header('Location: index.php?salvo=1&aba=' . $abaAtiva);
        exit;
    } else {
        $erro = 'Erro ao salvar configurações';
    }
}

// =====================================================================
// BLOCO 14 - ENDPOINTS DE TESTE E LOG (COM RATE LIMITING)
// =====================================================================

/**
 * Endpoint: Testar Telegram
 * Uso: ?testar_telegram=1
 */
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

/**
 * Endpoint: Obter log (COM VERIFICAÇÃO DE SESSÃO OBRIGATÓRIA)
 * Uso: ?get_log=1&linhas=500&buscar=termo
 */
if (isset($_GET['get_log'])) {
    header('Content-Type: text/plain');
    
    // VERIFICAÇÃO OBRIGATÓRIA DE SESSÃO
    if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
        header('HTTP/1.0 401 Unauthorized');
        echo "Sessão expirada";
        exit;
    }
    
    // Verificar se a sessão expirou (mesmo com ignore_timeout)
    if ($_SESSION['auto_logout'] === true && !($_SESSION['ignore_log_timeout'] ?? false)) {
        $tempo_inativo = time() - ($_SESSION['ultimo_acesso'] ?? time());
        if ($tempo_inativo > TEMPO_SESSAO) {
            header('HTTP/1.0 401 Unauthorized');
            echo "Sessão expirada por inatividade";
            exit;
        }
    }
    
    // Atualizar último acesso se ignore_timeout estiver ativo
    if (($_SESSION['ignore_log_timeout'] ?? false) && $_SESSION['auto_logout'] === true) {
        $_SESSION['ultimo_acesso'] = time();
    }
    
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
    
    // Tail mode
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
    
    // Modo normal
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

/**
 * Ação: Limpar log (COM CSRF)
 * Uso: POST com acao_log=limpar e csrf_token
 */
if (isset($_POST['acao_log']) && $_POST['acao_log'] === 'limpar' && file_exists($logPath)) {
    // Verificar CSRF
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die('CSRF token inválido');
    }
    
    if (is_writable($logPath)) {
        if (file_put_contents($logPath, "=== Log reiniciado em " . date('d/m/Y H:i:s') . " ===\n") !== false) {
            $mensagem = 'Log limpo com sucesso!';
        } else {
            $erro = 'Erro ao limpar o arquivo de log!';
        }
    } else {
        $erro = 'Arquivo de log sem permissão de escrita!';
    }
}

// =====================================================================
// BLOCO 15 - STATUS DO WHATSAPP
// =====================================================================

$status = 'offline';
if (file_exists($statusPath)) {
    $st = json_decode(file_get_contents($statusPath), true);
    if (!empty($st['status'])) $status = $st['status'];
}

/**
 * Endpoint: API de status
 * Uso: ?check_status=1 ou ?api_status=1
 */
if (isset($_GET['check_status']) || isset($_GET['api_status'])) {
    header('Content-Type: text/plain');
    echo $status;
    exit;
}

// Imagem conforme status
$imgSrc = 'qrcode_view.php';
if ($status === 'online') {
    $imgSrc = '/qrcode_online.png';
} elseif ($status === 'offline') {
    $imgSrc = '/qrcode_wait.png';
}

// =====================================================================
// BLOCO 16 - FORMATAÇÃO DO TELEFONE
// =====================================================================

$telefone_formatado = '(xx)xxxx-xxxx';
if (!empty($config['atendente_numero'])) {
    $numero_limpo = preg_replace('/[^0-9]/', '', $config['atendente_numero']);
    $codigo_pais = '';
    $codigo_pais_numerico = '';
    $numero_sem_pais = $numero_limpo;
    
    if (strlen($numero_limpo) >= 12) {
        $codigo_pais_numerico = substr($numero_limpo, 0, 2);
        $codigo_pais = '+' . $codigo_pais_numerico;
        $numero_sem_pais = substr($numero_limpo, 2);
    }
    
    if ($codigo_pais_numerico == '55') {
        $ddd = '';
        $numero_local = $numero_sem_pais;
        
        if (strlen($numero_sem_pais) >= 10) {
            $ddd = substr($numero_sem_pais, 0, 2);
            $numero_local = substr($numero_sem_pais, 2);
        }
        
        if (!empty($numero_local)) {
            $primeiro_digito = (int) substr($numero_local, 0, 1);
            if (in_array($primeiro_digito, [6, 7, 8, 9]) && $primeiro_digito != 9) {
                $numero_local = '9' . $numero_local;
            }
        }
        
        if (!empty($ddd) && !empty($numero_local)) {
            $primeiro_digito_final = (int) substr($numero_local, 0, 1);
            if (in_array($primeiro_digito_final, [6, 7, 8, 9])) {
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
                if (strlen($numero_local) >= 8) {
                    $parte1 = substr($numero_local, 0, 4);
                    $parte2 = substr($numero_local, 4, 4);
                    $telefone_formatado = $codigo_pais . '(' . $ddd . ')' . $parte1 . '-' . $parte2;
                }
            }
        }
    } else {
        if (!empty($codigo_pais)) {
            $telefone_formatado = $codigo_pais . ' ' . $numero_sem_pais;
        } else {
            $telefone_formatado = $numero_limpo;
        }
    }
}

$nome_empresa = !empty($config['empresa']) ? $config['empresa'] : 'PROVEDOR';

// =====================================================================
// BLOCO 17 - MENSAGEM DE SUCESSO
// =====================================================================

if (isset($_GET['salvo']) && $_GET['salvo'] == 1) {
    if (empty($mensagem)) $mensagem = 'Configurações salvas com sucesso!';
}

// Gerar CSRF token para o formulário
$csrf_token = generateCsrfToken();

// =====================================================================
// BLOCO 18 - INÍCIO DO HTML
// =====================================================================
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Bot WhatsApp – <?= htmlspecialchars($nome_empresa) ?> <?= htmlspecialchars($telefone_formatado) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- Service Worker -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('service-worker.js?v=<?= time() ?>')
            .then(function(registration) {
                console.log('Service Worker registrado com sucesso:', registration.scope);
            })
            .catch(function(error) {
                console.log('Falha ao registrar Service Worker:', error);
            });
    });
}
</script>

<style>
/* ==================== ESTILOS GLOBAIS ==================== */
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
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:15px;
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

/* ==================== VERSÃO DO WHATSAPP ==================== */
.whatsapp-version {
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px 15px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}

.version-info {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.version-badge {
    background: #2563eb;
    color: white;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.version-number {
    font-family: 'Courier New', monospace;
    font-size: 14px;
    font-weight: 600;
    color: #1f2937;
}

.version-status {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}

.version-status .atualizada {
    color: #059669;
    background: #d1fae5;
    padding: 4px 8px;
    border-radius: 16px;
    font-weight: 600;
}

.version-status .desatualizada {
    color: #b45309;
    background: #ffedd5;
    padding: 4px 8px;
    border-radius: 16px;
    font-weight: 600;
}

.version-detectada {
    color: #6b7280;
    font-size: 12px;
}

.version-fonte {
    background: #e5e7eb;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    color: #4b5563;
    font-weight: 500;
    display: inline-block;
    margin-left: 8px;
    white-space: nowrap;
}

.version-fonte::before {
    content: "📡";
    margin-right: 4px;
    font-size: 10px;
}

/* Responsividade para mobile */
@media (max-width: 700px) {
    .version-info {
        gap: 8px;
    }
    
    .version-fonte {
        margin-left: 0;
        margin-top: 5px;
        width: 100%;
        text-align: center;
    }
}

/* ==================== ESTILOS DO TERMINAL ==================== */
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
    color: #ffffff;
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
    border-color: #ffff;
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

/* ==================== ABAS DE NAVEGAÇÃO ==================== */
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
    flex-wrap: wrap;
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

/* ==================== ESTILOS DO DASHBOARD PIX ==================== */
.header-dashboard {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 15px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 6px rgba(0,0,0,.08);
    flex-wrap: wrap;
    gap: 15px;
}

.dashboard-title h1 {
    margin: 0;
    font-size: 24px;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
}

.dashboard-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 15px;
    color: #666;
    font-size: 14px;
    background: #f8f9fa;
    padding: 8px 12px;
    border-radius: 6px;
    border-left: 3px solid #00b894;
}

.user-nivel {
    display: inline-block;
    background: <?= $_SESSION['nivel'] === 'admin' ? '#3498db' : '#2ecc71' ?>;
    color: white;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: bold;
}

.btn-sair, .btn-admin, .btn-alterar-senha, .btn-autologout {
    padding: 10px 18px;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: bold;
    white-space: nowrap;
}

.btn-sair { background: #e74c3c; }
.btn-sair:hover { background: #c0392b; transform: translateY(-2px); }
.btn-admin { background: #3498db; }
.btn-admin:hover { background: #2980b9; transform: translateY(-2px); }
.btn-alterar-senha { background: #9b59b6; }
.btn-alterar-senha:hover { background: #8e44ad; transform: translateY(-2px); }
.btn-autologout { background: <?= $_SESSION['auto_logout'] ? '#27ae60' : '#dc2626' ?>; }
.btn-autologout:hover { background: <?= $_SESSION['auto_logout'] ? '#219653' : '#a11806' ?>; transform: translateY(-2px); }

.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 12px;
    background: <?= $_SESSION['auto_logout'] ? '#d5f4e6' : '#fcc7c7' ?>;
    color: <?= $_SESSION['auto_logout'] ? '#27ae60' : '#dc2626' ?>;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: <?= $_SESSION['auto_logout'] ? '#27ae60' : '#dc2626' ?>;
}

.cards {
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:20px
}
.card-dash {
    flex:1;
    min-width:140px;
    background:#fff;
    padding:16px;
    border-radius:10px;
    text-align:center;
    box-shadow:0 2px 6px rgba(0,0,0,.08);
    transition:transform 0.3s;
}
.card-dash:hover{ transform:translateY(-3px); }
.card-dash b{ font-size:26px }

.box {
    background:#fff;
    padding:16px;
    border-radius:10px;
    box-shadow:0 2px 6px rgba(0,0,0,.08);
    margin-bottom:20px;
}
select{
    padding:8px;
    font-size:14px;
    border-radius:6px;
    border:1px solid #ddd;
    width:100%;
    max-width:300px;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:12px;
    font-size:13px
}
th,td{
    padding:10px;
    border-bottom:1px solid #e0e0e0;
    text-align:left
}
th{
    background:#f7f7f7;
    font-weight:600;
}
tr:hover{ background:#f9f9f9; }

.acoes{
    text-align:center;
    margin:15px 0;
}
.btn-acoes{
    display:inline-block;
    padding:8px 15px;
    margin:0 5px;
    background:#3498db;
    color:white;
    text-decoration:none;
    border-radius:4px;
    font-size:14px;
    border:none;
    cursor:pointer;
    transition:all 0.3s;
}
.btn-hoje{ background:#00b894; }
.btn-ontem{ background:#3498db; }
.btn-exportar{ background:#9b59b6; }
.btn-acoes:hover{ opacity:0.9; transform:translateY(-2px); }

.info-box {
    background: #e8f8f5;
    padding: 15px;
    border-radius: 8px;
    margin: 20px 0;
    text-align: center;
    border-left: 4px solid #00b894;
}

.footer-dashboard {
    text-align: center;
    margin-top: 30px;
    padding-top: 15px;
    border-top: 1px solid #e0e0e0;
    color: #666;
    font-size: 12px;
}

.session-timer {
    position: fixed;
    bottom: 10px;
    left: 10px;
    background: rgba(0,0,0,0.7);
    color: white;
    padding: 8px 12px;
    border-radius: 5px;
    font-size: 12px;
    z-index: 1000;
    font-family: monospace;
    min-width: 140px;
    transition: all 0.3s ease;
}

.modal-dashboard {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

.modal-content-dashboard {
    background: white;
    padding: 30px;
    border-radius: 10px;
    max-width: 500px;
    width: 100%;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.modal-header-dashboard {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid #9b59b6;
}

.modal-header-dashboard h3 {
    margin: 0;
    color: #2c3e50;
    font-size: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.close-modal-dashboard {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #95a5a6;
    line-height: 1;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.close-modal-dashboard:hover {
    background: #f5f5f5;
    color: #7f8c8d;
}

.form-group-modal {
    margin-bottom: 20px;
}

.form-group-modal label {
    display: block;
    margin-bottom: 8px;
    color: #555;
    font-weight: 600;
    font-size: 14px;
}

.form-control-modal {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 15px;
    min-height: 44px;
}

.form-control-modal:focus {
    outline: none;
    border-color: #9b59b6;
    box-shadow: 0 0 0 2px rgba(155, 89, 182, 0.2);
}

.btn-modal {
    padding: 14px 20px;
    border: none;
    border-radius: 6px;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex: 1;
}

.btn-modal-cancelar { background: #95a5a6; color: white; }
.btn-modal-alterar { background: #9b59b6; color: white; }

.btn-modal:hover {
    opacity: 0.9;
    transform: translateY(-2px);
}

.mensagem-senha {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    font-size: 14px;
    z-index: 1100;
    max-width: 400px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    animation: slideIn 0.3s ease-out;
}

.mensagem-sucesso {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.mensagem-erro {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(100%);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideOut {
    from {
        opacity: 1;
        transform: translateX(0);
    }
    to {
        opacity: 0;
        transform: translateX(100%);
    }
}

/* ==================== RESPONSIVIDADE ==================== */
@media(max-width:700px){
    .container{
        grid-template-columns:1fr;
    }
    .qr-box img{
        width:100%;
        height:auto;
        max-width:320px;
    }
    .header-dashboard{
        flex-direction:column;
        align-items:stretch;
        text-align:center;
    }
    .dashboard-title h1{
        text-align:center;
        justify-content:center;
    }
    .dashboard-controls{
        justify-content:center;
        flex-direction:column;
        width:100%;
    }
    .user-info{
        flex-direction:column;
        text-align:center;
        gap:5px;
        width:100%;
    }
    .btn-sair,.btn-autologout,.btn-admin,.btn-alterar-senha{
        width:100%;
        justify-content:center;
    }
    table,thead,tbody,th,td,tr{display:block}
    thead{display:none}
    tr{margin-bottom:12px;border:1px solid #e0e0e0;border-radius:6px;padding:10px}
    td{
        padding:6px 0;
        border:none
    }
    td::before{
        content: attr(data-label);
        font-weight:bold;
        display:block;
        color:#555;
        margin-bottom:2px;
    }
    .session-timer{
        left:5px;
        bottom:5px;
        min-width:120px;
        font-size:11px;
    }
}
</style>
</head>

<body>

<header>
    <span>🤖 Bot WhatsApp - <?= htmlspecialchars($nome_empresa) ?> <?= htmlspecialchars($telefone_formatado) ?></span>
    <div style="display:flex; gap:10px; align-items:center;">
        <span style="font-size:14px; background:#2d3748; padding:5px 10px; border-radius:5px;">
            👤 <?= htmlspecialchars($_SESSION['usuario']) ?> (<?= $_SESSION['nivel'] ?>) | IP: <?= htmlspecialchars(getRealIp()) ?>
        </span>
        <button onclick="globalLogout()" style="background:#dc2626;color:#fff;padding:8px 14px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;border:none;cursor:pointer;">
            Sair
        </button>
    </div>
</header>

<!-- ==================== ABAS DE NAVEGAÇÃO ==================== -->
<div class="tabs-container">
    <div class="tabs">
        <a href="?aba=config" class="tab <?= $abaAtiva === 'config' ? 'active' : '' ?>">⚙️ Configurações</a>
        <a href="?aba=log" class="tab <?= $abaAtiva === 'log' ? 'active' : '' ?>">📋 Exibir Log</a>
        <a href="?aba=dashboard" class="tab <?= $abaAtiva === 'dashboard' ? 'active' : '' ?>">📊 Consultas Fatura</a>
        <?php if ($_SESSION['nivel'] === 'admin'): ?>
        <a href="?aba=usuarios" class="tab <?= $abaAtiva === 'usuarios' ? 'active' : '' ?>">👥 Usuários</a>
        <?php endif; ?>
    </div>
</div>

<!-- ===================================================================== -->
<!-- BLOCO 19 - CONTEÚDO DA ABA CONFIGURAÇÕES
<!-- ===================================================================== -->

<?php if ($abaAtiva === 'config'): ?>

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

    <!-- 1º Mensagem das configurações globais -->
    <?php if ($mensagem): ?>
        <div id="msg" class="alert success"><?= $mensagem ?></div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert error"><?= $erro ?></div>
    <?php endif; ?>

    <!-- 2º Mensagem do Auto-Logout (movida para o topo) -->
    <?php if (isset($_SESSION['mensagem_tempo'])): ?>
    <div class="alert <?= $_SESSION['tipo_mensagem_tempo'] === 'sucesso' ? 'success' : 'error' ?>" style="margin-bottom: 15px;">
        <?= $_SESSION['mensagem_tempo'] ?>
    </div>
    <?php 
        unset($_SESSION['mensagem_tempo']);
        unset($_SESSION['tipo_mensagem_tempo']);
    endif; 
    ?>

    <!-- 3º Mensagem do reset (se ainda existir) -->
    <?php if (isset($_SESSION['mensagem_reset'])): ?>
    <div class="alert <?= $_SESSION['tipo_mensagem_reset'] === 'sucesso' ? 'success' : 'error' ?>" style="margin-bottom: 15px;">
        <?= $_SESSION['mensagem_reset'] ?>
    </div>
    <?php 
        unset($_SESSION['mensagem_reset']);
        unset($_SESSION['tipo_mensagem_reset']);
    endif; 
    ?>
        <!-- ===== FIM DO PASSO 4 ===== -->

        <!-- VERSÃO SIMPLIFICADA DO WHATSAPP -->
        <div class="whatsapp-version">
            <div class="version-info">
                <span class="version-badge">📱 WhatsApp</span>
                <span class="version-number"><?= htmlspecialchars($versao_whatsapp) ?></span>
                <span class="version-fonte">via <?= htmlspecialchars($fonte_versao) ?></span>
            </div>
            <div class="version-status">
                <span class="atualizada">✅ Atualizada</span>
            </div>
        </div>

        <!-- FORMULÁRIO PRINCIPAL COM CSRF -->
        <form method="post">
            <input type="hidden" name="salvar_config" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            
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

            <!-- SEÇÃO: Feriado Local Personalizável -->
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

            <!-- SEÇÃO: Configurações do Telegram -->
            <div class="config-section" style="border-left-color: #0088cc;">
                <h3>
                    <span>📱</span> Configurações do Telegram
                </h3>
                <p style="margin-top: 0; font-size: 14px; color: #6b7280; margin-bottom: 20px;">
                    Receba notificações sobre o status do bot diretamente no Telegram
                </p>
                
                <label>🤖 Ativar notificações por Telegram?</label>
                <div class="radio-group" style="margin-bottom: 20px;">
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
                    value="<?= htmlspecialchars($config['telegram_token'] ?? '') ?>"
                    placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz"
                    style="margin-bottom: 5px;"
                >
                <small style="display: block; margin-bottom: 15px; color: #6b7280; font-size: 12px;">
                    Obtenha com <a href="https://t.me/BotFather" target="_blank" style="color: #0088cc;">@BotFather</a> no Telegram
                </small>

                <label>👤 Chat ID (ou Canal):</label>
                <input 
                    type="text" 
                    name="telegram_chat_id" 
                    value="<?= htmlspecialchars($config['telegram_chat_id'] ?? '-1003032257081') ?>"
                    placeholder="-1001234567890 ou 123456789"
                    style="margin-bottom: 5px;"
                >
                <small style="display: block; margin-bottom: 20px; color: #6b7280; font-size: 12px;">
                    Obtenha com <a href="https://t.me/userinfobot" target="_blank" style="color: #0088cc;">@userinfobot</a> no Telegram
                </small>

                <!-- Área de notificações -->
                <div style="background: #f0f9ff; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 15px; font-weight: 600; color: #1f2937;">
                        🔔 Notificações a enviar:
                    </label>
                    
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; width: 100%;">
                            <input type="checkbox" name="telegram_notificar_conexao" value="Sim" 
                                <?= (isset($config['telegram_notificar_conexao']) && $config['telegram_notificar_conexao'] === 'Sim') ? 'checked' : '' ?>
                                style="width: 18px; height: 18px; margin: 0; flex-shrink: 0;">
                            <span style="font-size: 14px; line-height: 1.4;">✅ Quando conectar</span>
                        </label>
                        
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; width: 100%;">
                            <input type="checkbox" name="telegram_notificar_desconexao" value="Sim" 
                                <?= (isset($config['telegram_notificar_desconexao']) && $config['telegram_notificar_desconexao'] === 'Sim') ? 'checked' : '' ?>
                                style="width: 18px; height: 18px; margin: 0; flex-shrink: 0;">
                            <span style="font-size: 14px; line-height: 1.4;">❌ Quando desconectar</span>
                        </label>
                        
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; width: 100%;">
                            <input type="checkbox" name="telegram_notificar_qr" value="Sim" 
                                <?= (isset($config['telegram_notificar_qr']) && $config['telegram_notificar_qr'] === 'Sim') ? 'checked' : '' ?>
                                style="width: 18px; height: 18px; margin: 0; flex-shrink: 0;">
                            <span style="font-size: 14px; line-height: 1.4;">📱 Quando QR Code for gerado</span>
                        </label>
                    </div>
                </div>

                <!-- Botão de teste -->
                <div style="text-align: center; margin-top: 10px; margin-bottom: 5px;">
                    <button type="button" onclick="testarTelegram()" style="background: #28a745; padding: 12px 25px; font-size: 15px; display: inline-flex; align-items: center; gap: 8px; margin: 0;">
                        📤 Testar Conexão Telegram
                    </button>
                </div>
                
                <!-- Área de resultado do teste -->
                <div id="telegramTestResult" style="margin-top: 15px; padding: 10px; border-radius: 6px; font-size: 13px; text-align: center;"></div>
            </div>

            <!-- SEÇÃO: Configurações MK-Auth -->
            <div class="config-section" style="border-left-color: #3b82f6; margin-top: 20px;">
                <h3>
                    <span>🔐</span> Configurações MK-Auth (Verificação de Clientes)
                </h3>
                <p style="margin-top: 0; font-size: 14px; color: #6b7280; margin-bottom: 20px;">
                    Configurações para verificação de CPF/CNPJ na base de clientes antes de gerar link PIX
                </p>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">URL do MK-Auth</label>
                    <input 
                        type="url" 
                        name="mkauth_url" 
                        value="<?= htmlspecialchars($config['mkauth_url']) ?>"
                        placeholder="https://www.SEU_DOMINIO.com.br/api"
                        style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; margin-bottom: 5px;"
                    >
                    <small style="display: block; margin-top: 5px; color: #6b7280; font-size: 12px;">
                        URL base do sistema MK-Auth (deve terminar com / se for API completa)
                    </small>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Client ID</label>
                    <input 
                        type="text" 
                        name="mkauth_client_id" 
                        value="<?= htmlspecialchars($config['mkauth_client_id']) ?>"
                        placeholder="c582c8ede2c9169c64f29cxxxxxxxxxx"
                        style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; margin-bottom: 5px;"
                    >
                    <small style="display: block; margin-top: 5px; color: #6b7280; font-size: 12px;">
                        Identificador do cliente para autenticação na API
                    </small>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Client Secret</label>
                    <div style="display: flex; align-items: center; gap: 10px; width: 100%;">
                        <input 
                            type="password" 
                            name="mkauth_client_secret" 
                            id="mkauth_client_secret"
                            value="<?= htmlspecialchars($config['mkauth_client_secret']) ?>"
                            placeholder="9d2367fbf45d2e89d8ee8cb92ca3c0xxxxxxxxxx"
                            style="flex: 1; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; margin: 0;"
                        >
                        <button 
                            type="button" 
                            onclick="toggleClientSecretVisibility()"
                            style="background: #f0f0f0; border: 1px solid #d1d5db; border-radius: 8px; cursor: pointer; font-size: 18px; padding: 10px 15px; height: 42px; display: flex; align-items: center; justify-content: center; margin: 0;"
                            title="Mostrar/Esconder Client Secret"
                        >
                            👁️
                        </button>
                    </div>
                    <small style="display: block; margin-top: 5px; color: #6b7280; font-size: 12px;">
                        Senha de acesso à API (chave secreta)
                    </small>
                </div>

                <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 8px; font-size: 13px; margin-top: 15px;">
                    <p style="margin: 0 0 8px 0; color: #92400e; font-weight: 600;">⚠️ Importante:</p>
                    <ul style="margin: 0; padding-left: 20px; color: #92400e;">
                        <li>As credenciais MK-Auth serão sincronizadas automaticamente com o arquivo pix.php</li>
                        <li>Se as credenciais não forem configuradas, o bot NÃO permitirá acesso direto ao PIX</li>
                        <li>Configure corretamente para filtrar apenas clientes da base</li>
                    </ul>
                </div>
            </div>

            <!-- SEÇÃO: Configurações de Planos e Assinatura -->
            <div class="config-section" style="border-left-color: #00b894; margin-top: 20px;">
                <h3>
                    <span>📶</span> Configurações de Planos (Opção 3 - Não sou Cliente!)
                </h3>
                <p style="margin-top: 0; font-size: 14px; color: #6b7280; margin-bottom: 20px;">
                    Configure os planos e o link de assinatura para a opção 3 do menu
                </p>

                <label style="margin-top: 5px;">🎯 Ativar opção de planos?</label>
                <div class="radio-group" style="margin-bottom: 20px;">
                    <label class="radio-option">
                        <input type="radio" name="planos_ativos" value="Sim" 
                            <?= (isset($config['planos_ativos']) && $config['planos_ativos'] === 'Sim') ? 'checked' : '' ?>>
                        Sim (ativar opção 3)
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="planos_ativos" value="Não"
                            <?= (!isset($config['planos_ativos']) || $config['planos_ativos'] === 'Não') ? 'checked' : '' ?>>
                        Não (desativado)
                    </label>
                </div>

                <label>📝 Mensagem de Planos:</label>
                <textarea 
                    name="planos_mensagem" 
                    placeholder="Digite os planos e valores no formato desejado..."
                    style="height: 200px; font-family: monospace;"
                ><?= htmlspecialchars($config['planos_mensagem'] ?? '📶 *100 megas* 💰 R$ 59,90 - FIBRA\n📶 *200 megas* 💰 R$ 69,90 - FIBRA\n📶 *300 megas* 💰 R$ 89,90 - FIBRA\n\n*Taxa de instalação* 💰 R$ 50,00 à vista ou R$ 60,00 no cartão em 2x.\n\n*Tá esperando o que?* 😱\n\n2️⃣ Falar com um Atendente    5️⃣ Assine Já!') ?></textarea>
                
                <small style="display: block; margin-bottom: 15px; color: #6b7280;">
                    Use \n para quebrar linha. Disponível: *texto* para negrito.
                </small>

                <label>🔗 Link de Assinatura:</label>
                <input 
                    type="url" 
                    name="link_assinatura" 
                    value="<?= htmlspecialchars($config['link_assinatura'] ?? 'https://www.weblinetelecom.com.br/cadastro.hhvm') ?>"
                    placeholder="https://www.seusite.com.br/cadastro"
                    style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; margin-bottom: 5px;"
                >
                <small style="display: block; margin-bottom: 15px; color: #6b7280;">
                    Link para onde o cliente será direcionado ao escolher a opção 5
                </small>

                <div style="background: #e8f8f5; border-left: 4px solid #00b894; padding: 15px; border-radius: 8px; font-size: 13px;">
                    <p style="margin: 0 0 8px 0; color: #0b5e4b; font-weight: 600;">📌 Como funciona:</p>
                    <ul style="margin: 0; padding-left: 20px; color: #0b5e4b;">
                        <li>O cliente verá os planos configurados acima</li>
                        <li>Poderá escolher entre "Falar com Atendente" (opção 2) ou "Assinar" (opção 5)</li>
                        <li>A opção 5 envia o link configurado</li>
                        <li>Use \n para quebras de linha na mensagem</li>
                    </ul>
                </div>
            </div>

            <button type="submit" style="margin-top:20px; padding:12px 18px; background:#2563eb; color:white; border:none; border-radius:10px; font-weight:bold; cursor:pointer; width:100%; font-size:16px;">
                💾 Salvar Configurações Globais
            </button>
        </form>

        <!-- FORMULÁRIO DE AUTO-LOGOUT UNIFICADO -->
<div class="config-section" style="border-left-color: #f39c12; margin-top: 20px;">
    <h3><span>⏰</span> Configurações de Auto-Logout</h3>

<!-- ===== SEU CÓDIGO ORIGINAL (mantém igual) ===== -->
<?php if (isset($_SESSION['mensagem_tempo'])): ?>
    <div class="alert success" style="margin-bottom: 15px; background: #28a745; color: white; padding: 15px; border-radius: 5px;">
        ✅ <?= $_SESSION['mensagem_tempo'] ?>
    </div>
    <?php 
        unset($_SESSION['mensagem_tempo']);
        unset($_SESSION['tipo_mensagem_tempo']);
    endif; 
?>
    
    <?php if (isset($_SESSION['mensagem_reset'])): ?>
    <div class="alert <?= $_SESSION['tipo_mensagem_reset'] === 'sucesso' ? 'success' : 'error' ?>" style="margin-bottom: 15px;">
        <?= $_SESSION['mensagem_reset'] ?>
    </div>
    <?php 
        unset($_SESSION['mensagem_reset']);
        unset($_SESSION['tipo_mensagem_reset']);
    endif; 
    ?>
    
    <form method="post" onsubmit="return validarTempoAutologout()">
        <input type="hidden" name="salvar_autologout_config" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        
        <!-- Botão de toggle do Auto-logout -->
        <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e5e7eb;">
            <button type="button" onclick="toggleAutoLogout()" class="btn-autologout" style="background: <?= $_SESSION['auto_logout'] ? '#27ae60' : '#dc2626' ?>; padding: 10px 20px; width: auto; min-width: 200px;">
                <?php if ($_SESSION['auto_logout']): ?>
                <span style="font-size:16px;">⏰</span> Auto-logout ON
                <?php else: ?>
                <span style="font-size:16px;">🔒</span> Auto-logout OFF
                <?php endif; ?>
            </button>
            <div style="margin-top: 10px; font-size: 13px; color: #666;">
                <p>• <strong>ON:</strong> A sessão expirará automaticamente após o tempo de inatividade</p>
                <p>• <strong>OFF:</strong> A sessão NUNCA expirará (não recomendado para produção)</p>
            </div>
        </div>
        
        <!-- Tempo de inatividade -->
        <label for="tempo_autologout">⏱️ Tempo de inatividade para logout automático:</label>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px;">
            <input 
                type="number" 
                id="tempo_autologout" 
                name="tempo_autologout" 
                value="<?= TEMPO_SESSAO ?>"
                min="60"
                max="86400"
                step="60"
                style="width: 150px; padding: 8px; border: 1px solid #d1d5db; border-radius: 8px;"
                required
            >
            <span>segundos</span>
        </div>
        
        <!-- Reset por atividade -->
        <div style="margin-bottom: 20px; padding-top: 10px;">
            <label>🖱️ Resetar timer quando o usuário interagir?</label>
            <div class="radio-group" style="margin: 10px 0 15px 0;">
                <label class="radio-option">
                    <input type="radio" name="reset_atividade" value="Sim" <?= (isset($config['reset_timer_on_activity']) && $config['reset_timer_on_activity'] === true) ? 'checked' : 'checked' ?>>
                    Sim (resetar ao mover mouse, clicar, digitar, etc)
                </label>
                <label class="radio-option">
                    <input type="radio" name="reset_atividade" value="Não" <?= (isset($config['reset_timer_on_activity']) && $config['reset_timer_on_activity'] === false) ? 'checked' : '' ?>>
                    Não (não resetar, sessão expira após tempo fixo)
                </label>
            </div>
            
            <div style="background: #fef9e7; padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 15px;">
                <p style="margin: 0;"><strong>ℹ️ Como funciona:</strong></p>
                <p style="margin: 5px 0 0 0;">• <strong>Sim:</strong> A cada movimento do mouse, clique ou tecla, o timer é resetado</p>
                <p style="margin: 2px 0 0 0;">• <strong>Não:</strong> O timer nunca é resetado, a sessão expira após o tempo configurado independente de atividade</p>
            </div>
        </div>
        
        <button type="submit" style="margin-top:0; width:100%; padding:12px; background:#2563eb; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:bold;">
            💾 Salvar Configurações de Logout
        </button>
    </form>
    
    <div style="margin-top: 15px; font-size: 13px; color: #666;">
        <p><strong>Equivalente a:</strong> 
            <?php 
                $horas = floor(TEMPO_SESSAO / 3600);
                $minutos = floor((TEMPO_SESSAO % 3600) / 60);
                $segundos = TEMPO_SESSAO % 60;
                echo sprintf("%02d:%02d:%02d", $horas, $minutos, $segundos);
            ?>
        </p>
        <p>• Mínimo: 1 minuto (60 segundos)</p>
        <p>• Máximo: 24 horas (86400 segundos)</p>
        <p>• Esta configuração afeta TODAS as abas do sistema</p>
    </div>
</div>

<!-- Timer para aba de Configurações -->
<?php if ($_SESSION['auto_logout']): ?>
<div class="session-timer" id="sessionTimer" style="bottom: 10px; left: 10px;">
    <div style="font-size: 10px; opacity: 0.8;">Auto-logout em:</div>
    <div style="font-size: 14px; font-weight: bold;" id="sessionTime"><?= gmdate("H:i:s", TEMPO_SESSAO) ?></div>
</div>
<?php endif; ?>

<!-- ===================================================================== -->
<!-- BLOCO 20 - CONTEÚDO DA ABA LOG (VERSÃO SIMPLIFICADA E LIMPA) -->
<!-- ===================================================================== -->

<?php elseif ($abaAtiva === 'log'): ?>

<?php if ($_SESSION['auto_logout']): ?>
<div class="session-timer" id="sessionTimer">
    <div style="font-size: 10px; opacity: 0.8;">Auto-logout em:</div>
    <div style="font-size: 14px; font-weight: bold;" id="sessionTime"><?= gmdate("H:i:s", TEMPO_SESSAO) ?></div>
</div>
<?php endif; ?>

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

<script>
// Script da aba de logs - VERSÃO UNIFICADA COM TIMER COMPARTILHADO
(function() {
    let autoRefreshInterval;
    let ultimoTamanhoArquivo = 0;
    let buscandoAtivo = false;
    let linhasSelecionadas = 500;
    let buscaAtiva = '';
    let wakeLock = null;
    let csrfToken = '<?= htmlspecialchars($csrf_token) ?>';
    
    // Timer unificado (igual às outras abas)
    let sessionTimeLeft = <?= TEMPO_SESSAO ?>;
    let timerInterval = null;
    let heartbeatInterval = null;
    
    // ===== FUNÇÕES DE SINCRONIZAÇÃO DE TIMER ENTRE ABAS =====
    function carregarTimerCompartilhado() {
    const timerSalvo = localStorage.getItem('timer_compartilhado');
    if (timerSalvo) {
        try {
            const dados = JSON.parse(timerSalvo);
            const agora = Date.now();
            const tempoPassado = Math.floor((agora - dados.timestamp) / 1000);
            let tempoRestante = dados.tempo - tempoPassado;
            
            if (tempoRestante > 0 && tempoRestante <= <?= TEMPO_SESSAO ?>) {
                sessionTimeLeft = tempoRestante;
            } else if (tempoRestante <= 0) {
                sessionTimeLeft = 0;
            } else {
                sessionTimeLeft = <?= TEMPO_SESSAO ?>;
            }
            updateTimerDisplay();
            console.log('📡 Timer carregado do localStorage:', sessionTimeLeft);
        } catch(e) {}
    }
    
    // Também buscar do servidor para garantir sincronia
    fetch('index.php?heartbeat=1&t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.time_left !== undefined) {
                sessionTimeLeft = data.time_left;
                updateTimerDisplay();
                salvarTimerCompartilhado();
                console.log('📡 Timer sincronizado com servidor:', sessionTimeLeft);
            }
        })
        .catch(error => console.error('Erro ao sincronizar timer:', error));
}

    function salvarTimerCompartilhado() {
        const dados = {
            tempo: sessionTimeLeft,
            timestamp: Date.now()
        };
        localStorage.setItem('timer_compartilhado', JSON.stringify(dados));
    }
    
    function formatTime(seconds) {
        if (seconds < 0) seconds = 0;
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        return [hours.toString().padStart(2, '0'), minutes.toString().padStart(2, '0'), secs.toString().padStart(2, '0')].join(':');
    }
    
    function updateTimerDisplay() {
        const timerElement = document.getElementById('sessionTimer');
        const timeElement = document.getElementById('sessionTime');
        if (!timerElement || !timeElement) return;
        
        timerElement.style.display = 'block';
        timeElement.textContent = formatTime(sessionTimeLeft);
        
        if (sessionTimeLeft < 60) {
            timerElement.style.background = 'rgba(231, 76, 60, 0.95)';
            timerElement.style.borderLeft = '4px solid #c0392b';
        } else if (sessionTimeLeft < 300) {
            timerElement.style.background = 'rgba(241, 196, 15, 0.95)';
            timerElement.style.borderLeft = '4px solid #f39c12';
            timeElement.style.color = '#000';
        } else {
            timerElement.style.background = 'rgba(46, 204, 113, 0.9)';
            timerElement.style.borderLeft = '4px solid #27ae60';
            timeElement.style.color = '#fff';
        }
    }
    
    // Carregar configuração de reset por atividade
    let resetOnActivity = <?= isset($config['reset_timer_on_activity']) && $config['reset_timer_on_activity'] === true ? 'true' : 'false' ?>;
    
    function resetTimerOnActivity() {
        if (!resetOnActivity) return;
        
        if (sessionTimeLeft > 0) {
            sessionTimeLeft = <?= TEMPO_SESSAO ?>;
            updateTimerDisplay();
            salvarTimerCompartilhado();
            
            fetch('index.php?update_session=1&t=' + Date.now())
                .catch(error => console.error('Erro ao resetar sessão:', error));
        }
    }
    
    function syncWithServer() {
        fetch('index.php?heartbeat=1&t=' + Date.now())
            .then(response => response.json())
            .then(data => {
                if (data.expired) {
                    window.location.href = 'index.php?expired=true';
                    return;
                }
                if (data.time_left !== undefined) {
                    sessionTimeLeft = data.time_left;
                    updateTimerDisplay();
                    salvarTimerCompartilhado();
                }
            })
            .catch(error => console.error('Erro no heartbeat:', error));
    }
    
    function initTimer() {
        if (timerInterval) clearInterval(timerInterval);
        if (heartbeatInterval) clearInterval(heartbeatInterval);
        
        timerInterval = setInterval(() => {
            if (sessionTimeLeft > 0) {
                sessionTimeLeft--;
                updateTimerDisplay();
                salvarTimerCompartilhado();
                if (sessionTimeLeft <= 0) {
                    window.location.href = 'index.php?expired=true';
                }
            }
        }, 1000);
        
        heartbeatInterval = setInterval(syncWithServer, 30000);
        syncWithServer();
    }
    
    // ===== FUNÇÕES DE WAKE LOCK =====
    async function ativarWakeLock() {
        if ('wakeLock' in navigator) {
            try {
                wakeLock = await navigator.wakeLock.request('screen');
                console.log('✅ Tela será mantida ativa');
                wakeLock.addEventListener('release', () => {
                    console.log('📴 Wake lock liberado');
                });
            } catch (err) {
                console.error(`❌ Erro ao ativar wake lock: ${err.message}`);
            }
        }
    }
    
    function desativarWakeLock() {
        if (wakeLock !== null) {
            wakeLock.release().then(() => {
                wakeLock = null;
                console.log('✅ Wake lock desativado');
            });
        }
    }
    
    // ===== FUNÇÕES DE LOG =====
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
        return { tamanho: 0, modificacao: 0, conteudo: conteudo };
    }
    
    async function carregarLog(forcarCompleto = false) {
        const terminal = document.getElementById('terminalContent');
        const buscar = document.getElementById('buscarLog')?.value || '';
        const linhas = parseInt(document.getElementById('linhasLog')?.value || 500);
        if (!terminal || buscandoAtivo) return;
        
        const scrollPos = terminal.scrollTop;
        const estavaNoFinal = terminal.scrollHeight - terminal.clientHeight <= scrollPos + 50;
        
        if (!forcarCompleto) {
            linhasSelecionadas = linhas;
            buscaAtiva = buscar;
        }
        
        let url = `?get_log=1&linhas=${linhasSelecionadas}`;
        if (!forcarCompleto && ultimoTamanhoArquivo > 0 && buscaAtiva === '') {
            url += `&tail=1&ultimo_tamanho=${ultimoTamanhoArquivo}`;
        } else if (buscaAtiva !== '') {
            url += `&buscar=${encodeURIComponent(buscaAtiva)}`;
        }
        
        buscandoAtivo = true;
        
        fetch(url + '&t=' + Date.now())
            .then(response => {
                if (response.status === 401) {
                    window.location.href = 'index.php?expired=true';
                    throw new Error('Unauthorized');
                }
                return response.text();
            })
            .then(conteudo => {
                buscandoAtivo = false;
                if (conteudo === '=== NO_UPDATE ===') return;
                if (conteudo === '=== LOG_RESET ===' || conteudo.includes('=== METADATA:0:')) {
                    ultimoTamanhoArquivo = 0;
                    terminal.innerHTML = '';
                    carregarLog(true);
                    return;
                }
                
                const metadata = extrairMetadata(conteudo);
                const tamanhoAtual = metadata.tamanho;
                const conteudoReal = metadata.conteudo;
                
                if (!forcarCompleto && ultimoTamanhoArquivo > 0 && buscaAtiva === '' && conteudoReal.trim()) {
                    const linhasNovas = conteudoReal.split('\n').filter(l => l.trim() !== '').map(linha => `<div class="log-line">${linha}</div>`).join('');
                    if (linhasNovas) {
                        if (terminal.innerHTML.includes('Nenhuma entrada') || terminal.innerHTML.trim() === '') {
                            terminal.innerHTML = linhasNovas;
                        } else {
                            terminal.insertAdjacentHTML('beforeend', linhasNovas);
                        }
                        if (estavaNoFinal) terminal.scrollTop = terminal.scrollHeight;
                    }
                } else {
                    const linhasFormatadas = conteudoReal.split('\n').filter(l => l.trim() !== '').map(linha => `<div class="log-line">${linha}</div>`).join('');
                    terminal.innerHTML = linhasFormatadas || '<div style="color: #888; text-align: center; padding: 20px;">📭 Nenhuma entrada no log</div>';
                    if (ultimoTamanhoArquivo === 0 || buscaAtiva !== '') {
                        terminal.scrollTop = terminal.scrollHeight;
                    } else {
                        terminal.scrollTop = scrollPos;
                    }
                }
                ultimoTamanhoArquivo = tamanhoAtual;
                
                const linhasCountEl = document.getElementById('linhasCount');
                const tamanhoLogEl = document.getElementById('tamanhoLog');
                const dataAtualizacaoEl = document.getElementById('dataAtualizacao');
                if (linhasCountEl) linhasCountEl.textContent = terminal.querySelectorAll('.log-line').length;
                if (tamanhoLogEl) tamanhoLogEl.textContent = Math.round(tamanhoAtual / 1024) + ' KB';
                if (dataAtualizacaoEl) dataAtualizacaoEl.textContent = new Date().toLocaleTimeString('pt-BR');
            })
            .catch(error => {
                if (error.message !== 'Unauthorized') {
                    console.error('Erro:', error);
                    buscandoAtivo = false;
                    terminal.innerHTML = `<div style="color: #FF5F5F; text-align: center; padding: 20px;">❌ Erro ao carregar log: ${error.message}</div>`;
                }
            });
    }
    
    window.atualizarLog = function() { carregarLog(true); };
    
    function toggleAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
        const autoRefresh = document.getElementById('autoRefresh');
        if (autoRefresh && autoRefresh.checked) {
            autoRefreshInterval = setInterval(() => carregarLog(false), 2000);
        }
    }
    
    window.confirmarLimparLog = async function() {
        if (!confirm('⚠️ Deseja realmente LIMPAR TODO o arquivo de log?\n\nEsta ação é PERMANENTE!')) return;
        if (!confirm('🚨 CONFIRMAÇÃO FINAL: Tem CERTEZA?')) return;
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
        try {
            const formData = new FormData();
            formData.append('acao_log', 'limpar');
            formData.append('csrf_token', csrfToken);
            await fetch('index.php?aba=log', { method: 'POST', body: formData });
            ultimoTamanhoArquivo = 0;
            carregarLog(true);
            toggleAutoRefresh();
            alert('✅ Log limpo com sucesso!');
        } catch (error) {
            console.error('Erro ao limpar log:', error);
            alert('❌ Erro ao limpar log!');
        }
    };
    
    window.toggleAutoRefresh = toggleAutoRefresh;
    
    // ===== DOMContentLoaded =====
    document.addEventListener('DOMContentLoaded', async function() {
        if (!window.location.search.includes('aba=log')) return;
        console.log('📋 Inicializando aba de logs...');
        
        // Carregar timer compartilhado entre abas
        carregarTimerCompartilhado();
        
        initTimer();
        ativarWakeLock();
        
        // Resetar timer quando usuário interagir
        const activityEvents = ['mousemove', 'keypress', 'click', 'scroll', 'touchstart'];
        activityEvents.forEach(event => {
            document.addEventListener(event, resetTimerOnActivity, { passive: true });
        });
        
        document.getElementById('autoRefresh')?.addEventListener('change', toggleAutoRefresh);
        toggleAutoRefresh();
        
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
        
        carregarLog(true);
        
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
        
        document.addEventListener('visibilitychange', async () => {
            if (document.visibilityState === 'visible' && wakeLock === null) {
                await ativarWakeLock();
            }
        });
    });
    
    window.addEventListener('beforeunload', function() {
        if (autoRefreshInterval) clearInterval(autoRefreshInterval);
        if (timerInterval) clearInterval(timerInterval);
        if (heartbeatInterval) clearInterval(heartbeatInterval);
        desativarWakeLock();
        salvarTimerCompartilhado();
    });
})();
</script>

<!-- ===================================================================== -->
<!-- BLOCO 21 - CONTEÚDO DA ABA DASHBOARD PIX
<!-- ===================================================================== -->

<?php elseif ($abaAtiva === 'dashboard'): ?>

<!-- Timer de sessão -->
<?php if ($_SESSION['auto_logout']): ?>
<div class="session-timer" id="sessionTimer">
    <div style="font-size: 10px; opacity: 0.8;">Auto-logout em:</div>
    <div style="font-size: 14px; font-weight: bold;" id="sessionTime">00:00</div>
</div>
<?php endif; ?>

<!-- Mensagens de alteração de senha (agora só aparece se vier do processamento) -->
<?php if (isset($_SESSION['mensagem_senha'])): ?>
<div class="mensagem-senha mensagem-<?= $_SESSION['tipo_mensagem_senha'] ?>" id="mensagemSenha">
    <?= htmlspecialchars($_SESSION['mensagem_senha']) ?>
</div>
<?php 
    unset($_SESSION['mensagem_senha']);
    unset($_SESSION['tipo_mensagem_senha']);
endif; 
?>

<!-- Header do Dashboard PIX - SIMPLIFICADO -->
<div class="header-dashboard">
    <div class="dashboard-title">
        <h1>📊 Dashboard Consultas Fatura</h1>
    </div>
</div>

<?php
// Dados do dashboard
$hoje = date('Y-m-d');
$ontem = date('Y-m-d', strtotime('-1 day'));

$ultimos7 = 0;
for ($i = 0; $i < 7; $i++) {
    $ultimos7 += contarDia(date('Y-m-d', strtotime("-$i day")));
}

$diasDisponiveis = listarDiasDisponiveis();
$diaSelecionado = $_GET['dia'] ?? $hoje;

$arquivoDia = "/var/log/pix_acessos/pix_log_$diaSelecionado.log";
$linhas = file_exists($arquivoDia) 
    ? file($arquivoDia, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) 
    : [];

// Estatísticas de filtros (apenas admin)
$totalBloqueios = 0;
$ipsBloqueados = [];
$motivos = [
    'IP BLOQUEADO' => 0,
    'USER-AGENT BLOQUEADO' => 0,
    'ACESSO RECENTE DO CPF' => 0,
    'DUPLICAÇÃO DETECTADA' => 0,
    'LOG REGISTRADO' => 0,
];
$topIps = [];

if ($_SESSION['nivel'] === 'admin') {
    $filtrosLog = '/var/log/pix_acessos/pix_filtros.log';
    if (file_exists($filtrosLog)) {
        $linhasFiltros = file($filtrosLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($linhasFiltros as $linha) {
            $totalBloqueios++;
            
            foreach ($motivos as $motivo => $contador) {
                if (strpos($linha, "STATUS: $motivo") !== false) {
                    $motivos[$motivo]++;
                    break;
                }
            }
            
            if (preg_match('/IP:\s*([0-9\.]+)/', $linha, $matches)) {
                $ip = $matches[1];
                if (!isset($ipsBloqueados[$ip])) $ipsBloqueados[$ip] = 0;
                $ipsBloqueados[$ip]++;
            }
        }
        arsort($ipsBloqueados);
        $topIps = array_slice($ipsBloqueados, 0, 5, true);
    }
}
?>

<!-- Cards de estatísticas -->
<div class="cards">
    <div class="card-dash">
        <div>Hoje</div>
        <b><?= contarDia($hoje) ?></b>
    </div>
    <div class="card-dash">
        <div>Ontem</div>
        <b><?= contarDia($ontem) ?></b>
    </div>
    <div class="card-dash">
        <div>Últimos 7 dias</div>
        <b><?= $ultimos7 ?></b>
    </div>
</div>

<!-- Seleção de dia e tabela -->
<div class="box">
    <form method="get">
        <input type="hidden" name="aba" value="dashboard">
        <label><b>Selecionar dia:</b></label><br>
        <select name="dia" onchange="this.form.submit()">
        <?php foreach ($diasDisponiveis as $d): 
            $contagem = contarDia($d);
        ?>
        <option value="<?= $d ?>" <?= $d === $diaSelecionado ? 'selected' : '' ?>>
            <?= date('d/m/Y', strtotime($d)) ?> (<?= $contagem ?> acesso<?= $contagem != 1 ? 's' : '' ?>)
        </option>
        <?php endforeach; ?>
        </select>
    </form>

    <div class="acoes">
        <a href="?aba=dashboard&dia=<?= $hoje ?>" class="btn-acoes btn-hoje">Hoje</a>
        <a href="?aba=dashboard&dia=<?= $ontem ?>" class="btn-acoes btn-ontem">Ontem</a>
        <button onclick="exportarCSV()" class="btn-acoes btn-exportar">Exportar CSV</button>
    </div>

    <?php if (!$linhas): ?>
    <div class="info-box">
        <p style="margin:0;">Nenhum acesso registrado em <b><?= date('d/m/Y', strtotime($diaSelecionado)) ?></b></p>
    </div>
    <?php else: ?>
    <div class="info-box">
        <p style="margin:0;"><b><?= count($linhas) ?> acesso<?= count($linhas) != 1 ? 's' : '' ?></b> em <?= date('d/m/Y', strtotime($diaSelecionado)) ?></p>
    </div>
    
    <div style="overflow-x:auto;">
        <table>
        <thead>
        <tr>
            <th>Data</th>
            <th>Hora</th>
            <th>Vencimento</th>
            <th>IP</th>
            <th>Cliente</th>
            <th>CPF</th>
            <th>Título</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($linhas as $l):
            preg_match(
                '/(\d{4}-\d{2}-\d{2})\s\|\s(\d{2}:\d{2}:\d{2})\s\|\sVENC:\s([^|]+)\s\|\sIP:\s([^|]+)\s\|\sNOME:\s([^|]+)\s\|\sCPF:\s([^|]+)\s\|\sTITULO:\s(.+)/',
                $l,
                $m
            );
        ?>
        <tr>
            <td data-label="Data"><?= isset($m[1]) ? date('d/m/Y', strtotime($m[1])) : '' ?></td>
            <td data-label="Hora"><?= $m[2] ?? '' ?></td>
            <td data-label="Vencimento"><?= $m[3] ?? '' ?></td>
            <td data-label="IP"><?= $m[4] ?? '' ?></td>
            <td data-label="Cliente"><?= $m[5] ?? '' ?></td>
            <td data-label="CPF"><?= $m[6] ?? '' ?></td>
            <td data-label="Título"><?= $m[7] ?? '' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Estatísticas de filtros (apenas admin) -->
<?php if ($_SESSION['nivel'] === 'admin' && $totalBloqueios > 0): ?>
<div class="box" style="margin-top: 20px;">
    <h3 style="text-align:center;">🛡️ Estatísticas de Filtros</h3>
    
    <div style="display: flex; justify-content: space-around; text-align: center; flex-wrap: wrap; gap: 15px;">
        <div>
            <div style="font-size: 12px; color: #666;">Total Bloqueios</div>
            <div style="font-size: 24px; font-weight: bold; color: #e74c3c;"><?= $totalBloqueios ?></div>
        </div>
        <div>
            <div style="font-size: 12px; color: #666;">Logs Registrados</div>
            <div style="font-size: 24px; font-weight: bold; color: #27ae60;"><?= $motivos['LOG REGISTRADO'] ?></div>
        </div>
        <div>
            <div style="font-size: 12px; color: #666;">IPs Bloqueados</div>
            <div style="font-size: 24px; font-weight: bold; color: #f39c12;"><?= count($ipsBloqueados) ?></div>
        </div>
    </div>
    
    <div style="margin-top: 20px;">
        <h4 style="text-align: center; margin-bottom: 10px;">📊 Distribuição de Bloqueios</h4>
        <table style="width: 100%; font-size: 12px;">
            <tr>
                <th>Motivo</th>
                <th>Quantidade</th>
                <th>Percentual</th>
            </tr>
            <?php foreach ($motivos as $motivo => $quantidade): 
                if ($quantidade > 0):
                    $percentual = $totalBloqueios > 0 ? round(($quantidade / $totalBloqueios) * 100, 1) : 0;
            ?>
            <tr>
                <td><?= $motivo ?></td>
                <td style="text-align: center;"><?= $quantidade ?></td>
                <td style="text-align: center;"><?= $percentual ?>%</td>
            </tr>
            <?php endif; endforeach; ?>
        </table>
    </div>
    
    <?php if (!empty($topIps)): ?>
    <div style="margin-top: 20px;">
        <h4 style="text-align: center; margin-bottom: 10px;">🔝 Top 5 IPs Bloqueados</h4>
        <table style="width: 100%; font-size: 12px;">
            <tr>
                <th>IP</th>
                <th>Tentativas</th>
                <th>Última</th>
            </tr>
            <?php foreach ($topIps as $ip => $tentativas): ?>
            <tr>
                <td><?= htmlspecialchars($ip) ?></td>
                <td style="text-align: center;"><?= $tentativas ?></td>
                <td style="text-align: center;">
                    <?php 
                    $ultimaOcorrencia = '';
                    if (file_exists($filtrosLog)) {
                        $linhas = file($filtrosLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        foreach ($linhas as $linha) {
                            if (strpos($linha, "IP: $ip") !== false) {
                                if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $linha, $match)) {
                                    $ultimaOcorrencia = $match[1];
                                }
                            }
                        }
                    }
                    echo $ultimaOcorrencia ?: 'N/A';
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
    
    <div style="text-align: center; margin-top: 15px;">
        <button onclick="limparLogsFiltros()" style="padding: 8px 15px; background: #95a5a6; color: white; border: none; border-radius: 4px; cursor: pointer;">
            🗑️ Limpar Logs de Filtros
        </button>
    </div>
</div>
<?php endif; ?>

<div class="footer-dashboard">
    WebLine Telecom - Dashboard Pix
</div>

<!-- ===================================================================== -->
<!-- BLOCO 22 - CONTEÚDO DA ABA USUÁRIOS (APENAS ADMIN)
<!-- ===================================================================== -->

<?php elseif ($abaAtiva === 'usuarios' && $_SESSION['nivel'] === 'admin'): ?>

<!-- Timer de sessão para aba Usuários -->
<?php if ($_SESSION['auto_logout']): ?>
<div class="session-timer" id="sessionTimer">
    <div style="font-size: 10px; opacity: 0.8;">Auto-logout em:</div>
    <div style="font-size: 14px; font-weight: bold;" id="sessionTime"><?= gmdate("H:i:s", TEMPO_SESSAO) ?></div>
</div>
<?php endif; ?>

<?php
// Processar ações de gerenciamento (COM CSRF)
$mensagem_usuario = '';
$tipo_mensagem_usuario = '';

// Verificar CSRF para todas ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die('CSRF token inválido');
    }
}

// Adicionar usuário
if (isset($_POST['adicionar_usuario'])) {
    $dados = [
        'usuario' => trim($_POST['usuario']),
        'nome' => trim($_POST['nome']),
        'email' => trim($_POST['email'] ?? ''),
        'senha' => $_POST['senha'],
        'nivel' => $_POST['nivel'] ?? 'usuario'
    ];
    
    $resultado = adicionarUsuario($dados);
    $mensagem_usuario = $resultado['message'];
    $tipo_mensagem_usuario = $resultado['success'] ? 'sucesso' : 'erro';
    
    if ($resultado['success']) {
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'],
            $_SESSION['nivel'],
            'CRIAR_USUÁRIO: ' . $dados['usuario']
        );
    }
}

// Excluir usuário (apenas GET, mas verificado)
if (isset($_GET['excluir'])) {
    $usuario_excluir = $_GET['excluir'];
    
    // Registrar tentativa (log)
    error_log("DELETE_USER_ATTEMPT: User={$_SESSION['usuario']}, Target={$usuario_excluir}, IP=" . getRealIp());
    
    if ($usuario_excluir != $_SESSION['usuario'] && $usuario_excluir != 'admin') {
        $usuarios = carregarUsuarios();
        
        if (isset($usuarios[$usuario_excluir])) {
            unset($usuarios[$usuario_excluir]);
            
            if (salvarUsuarios($usuarios)) {
                $mensagem_usuario = 'Usuário excluído com sucesso!';
                $tipo_mensagem_usuario = 'sucesso';
                
                registrarLogAcesso(
                    $_SESSION['usuario'],
                    $_SESSION['nome'],
                    $_SESSION['nivel'],
                    'EXCLUIR_USUÁRIO: ' . $usuario_excluir
                );
            } else {
                $mensagem_usuario = 'Erro ao excluir usuário!';
                $tipo_mensagem_usuario = 'erro';
            }
        } else {
            $mensagem_usuario = 'Usuário não encontrado!';
            $tipo_mensagem_usuario = 'erro';
        }
    } else {
        $mensagem_usuario = 'Você não pode excluir este usuário!';
        $tipo_mensagem_usuario = 'erro';
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'],
            $_SESSION['nivel'],
            'TENTATIVA_EXCLUIR_USUÁRIO_PROTEGIDO: ' . $usuario_excluir
        );
    }
}

// Resetar senha
if (isset($_GET['reset_senha'])) {
    $usuario_reset = $_GET['reset_senha'];
    $usuarios = carregarUsuarios();
    
    if (isset($usuarios[$usuario_reset])) {
        $usuarios[$usuario_reset]['senha_hash'] = password_hash('123456', PASSWORD_DEFAULT);
        
        if (salvarUsuarios($usuarios)) {
            $mensagem_usuario = 'Senha resetada para: 123456';
            $tipo_mensagem_usuario = 'sucesso';
            
            registrarLogAcesso(
                $_SESSION['usuario'],
                $_SESSION['nome'],
                $_SESSION['nivel'],
                'RESET_SENHA: ' . $usuario_reset
            );
        } else {
            $mensagem_usuario = 'Erro ao resetar senha!';
            $tipo_mensagem_usuario = 'erro';
        }
    }
}

// Alterar senha (admin alterando para outro usuário) - JÁ COM CSRF VERIFICADO
if (isset($_POST['alterar_senha_admin'])) {
    $resultado = alterarSenhaAdmin(
        $_SESSION['usuario'],
        $_POST['usuario_alvo'] ?? '',
        $_POST['nova_senha_admin'] ?? ''
    );
    
    $mensagem_usuario = $resultado['message'];
    $tipo_mensagem_usuario = $resultado['success'] ? 'sucesso' : 'erro';
    
    if ($resultado['success']) {
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'],
            $_SESSION['nivel'],
            'ALTERAR_SENHA_ADMIN: ' . $_POST['usuario_alvo']
        );
    }
}

// Limpar logs de acesso
if (isset($_GET['limpar_logs_acesso']) && $_GET['limpar_logs_acesso'] == '1') {
    if (file_exists(ARQUIVO_LOG_ACESSOS)) {
        file_put_contents(ARQUIVO_LOG_ACESSOS, '');
        $mensagem_usuario = 'Logs de acesso limpos com sucesso!';
        $tipo_mensagem_usuario = 'sucesso';
        registrarLogAcesso(
            $_SESSION['usuario'],
            $_SESSION['nome'],
            $_SESSION['nivel'],
            'LIMPAR_LOGS_ACESSO'
        );
    }
}

// Carregar dados
$usuarios = carregarUsuarios();
$logs_acesso = [];
$total_logs = 0;

if (file_exists(ARQUIVO_LOG_ACESSOS)) {
    $conteudo_logs = file(ARQUIVO_LOG_ACESSOS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($conteudo_logs) {
        $logs_acesso = array_reverse($conteudo_logs);
        $total_logs = count($conteudo_logs);
    }
}
?>

<!-- HTML do Gerenciamento de Usuários -->
<div style="max-width:1200px; margin:0 auto; padding:20px;">
    <!-- Header -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; background:white; padding:20px; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.1); flex-wrap:wrap; gap:15px;">
        <h1 style="color:#2c3e50; display:flex; align-items:center; gap:10px;">👥 Gerenciamento de Usuários</h1>
    </div>
    
    <!-- Mensagens -->
    <?php if ($mensagem_usuario): ?>
    <div style="padding:15px; border-radius:8px; margin-bottom:20px; background:<?= $tipo_mensagem_usuario === 'sucesso' ? '#d4edda' : '#f8d7da' ?>; color:<?= $tipo_mensagem_usuario === 'sucesso' ? '#155724' : '#721c24' ?>; border:1px solid <?= $tipo_mensagem_usuario === 'sucesso' ? '#c3e6cb' : '#f5c6cb' ?>;">
        <?= htmlspecialchars($mensagem_usuario) ?>
    </div>
    <?php endif; ?>
    
    <!-- Stats -->
    <div style="display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
        <div style="background:white; padding:15px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1); flex:1; min-width:140px; text-align:center;">
            <div style="font-size:13px; color:#666;">Total de Usuários</div>
            <div style="font-size:22px; font-weight:bold; color:#00b894;"><?= count($usuarios) ?></div>
        </div>
        <?php 
        $admins = array_filter($usuarios, function($u) { return $u['nivel'] === 'admin'; });
        $ativos = array_filter($usuarios, function($u) { return $u['status'] === 'ativo'; });
        ?>
        <div style="background:white; padding:15px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1); flex:1; min-width:140px; text-align:center;">
            <div style="font-size:13px; color:#666;">Administradores</div>
            <div style="font-size:22px; font-weight:bold; color:#3498db;"><?= count($admins) ?></div>
        </div>
        <div style="background:white; padding:15px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1); flex:1; min-width:140px; text-align:center;">
            <div style="font-size:13px; color:#666;">Usuários Ativos</div>
            <div style="font-size:22px; font-weight:bold; color:#27ae60;"><?= count($ativos) ?></div>
        </div>
        <div style="background:white; padding:15px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1); flex:1; min-width:140px; text-align:center;">
            <div style="font-size:13px; color:#666;">Logs de Acesso</div>
            <div style="font-size:22px; font-weight:bold; color:#f39c12;"><?= $total_logs ?></div>
        </div>
    </div>
    
    <!-- Formulário de adição COM CSRF -->
    <div style="background:white; border-radius:10px; padding:20px; margin-bottom:25px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
        <h2 style="color:#2c3e50; margin-bottom:20px; padding-bottom:10px; border-bottom:2px solid #00b894; display:flex; align-items:center; gap:10px;">➕ Adicionar Novo Usuário</h2>
        
        <form method="POST" onsubmit="return validarFormularioUsuario()">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:15px; margin-bottom:20px;">
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:6px; color:#555; font-weight:600; font-size:13px;">Usuário *</label>
                    <input type="text" name="usuario" required style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:15px;">
                </div>
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:6px; color:#555; font-weight:600; font-size:13px;">Nome Completo *</label>
                    <input type="text" name="nome" required style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:15px;">
                </div>
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:6px; color:#555; font-weight:600; font-size:13px;">E-mail</label>
                    <input type="email" name="email" style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:15px;">
                </div>
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:6px; color:#555; font-weight:600; font-size:13px;">Nível de Acesso *</label>
                    <select name="nivel" required style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:15px;">
                        <option value="usuario">Usuário</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:6px; color:#555; font-weight:600; font-size:13px;">Senha * (mín. 6 caracteres)</label>
                    <input type="password" name="senha" id="senha_usuario" required minlength="6" style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:15px;">
                </div>
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:6px; color:#555; font-weight:600; font-size:13px;">Confirmar Senha *</label>
                    <input type="password" id="confirmar_senha_usuario" required style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:15px;">
                </div>
            </div>
            
            <button type="submit" name="adicionar_usuario" style="width:100%; padding:14px 25px; background:#00b894; color:white; border:none; border-radius:6px; font-size:15px; font-weight:bold; cursor:pointer; transition:all 0.3s;">
                📝 Cadastrar Usuário
            </button>
        </form>
    </div>
    
    <!-- Lista de usuários -->
    <div style="background:white; border-radius:10px; padding:20px; margin-bottom:25px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
        <h2 style="color:#2c3e50; margin-bottom:20px; padding-bottom:10px; border-bottom:2px solid #00b894; display:flex; align-items:center; gap:10px;">📋 Lista de Usuários</h2>
        
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8f9fa;">
                        <th style="padding:12px 10px; text-align:left;">Usuário</th>
                        <th style="padding:12px 10px; text-align:left;">Nome</th>
                        <th style="padding:12px 10px; text-align:left;">E-mail</th>
                        <th style="padding:12px 10px; text-align:left;">Nível</th>
                        <th style="padding:12px 10px; text-align:left;">Status</th>
                        <th style="padding:12px 10px; text-align:left;">Auto-logout</th>
                        <th style="padding:12px 10px; text-align:left;">Última Alteração</th>
                        <th style="padding:12px 10px; text-align:left;">Último Acesso</th>
                        <th style="padding:12px 10px; text-align:left;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $usuario_nome => $dados): 
                        $auto_logout_pref = $dados['auto_logout'] ?? true;
                        $ultima_alteracao = isset($dados['ultima_alteracao_autologout']) ? 
                            date('d/m/Y H:i', strtotime($dados['ultima_alteracao_autologout'])) : '';
                    ?>
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:12px 10px;"><strong><?= htmlspecialchars($usuario_nome) ?></strong></td>
                        <td style="padding:12px 10px;"><?= htmlspecialchars($dados['nome']) ?></td>
                        <td style="padding:12px 10px;"><?= htmlspecialchars($dados['email'] ?? '') ?></td>
                        <td style="padding:12px 10px;">
                            <span style="display:inline-block; padding:5px 10px; border-radius:12px; font-size:11px; font-weight:bold; background:<?= $dados['nivel'] === 'admin' ? '#e3f2fd' : '#f3e5f5' ?>; color:<?= $dados['nivel'] === 'admin' ? '#1976d2' : '#7b1fa2' ?>;">
                                <?= ucfirst($dados['nivel']) ?>
                            </span>
                        </td>
                        <td style="padding:12px 10px;">
                            <span style="display:inline-block; padding:5px 10px; border-radius:12px; font-size:11px; font-weight:bold; background:<?= $dados['status'] === 'ativo' ? '#d4edda' : '#f8d7da' ?>; color:<?= $dados['status'] === 'ativo' ? '#155724' : '#721c24' ?>;">
                                <?= ucfirst($dados['status']) ?>
                            </span>
                        </td>
                        <td style="padding:12px 10px;">
                            <?php if ($auto_logout_pref): ?>
                                <span style="display:inline-block; padding:5px 10px; border-radius:12px; font-size:11px; font-weight:bold; background:#d5f4e6; color:#27ae60; border:1px solid #27ae60;">
                                    ✅ ON
                                </span>
                            <?php else: ?>
                                <span style="display:inline-block; padding:5px 10px; border-radius:12px; font-size:11px; font-weight:bold; background:#f0f0f0; color:#7f8c8d; border:1px solid #95a5a6;">
                                    ❌ OFF
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px 10px;">
                            <?php if ($ultima_alteracao): ?>
                                <small><?= $ultima_alteracao ?></small><br>
                                <?php if (isset($dados['ip_alteracao_autologout'])): ?>
                                    <small style="color:#666;">IP: <?= htmlspecialchars($dados['ip_alteracao_autologout']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <small style="color:#999;">Nunca alterado</small>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px 10px;">
                            <?= $dados['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($dados['ultimo_acesso'])) : 'Nunca' ?><br>
                            <small style="color:#666;">IP: <?= htmlspecialchars($dados['ip_ultimo_acesso'] ?? 'N/A') ?></small>
                        </td>
                        <td style="padding:12px 10px;">
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <?php if ($usuario_nome !== 'admin' && $usuario_nome !== $_SESSION['usuario']): ?>
                                <a href="?aba=usuarios&excluir=<?= urlencode($usuario_nome) ?>" 
                                   style="padding:8px 12px; background:#e74c3c; color:white; text-decoration:none; border-radius:4px; font-size:12px;"
                                   onclick="return confirm('Excluir usuário <?= addslashes($usuario_nome) ?>?')">
                                    🗑️ Excluir
                                </a>
                                <?php endif; ?>
                                
                                <a href="?aba=usuarios&reset_senha=<?= urlencode($usuario_nome) ?>" 
                                   style="padding:8px 12px; background:#f39c12; color:white; text-decoration:none; border-radius:4px; font-size:12px;"
                                   onclick="return confirm('Resetar senha para 123456?')">
                                    🔄 Resetar
                                </a>
                                
                                <button onclick="abrirModalUsuario('<?= addslashes($usuario_nome) ?>')" 
                                        style="padding:8px 12px; background:#3498db; color:white; border:none; border-radius:4px; font-size:12px; cursor:pointer;">
                                    🔑 Alterar
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Logs de acesso -->
    <div style="background:white; border-radius:10px; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
        <h2 style="color:#2c3e50; margin-bottom:20px; padding-bottom:10px; border-bottom:2px solid #00b894; display:flex; align-items:center; gap:10px;">📋 Logs de Acesso</h2>
        
        <div style="margin-bottom:15px; text-align:center;">
            <small style="color:#666;">Arquivo: <?= htmlspecialchars(ARQUIVO_LOG_ACESSOS) ?></small>
        </div>
        
        <?php if ($total_logs > 0): ?>
        <div style="text-align:center; margin-bottom:15px;">
            <a href="?aba=usuarios&limpar_logs_acesso=1" 
               style="display:inline-block; padding:8px 15px; background:#95a5a6; color:white; text-decoration:none; border-radius:4px; margin-right:10px;"
               onclick="return confirm('Limpar todos os logs?')">
                🗑️ Limpar Logs
            </a>
            <button onclick="exportarLogsCSV()" style="padding:8px 15px; background:#27ae60; color:white; border:none; border-radius:4px; cursor:pointer;">
                📥 Exportar CSV
            </button>
        </div>
        
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px; min-width:800px;">
                <thead>
                    <tr>
                        <th style="padding:10px 8px; text-align:left; background:#f8f9fa; border-bottom:2px solid #00b894;">Data</th>
                        <th style="padding:10px 8px; text-align:left; background:#f8f9fa; border-bottom:2px solid #00b894;">Hora</th>
                        <th style="padding:10px 8px; text-align:left; background:#f8f9fa; border-bottom:2px solid #00b894;">Usuário</th>
                        <th style="padding:10px 8px; text-align:left; background:#f8f9fa; border-bottom:2px solid #00b894;">Nome</th>
                        <th style="padding:10px 8px; text-align:left; background:#f8f9fa; border-bottom:2px solid #00b894;">Nível</th>
                        <th style="padding:10px 8px; text-align:left; background:#f8f9fa; border-bottom:2px solid #00b894;">IP</th>
                        <th style="padding:10px 8px; text-align:left; background:#f8f9fa; border-bottom:2px solid #00b894;">Ação</th>
                        <th style="padding:10px 8px; text-align:left; background:#f8f9fa; border-bottom:2px solid #00b894;">Tempo Ativo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($logs_acesso, 0, 50) as $log): 
                        $partes = explode("\t", $log);
                    ?>
                    <tr>
                        <td style="padding:8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($partes[0] ?? '') ?></td>
                        <td style="padding:8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($partes[1] ?? '') ?></td>
                        <td style="padding:8px; border-bottom:1px solid #eee;"><strong><?= htmlspecialchars($partes[2] ?? '') ?></strong></td>
                        <td style="padding:8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($partes[3] ?? '') ?></td>
                        <td style="padding:8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($partes[4] ?? '') ?></td>
                        <td style="padding:8px; border-bottom:1px solid #eee; font-family:monospace;"><?= htmlspecialchars($partes[5] ?? '') ?></td>
                        <td style="padding:8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($partes[6] ?? '') ?></td>
                        <td style="padding:8px; border-bottom:1px solid #eee; font-family:monospace;"><?= htmlspecialchars($partes[7] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="text-align:center; margin-top:10px; font-size:12px; color:#666;">
            Mostrando os últimos <?= min(50, $total_logs) ?> logs de acesso
        </div>
        <?php else: ?>
        <div style="text-align:center; padding:30px; color:#666;">
            <p>Nenhum log de acesso registrado ainda.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal para alterar senha (admin) COM CSRF -->
<div id="modalAlterarSenhaUsuario" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:2000; justify-content:center; align-items:center; padding:20px;">
    <div style="background:white; padding:30px; border-radius:10px; max-width:500px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; padding-bottom:15px; border-bottom:2px solid #3498db;">
            <h3 style="margin:0; color:#2c3e50; display:flex; align-items:center; gap:10px;">🔑 Alterar Senha</h3>
            <button onclick="fecharModalUsuario()" style="background:none; border:none; font-size:28px; cursor:pointer; color:#95a5a6;">&times;</button>
        </div>
        
        <form method="POST" onsubmit="return validarSenhaUsuario()">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="usuario_alvo_modal" name="usuario_alvo">
            
            <div style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:8px; color:#555; font-weight:600; font-size:14px;">Nova Senha * (mín. 6 caracteres)</label>
                <input type="password" id="nova_senha_modal" name="nova_senha_admin" required minlength="6" style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:15px;">
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block; margin-bottom:8px; color:#555; font-weight:600; font-size:14px;">Confirmar Nova Senha *</label>
                <input type="password" id="confirmar_nova_senha_modal" required style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px; font-size:15px;">
            </div>
            
            <div style="display:flex; gap:10px;">
                <button type="button" onclick="fecharModalUsuario()" style="flex:1; padding:14px; background:#95a5a6; color:white; border:none; border-radius:6px; font-weight:bold; cursor:pointer;">Cancelar</button>
                <button type="submit" name="alterar_senha_admin" style="flex:2; padding:14px; background:#3498db; color:white; border:none; border-radius:6px; font-weight:bold; cursor:pointer;">Alterar Senha</button>
            </div>
        </form>
    </div>
</div>

<script>
function validarFormularioUsuario() {
    const senha = document.getElementById('senha_usuario');
    const confirmar = document.getElementById('confirmar_senha_usuario');
    
    if (senha.value !== confirmar.value) {
        alert('As senhas não coincidem!');
        return false;
    }
    return true;
}

function abrirModalUsuario(usuario) {
    document.getElementById('usuario_alvo_modal').value = usuario;
    document.getElementById('modalAlterarSenhaUsuario').style.display = 'flex';
    document.getElementById('nova_senha_modal').focus();
}

function fecharModalUsuario() {
    document.getElementById('modalAlterarSenhaUsuario').style.display = 'none';
    document.getElementById('nova_senha_modal').value = '';
    document.getElementById('confirmar_nova_senha_modal').value = '';
}

function validarSenhaUsuario() {
    const senha = document.getElementById('nova_senha_modal');
    const confirmar = document.getElementById('confirmar_nova_senha_modal');
    
    if (senha.value !== confirmar.value) {
        alert('As senhas não coincidem!');
        return false;
    }
    
    return true;
}

function exportarLogsCSV() {
    let csv = 'Data;Hora;Usuário;Nome;Nível;IP;Ação;Tempo Ativo\n';
    
    document.querySelectorAll('table tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 8) {
            csv += `"${cells[0].textContent}";"${cells[1].textContent}";"${cells[2].textContent}";"${cells[3].textContent}";"${cells[4].textContent}";"${cells[5].textContent}";"${cells[6].textContent}";"${cells[7].textContent}"\n`;
        }
    });
    
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'acessos_usuarios_<?= date('Y-m-d') ?>.csv';
    link.click();
}

window.onclick = function(event) {
    const modal = document.getElementById('modalAlterarSenhaUsuario');
    if (event.target === modal) {
        fecharModalUsuario();
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fecharModalUsuario();
    }
});
</script>

<?php endif; ?>

<!-- ===================================================================== -->
<!-- BLOCO 23 - SCRIPTS JS PRINCIPAIS (COM LOGOUT GLOBAL E CSRF) -->
<!-- ===================================================================== -->

<script>
// ==================== SCRIPT PRINCIPAL - COM LOGOUT GLOBAL ====================
(function() {
    // ==================== VARIÁVEIS GLOBAIS ====================
    let autoLogoutEnabled = <?= $_SESSION['auto_logout'] ? 'true' : 'false' ?>;
    let sessionTimeLeft = <?= TEMPO_SESSAO ?>;
    let heartbeatInterval;
    let timerInterval = null;
    let currentAba = '<?= $abaAtiva ?>';
    let currentTabId = Math.random().toString(36).substring(7);
    let lastServerSync = 0;
    let lastUserActivity = Date.now();
    let isLogAba = (currentAba === 'log');
    let csrfToken = '<?= htmlspecialchars($csrf_token) ?>';
    let heartbeatKeepAlive = null;
    let syncing = false;

    // ===== CARREGAR CONFIGURAÇÃO DE RESET =====
    let resetOnActivity = <?= isset($config['reset_timer_on_activity']) && $config['reset_timer_on_activity'] === true ? 'true' : 'false' ?>;

    // ===== FUNÇÕES DE SINCRONIZAÇÃO DE TIMER ENTRE ABAS =====
    function carregarTimerCompartilhado() {
    // Verificar se tem um timestamp recente (menos de 5 minutos)
    const timerSalvo = localStorage.getItem('timer_compartilhado');
    if (timerSalvo) {
        try {
            const dados = JSON.parse(timerSalvo);
            const agora = Date.now();
            const diferenca = Math.floor((agora - dados.timestamp) / 1000);
            
            // Se o timer foi salvo há mais de 5 minutos, ignorar (sessão nova)
            if (diferenca > 300) {
                console.log('📡 Timer antigo ignorado (>5min), resetando para', <?= TEMPO_SESSAO ?>);
                sessionTimeLeft = <?= TEMPO_SESSAO ?>;
                updateTimerDisplay();
                salvarTimerCompartilhado();
                return;
            }
            
            const tempoPassado = Math.floor((agora - dados.timestamp) / 1000);
            let tempoRestante = dados.tempo - tempoPassado;
            
            if (tempoRestante > 0 && tempoRestante <= <?= TEMPO_SESSAO ?>) {
                sessionTimeLeft = tempoRestante;
            } else if (tempoRestante <= 0) {
                sessionTimeLeft = 0;
            } else {
                sessionTimeLeft = <?= TEMPO_SESSAO ?>;
            }
            updateTimerDisplay();
            console.log('📡 Timer carregado do localStorage:', sessionTimeLeft);
        } catch(e) {}
    }
    
    // Buscar do servidor para garantir sincronia
    fetch('index.php?heartbeat=1&t=' + Date.now())
        .then(response => response.json())
        .then(data => {
            if (data.time_left !== undefined) {
                sessionTimeLeft = data.time_left;
                updateTimerDisplay();
                salvarTimerCompartilhado();
                console.log('📡 Timer sincronizado com servidor:', sessionTimeLeft);
            }
        })
        .catch(error => console.error('Erro ao sincronizar timer:', error));
}

    function salvarTimerCompartilhado() {
        const dados = {
            tempo: sessionTimeLeft,
            timestamp: Date.now()
        };
        localStorage.setItem('timer_compartilhado', JSON.stringify(dados));
    }

    // ===== FUNÇÃO RESET TIMER =====
    function resetTimerOnActivity() {
        if (!resetOnActivity) return;
        
        if (sessionTimeLeft > 0) {
            sessionTimeLeft = <?= TEMPO_SESSAO ?>;
            updateTimerDisplay();
            salvarTimerCompartilhado();
            lastUserActivity = Date.now();
            
            fetch('index.php?update_session=1&t=' + Date.now())
                .catch(error => console.error('Erro ao resetar sessão:', error));
        }
    }

    // ==================== BROADCAST CHANNEL ====================
    let broadcastChannel = null;
    
    function initBroadcastChannel() {
        if (!window.BroadcastChannel) {
            console.warn('BroadcastChannel não suportado neste navegador');
            return;
        }
        
        broadcastChannel = new BroadcastChannel('session_channel');
        
        broadcastChannel.addEventListener('message', (event) => {
            if (event.data.tabId === currentTabId) return;
            
            switch (event.data.type) {
                case 'FORCE_LOGOUT':
                    if (event.data.sourceTab !== currentTabId) {
                        if (timerInterval) clearInterval(timerInterval);
                        if (heartbeatInterval) clearInterval(heartbeatInterval);
                        window.location.href = 'index.php?expired=true';
                    }
                    break;
                    
                case 'AUTOLOGOUT_TOGGLED':
                    autoLogoutEnabled = event.data.enabled;
                    updateAutoLogoutButton(event.data.enabled);
                    
                    if (isLogAba) {
                        location.reload();
                    } else {
                        if (event.data.enabled) {
                            sessionTimeLeft = <?= TEMPO_SESSAO ?>;
                            initTimer();
                        } else {
                            stopTimer();
                        }
                    }
                    break;
            }
        });
    }

    // ==================== FUNÇÕES DE TIMER ====================
    
    function updateTimerDisplay() {
        const timerElement = document.getElementById('sessionTimer');
        const timeElement = document.getElementById('sessionTime');
        
        if (!timerElement || !timeElement) return;
        
        timerElement.style.display = 'block';
        timeElement.textContent = formatTime(sessionTimeLeft);
        
        if (sessionTimeLeft < 60) {
            timerElement.style.background = 'rgba(231, 76, 60, 0.95)';
            timerElement.style.borderLeft = '4px solid #c0392b';
            timerElement.style.boxShadow = '0 0 20px rgba(231, 76, 60, 0.8)';
            timerElement.style.color = '#fff';
        } else if (sessionTimeLeft < 300) {
            timerElement.style.background = 'rgba(241, 196, 15, 0.95)';
            timerElement.style.borderLeft = '4px solid #f39c12';
            timerElement.style.boxShadow = '0 0 20px rgba(241, 196, 15, 0.8)';
            timeElement.style.color = '#000';
        } else {
            timerElement.style.background = 'rgba(46, 204, 113, 0.9)';
            timerElement.style.borderLeft = '4px solid #27ae60';
            timerElement.style.boxShadow = '0 0 15px rgba(46, 204, 113, 0.5)';
            timeElement.style.color = '#fff';
        }
    }
    
    function formatTime(seconds) {
        if (seconds < 0) seconds = 0;
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        return [
            hours.toString().padStart(2, '0'),
            minutes.toString().padStart(2, '0'),
            secs.toString().padStart(2, '0')
        ].join(':');
    }
    
    function initTimer() {
    if (timerInterval) clearInterval(timerInterval);
    if (heartbeatInterval) clearInterval(heartbeatInterval);
    
    // Garantir que o timer está correto antes de iniciar
    if (sessionTimeLeft <= 0 || sessionTimeLeft > <?= TEMPO_SESSAO ?>) {
        sessionTimeLeft = <?= TEMPO_SESSAO ?>;
    }
    
    timerInterval = setInterval(() => {
        if (sessionTimeLeft > 0) {
            sessionTimeLeft--;
            updateTimerDisplay();
            salvarTimerCompartilhado();
            if (sessionTimeLeft <= 0) {
                window.location.href = 'index.php?expired=true';
            }
        }
    }, 1000);
    
    heartbeatInterval = setInterval(syncWithServer, 30000);
    syncWithServer();
}
    
    function stopTimer() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        
        if (heartbeatInterval) {
            clearInterval(heartbeatInterval);
            heartbeatInterval = null;
        }
        
        const timerElement = document.getElementById('sessionTimer');
        if (timerElement) {
            timerElement.style.display = 'none';
        }
    }
    
    async function syncWithServer() {
        if (isLogAba || !autoLogoutEnabled) return;
        
        try {
            const response = await fetch('index.php?heartbeat=1&t=' + Date.now());
            const data = await response.json();
            
            if (data.expired) {
                forceLogout('Sessão expirada pelo servidor');
                return;
            }
            
            if (data.time_left !== undefined) {
                if (resetOnActivity) {
                    sessionTimeLeft = data.time_left;
                    lastServerSync = Date.now();
                    updateTimerDisplay();
                    salvarTimerCompartilhado();
                }
            }
        } catch (error) {
            console.error('Erro no heartbeat:', error);
        }
    }
    
    function updateVisualTimer() {
        if (isLogAba || !autoLogoutEnabled) return;
        
        sessionTimeLeft--;
        salvarTimerCompartilhado();
        
        if (sessionTimeLeft <= 0) {
            fetch('index.php?check_session=1&t=' + Date.now())
                .then(response => response.text())
                .then(status => {
                    if (status === 'expired') {
                        forceLogout('Tempo esgotado');
                    } else {
                        syncWithServer();
                    }
                })
                .catch(() => forceLogout('Erro de comunicação'));
            return;
        }
        
        updateTimerDisplay();
    }
    
    function forceLogout(reason) {
    // Limpar timer compartilhado ao fazer logout
    localStorage.removeItem('timer_compartilhado');
    
    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({
            type: 'SESSION_EXPIRED',
            reason: reason
        });
    }
    
    window.location.href = 'index.php?action=logout';
}
    
    function setupActivityListeners() {
        if (isLogAba) return;
        
        const activityEvents = ['mousemove', 'keypress', 'click', 'scroll', 'touchstart', 'touchmove'];
        let activityTimeout;
        
        function handleUserActivity() {
            if (!autoLogoutEnabled) return;
            
            if (activityTimeout) clearTimeout(activityTimeout);
            
            activityTimeout = setTimeout(() => {
                resetTimerOnActivity();
            }, 200);
        }
        
        activityEvents.forEach(event => {
            document.removeEventListener(event, handleUserActivity);
            document.addEventListener(event, handleUserActivity, { passive: true });
        });
    }
    
    function sendGlobalLogout() {
        if (broadcastChannel) {
            broadcastChannel.postMessage({
                type: 'FORCE_LOGOUT',
                tabId: currentTabId,
                sourceTab: currentTabId,
                timestamp: Date.now()
            });
        }
    }
    
    function sendAutoLogoutToggle(enabled) {
        if (broadcastChannel) {
            broadcastChannel.postMessage({
                type: 'AUTOLOGOUT_TOGGLED',
                enabled: enabled,
                tabId: currentTabId,
                timestamp: Date.now()
            });
        }
    }
    
    window.globalLogout = async function() {
    if (!confirm('Deseja realmente sair do sistema?')) return;
    
    // Limpar timer compartilhado ao fazer logout
    localStorage.removeItem('timer_compartilhado');
    
    try {
        const formData = new FormData();
        formData.append('action', 'logout');
        formData.append('csrf_token', csrfToken);
        
        await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        sendGlobalLogout();
        window.location.href = 'index.php';
    } catch (error) {
        window.location.href = 'index.php?action=logout';
    }
};
    
    window.updateAutoLogoutButton = function(enabled) {
        const buttons = document.querySelectorAll('.btn-autologout');
        buttons.forEach(button => {
            if (enabled) {
                button.style.background = '#27ae60';
                button.innerHTML = '<span style="font-size:16px;">⏰</span> Auto-logout ON';
            } else {
                button.style.background = '#dc2626';
                button.innerHTML = '<span style="font-size:16px;">🔒</span> Auto-logout OFF';
            }
        });
        
        const indicators = document.querySelectorAll('.status-indicator');
        indicators.forEach(indicator => {
            const dot = indicator.querySelector('.status-dot');
            const text = indicator.querySelector('span:not(.status-dot)');
            if (enabled) {
                indicator.style.background = '#d5f4e6';
                indicator.style.color = '#27ae60';
                if (dot) dot.style.background = '#27ae60';
                if (text) text.textContent = 'Auto-logout: ON';
            } else {
                indicator.style.background = '#f0f0f0';
                indicator.style.color = '#7f8c8d';
                if (dot) dot.style.background = '#95a5a6';
                if (text) text.textContent = 'Auto-logout: OFF';
            }
        });
    };
    
    window.toggleAutoLogout = function() {
        const currentState = <?= $_SESSION['auto_logout'] ? 'true' : 'false' ?>;
        const newState = currentState ? 'off' : 'on';
        
        if (confirm('Deseja ' + (currentState ? 'DESATIVAR' : 'ATIVAR') + ' o auto-logout?')) {
            sendAutoLogoutToggle(!currentState);
            window.location.href = 'index.php?toggle_autologout=' + newState + '&aba=' + currentAba;
        }
    };
    
    window.validarTempoAutologout = function() {
        const tempo = document.getElementById('tempo_autologout').value;
        
        if (tempo < 60) {
            alert('O tempo mínimo é 60 segundos (1 minuto)');
            return false;
        }
        
        if (tempo > 86400) {
            alert('O tempo máximo é 86400 segundos (24 horas)');
            return false;
        }
        
        return true;
    };
    
    function monitorWhatsAppStatus() {
        let statusAtual = document.querySelector('.status')?.classList.contains('online') ? 'online' : 
                         document.querySelector('.status')?.classList.contains('qr') ? 'qr' : 'offline';
        
        const intervalo = setInterval(() => {
            fetch('?api_status=1&t=' + Date.now())
                .then(r => r.text())
                .then(novoStatus => {
                    if (novoStatus !== statusAtual) {
                        clearInterval(intervalo);
                        location.reload();
                    }
                })
                .catch(() => {});
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
    
    // ==================== INICIALIZAÇÃO ====================
    document.addEventListener('DOMContentLoaded', function() {
        // Carregar timer compartilhado entre abas
        carregarTimerCompartilhado();
        
        initBroadcastChannel();
        
        if (!isLogAba) {
            initTimer();
        }
        
        const statusDiv = document.querySelector('.status');
        if (statusDiv && currentAba === 'config') {
            monitorWhatsAppStatus();
        }
        
        if (window.location.search.includes('salvo=1')) {
            const params = new URLSearchParams(window.location.search);
            const newUrl = window.location.pathname + (params.get('aba') ? '?aba=' + params.get('aba') : '');
            window.history.replaceState({}, document.title, newUrl);
        }
        
        const msg = document.getElementById('msg');
        if (msg) {
            setTimeout(() => {
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            }, 3000);
        }
        
        setupActivityListeners();
    });
    
    window.addEventListener('beforeunload', function() {
        if (timerInterval) clearInterval(timerInterval);
        if (heartbeatInterval) clearInterval(heartbeatInterval);
        if (broadcastChannel) broadcastChannel.close();
        salvarTimerCompartilhado();
    });
    
    // ==================== FUNÇÕES GLOBAIS EXPORTADAS ====================
    window.abrirModalAlterarSenhaDashboard = function() {
        const modal = document.getElementById('modalAlterarSenhaDashboard');
        if (modal) modal.style.display = 'flex';
        document.getElementById('senha_atual')?.focus();
    };
    
    window.fecharModalAlterarSenhaDashboard = function() {
        const modal = document.getElementById('modalAlterarSenhaDashboard');
        if (modal) modal.style.display = 'none';
        document.getElementById('senha_atual').value = '';
        document.getElementById('nova_senha').value = '';
        document.getElementById('confirmar_nova_senha').value = '';
    };
    
    window.validarSenhaDashboard = function() {
        const senhaAtual = document.getElementById('senha_atual');
        const novaSenha = document.getElementById('nova_senha');
        const confirmarSenha = document.getElementById('confirmar_nova_senha');
        
        if (!senhaAtual.value) {
            alert('Digite sua senha atual!');
            senhaAtual.focus();
            return false;
        }
        
        if (novaSenha.value !== confirmarSenha.value) {
            alert('As novas senhas não coincidem!');
            confirmarSenha.focus();
            return false;
        }
        
        if (novaSenha.value.length < 6) {
            alert('A nova senha deve ter no mínimo 6 caracteres!');
            novaSenha.focus();
            return false;
        }
        
        return confirm('Deseja alterar sua senha?');
    };
    
    window.testarTelegram = function() {
        const resultDiv = document.getElementById('telegramTestResult');
        if (resultDiv) {
            resultDiv.innerHTML = '⏳ Enviando...';
            resultDiv.style.color = '#666';
        }
        
        fetch('?testar_telegram=1&t=' + Date.now())
            .then(response => response.json())
            .then(data => {
                if (resultDiv) {
                    resultDiv.innerHTML = data.sucesso ? '✅ ' + data.mensagem : '❌ ' + data.mensagem;
                    resultDiv.style.color = data.sucesso ? '#28a745' : '#dc3545';
                }
            })
            .catch(error => {
                if (resultDiv) {
                    resultDiv.innerHTML = '❌ Erro ao testar: ' + error.message;
                    resultDiv.style.color = '#dc3545';
                }
            });
    };
    
    window.toggleClientSecretVisibility = function() {
        const secretField = document.getElementById('mkauth_client_secret');
        const button = event.currentTarget;
        
        if (secretField.type === 'password') {
            secretField.type = 'text';
            button.innerHTML = '🙈';
            button.title = 'Esconder Client Secret';
        } else {
            secretField.type = 'password';
            button.innerHTML = '👁️';
            button.title = 'Mostrar Client Secret';
        }
    };
    
    window.exportarCSV = function() {
        let csv = 'Data;Hora;Vencimento;IP;Cliente;CPF;Título\n';
        
        document.querySelectorAll('table tbody tr').forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length >= 7) {
                csv += `"${cells[0].textContent}";"${cells[1].textContent}";"${cells[2].textContent}";"${cells[3].textContent}";"${cells[4].textContent}";"${cells[5].textContent}";"${cells[6].textContent}"\n`;
            }
        });
        
        const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'pix_acessos_<?= $diaSelecionado ?? date('Y-m-d') ?>.csv';
        link.click();
    };
    
    window.limparLogsFiltros = function() {
        if (confirm('Deseja limpar os logs de filtros?')) {
            window.location.href = 'index.php?aba=dashboard&limpar_filtros=1';
        }
    };
})();
</script>
