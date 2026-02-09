<?php
/*************************************************
 *            SISTEMA DE AUTENTICAÇÃO
 *************************************************/
session_start();

// Configurações - USANDO MESMO DIRETÓRIO DOS LOGS
$ARQUIVO_USUARIOS = '/var/log/pix_acessos/usuarios.json';
$ARQUIVO_LOG_ACESSOS = '/var/log/pix_acessos/acessos_usuarios.log';
$TEMPO_SESSAO = 1800; // Tempo de Logout 7200=2hs = 1800=30 minutos para testes

// CORREÇÃO: Endpoint para verificação de sessão via AJAX/JavaScript
if (isset($_GET['check_session'])) {
    if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
        if (isset($_SESSION['ultimo_acesso']) && (time() - $_SESSION['ultimo_acesso'] > $TEMPO_SESSAO)) {
            echo 'expired';
        } else {
            echo 'active';
        }
    } else {
        echo 'expired';
    }
    exit;
}

// CORREÇÃO: Endpoint para atualizar tempo de sessão
if (isset($_GET['update_session'])) {
    if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
        $_SESSION['ultimo_acesso'] = time();
        echo json_encode(['success' => true, 'timestamp' => time()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    }
    exit;
}

// CORREÇÃO 1: Verificar se foi redirecionado por expiração
if (isset($_GET['expired']) && $_GET['expired'] == 'true') {
    // Se chegou aqui por expiração, destruir sessão e mostrar login
    session_destroy();
    // Remover parâmetro da URL
    header('Location: pix_dashboard.php');
    exit;
}

/*************************************************
 * FUNÇÕES PARA ARQUIVO JSON
 *************************************************/
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

function carregarUsuarios($arquivo) {
    // Tenta carregar do arquivo
    if (file_exists($arquivo)) {
        $conteudo = @file_get_contents($arquivo);
        if ($conteudo !== false) {
            $usuarios = json_decode($conteudo, true);
            if (is_array($usuarios)) {
                return $usuarios;
            }
        }
    }
    
    // Se não existir ou erro, cria admin padrão
    $usuarios = [
        'admin' => [
            'senha_hash' => '$2y$10$WN/a1/7yFMPbsPyfM6.ysuRtFBqG8RpoAF/DwpyxFTu2tnlo1ekde',
            'nome' => 'Administrador',
            'email' => 'admin@sistema.com',
            'nivel' => 'admin',
            'status' => 'ativo',
            'data_criacao' => date('Y-m-d H:i:s'),
            'ip_cadastro' => '127.0.0.1',
            'ultimo_acesso' => null,
            'ip_ultimo_acesso' => null
        ]
    ];
    
    // Tenta salvar
    @file_put_contents($arquivo, json_encode($usuarios, JSON_PRETTY_PRINT));
    
    return $usuarios;
}

function salvarUsuarios($arquivo, $usuarios) {
    // Converte para JSON
    $json = json_encode($usuarios, JSON_PRETTY_PRINT);
    
    // Salva no arquivo (igual ao pix.php)
    $resultado = @file_put_contents($arquivo, $json);
    
    return $resultado !== false;
}

function atualizarUltimoAcessoJSON($usuario, $arquivo) {
    $usuarios = carregarUsuarios($arquivo);
    
    if (isset($usuarios[$usuario])) {
        $usuarios[$usuario]['ultimo_acesso'] = date('Y-m-d H:i:s');
        $usuarios[$usuario]['ip_ultimo_acesso'] = getRealIp();
        
        return salvarUsuarios($arquivo, $usuarios);
    }
    
    return false;
}

// CORREÇÃO: Função registrarLogAcesso com cálculo correto do tempo ativo
function registrarLogAcesso($arquivo_log, $usuario, $nome, $nivel, $acao, $tempo_ativo = null, $timestamp = null, $login_time = null) {
    // Usar timestamp fornecido ou current time
    $timestamp = $timestamp ?: time();
    
    // Formato brasileiro dd/mm/yyyy H:i:s
    $data_brasileira = date('d/m/Y', $timestamp);
    $hora_brasileira = date('H:i:s', $timestamp);
    
    $ip = getRealIp();
    
    // CORREÇÃO: Calcular tempo ativo corretamente se não fornecido
    if ($tempo_ativo === null && $login_time !== null && ($acao === 'LOGOUT' || $acao === 'LOGOUT_AUTO' || $acao === 'SESSÃO_EXPIRADA')) {
        $tempo_ativo_segundos = $timestamp - $login_time;
        $tempo_ativo = $tempo_ativo_segundos > 0 ? gmdate('H:i:s', $tempo_ativo_segundos) : '00:00:00';
    }
    
    // Formato: DATA | HORA | USUÁRIO | NOME | NÍVEL | IP | AÇÃO | TEMPO ATIVO
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
    
    // Garantir que o diretório existe
    $diretorio = dirname($arquivo_log);
    if (!is_dir($diretorio)) {
        @mkdir($diretorio, 0777, true);
    }
    
    // Verificar se o diretório é gravável
    if (!is_writable($diretorio) && is_dir($diretorio)) {
        @chmod($diretorio, 0777);
    }
    
    // Se o arquivo não existir, tentar criar
    if (!file_exists($arquivo_log)) {
        @touch($arquivo_log);
        @chmod($arquivo_log, 0666);
    }
    
    // Verificar se o arquivo é gravável
    if (file_exists($arquivo_log) && !is_writable($arquivo_log)) {
        @chmod($arquivo_log, 0666);
    }
    
    // Usar o MESMO método que funciona para o pix_log (não o JSON)
    // O arquivo pix_log_*.log usa file_put_contents com FILE_APPEND
    $resultado = @file_put_contents($arquivo_log, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Log para debug (remover depois)
    if ($resultado === false) {
        error_log("FALHA ao gravar log de acesso: $arquivo_log");
        error_log("Usuário: $usuario, Ação: $acao");
        error_log("Diretório gravável: " . (is_writable($diretorio) ? 'sim' : 'não'));
        error_log("Arquivo gravável: " . (file_exists($arquivo_log) ? (is_writable($arquivo_log) ? 'sim' : 'não') : 'não existe'));
        
        // Tentar método alternativo
        $resultado = @file_put_contents($arquivo_log, $log_entry, FILE_APPEND);
    }
    
    return $resultado !== false;
}

function verificarLoginJSON($usuario, $senha, $arquivo) {
    $usuarios = carregarUsuarios($arquivo);
    
    if (isset($usuarios[$usuario]) && $usuarios[$usuario]['status'] === 'ativo') {
        if (password_verify($senha, $usuarios[$usuario]['senha_hash'])) {
            return $usuarios[$usuario];
        }
    }
    return false;
}

function adicionarUsuarioJSON($dados, $arquivo) {
    $usuarios = carregarUsuarios($arquivo);
    
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
    
    // Cria novo usuário
    $usuarios[$dados['usuario']] = [
        'senha_hash' => password_hash($dados['senha'], PASSWORD_DEFAULT),
        'nome' => $dados['nome'],
        'email' => $dados['email'] ?? '',
        'nivel' => $dados['nivel'] ?? 'usuario',
        'status' => 'ativo',
        'data_criacao' => date('Y-m-d H:i:s'),
        'ip_cadastro' => getRealIp(),
        'ultimo_acesso' => null,
        'ip_ultimo_acesso' => null
    ];
    
    // Tenta salvar
    if (salvarUsuarios($arquivo, $usuarios)) {
        return ['success' => true, 'message' => 'Usuário cadastrado com sucesso!'];
    }
    
    return ['success' => false, 'message' => 'Erro ao salvar usuário!'];
}

function alterarSenhaJSON($usuario, $senha_atual, $nova_senha, $arquivo) {
    $usuarios = carregarUsuarios($arquivo);
    
    if (!isset($usuarios[$usuario])) {
        return ['success' => false, 'message' => 'Usuário não encontrado!'];
    }
    
    // Verificar senha atual
    if (!password_verify($senha_atual, $usuarios[$usuario]['senha_hash'])) {
        return ['success' => false, 'message' => 'Senha atual incorreta!'];
    }
    
    // Validar nova senha
    if (strlen($nova_senha) < 6) {
        return ['success' => false, 'message' => 'A nova senha deve ter no mínimo 6 caracteres!'];
    }
    
    // Atualizar senha
    $usuarios[$usuario]['senha_hash'] = password_hash($nova_senha, PASSWORD_DEFAULT);
    
    // Salvar
    if (salvarUsuarios($arquivo, $usuarios)) {
        return ['success' => true, 'message' => 'Senha alterada com sucesso!'];
    }
    
    return ['success' => false, 'message' => 'Erro ao salvar nova senha!'];
}

function alterarSenhaAdminJSON($usuario_admin, $usuario_alvo, $nova_senha, $arquivo) {
    $usuarios = carregarUsuarios($arquivo);
    
    if (!isset($usuarios[$usuario_admin]) || $usuarios[$usuario_admin]['nivel'] !== 'admin') {
        return ['success' => false, 'message' => 'Acesso negado! Apenas administradores podem alterar senhas de outros usuários.'];
    }
    
    if (!isset($usuarios[$usuario_alvo])) {
        return ['success' => false, 'message' => 'Usuário alvo não encontrado!'];
    }
    
    // Validar nova senha
    if (strlen($nova_senha) < 6) {
        return ['success' => false, 'message' => 'A nova senha deve ter no mínimo 6 caracteres!'];
    }
    
    // Atualizar senha
    $usuarios[$usuario_alvo]['senha_hash'] = password_hash($nova_senha, PASSWORD_DEFAULT);
    
    // Salvar
    if (salvarUsuarios($arquivo, $usuarios)) {
        return ['success' => true, 'message' => 'Senha do usuário ' . $usuario_alvo . ' alterada com sucesso!'];
    }
    
    return ['success' => false, 'message' => 'Erro ao salvar nova senha!'];
}

/*************************************************
 * VERIFICAÇÕES DO SISTEMA
 *************************************************/
// Verificar logout
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    // Registrar log de logout antes de destruir a sessão
    if (isset($_SESSION['usuario'])) {
        $tempo_ativo = isset($_SESSION['login_time']) ? 
            (time() - $_SESSION['login_time']) : null;
        
        registrarLogAcesso(
            $ARQUIVO_LOG_ACESSOS,
            $_SESSION['usuario'],
            $_SESSION['nome'] ?? 'N/A',
            $_SESSION['nivel'] ?? 'N/A',
            'LOGOUT',
            $tempo_ativo ? gmdate('H:i:s', $tempo_ativo) : 'N/A',
            time(),
            $_SESSION['login_time'] ?? null
        );
    }
    
    session_destroy();
    header('Location: pix_dashboard.php');
    exit;
}

// Verificar toggle auto-logout
if (isset($_GET['toggle_autologout'])) {
    $novo_status = $_GET['toggle_autologout'] == 'on' ? true : false;
    
    // Registrar log da alteração
    registrarLogAcesso(
        $ARQUIVO_LOG_ACESSOS,
        $_SESSION['usuario'],
        $_SESSION['nome'],
        $_SESSION['nivel'],
        'ALTERAR_AUTO_LOGOUT: ' . ($novo_status ? 'ATIVADO' : 'DESATIVADO'),
        null,
        time(),
        $_SESSION['login_time'] ?? null
    );
    
    $_SESSION['auto_logout'] = $novo_status;
    header('Location: pix_dashboard.php');
    exit;
}

// Verificar alteração de senha (dashboard principal)
if (isset($_POST['alterar_senha_dashboard'])) {
    $resultado = alterarSenhaJSON(
        $_SESSION['usuario'],
        $_POST['senha_atual'] ?? '',
        $_POST['nova_senha'] ?? '',
        $ARQUIVO_USUARIOS
    );
    
    $_SESSION['mensagem_senha'] = $resultado['message'];
    $_SESSION['tipo_mensagem_senha'] = $resultado['success'] ? 'sucesso' : 'erro';
    
    // Registrar log de alteração de senha
    if ($resultado['success']) {
        registrarLogAcesso(
            $ARQUIVO_LOG_ACESSOS,
            $_SESSION['usuario'],
            $_SESSION['nome'],
            $_SESSION['nivel'],
            'ALTERAR_SENHA_PRÓPRIA',
            null,
            time(),
            $_SESSION['login_time'] ?? null
        );
    }
    
    header('Location: pix_dashboard.php');
    exit;
}

// CORREÇÃO: Verificar logout automático via parâmetro específico
if (isset($_GET['action']) && $_GET['action'] == 'auto_logout') {
    // Registrar log de logout automático
    if (isset($_SESSION['usuario'])) {
        $tempo_ativo = isset($_SESSION['login_time']) ? 
            (time() - $_SESSION['login_time']) : null;
        
        registrarLogAcesso(
            $ARQUIVO_LOG_ACESSOS,
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
    header('Location: pix_dashboard.php?expired=true');
    exit;
}

// CORREÇÃO CRÍTICA: Verificar se está logado ANTES de qualquer processamento
// Esta verificação deve acontecer em TODAS as páginas
$pagina_atual = basename($_SERVER['PHP_SELF']);
$is_pagina_usuarios = isset($_GET['page']) && $_GET['page'] == 'usuarios';

// Verificar se está logado
if (isset($_SESSION['logado']) && $_SESSION['logado'] === true) {
    // Verificar auto-logout
    if (!isset($_SESSION['auto_logout'])) {
        $_SESSION['auto_logout'] = true;
    }
    
    // CORREÇÃO: Verificar tempo da sessão com registro de log correto
    // Esta verificação deve acontecer em TODAS as páginas protegidas
    if ($_SESSION['auto_logout'] && isset($_SESSION['ultimo_acesso']) && 
        (time() - $_SESSION['ultimo_acesso'] > $TEMPO_SESSAO)) {
        
        // Capturar timestamp real da expiração
        $expiration_time = time();
        
        // Registrar log com timestamp correto ANTES de destruir a sessão
        registrarLogAcesso(
            $ARQUIVO_LOG_ACESSOS,
            $_SESSION['usuario'],
            $_SESSION['nome'],
            $_SESSION['nivel'],
            'SESSÃO_EXPIRADA',
            gmdate('H:i:s', $TEMPO_SESSAO),
            $expiration_time,
            $_SESSION['login_time'] ?? null
        );
        
        // Destruir sessão
        session_destroy();
        
        // CORREÇÃO: Redirecionar para página de logout automático
        // Usar JavaScript para garantir o redirecionamento mesmo em páginas diferentes
        echo '<script>';
        echo 'window.location.href = "pix_dashboard.php?action=auto_logout";';
        echo '</script>';
        exit;
    }
    
    // Atualizar tempo de acesso
    $_SESSION['ultimo_acesso'] = time();
} else {
    // Não está logado - verificar login
    if (isset($_POST['usuario']) && isset($_POST['senha'])) {
        $usuarioDB = verificarLoginJSON($_POST['usuario'], $_POST['senha'], $ARQUIVO_USUARIOS);
        
        if ($usuarioDB) {
            // Login bem-sucedido
            $_SESSION['logado'] = true;
            $_SESSION['usuario'] = $_POST['usuario'];
            $_SESSION['nome'] = $usuarioDB['nome'];
            $_SESSION['nivel'] = $usuarioDB['nivel'];
            $_SESSION['login_time'] = time(); // CORREÇÃO: Armazenar tempo do login
            $_SESSION['ultimo_acesso'] = time();
            $_SESSION['ip'] = getRealIp();
            $_SESSION['auto_logout'] = true;
            
            // ATUALIZAR ÚLTIMO ACESSO NO ARQUIVO JSON
            atualizarUltimoAcessoJSON($_POST['usuario'], $ARQUIVO_USUARIOS);
            
            // REGISTRAR LOG DE LOGIN
            registrarLogAcesso(
                $ARQUIVO_LOG_ACESSOS,
                $_POST['usuario'],
                $usuarioDB['nome'],
                $usuarioDB['nivel'],
                'LOGIN',
                null,
                time(),
                null
            );
            
            header('Location: pix_dashboard.php');
            exit;
        } else {
            // Registrar tentativa de login falha
            registrarLogAcesso(
                $ARQUIVO_LOG_ACESSOS,
                $_POST['usuario'] ?? 'desconhecido',
                'N/A',
                'N/A',
                'LOGIN_FALHOU',
                null,
                time(),
                null
            );
            
            $erro_login = 'Usuário ou senha incorretos!';
        }
    }
    
    // Mostrar tela de login
    mostrarTelaLogin($erro_login ?? null, $ARQUIVO_USUARIOS);
    exit;
}

/*************************************************
 * GERENCIAMENTO DE USUÁRIOS (SEM DEBUG)
 *************************************************/
// Processar ações de gerenciamento
if (isset($_GET['page']) && $_GET['page'] == 'usuarios') {
    // Verificar se é admin
    if ($_SESSION['nivel'] !== 'admin') {
        header('Location: pix_dashboard.php');
        exit;
    }
    
    $mensagem = '';
    $tipo_mensagem = '';
    
    // Adicionar usuário
    if (isset($_POST['adicionar_usuario'])) {
        $dados = [
            'usuario' => trim($_POST['usuario']),
            'nome' => trim($_POST['nome']),
            'email' => trim($_POST['email'] ?? ''),
            'senha' => $_POST['senha'],
            'nivel' => $_POST['nivel'] ?? 'usuario'
        ];
        
        $resultado = adicionarUsuarioJSON($dados, $ARQUIVO_USUARIOS);
        $mensagem = $resultado['message'];
        $tipo_mensagem = $resultado['success'] ? 'sucesso' : 'erro';
        
        // Registrar log de criação de usuário
        if ($resultado['success']) {
            registrarLogAcesso(
                $ARQUIVO_LOG_ACESSOS,
                $_SESSION['usuario'],
                $_SESSION['nome'],
                $_SESSION['nivel'],
                'CRIAR_USUÁRIO: ' . $dados['usuario'],
                null,
                time(),
                $_SESSION['login_time'] ?? null
            );
        }
    }
    
    // Excluir usuário
    if (isset($_GET['excluir'])) {
        $usuario_excluir = $_GET['excluir'];
        
        if ($usuario_excluir != $_SESSION['usuario'] && $usuario_excluir != 'admin') {
            $usuarios = carregarUsuarios($ARQUIVO_USUARIOS);
            
            if (isset($usuarios[$usuario_excluir])) {
                unset($usuarios[$usuario_excluir]);
                
                if (salvarUsuarios($ARQUIVO_USUARIOS, $usuarios)) {
                    $mensagem = 'Usuário excluído com sucesso!';
                    $tipo_mensagem = 'sucesso';
                    
                    // Registrar log de exclusão
                    registrarLogAcesso(
                        $ARQUIVO_LOG_ACESSOS,
                        $_SESSION['usuario'],
                        $_SESSION['nome'],
                        $_SESSION['nivel'],
                        'EXCLUIR_USUÁRIO: ' . $usuario_excluir,
                        null,
                        time(),
                        $_SESSION['login_time'] ?? null
                    );
                } else {
                    $mensagem = 'Erro ao excluir usuário!';
                    $tipo_mensagem = 'erro';
                }
            } else {
                $mensagem = 'Usuário não encontrado!';
                $tipo_mensagem = 'erro';
            }
        } else {
            $mensagem = 'Você não pode excluir este usuário!';
            $tipo_mensagem = 'erro';
        }
    }
    
    // Resetar senha
    if (isset($_GET['reset_senha'])) {
        $usuario_reset = $_GET['reset_senha'];
        $usuarios = carregarUsuarios($ARQUIVO_USUARIOS);
        
        if (isset($usuarios[$usuario_reset])) {
            $usuarios[$usuario_reset]['senha_hash'] = password_hash('123456', PASSWORD_DEFAULT);
            
            if (salvarUsuarios($ARQUIVO_USUARIOS, $usuarios)) {
                $mensagem = 'Senha resetada para: 123456';
                $tipo_mensagem = 'sucesso';
                
                // Registrar log de reset de senha
                registrarLogAcesso(
                    $ARQUIVO_LOG_ACESSOS,
                    $_SESSION['usuario'],
                    $_SESSION['nome'],
                    $_SESSION['nivel'],
                    'RESET_SENHA: ' . $usuario_reset,
                    null,
                    time(),
                    $_SESSION['login_time'] ?? null
                );
            } else {
                $mensagem = 'Erro ao resetar senha!';
                $tipo_mensagem = 'erro';
            }
        }
    }
    
    // Alterar senha (admin alterando para outro usuário)
    if (isset($_POST['alterar_senha_admin'])) {
        $resultado = alterarSenhaAdminJSON(
            $_SESSION['usuario'],
            $_POST['usuario_alvo'] ?? '',
            $_POST['nova_senha_admin'] ?? '',
            $ARQUIVO_USUARIOS
        );
        
        $mensagem = $resultado['message'];
        $tipo_mensagem = $resultado['success'] ? 'sucesso' : 'erro';
        
        // Registrar log de alteração de senha (admin)
        if ($resultado['success']) {
            registrarLogAcesso(
                $ARQUIVO_LOG_ACESSOS,
                $_SESSION['usuario'],
                $_SESSION['nome'],
                $_SESSION['nivel'],
                'ALTERAR_SENHA_ADMIN: ' . $_POST['usuario_alvo'],
                null,
                time(),
                $_SESSION['login_time'] ?? null
            );
        }
    }
    
    // Buscar todos os usuários
    $usuarios = carregarUsuarios($ARQUIVO_USUARIOS);
    
    // Mostrar página de gerenciamento
    mostrarGerenciamentoUsuarios($usuarios, $mensagem, $tipo_mensagem, $ARQUIVO_USUARIOS);
    exit;
}

/*************************************************
 * FUNÇÕES DO DASHBOARD (mantidas do original)
 *************************************************/
function contarDia($data) {
    $arquivo = "/var/log/pix_acessos/pix_log_$data.log";
    
    if (!file_exists($arquivo)) {
        return 0;
    }
    
    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return $linhas ? count($linhas) : 0;
}

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

/*************************************************
 * DATAS
 *************************************************/
$hoje   = date('Y-m-d');
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

/*************************************************
 * FUNÇÃO PARA MOSTRAR TELA DE LOGIN
 *************************************************/
function mostrarTelaLogin($erro = null, $arquivo_usuarios = null) {
    $total_usuarios = 0;
    if ($arquivo_usuarios && file_exists($arquivo_usuarios)) {
        $conteudo = file_get_contents($arquivo_usuarios);
        $usuarios = json_decode($conteudo, true);
        $total_usuarios = is_array($usuarios) ? count($usuarios) : 0;
    }
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Login - Dashboard Pix</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Arial', sans-serif;
            }
            
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            
            .login-container {
                background: white;
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.2);
                overflow: hidden;
                width: 100%;
                max-width: 450px;
                animation: slideUp 0.5s ease-out;
            }
            
            .login-header {
                background: linear-gradient(to right, #00b894, #00a085);
                padding: 40px 20px;
                text-align: center;
                color: white;
                position: relative;
            }
            
            .login-header h1 {
                font-size: 28px;
                margin-bottom: 10px;
            }
            
            .login-header p {
                opacity: 0.9;
                font-size: 14px;
            }
            
            .admin-badge {
                position: absolute;
                top: 10px;
                right: 10px;
                background: rgba(255,255,255,0.2);
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: bold;
            }
            
            .login-body {
                padding: 40px;
            }
            
            .form-group {
                margin-bottom: 25px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 8px;
                color: #333;
                font-weight: 600;
                font-size: 14px;
            }
            
            .form-control {
                width: 100%;
                padding: 15px;
                border: 2px solid #e1e5e9;
                border-radius: 10px;
                font-size: 16px;
                transition: all 0.3s;
            }
            
            .form-control:focus {
                outline: none;
                border-color: #00b894;
                box-shadow: 0 0 0 3px rgba(0, 184, 148, 0.1);
            }
            
            .btn-login {
                width: 100%;
                padding: 16px;
                background: linear-gradient(to right, #00b894, #00a085);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                margin-top: 10px;
            }
            
            .btn-login:hover {
                background: linear-gradient(to right, #00a085, #008b74);
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0, 184, 148, 0.3);
            }
            
            .btn-login:active {
                transform: translateY(0);
            }
            
            .alert {
                padding: 15px;
                border-radius: 10px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            
            .alert-danger {
                background-color: #ffeaea;
                color: #e74c3c;
                border: 1px solid #ffc9c9;
            }
            
            .alert-warning {
                background-color: #fff8e1;
                color: #f39c12;
                border: 1px solid #ffeaa7;
            }
            
            .alert-success {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            
            .login-footer {
                text-align: center;
                padding: 20px;
                color: #666;
                font-size: 13px;
                border-top: 1px solid #eee;
            }
            
            .info-session {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 10px;
                margin-top: 20px;
                font-size: 12px;
                color: #666;
                text-align: center;
            }
            
            .user-count {
                display: inline-block;
                background: rgba(255,255,255,0.3);
                color: white;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                margin-top: 10px;
                backdrop-filter: blur(5px);
            }
            
            @keyframes slideUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            @media (max-width: 480px) {
                .login-container {
                    max-width: 100%;
                }
                
                .login-body {
                    padding: 30px 20px;
                }
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-header">
                <div class="admin-badge">Sistema de Usuários</div>
                <h1>📊 Dashboard Pix</h1>
                <p>WebLine Telecom - Acesso Restrito</p>
                <div class="user-count"><?= $total_usuarios ?> usuário(s) cadastrado(s)</div>
            </div>
            
            <div class="login-body">
                <?php if (isset($_GET['expired'])): ?>
                <div class="alert alert-warning">
                    ⚠️ Sua sessão expirou por inatividade. Faça login novamente.
                </div>
                <?php endif; ?>
                
                <?php if (isset($erro)): ?>
                <div class="alert alert-danger">
                    ❌ <?= htmlspecialchars($erro) ?>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="usuario">Usuário</label>
                        <input type="text" 
                               id="usuario" 
                               name="usuario" 
                               class="form-control" 
                               placeholder="Digite seu usuário"
                               required
                               autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label for="senha">Senha</label>
                        <input type="password" 
                               id="senha" 
                               name="senha" 
                               class="form-control" 
                               placeholder="Digite sua senha"
                               required>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        🔐 Entrar no Dashboard
                    </button>
                </form>
                
                <div class="info-session">
                    <p>⏱️ Sessão válida por 2 horas de inatividade</p>
                    <p>🛡️ Acesso restrito à equipe autorizada</p>
                    <p style="margin-top:10px; font-size:11px; color:#999;">
                        Usuário admin padrão: admin / Admin@123
                    </p>
                </div>
            </div>
            
            <div class="login-footer">
                WebLine Telecom &copy; <?= date('Y') ?> - Sistema de Monitoramento<br>
                <small>Autenticação Requerida</small>
            </div>
        </div>
        
        <script>
            // Focar automaticamente no campo de usuário
            document.getElementById('usuario').focus();
            
            // Prevenir reenvio do formulário ao recarregar página
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            // CORREÇÃO: Verificar se há parâmetro de expiração
            if (window.location.href.indexOf('expired=true') !== -1) {
                // Limpar parâmetro da URL
                window.history.replaceState({}, document.title, window.location.href.split('?')[0]);
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

/*************************************************
 * FUNÇÃO PARA MOSTRAR GERENCIAMENTO DE USUÁRIOS (OTIMIZADA PARA MOBILE)
 *************************************************/
function mostrarGerenciamentoUsuarios($usuarios, $mensagem = '', $tipo_mensagem = '', $arquivo_usuarios) {
    // Verificar se o arquivo de log de acessos existe
    global $ARQUIVO_LOG_ACESSOS;
    global $TEMPO_SESSAO;
    $logs_acesso = [];
    $total_logs = 0;
    
    if (file_exists($ARQUIVO_LOG_ACESSOS)) {
        $conteudo_logs = file($ARQUIVO_LOG_ACESSOS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($conteudo_logs) {
            $logs_acesso = array_reverse($conteudo_logs); // Mostrar os mais recentes primeiro
            $total_logs = count($conteudo_logs);
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <title>Gerenciar Usuários - Dashboard Pix</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Arial', sans-serif;
            }
            
            body {
                background: #f5f7fa;
                padding: 15px;
                font-size: 14px;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
            }
            
            .header-admin {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 25px;
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .header-admin h1 {
                color: #2c3e50;
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 20px;
                flex: 1;
                min-width: 200px;
            }
            
            .btn-voltar {
                padding: 12px 20px;
                background: #95a5a6;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: bold;
                transition: all 0.3s;
                text-align: center;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .btn-voltar:hover {
                background: #7f8c8d;
                transform: translateY(-2px);
            }
            
            .card {
                background: white;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 25px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            
            .card h2 {
                color: #2c3e50;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #00b894;
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 18px;
            }
            
            .alert {
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 20px;
                font-size: 14px;
                line-height: 1.5;
            }
            
            .alert-sucesso {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            
            .alert-erro {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            
            .form-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .form-group {
                margin-bottom: 15px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 6px;
                color: #555;
                font-weight: 600;
                font-size: 13px;
            }
            
            .form-control {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-size: 15px;
                min-height: 44px;
            }
            
            .form-control:focus {
                outline: none;
                border-color: #00b894;
                box-shadow: 0 0 0 2px rgba(0, 184, 148, 0.2);
            }
            
            .btn-primary {
                padding: 14px 25px;
                background: #00b894;
                color: white;
                border: none;
                border-radius: 6px;
                font-size: 15px;
                font-weight: bold;
                cursor: pointer;
                transition: all 0.3s;
                min-height: 44px;
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            
            .btn-primary:hover {
                background: #00a085;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0, 184, 148, 0.3);
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
                font-size: 13px;
            }
            
            th, td {
                padding: 12px 10px;
                text-align: left;
                border-bottom: 1px solid #eee;
            }
            
            th {
                background: #f8f9fa;
                font-weight: 600;
                color: #555;
                white-space: nowrap;
            }
            
            tr:hover {
                background: #f9f9f9;
            }
            
            .badge {
                padding: 5px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: bold;
                display: inline-block;
                min-width: 70px;
                text-align: center;
            }
            
            .badge-admin {
                background: #e3f2fd;
                color: #1976d2;
            }
            
            .badge-usuario {
                background: #f3e5f5;
                color: #7b1fa2;
            }
            
            .badge-ativo {
                background: #d4edda;
                color: #155724;
            }
            
            .badge-inativo {
                background: #f8d7da;
                color: #721c24;
            }
            
            .acoes {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
            }
            
            .btn-acao {
                padding: 8px 12px;
                border: none;
                border-radius: 4px;
                font-size: 12px;
                cursor: pointer;
                transition: all 0.3s;
                text-decoration: none;
                display: inline-block;
                min-height: 36px;
                display: flex;
                align-items: center;
                justify-content: center;
                text-align: center;
            }
            
            .btn-excluir {
                background: #e74c3c;
                color: white;
            }
            
            .btn-reset {
                background: #f39c12;
                color: white;
            }
            
            .btn-alterar {
                background: #3498db;
                color: white;
            }
            
            .btn-acao:hover {
                opacity: 0.9;
                transform: translateY(-1px);
            }
            
            .stats {
                display: flex;
                gap: 12px;
                margin-bottom: 20px;
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .stat-card {
                background: white;
                padding: 15px;
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                flex: 1;
                min-width: 140px;
                text-align: center;
            }
            
            .stat-card h3 {
                font-size: 13px;
                color: #666;
                margin-bottom: 5px;
            }
            
            .stat-card .numero {
                font-size: 22px;
                font-weight: bold;
                color: #00b894;
            }
            
            .mobile-only {
                display: none;
            }
            
            .desktop-only {
                display: block;
            }
            
            /* CORREÇÃO: Estilos para tabela de logs */
            .log-table-container {
                overflow-x: auto;
                margin-top: 15px;
                border-radius: 6px;
                border: 1px solid #e0e0e0;
            }
            
            .log-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
                min-width: 800px;
            }
            
            .log-table th {
                background: #f8f9fa;
                padding: 10px 8px;
                text-align: left;
                font-weight: 600;
                color: #555;
                border-bottom: 2px solid #00b894;
                white-space: nowrap;
                position: sticky;
                top: 0;
                z-index: 10;
            }
            
            .log-table td {
                padding: 8px;
                border-bottom: 1px solid #eee;
                vertical-align: top;
            }
            
            .log-table tr:hover {
                background-color: #f9f9f9 !important;
            }
            
            /* Cores para diferentes tipos de ações */
            .log-row-login {
                background-color: #e8f8f5; /* Verde claro */
            }
            
            .log-row-logout {
                background-color: #ffeaea; /* Vermelho claro */
            }
            
            .log-row-logout-auto {
                background-color: #ffebcc; /* Laranja claro */
            }
            
            .log-row-sessao-expirou {
                background-color: #f3e5f5; /* Roxo claro */
            }
            
            .log-row-alteracao {
                background-color: #fff8e1; /* Amarelo claro */
            }
            
            .log-row-falha {
                background-color: #f5f5f5; /* Cinza */
            }
            
            .log-acoes {
                display: flex;
                gap: 10px;
                margin-top: 15px;
                justify-content: center;
            }
            
            /* Estilo para badge de nível */
            .nivel-badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: bold;
                text-transform: uppercase;
            }
            
            .nivel-admin {
                background: #3498db;
                color: white;
            }
            
            .nivel-usuario {
                background: #2ecc71;
                color: white;
            }
            
            /* CORREÇÃO: Timer de sessão para página de usuários - MESMA POSIÇÃO DO DASHBOARD */
            .session-timer-usuarios {
                position: fixed;
                bottom: 10px;
                left: 10px;
                background: rgba(0,0,0,0.7);
                color: white;
                padding: 8px 12px;
                border-radius: 5px;
                font-size: 12px;
                z-index: 1000;
                display: block;
                font-family: monospace;
                min-width: 140px;
            }
            
            /* Modal para alterar senha */
            .modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.5);
                z-index: 2000;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            
            .modal-content {
                background: white;
                padding: 30px;
                border-radius: 10px;
                max-width: 500px;
                width: 100%;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            }
            
            .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 25px;
                padding-bottom: 15px;
                border-bottom: 2px solid #3498db;
            }
            
            .modal-header h3 {
                margin: 0;
                color: #2c3e50;
                font-size: 20px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .close-modal {
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
            
            .close-modal:hover {
                background: #f5f5f5;
                color: #7f8c8d;
            }
            
            /* Para dispositivos com touch */
            @media (hover: none) and (pointer: coarse) {
                .btn-voltar:hover,
                .btn-primary:hover,
                .btn-acao:hover {
                    transform: none;
                    opacity: 1;
                }
                
                tr:hover {
                    background: inherit;
                }
                
                .btn-voltar:active,
                .btn-primary:active,
                .btn-acao:active {
                    opacity: 0.8;
                    transform: scale(0.98);
                }
            }
            
            /* MEDIA QUERIES PARA MOBILE */
            @media (max-width: 768px) {
                body {
                    padding: 10px;
                    font-size: 13px;
                }
                
                .header-admin {
                    flex-direction: column;
                    text-align: center;
                    padding: 15px;
                    gap: 10px;
                }
                
                .header-admin h1 {
                    font-size: 18px;
                    justify-content: center;
                    text-align: center;
                    width: 100%;
                }
                
                .btn-voltar {
                    width: 100%;
                    margin-top: 5px;
                }
                
                .card {
                    padding: 15px;
                    margin-bottom: 15px;
                }
                
                .card h2 {
                    font-size: 16px;
                    margin-bottom: 15px;
                }
                
                .form-grid {
                    grid-template-columns: 1fr;
                    gap: 10px;
                }
                
                .form-control {
                    font-size: 16px;
                    padding: 14px;
                    min-height: 48px;
                }
                
                .btn-primary {
                    width: 100%;
                    padding: 16px;
                    font-size: 16px;
                }
                
                table {
                    display: block;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    font-size: 12px;
                }
                
                th, td {
                    padding: 10px 8px;
                    min-width: 100px;
                }
                
                th:first-child, td:first-child {
                    min-width: 120px;
                }
                
                th:last-child, td:last-child {
                    min-width: 140px;
                }
                
                .badge {
                    font-size: 10px;
                    padding: 4px 8px;
                    min-width: 60px;
                }
                
                .acoes {
                    flex-direction: column;
                    min-width: 110px;
                }
                
                .btn-acao {
                    width: 100%;
                    padding: 10px;
                    margin: 2px 0;
                    font-size: 12px;
                    min-height: 40px;
                }
                
                .stats {
                    flex-direction: column;
                    gap: 8px;
                }
                
                .stat-card {
                    width: 100%;
                    min-width: 100%;
                }
                
                .mobile-only {
                    display: block;
                }
                
                .desktop-only {
                    display: none;
                }
                
                /* Ajustes para tabela de logs mobile */
                .log-table-container {
                    margin: 0 -10px;
                    border-radius: 0;
                    border-left: none;
                    border-right: none;
                }
                
                .log-table {
                    font-size: 11px;
                }
                
                .log-table th,
                .log-table td {
                    padding: 6px 4px;
                }
                
                .nivel-badge {
                    font-size: 9px;
                    padding: 1px 4px;
                }
                
                /* CORREÇÃO: Ajuste do timer para mobile */
                .session-timer-usuarios {
                    left: 5px;
                    bottom: 5px;
                    min-width: 120px;
                    font-size: 11px;
                }
                
                /* Ajuste do modal para mobile */
                .modal {
                    padding: 10px;
                }
                
                .modal-content {
                    padding: 20px;
                }
                
                .modal-header h3 {
                    font-size: 18px;
                }
            }
            
            @media (max-width: 480px) {
                .header-admin h1 {
                    font-size: 16px;
                }
                
                .card {
                    padding: 12px;
                }
                
                .alert {
                    padding: 12px;
                    font-size: 13px;
                }
                
                th, td {
                    padding: 8px 6px;
                    font-size: 11px;
                }
                
                .stat-card h3 {
                    font-size: 12px;
                }
                
                .stat-card .numero {
                    font-size: 20px;
                }
                
                .btn-acao {
                    font-size: 11px;
                    padding: 8px;
                }
                
                .form-group label {
                    font-size: 12px;
                }
                
                /* Ajustes para tabela de logs em telas muito pequenas */
                .log-table th,
                .log-table td {
                    font-size: 10px;
                    padding: 4px 3px;
                }
            }
        </style>
    </head>
    <body>
        <!-- Timer de sessão para página de usuários - MESMA POSIÇÃO DO DASHBOARD -->
        <?php if ($_SESSION['auto_logout']): ?>
        <div class="session-timer-usuarios" id="sessionTimerUsuarios">
            <div style="font-size: 10px; opacity: 0.8;">Auto-logout em:</div>
            <div style="font-size: 14px; font-weight: bold;" id="sessionTimeUsuarios">00:30:00</div>
        </div>
        <?php endif; ?>
        
        <div class="container">
            <div class="header-admin">
                <h1>👥 Gerenciamento de Usuários</h1>
                <a href="pix_dashboard.php" class="btn-voltar">⬅ Voltar ao Dashboard</a>
            </div>
            
            <?php if ($mensagem): ?>
            <div class="alert alert-<?= $tipo_mensagem ?>">
                <?= htmlspecialchars($mensagem) ?>
            </div>
            <?php endif; ?>
            
            <div class="stats">
                <div class="stat-card">
                    <h3>Total de Usuários</h3>
                    <div class="numero"><?= count($usuarios) ?></div>
                </div>
                <?php 
                $admins = array_filter($usuarios, function($u) { 
                    return $u['nivel'] === 'admin'; 
                });
                ?>
                <div class="stat-card">
                    <h3>Administradores</h3>
                    <div class="numero"><?= count($admins) ?></div>
                </div>
                <?php 
                $ativos = array_filter($usuarios, function($u) { 
                    return $u['status'] === 'ativo'; 
                });
                ?>
                <div class="stat-card">
                    <h3>Usuários Ativos</h3>
                    <div class="numero"><?= count($ativos) ?></div>
                </div>
                <div class="stat-card">
                    <h3>Logs de Acesso</h3>
                    <div class="numero"><?= $total_logs ?></div>
                </div>
            </div>
            
            <div class="card">
                <h2>📋 Logs de Acesso dos Usuários</h2>
                <div style="margin-bottom: 15px; text-align: center;">
                    <small style="color: #666;">Arquivo: <?= htmlspecialchars($ARQUIVO_LOG_ACESSOS) ?></small>
                </div>
                
                <?php if ($total_logs > 0): ?>
                <div class="log-acoes">
                    <button onclick="limparLogsAcesso()" class="btn-acao" style="background: #95a5a6;">
                        🗑️ Limpar Logs
                    </button>
                    <button onclick="exportarLogsCSV()" class="btn-acao" style="background: #27ae60;">
                        📥 Exportar CSV
                    </button>
                </div>
                
                <div class="log-table-container">
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Hora</th>
                                <th>Usuário</th>
                                <th>Nome</th>
                                <th>Nível</th>
                                <th>IP</th>
                                <th>Ação</th>
                                <th>Tempo Ativo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Mostrar os logs mais recentes primeiro
                            $logs_mostrar = array_slice($logs_acesso, 0, 50);
                            
                            foreach ($logs_mostrar as $log): 
                                // Parse do log formatado (usando tabulação)
                                $partes = explode("\t", $log);
                                
                                // Extrair dados
                                $data = $partes[0] ?? '';
                                $hora = $partes[1] ?? '';
                                $usuario_log = $partes[2] ?? '';
                                $nome_log = $partes[3] ?? '';
                                $nivel_log = $partes[4] ?? '';
                                $ip_log = $partes[5] ?? '';
                                $acao_log = $partes[6] ?? '';
                                $tempo_ativo_log = $partes[7] ?? '';
                                
                                // Determinar classe da linha baseada na ação
                                $classe_linha = '';
                                if (strpos($acao_log, 'LOGIN') !== false && strpos($acao_log, 'LOGIN_FALHOU') === false) {
                                    $classe_linha = 'log-row-login';
                                } elseif (strpos($acao_log, 'LOGOUT') !== false && strpos($acao_log, 'LOGOUT_AUTO') === false) {
                                    $classe_linha = 'log-row-logout';
                                } elseif (strpos($acao_log, 'LOGOUT_AUTO') !== false) {
                                    $classe_linha = 'log-row-logout-auto';
                                } elseif (strpos($acao_log, 'SESSÃO_EXPIRADA') !== false) {
                                    $classe_linha = 'log-row-sessao-expirou';
                                } elseif (strpos($acao_log, 'ALTERAR') !== false || strpos($acao_log, 'RESET') !== false || 
                                         strpos($acao_log, 'CRIAR') !== false || strpos($acao_log, 'EXCLUIR') !== false) {
                                    $classe_linha = 'log-row-alteracao';
                                } elseif (strpos($acao_log, 'LOGIN_FALHOU') !== false) {
                                    $classe_linha = 'log-row-falha';
                                }
                                
                                // Determinar ícone da ação
                                $icone_acao = '';
                                if (strpos($acao_log, 'LOGIN') !== false && strpos($acao_log, 'LOGIN_FALHOU') === false) {
                                    $icone_acao = '🔓';
                                } elseif (strpos($acao_log, 'LOGOUT') !== false && strpos($acao_log, 'LOGOUT_AUTO') === false) {
                                    $icone_acao = '🚪';
                                } elseif (strpos($acao_log, 'LOGOUT_AUTO') !== false) {
                                    $icone_acao = '⏰';
                                } elseif (strpos($acao_log, 'ALTERAR') !== false) {
                                    $icone_acao = '⚙️';
                                } elseif (strpos($acao_log, 'RESET') !== false) {
                                    $icone_acao = '🔄';
                                } elseif (strpos($acao_log, 'CRIAR') !== false) {
                                    $icone_acao = '➕';
                                } elseif (strpos($acao_log, 'EXCLUIR') !== false) {
                                    $icone_acao = '🗑️';
                                } elseif (strpos($acao_log, 'SESSÃO_EXPIRADA') !== false) {
                                    $icone_acao = '⚠️';
                                }
                            ?>
                            <tr class="<?= $classe_linha ?>">
                                <td style="white-space: nowrap;"><?= htmlspecialchars($data) ?></td>
                                <td style="white-space: nowrap;"><?= htmlspecialchars($hora) ?></td>
                                <td><strong><?= htmlspecialchars($usuario_log) ?></strong></td>
                                <td><?= htmlspecialchars($nome_log) ?></td>
                                <td>
                                    <span class="nivel-badge nivel-<?= $nivel_log ?>">
                                        <?= strtoupper($nivel_log) ?>
                                    </span>
                                </td>
                                <td style="font-family: monospace; font-size: 11px;"><?= htmlspecialchars($ip_log) ?></td>
                                <td>
                                    <?= $icone_acao ?> <?= htmlspecialchars($acao_log) ?>
                                </td>
                                <td style="font-family: monospace; font-size: 11px; white-space: nowrap;">
                                    <?= htmlspecialchars($tempo_ativo_log) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="text-align: center; margin-top: 10px; font-size: 12px; color: #666;">
                    Mostrando os últimos <?= min(50, $total_logs) ?> logs de acesso
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 30px; color: #666;">
                    <p>Nenhum log de acesso registrado ainda.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2>➕ Adicionar Novo Usuário</h2>
                <form method="POST" action="" onsubmit="return validarFormulario()">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="usuario">Usuário *</label>
                            <input type="text" id="usuario" name="usuario" class="form-control" required 
                                   placeholder="Digite o nome de usuário">
                        </div>
                        
                        <div class="form-group">
                            <label for="nome">Nome Completo *</label>
                            <input type="text" id="nome" name="nome" class="form-control" required 
                                   placeholder="Digite o nome completo">
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-mail</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   placeholder="email@exemplo.com">
                        </div>
                        
                        <div class="form-group">
                            <label for="nivel">Nível de Acesso *</label>
                            <select id="nivel" name="nivel" class="form-control" required>
                                <option value="usuario">Usuário</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="senha">Senha * (mínimo 6 caracteres)</label>
                            <input type="password" id="senha" name="senha" class="form-control" required 
                                   minlength="6" placeholder="Mínimo 6 caracteres">
                        </div>
                        
                        <div class="form-group">
                            <label for="confirmar_senha">Confirmar Senha *</label>
                            <input type="password" id="confirmar_senha" name="confirmar_senha" class="form-control" 
                                   required placeholder="Repita a senha">
                        </div>
                    </div>
                    
                    <button type="submit" name="adicionar_usuario" class="btn-primary">
                        <span>📝</span> Cadastrar Usuário
                    </button>
                </form>
            </div>
            
            <div class="card">
                <h2>📋 Lista de Usuários</h2>
                <div class="mobile-only" style="margin-bottom: 15px; font-size: 12px; color: #666; text-align: center;">
                    <p>Deslize horizontalmente para ver todas as colunas →</p>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Nome</th>
                            <th class="desktop-only">E-mail</th>
                            <th>Nível</th>
                            <th>Status</th>
                            <th class="desktop-only">Cadastro</th>
                            <th>Último Acesso</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario_nome => $dados): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($usuario_nome) ?></strong>
                                <div class="mobile-only">
                                    <small style="color: #666;"><?= htmlspecialchars($dados['email'] ?? '') ?></small>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($dados['nome']) ?></td>
                            <td class="desktop-only"><?= htmlspecialchars($dados['email'] ?? '') ?></td>
                            <td>
                                <span class="badge badge-<?= $dados['nivel'] ?>">
                                    <?= ucfirst($dados['nivel']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $dados['status'] ?>">
                                    <?= ucfirst($dados['status']) ?>
                                </span>
                            </td>
                            <td class="desktop-only">
                                <small>
                                    <?= date('d/m/Y', strtotime($dados['data_criacao'])) ?><br>
                                    <span style="color: #666; font-size: 11px;">
                                        IP: <?= htmlspecialchars($dados['ip_cadastro'] ?? 'N/A') ?>
                                    </span>
                                </small>
                            </td>
                            <td>
                                <div class="desktop-only">
                                    <small>
                                        <?= $dados['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($dados['ultimo_acesso'])) : 'Nunca' ?><br>
                                        <span style="color: #666; font-size: 11px;">
                                            IP: <?= htmlspecialchars($dados['ip_ultimo_acesso'] ?? 'N/A') ?>
                                        </span>
                                    </small>
                                </div>
                                <div class="mobile-only">
                                    <?= $dados['ultimo_acesso'] ? date('d/m/Y', strtotime($dados['ultimo_acesso'])) : 'Nunca' ?><br>
                                    <small style="color: #666;">IP: <?= htmlspecialchars($dados['ip_ultimo_acesso'] ?? 'N/A') ?></small>
                                </div>
                            </td>
                            <td>
                                <div class="acoes">
                                    <?php if ($usuario_nome !== 'admin' && $usuario_nome !== $_SESSION['usuario']): ?>
                                    <a href="?page=usuarios&excluir=<?= urlencode($usuario_nome) ?>" 
                                       class="btn-acao btn-excluir"
                                       onclick="return confirm('Tem certeza que deseja excluir o usuário <?= addslashes($usuario_nome) ?>?')">
                                        🗑️ Excluir
                                    </a>
                                    <?php endif; ?>
                                    
                                    <a href="?page=usuarios&reset_senha=<?= urlencode($usuario_nome) ?>" 
                                       class="btn-acao btn-reset"
                                       onclick="return confirm('Resetar senha do usuário <?= addslashes($usuario_nome) ?> para 123456?')">
                                        🔄 Resetar
                                    </a>
                                    
                                    <button class="btn-acao btn-alterar" 
                                            onclick="abrirModalAlterarSenha('<?= addslashes($usuario_nome) ?>')">
                                        🔑 Alterar
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card">
                <h2>⚙️ Informações do Sistema</h2>
                <p><strong>Arquivo de usuários:</strong> <?= htmlspecialchars($arquivo_usuarios) ?></p>
                <?php if (file_exists($arquivo_usuarios)): ?>
                <p><strong>Tamanho do arquivo:</strong> <?= filesize($arquivo_usuarios) ?> bytes</p>
                <p><strong>Última modificação:</strong> <?= date('d/m/Y H:i:s', filemtime($arquivo_usuarios)) ?></p>
                <?php endif; ?>
                
                <p><strong>Arquivo de logs de acesso:</strong> <?= htmlspecialchars($ARQUIVO_LOG_ACESSOS) ?></p>
                <?php if (file_exists($ARQUIVO_LOG_ACESSOS)): ?>
                <p><strong>Tamanho do arquivo:</strong> <?= filesize($ARQUIVO_LOG_ACESSOS) ?> bytes</p>
                <p><strong>Última modificação:</strong> <?= date('d/m/Y H:i:s', filemtime($ARQUIVO_LOG_ACESSOS)) ?></p>
                <?php endif; ?>
                
                <p><strong>Usuário logado:</strong> <?= htmlspecialchars($_SESSION['usuario']) ?> (<?= $_SESSION['nivel'] ?>)</p>
                <p><strong>IP atual:</strong> <?= htmlspecialchars(getRealIp()) ?></p>
                <p><strong>Tempo de sessão:</strong> <?= floor($TEMPO_SESSAO / 60) ?> minutos</p>
                <p><strong>Auto-logout:</strong> <?= $_SESSION['auto_logout'] ? '✅ ATIVADO' : '❌ DESATIVADO' ?></p>
            </div>
        </div>
        
        <!-- Modal para alterar senha específica -->
        <div id="modalAlterarSenha" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>🔑 Alterar Senha</h3>
                    <button class="close-modal" onclick="fecharModalAlterarSenha()">&times;</button>
                </div>
                <form id="formAlterarSenha" method="POST" onsubmit="return validarSenhaModal()">
                    <input type="hidden" id="usuario_alvo_modal" name="usuario_alvo">
                    
                    <div class="form-group">
                        <label for="nova_senha_modal">Nova Senha * (mínimo 6 caracteres)</label>
                        <input type="password" id="nova_senha_modal" name="nova_senha_admin" class="form-control" 
                               required minlength="6" placeholder="Digite a nova senha">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirmar_nova_senha_modal">Confirmar Nova Senha *</label>
                        <input type="password" id="confirmar_nova_senha_modal" name="confirmar_nova_senha_admin" 
                               class="form-control" required placeholder="Confirme a nova senha">
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn-primary" style="background: #95a5a6; flex: 1;" 
                                onclick="fecharModalAlterarSenha()">
                            Cancelar
                        </button>
                        <button type="submit" name="alterar_senha_admin" class="btn-primary" style="flex: 2;">
                            <span>🔑</span> Alterar Senha
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            // CORREÇÃO: Configuração do timer de sessão para página de usuários - MESMO COMPORTAMENTO DO DASHBOARD
            let autoLogoutEnabledUsuarios = <?= $_SESSION['auto_logout'] ? 'true' : 'false' ?>;
            let sessionTimeLeftUsuarios = <?= $TEMPO_SESSAO ?>;
            
            function formatTimeUsuarios(seconds) {
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                const secs = seconds % 60;
                
                return [
                    hours.toString().padStart(2, '0'),
                    minutes.toString().padStart(2, '0'),
                    secs.toString().padStart(2, '0')
                ].join(':');
            }
            
            function updateSessionTimerUsuarios() {
                if (!autoLogoutEnabledUsuarios) {
                    const timerElement = document.getElementById('sessionTimerUsuarios');
                    if (timerElement) timerElement.style.display = 'none';
                    return;
                }
                
                sessionTimeLeftUsuarios--;
                
                if (sessionTimeLeftUsuarios <= 0) {
                    // Sessão expirou - redirecionar para logout automático
                    window.location.href = 'pix_dashboard.php?action=auto_logout';
                    return;
                }
                
                const timeElement = document.getElementById('sessionTimeUsuarios');
                if (timeElement) {
                    timeElement.textContent = formatTimeUsuarios(sessionTimeLeftUsuarios);
                }
                
                const timerElement = document.getElementById('sessionTimerUsuarios');
                if (timerElement) {
                    if (sessionTimeLeftUsuarios < 300) {
                        timerElement.style.background = 'rgba(231, 76, 60, 0.8)';
                        timerElement.style.color = '#ffebee';
                    } else if (sessionTimeLeftUsuarios < 1800) {
                        timerElement.style.background = 'rgba(241, 196, 15, 0.8)';
                        timerElement.style.color = '#fff8e1';
                    } else {
                        timerElement.style.background = 'rgba(0,0,0,0.7)';
                        timerElement.style.color = 'white';
                    }
                }
            }
            
            // CORREÇÃO: Função para reiniciar o timer quando houver atividade - MESMO COMPORTAMENTO
            function resetSessionTimerUsuarios() {
                sessionTimeLeftUsuarios = <?= $TEMPO_SESSAO ?>;
                const timerElement = document.getElementById('sessionTimerUsuarios');
                if (timerElement) {
                    timerElement.style.background = 'rgba(0,0,0,0.7)';
                    timerElement.style.color = 'white';
                }
                
                // Atualizar no servidor via AJAX
                fetch('pix_dashboard.php?update_session=1')
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            console.error('Erro ao atualizar sessão no servidor');
                        }
                    })
                    .catch(error => {
                        console.error('Erro na requisição AJAX:', error);
                    });
            }
            
            // Iniciar timer se auto-logout estiver ativado
            if (autoLogoutEnabledUsuarios) {
                updateSessionTimerUsuarios();
                setInterval(updateSessionTimerUsuarios, 1000);
                
                // CORREÇÃO: MESMO COMPORTAMENTO DO DASHBOARD - Resetar timer em eventos de atividade
                const resetEventsUsuarios = ['mousemove', 'keypress', 'click', 'scroll', 'touchstart', 'touchmove'];
                resetEventsUsuarios.forEach(event => {
                    document.addEventListener(event, resetSessionTimerUsuarios, { passive: true });
                });
            }
            
            // CORREÇÃO: Detector de inatividade para página de usuários - SIMPLIFICADO
            let inactivityTimerUsuarios;
            
            function resetInactivityTimerUsuarios() {
                clearTimeout(inactivityTimerUsuarios);
                
                // Configurar timer para 29 minutos (ligeiramente menor que o PHP)
                inactivityTimerUsuarios = setTimeout(() => {
                    // Verificar se a sessão ainda está ativa
                    fetch('pix_dashboard.php?check_session=1')
                        .then(response => response.text())
                        .then(data => {
                            if (data === 'expired') {
                                window.location.href = 'pix_dashboard.php?action=auto_logout';
                            }
                        })
                        .catch(() => {
                            // Se houver erro na verificação, assumir que expirou
                            window.location.href = 'pix_dashboard.php?action=auto_logout';
                        });
                }, 1740000); // 29 minutos
            }
            
            // Iniciar detector de inatividade
            if (autoLogoutEnabledUsuarios) {
                resetInactivityTimerUsuarios();
                
                ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
                    document.addEventListener(event, resetInactivityTimerUsuarios);
                });
            }
            
            function validarFormulario() {
                const senha = document.getElementById('senha');
                const confirmar = document.getElementById('confirmar_senha');
                
                if (senha.value !== confirmar.value) {
                    alert('As senhas não coincidem!');
                    confirmar.focus();
                    return false;
                }
                
                if (senha.value.length < 6) {
                    alert('A senha deve ter no mínimo 6 caracteres!');
                    senha.focus();
                    return false;
                }
                
                return true;
            }
            
            function abrirModalAlterarSenha(usuario) {
                document.getElementById('usuario_alvo_modal').value = usuario;
                document.getElementById('modalAlterarSenha').style.display = 'flex';
                document.getElementById('nova_senha_modal').focus();
            }
            
            function fecharModalAlterarSenha() {
                document.getElementById('modalAlterarSenha').style.display = 'none';
                document.getElementById('formAlterarSenha').reset();
            }
            
            function validarSenhaModal() {
                const senha = document.getElementById('nova_senha_modal');
                const confirmar = document.getElementById('confirmar_nova_senha_modal');
                const usuario = document.getElementById('usuario_alvo_modal').value;
                
                if (senha.value !== confirmar.value) {
                    alert('As senhas não coincidem!');
                    confirmar.focus();
                    return false;
                }
                
                if (senha.value.length < 6) {
                    alert('A senha deve ter no mínimo 6 caracteres!');
                    senha.focus();
                    return false;
                }
                
                return confirm('Deseja alterar a senha do usuário ' + usuario + '?');
            }
            
            function limparLogsAcesso() {
                if (confirm('Deseja limpar todos os logs de acesso?\n\nEsta ação não pode ser desfeita.')) {
                    window.location.href = '?page=usuarios&limpar_logs_acesso=1';
                }
            }
            
            function exportarLogsCSV() {
                let csv = 'Data;Hora;Usuário;Nome;Nível;IP;Ação;Tempo Ativo\n';
                
                // Pegar dados da tabela de logs
                const rows = document.querySelectorAll('.log-table tbody tr');
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 8) {
                        // Remover ícones da ação
                        let acao = cells[6].textContent.trim();
                        acao = acao.replace(/[🔓🚪⏰⚙️🔄➕🗑️⚠️]/g, '').trim();
                        
                        csv += `"${cells[0].textContent}";"${cells[1].textContent}";"${cells[2].textContent}";"${cells[3].textContent}";"${cells[4].textContent.replace('ADMIN', 'admin').replace('USUARIO', 'usuario')}";"${cells[5].textContent}";"${acao}";"${cells[7].textContent}"\n`;
                    }
                });
                
                if (csv === 'Data;Hora;Usuário;Nome;Nível;IP;Ação;Tempo Ativo\n') {
                    alert('Não há logs para exportar.');
                    return;
                }
                
                const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                
                link.setAttribute('href', url);
                link.setAttribute('download', 'acessos_usuarios_<?= date('Y-m-d') ?>.csv');
                link.style.visibility = 'hidden';
                
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
            
            // Modal para alterar senha específica
            const modal = document.getElementById('modalAlterarSenha');
            const closeModal = document.querySelector('.close-modal');
            
            if (modal) {
                // Fechar modal ao clicar fora
                window.onclick = function(event) {
                    if (event.target === modal) {
                        fecharModalAlterarSenha();
                    }
                }
                
                // Fechar modal com ESC
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        fecharModalAlterarSenha();
                    }
                });
            }
            
            // Melhorias para mobile
            document.addEventListener('DOMContentLoaded', function() {
                // Detectar mobile
                const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                
                if (isMobile) {
                    // Ajustar inputs para evitar zoom no iOS
                    const inputs = document.querySelectorAll('input, select, textarea');
                    inputs.forEach(input => {
                        input.addEventListener('focus', function() {
                            // Forçar tamanho de fonte 16px para evitar zoom
                            this.style.fontSize = '16px';
                        });
                        
                        input.addEventListener('blur', function() {
                            // Restaurar tamanho original
                            this.style.fontSize = '';
                        });
                    });
                }
                
                // Prevenir reenvio do formulário
                if (window.history.replaceState) {
                    window.history.replaceState(null, null, window.location.href);
                }
            });
            
            // CORREÇÃO: Sistema de monitoramento de abas em segundo plano
            let lastActivityTime = Date.now();
            let isTabActive = true;
            
            // Monitorar visibilidade da aba
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    // Aba ficou oculta (foi para segundo plano)
                    isTabActive = false;
                    console.log('Aba foi para segundo plano');
                } else {
                    // Aba ficou visível (voltou para primeiro plano)
                    isTabActive = true;
                    console.log('Aba voltou para primeiro plano');
                    
                    // Verificar sessão quando a aba voltar
                    checkSessionOnTabActivation();
                }
            });
            
            // Verificar sessão quando a aba voltar ao primeiro plano
            function checkSessionOnTabActivation() {
                if (!autoLogoutEnabledUsuarios) return;
                
                // Calcular quanto tempo a aba ficou inativa
                const timeInactive = Date.now() - lastActivityTime;
                const timeInactiveSeconds = Math.floor(timeInactive / 1000);
                
                console.log('Aba ficou inativa por:', timeInactiveSeconds, 'segundos');
                
                // Se ficou inativa por mais de 1 minuto, verificar sessão
                if (timeInactiveSeconds > 60) {
                    fetch('pix_dashboard.php?check_session=1')
                        .then(response => response.text())
                        .then(data => {
                            if (data === 'expired') {
                                console.log('Sessão expirou enquanto a aba estava inativa');
                                window.location.href = 'pix_dashboard.php?action=auto_logout';
                            } else {
                                // Atualizar timer local
                                resetSessionTimerUsuarios();
                                console.log('Sessão ainda ativa após aba inativa');
                            }
                        })
                        .catch(() => {
                            console.error('Erro ao verificar sessão');
                        });
                }
            }
            
            // Atualizar tempo da última atividade
            function updateLastActivityTime() {
                lastActivityTime = Date.now();
            }
            
            // Monitorar eventos de atividade
            ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'].forEach(event => {
                document.addEventListener(event, updateLastActivityTime);
            });
            
            // CORREÇÃO: Verificação periódica de sessão mesmo em abas inativas
            function checkSessionPeriodically() {
                if (!autoLogoutEnabledUsuarios) return;
                
                // Verificar a cada 30 segundos
                setInterval(() => {
                    fetch('pix_dashboard.php?check_session=1')
                        .then(response => response.text())
                        .then(data => {
                            if (data === 'expired') {
                                console.log('Sessão expirou (verificação periódica)');
                                window.location.href = 'pix_dashboard.php?action=auto_logout';
                            }
                        })
                        .catch(() => {
                            // Ignorar erros
                        });
                }, 30000); // Verificar a cada 30 segundos
            }
            
            // Iniciar verificação periódica
            if (autoLogoutEnabledUsuarios) {
                checkSessionPeriodically();
            }
        </script>
        
        <?php
        // Processar limpeza de logs de acesso
        if (isset($_GET['limpar_logs_acesso']) && $_GET['limpar_logs_acesso'] == '1') {
            if (file_exists($ARQUIVO_LOG_ACESSOS)) {
                file_put_contents($ARQUIVO_LOG_ACESSOS, '');
                echo '<script>alert("Logs de acesso limpos com sucesso!");</script>';
                echo '<script>setTimeout(function(){ window.location.href = "?page=usuarios"; }, 1000);</script>';
            }
        }
        ?>
    </body>
    </html>
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Dashboard Pix - WebLine Telecom</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
/* ESTILOS EXISTENTES DO DASHBOARD (mantidos) */
*{box-sizing:border-box}
body{margin:0;padding:20px;background:#f2f4f7;font-family:Arial}

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

.dashboard-title {
    flex: 1;
    min-width: 200px;
}
.dashboard-title h1 {
    margin: 0;
    text-align: left;
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

.btn-sair {
    padding: 10px 18px;
    background: #e74c3c;
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
.btn-sair:hover {
    background: #c0392b;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.btn-admin {
    padding: 10px 18px;
    background: #3498db;
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
.btn-admin:hover {
    background: #2980b9;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.btn-alterar-senha {
    padding: 10px 18px;
    background: #9b59b6;
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
.btn-alterar-senha:hover {
    background: #8e44ad;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(155, 89, 182, 0.3);
}

.btn-autologout {
    padding: 10px 18px;
    background: <?= $_SESSION['auto_logout'] ? '#27ae60' : '#95a5a6' ?>;
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
.btn-autologout:hover {
    background: <?= $_SESSION['auto_logout'] ? '#219653' : '#7f8c8d' ?>;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.status-indicator {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 12px;
    background: <?= $_SESSION['auto_logout'] ? '#d5f4e6' : '#f0f0f0' ?>;
    color: <?= $_SESSION['auto_logout'] ? '#27ae60' : '#7f8c8d' ?>;
}
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: <?= $_SESSION['auto_logout'] ? '#27ae60' : '#95a5a6' ?>;
}

.cards{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:20px
}
.card{
    flex:1;
    min-width:140px;
    background:#fff;
    padding:16px;
    border-radius:10px;
    text-align:center;
    box-shadow:0 2px 6px rgba(0,0,0,.08);
    transition:transform 0.3s;
}
.card:hover{
    transform:translateY(-3px);
}
.card b{font-size:26px}

.box{
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
tr:hover{
    background:#f9f9f9;
}

@media(max-width:700px){
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
    
    .header-dashboard {
        flex-direction: column;
        align-items: stretch;
        text-align: center;
        gap: 10px;
    }
    .dashboard-title h1 {
        text-align: center;
        justify-content: center;
    }
    .dashboard-controls {
        justify-content: center;
    }
    .user-info {
        justify-content: center;
        text-align: center;
    }
    .btn-sair, .btn-autologout, .btn-admin, .btn-alterar-senha {
        width: 100%;
        justify-content: center;
    }
}

@media(max-width: 480px){
    .dashboard-controls {
        flex-direction: column;
        width: 100%;
    }
    .btn-sair, .btn-autologout, .btn-admin, .btn-alterar-senha {
        width: 100%;
    }
    .user-info {
        flex-direction: column;
        gap: 5px;
    }
}

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
.btn-hoje{
    background:#00b894;
}
.btn-ontem{
    background:#3498db;
}
.btn-exportar{
    background:#9b59b6;
}
.btn-acoes:hover{
    opacity:0.9;
    transform:translateY(-2px);
}

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

<?php if ($_SESSION['auto_logout']): ?>
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
    display: block;
    font-family: monospace;
    min-width: 140px;
}
<?php else: ?>
.session-timer {
    display: none;
}
<?php endif; ?>

/* Modal para alterar senha */
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

.btn-modal-cancelar {
    background: #95a5a6;
    color: white;
}

.btn-modal-alterar {
    background: #9b59b6;
    color: white;
}

.btn-modal:hover {
    opacity: 0.9;
    transform: translateY(-2px);
}

/* Mensagens de sucesso/erro */
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

@media(max-width: 768px) {
    .modal-dashboard {
        padding: 10px;
    }
    
    .modal-content-dashboard {
        padding: 20px;
    }
    
    .modal-header-dashboard h3 {
        font-size: 18px;
    }
    
    .mensagem-senha {
        left: 10px;
        right: 10px;
        top: 10px;
        max-width: calc(100% - 20px);
    }
}
</style>
</head>
<body>

<!-- Mensagens de alteração de senha -->
<?php if (isset($_SESSION['mensagem_senha'])): ?>
<div class="mensagem-senha mensagem-<?= $_SESSION['tipo_mensagem_senha'] ?>" id="mensagemSenha">
    <?= htmlspecialchars($_SESSION['mensagem_senha']) ?>
</div>
<?php 
    unset($_SESSION['mensagem_senha']);
    unset($_SESSION['tipo_mensagem_senha']);
endif; 
?>

<!-- Modal para alterar senha -->
<div id="modalAlterarSenhaDashboard" class="modal-dashboard">
    <div class="modal-content-dashboard">
        <div class="modal-header-dashboard">
            <h3>🔑 Alterar Minha Senha</h3>
            <button class="close-modal-dashboard" onclick="fecharModalAlterarSenhaDashboard()">&times;</button>
        </div>
        <form method="POST" action="" onsubmit="return validarSenhaDashboard()">
            <div class="form-group-modal">
                <label for="senha_atual">Senha Atual *</label>
                <input type="password" id="senha_atual" name="senha_atual" class="form-control-modal" 
                       required placeholder="Digite sua senha atual">
            </div>
            
            <div class="form-group-modal">
                <label for="nova_senha">Nova Senha * (mínimo 6 caracteres)</label>
                <input type="password" id="nova_senha" name="nova_senha" class="form-control-modal" 
                       required minlength="6" placeholder="Digite a nova senha">
            </div>
            
            <div class="form-group-modal">
                <label for="confirmar_nova_senha">Confirmar Nova Senha *</label>
                <input type="password" id="confirmar_nova_senha" name="confirmar_nova_senha" 
                       class="form-control-modal" required placeholder="Confirme a nova senha">
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 25px;">
                <button type="button" class="btn-modal btn-modal-cancelar" onclick="fecharModalAlterarSenhaDashboard()">
                    Cancelar
                </button>
                <button type="submit" name="alterar_senha_dashboard" class="btn-modal btn-modal-alterar">
                    <span>🔑</span> Alterar Senha
                </button>
            </div>
        </form>
    </div>
</div>

<!-- TIMER DE SESSÃO - APENAS SE ATIVADO -->
<?php if ($_SESSION['auto_logout']): ?>
<div class="session-timer" id="sessionTimer">
    <div style="font-size: 10px; opacity: 0.8;">Auto-logout em:</div>
    <div style="font-size: 14px; font-weight: bold;" id="sessionTime">00:30:00</div>
</div>
<?php endif; ?>

<!-- HEADER COM LAYOUT ALINHADO -->
<div class="header-dashboard">
    <!-- TÍTULO À ESQUERDA -->
    <div class="dashboard-title">
        <h1>📊 Dashboard Consultas Fatura</h1>
    </div>
    
    <!-- CONTROLES À DIREITA -->
    <div class="dashboard-controls">
        <!-- INFORMAÇÕES DO USUÁRIO -->
        <div class="user-info">
            <div>
                👤 <b><?= htmlspecialchars($_SESSION['usuario'] ?? 'Usuário') ?></b>
                <span class="user-nivel"><?= strtoupper($_SESSION['nivel']) ?></span><br>
                <small>IP: <?= htmlspecialchars(getRealIp()) ?></small>
            </div>
            <div class="status-indicator">
                <span class="status-dot"></span>
                Auto-logout: <?= $_SESSION['auto_logout'] ? 'ON' : 'OFF' ?>
            </div>
        </div>
        
        <!-- BOTÕES -->
        <button onclick="abrirModalAlterarSenhaDashboard()" class="btn-alterar-senha" title="Alterar minha senha">
            <span style="font-size:16px;">🔑</span> Alterar Senha
        </button>
        
        <?php if ($_SESSION['nivel'] === 'admin'): ?>
        <a href="?page=usuarios" class="btn-admin">
            👥 Gerenciar Usuários
        </a>
        <?php endif; ?>
        
        <button onclick="toggleAutoLogout()" class="btn-autologout" title="<?= $_SESSION['auto_logout'] ? 'Desativar' : 'Ativar' ?> logout automático">
            <?php if ($_SESSION['auto_logout']): ?>
            <span style="font-size:16px;">⏰</span> Desativar
            <?php else: ?>
            <span style="font-size:16px;">🔒</span> Ativar
            <?php endif; ?>
        </button>
        
        <button onclick="sairDashboard()" class="btn-sair" title="Sair do Dashboard">
            <span style="font-size:16px;">🚪</span> Sair
        </button>
    </div>
</div>

<!-- CONTEÚDO PRINCIPAL -->
<div class="cards">
    <div class="card">
        <div>Hoje</div>
        <b><?= contarDia($hoje) ?></b>
    </div>
    <div class="card">
        <div>Ontem</div>
        <b><?= contarDia($ontem) ?></b>
    </div>
    <div class="card">
        <div>Últimos 7 dias</div>
        <b><?= $ultimos7 ?></b>
    </div>
</div>

<div class="box">
    <form method="get">
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
        <a href="?dia=<?= $hoje ?>" class="btn-acoes btn-hoje">Hoje</a>
        <a href="?dia=<?= $ontem ?>" class="btn-acoes btn-ontem">Ontem</a>
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

<?php if ($_SESSION['nivel'] === 'admin'): ?>
<!-- ESTATÍSTICAS DE FILTROS - APENAS PARA ADMINISTRADORES -->
<div class="box" style="margin-top: 20px;">
    <h3 style="text-align:center;">🛡️ Estatísticas de Filtros</h3>
    
    <?php
    $filtrosLog = '/var/log/pix_acessos/pix_filtros.log';
    $totalBloqueios = 0;
    $ipsBloqueados = [];
    $motivos = [
        'IP BLOQUEADO' => 0,
        'USER-AGENT BLOQUEADO' => 0,
        'ACESSO RECENTE DO CPF' => 0,
        'DUPLICAÇÃO DETECTADA' => 0,
        'LOG REGISTRADO' => 0,
    ];
    
    if (file_exists($filtrosLog)) {
        $linhas = file($filtrosLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($linhas as $linha) {
            $totalBloqueios++;
            
            foreach ($motivos as $motivo => $contador) {
                if (strpos($linha, "STATUS: $motivo") !== false) {
                    $motivos[$motivo]++;
                    break;
                }
            }
            
            if (preg_match('/IP:\s*([0-9\.]+)/', $linha, $matches)) {
                $ip = $matches[1];
                if (!isset($ipsBloqueados[$ip])) {
                    $ipsBloqueados[$ip] = 0;
                }
                $ipsBloqueados[$ip]++;
            }
        }
    }
    
    arsort($ipsBloqueados);
    $topIps = array_slice($ipsBloqueados, 0, 5, true);
    ?>
    
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
    
    <?php if ($totalBloqueios > 0): ?>
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
        <script>
        function limparLogsFiltros() {
            if (confirm('Deseja limpar os logs de filtros?\n\nIsso não afeta os logs principais de acesso.')) {
                window.location.href = 'pix_dashboard.php?limpar_filtros=1';
            }
        }
        </script>
        
        <?php
        if (isset($_GET['limpar_filtros']) && $_GET['limpar_filtros'] == '1') {
            $filtrosLog = '/var/log/pix_acessos/pix_filtros.log';
            if (file_exists($filtrosLog)) {
                file_put_contents($filtrosLog, '');
                echo '<script>alert("Logs de filtros limpos com sucesso!");</script>';
                echo '<script>setTimeout(function(){ window.location.href = "pix_dashboard.php"; }, 1000);</script>';
            }
        }
        ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="footer-dashboard">
    WebLine Telecom - Dashboard Pix | 
    Usuário: <?= htmlspecialchars($_SESSION['usuario']) ?> (<?= $_SESSION['nivel'] ?>) | 
    Sessão iniciada: <?= date('d/m/Y H:i:s', $_SESSION['login_time'] ?? time()) ?> | 
    Auto-logout: <?= $_SESSION['auto_logout'] ? '✅ ATIVADO' : '❌ DESATIVADO' ?>
</div>

<script>
// CORREÇÃO: Sistema de monitoramento de abas em segundo plano
let lastActivityTime = Date.now();
let isTabActive = true;
let sessionCheckInterval;

// Monitorar visibilidade da aba
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        // Aba ficou oculta (foi para segundo plano)
        isTabActive = false;
        console.log('Aba foi para segundo plano');
        
        // Quando a aba vai para segundo plano, iniciamos uma verificação periódica
        startBackgroundSessionCheck();
    } else {
        // Aba ficou visível (voltou para primeiro plano)
        isTabActive = true;
        console.log('Aba voltou para primeiro plano');
        
        // Parar verificação em segundo plano
        stopBackgroundSessionCheck();
        
        // Verificar sessão quando a aba voltar
        checkSessionOnTabActivation();
    }
});

// Verificar sessão quando a aba voltar ao primeiro plano
function checkSessionOnTabActivation() {
    if (!<?= $_SESSION['auto_logout'] ? 'true' : 'false' ?>) return;
    
    // Calcular quanto tempo a aba ficou inativa
    const timeInactive = Date.now() - lastActivityTime;
    const timeInactiveSeconds = Math.floor(timeInactive / 1000);
    
    console.log('Aba ficou inativa por:', timeInactiveSeconds, 'segundos');
    
    // Se ficou inativa por mais de 30 segundos, verificar sessão
    if (timeInactiveSeconds > 30) {
        fetch('pix_dashboard.php?check_session=1')
            .then(response => response.text())
            .then(data => {
                if (data === 'expired') {
                    console.log('Sessão expirou enquanto a aba estava inativa');
                    window.location.href = 'pix_dashboard.php?action=auto_logout';
                } else {
                    // Atualizar timer local
                    resetSessionTimer();
                    console.log('Sessão ainda ativa após aba inativa');
                }
            })
            .catch(() => {
                console.error('Erro ao verificar sessão');
            });
    }
}

// Atualizar tempo da última atividade
function updateLastActivityTime() {
    lastActivityTime = Date.now();
    
    // Atualizar no servidor a cada 30 segundos de atividade
    if (lastActivityTime % 30000 < 1000) { // Aproximadamente a cada 30 segundos
        updateSessionTime();
    }
}

// Monitorar eventos de atividade
['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click', 'input'].forEach(event => {
    document.addEventListener(event, updateLastActivityTime);
});

// Verificação em segundo plano quando a aba está oculta
function startBackgroundSessionCheck() {
    if (!<?= $_SESSION['auto_logout'] ? 'true' : 'false' ?>) return;
    
    // Limpar intervalo anterior se existir
    if (sessionCheckInterval) {
        clearInterval(sessionCheckInterval);
    }
    
    // Verificar a cada 30 segundos quando em segundo plano
    sessionCheckInterval = setInterval(() => {
        fetch('pix_dashboard.php?check_session=1')
            .then(response => response.text())
            .then(data => {
                if (data === 'expired') {
                    console.log('Sessão expirou em segundo plano');
                    // Quando a sessão expira em segundo plano, forçar logout quando voltar
                    localStorage.setItem('session_expired', 'true');
                }
            })
            .catch(() => {
                // Ignorar erros em segundo plano
            });
    }, 30000); // Verificar a cada 30 segundos
}

function stopBackgroundSessionCheck() {
    if (sessionCheckInterval) {
        clearInterval(sessionCheckInterval);
        sessionCheckInterval = null;
    }
    
    // Verificar se a sessão expirou enquanto estava em segundo plano
    if (localStorage.getItem('session_expired') === 'true') {
        localStorage.removeItem('session_expired');
        window.location.href = 'pix_dashboard.php?action=auto_logout';
    }
}

// Detector de inatividade corrigido
let inactivityTime = function() {
    let time;
    
    const resetTimer = () => {
        clearTimeout(time);
        // Configurar timer para o tempo de sessão menos 30 segundos
        const sessionTime = <?= $TEMPO_SESSAO ?> - 30;
        time = setTimeout(() => {
            // Verificar se a sessão ainda está ativa
            fetch('pix_dashboard.php?check_session=1')
                .then(response => response.text())
                .then(data => {
                    if (data === 'expired') {
                        window.location.href = 'pix_dashboard.php?action=auto_logout';
                    }
                })
                .catch(() => {
                    // Em caso de erro, redirecionar para logout
                    window.location.href = 'pix_dashboard.php?action=auto_logout';
                });
        }, sessionTime * 1000); // Converter para milissegundos
    };
    
    resetTimer();
    
    // Resetar timer em eventos de atividade
    ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'].forEach(event => {
        document.addEventListener(event, resetTimer);
    });
};

// Funções para os botões
function sairDashboard() {
    if (confirm('Deseja realmente sair do dashboard?')) {
        window.location.href = 'pix_dashboard.php?action=logout';
    }
}

function toggleAutoLogout() {
    const currentState = <?= $_SESSION['auto_logout'] ? 'true' : 'false' ?>;
    const newState = currentState ? 'off' : 'on';
    
    if (confirm('Deseja ' + (currentState ? 'DESATIVAR' : 'ATIVAR') + ' o auto-logout?')) {
        window.location.href = 'pix_dashboard.php?toggle_autologout=' + newState;
    }
}

// Configurações do timer
let autoLogoutEnabled = <?= $_SESSION['auto_logout'] ? 'true' : 'false' ?>;
let sessionTimeLeft = <?= $TEMPO_SESSAO ?>;

function formatTime(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    
    return [
        hours.toString().padStart(2, '0'),
        minutes.toString().padStart(2, '0'),
        secs.toString().padStart(2, '0')
    ].join(':');
}

function updateSessionTimer() {
    if (!autoLogoutEnabled) {
        const timerElement = document.getElementById('sessionTimer');
        if (timerElement) timerElement.style.display = 'none';
        return;
    }
    
    sessionTimeLeft--;
    
    if (sessionTimeLeft <= 0) {
        // Redirecionar para logout automático
        window.location.href = 'pix_dashboard.php?action=auto_logout';
        return;
    }
    
    const timeElement = document.getElementById('sessionTime');
    if (timeElement) {
        timeElement.textContent = formatTime(sessionTimeLeft);
    }
    
    const timerElement = document.getElementById('sessionTimer');
    if (timerElement) {
        if (sessionTimeLeft < 300) {
            timerElement.style.background = 'rgba(231, 76, 60, 0.8)';
            timerElement.style.color = '#ffebee';
        } else if (sessionTimeLeft < 1800) {
            timerElement.style.background = 'rgba(241, 196, 15, 0.8)';
            timerElement.style.color = '#fff8e1';
        } else {
            timerElement.style.background = 'rgba(0,0,0,0.7)';
            timerElement.style.color = 'white';
        }
    }
}

// CORREÇÃO: Função para reiniciar o timer quando houver atividade - MESMO COMPORTAMENTO
function resetSessionTimer() {
    sessionTimeLeft = <?= $TEMPO_SESSAO ?>;
    const timerElement = document.getElementById('sessionTimer');
    if (timerElement) {
        timerElement.style.background = 'rgba(0,0,0,0.7)';
        timerElement.style.color = 'white';
    }
    
    // Atualizar no servidor via AJAX
    fetch('pix_dashboard.php?update_session=1')
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Erro ao atualizar sessão no servidor');
            }
        })
        .catch(error => {
            console.error('Erro na requisição AJAX:', error);
        });
}

// Iniciar timers
if (autoLogoutEnabled) {
    // Timer do PHP
    setInterval(updateSessionTimer, 1000);
    
    // Timer de redundância JavaScript
    inactivityTime();
    
    // CORREÇÃO: MESMO COMPORTAMENTO - Resetar timer em eventos de atividade
    const resetEvents = ['mousemove', 'keypress', 'click', 'scroll', 'touchstart', 'touchmove'];
    resetEvents.forEach(event => {
        document.addEventListener(event, resetSessionTimer, { passive: true });
    });
    
    updateSessionTimer();
    document.getElementById('sessionTime').textContent = formatTime(sessionTimeLeft);
}

// Funções para alterar senha
function abrirModalAlterarSenhaDashboard() {
    document.getElementById('modalAlterarSenhaDashboard').style.display = 'flex';
    document.getElementById('senha_atual').focus();
}

function fecharModalAlterarSenhaDashboard() {
    document.getElementById('modalAlterarSenhaDashboard').style.display = 'none';
    document.getElementById('senha_atual').value = '';
    document.getElementById('nova_senha').value = '';
    document.getElementById('confirmar_nova_senha').value = '';
}

function validarSenhaDashboard() {
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
}

// Fechar modal com ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fecharModalAlterarSenhaDashboard();
    }
});

// Fechar mensagem após 5 segundos
const mensagemSenha = document.getElementById('mensagemSenha');
if (mensagemSenha) {
    setTimeout(() => {
        mensagemSenha.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => {
            mensagemSenha.style.display = 'none';
        }, 300);
    }, 5000);
}

// Fechar mensagem ao clicar
if (mensagemSenha) {
    mensagemSenha.addEventListener('click', function() {
        this.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => {
            this.style.display = 'none';
        }, 300);
    });
}

function exportarCSV() {
    let csv = 'Data;Hora;Vencimento;IP;Cliente;CPF;Título\n';
    
    document.querySelectorAll('table tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 7) {
            csv += `"${cells[0].textContent}";"${cells[1].textContent}";"${cells[2].textContent}";"${cells[3].textContent}";"${cells[4].textContent}";"${cells[5].textContent}";"${cells[6].textContent}"\n`;
        }
    });
    
    if (csv === 'Data;Hora;Vencimento;IP;Cliente;CPF;Título\n') {
        csv += '"Nenhum registro encontrado";"";"";"";"";"";""\n';
    }
    
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'pix_acessos_<?= $diaSelecionado ?>.csv');
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

document.querySelector('.btn-exportar')?.addEventListener('click', function(e) {
    <?php if (empty($linhas)): ?>
    if (!confirm('Não há dados para exportar no dia selecionado. Deseja exportar mesmo assim?')) {
        e.preventDefault();
    }
    <?php endif; ?>
});

// Atalhos de teclado
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        sairDashboard();
    }
    
    if (e.altKey && e.key === 'l') {
        e.preventDefault();
        toggleAutoLogout();
    }
    
    if (e.altKey && e.key === 's') {
        e.preventDefault();
        sairDashboard();
    }
    
    if (e.altKey && e.key === 'p') {
        e.preventDefault();
        abrirModalAlterarSenhaDashboard();
    }
    
    <?php if ($_SESSION['nivel'] === 'admin'): ?>
    if (e.altKey && e.key === 'u') {
        e.preventDefault();
        window.location.href = '?page=usuarios';
    }
    <?php endif; ?>
});

console.log('Atalhos disponíveis:');
console.log('- ESC: Sair do dashboard');
console.log('- Alt+L: Alternar auto-logout');
console.log('- Alt+S: Sair rapidamente');
console.log('- Alt+P: Alterar minha senha');
<?php if ($_SESSION['nivel'] === 'admin'): ?>
console.log('- Alt+U: Gerenciar usuários');
<?php endif; ?>

// CORREÇÃO: Verificar se está na página de login após expiração
if (window.location.href.indexOf('expired=true') !== -1) {
    // Limpar parâmetro da URL sem recarregar
    window.history.replaceState({}, document.title, window.location.href.split('?')[0]);
}

// CORREÇÃO: Função para atualizar tempo de sessão no servidor
function updateSessionTime() {
    if (autoLogoutEnabled) {
        fetch('pix_dashboard.php?update_session=1')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Sessão atualizada no servidor');
                }
            })
            .catch(() => {
                // Ignorar erros
            });
    }
}

// Inicializar o sistema quando a página carregar
document.addEventListener('DOMContentLoaded', function() {
    // Verificar imediatamente se há uma sessão expirada no localStorage
    if (localStorage.getItem('session_expired') === 'true') {
        localStorage.removeItem('session_expired');
        window.location.href = 'pix_dashboard.php?action=auto_logout';
    }
    
    // Atualizar tempo de sessão no servidor periodicamente (a cada 5 minutos)
    if (autoLogoutEnabled) {
        setInterval(updateSessionTime, 300000);
    }
});

// Adicionar evento para quando a janela perder o foco (mesmo dentro da mesma aba)
window.addEventListener('blur', function() {
    console.log('Janela perdeu o foco');
    // Marcar como inativa, mas não iniciar verificação em segundo plano ainda
});

window.addEventListener('focus', function() {
    console.log('Janela ganhou foco');
    // Verificar sessão quando a janela voltar a ter foco
    if (autoLogoutEnabled) {
        fetch('pix_dashboard.php?check_session=1')
            .then(response => response.text())
            .then(data => {
                if (data === 'expired') {
                    window.location.href = 'pix_dashboard.php?action=auto_logout';
                }
            })
            .catch(() => {
                // Ignorar erros
            });
    }
});
</script>

</body>
</html>