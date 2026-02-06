#!/bin/bash
set -e

echo "🚀 Iniciando instalação do Bot WhatsApp – Debian 12"
echo "📅 $(date)"
echo ""

# =====================================================
# VARIÁVEIS
# =====================================================
BOT_DIR="/opt/whatsapp-bot"
WEB_DIR="/var/www/botzap"
BOT_USER="botzap"
WEB_GROUP="www-data"
NODE_VERSION="20"
LOG_FILE="/var/log/botzap.log"
REPO_URL="https://raw.githubusercontent.com/miranildo/BotZap-WEBLINE-2026/main"

# =====================================================
# VERIFICA ROOT
# =====================================================
if [ "$EUID" -ne 0 ]; then
  echo "❌ Execute como root"
  exit 1
fi

# =====================================================
# ATUALIZA SISTEMA
# =====================================================
echo "🔄 Atualizando sistema..."
apt update && apt upgrade -y

# =====================================================
# DEPENDÊNCIAS BÁSICAS
# =====================================================
echo "📦 Instalando dependências básicas..."
apt install -y \
  curl \
  git \
  unzip \
  ca-certificates \
  gnupg \
  lsb-release \
  sudo

# =====================================================
# NODE.JS LTS
# =====================================================
echo "🟢 Instalando Node.js ${NODE_VERSION}..."
curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash -
apt install -y nodejs

echo "✅ Node.js $(node -v)"
echo "✅ npm $(npm -v)"

# =====================================================
# APACHE + PHP
# =====================================================
echo "🌐 Instalando Apache e PHP..."
apt install -y \
  apache2 \
  php \
  php-cli \
  php-curl \
  php-json \
  php-mbstring \
  php-zip \
  php-xml \
  libapache2-mod-php

systemctl enable apache2
systemctl start apache2

# =====================================================
# USUÁRIO E GRUPOS
# =====================================================
echo "👤 Configurando usuários e grupos..."
if ! id "$BOT_USER" &>/dev/null; then
  useradd -r -s /bin/false -d "$BOT_DIR" "$BOT_USER"
  echo "✅ Usuário $BOT_USER criado"
else
  echo "✅ Usuário $BOT_USER já existe"
fi

# Adicionar usuário botzap ao grupo www-data para acesso a arquivos
usermod -a -G "$WEB_GROUP" "$BOT_USER"
echo "✅ $BOT_USER adicionado ao grupo $WEB_GROUP"

# =====================================================
# DIRETÓRIOS
# =====================================================
echo "📁 Criando diretórios..."
mkdir -p "$BOT_DIR"
mkdir -p "$WEB_DIR"
mkdir -p "$WEB_DIR/sessions"
echo "✅ Diretórios criados:"
echo "   - $BOT_DIR"
echo "   - $WEB_DIR"
echo "   - $WEB_DIR/sessions"

# =====================================================
# PERMISSÕES COMPARTILHADAS
# =====================================================
echo "🔐 Configurando permissões compartilhadas..."

# 1. Diretório principal do bot - ACESSO COMPARTILHADO
chown -R "$BOT_USER:$WEB_GROUP" "$BOT_DIR"
chmod 775 "$BOT_DIR"
echo "✅ Permissões de $BOT_DIR ajustadas"

# 2. Node_modules
mkdir -p "$BOT_DIR/node_modules"
chown -R "$BOT_USER:$WEB_GROUP" "$BOT_DIR/node_modules"
find "$BOT_DIR/node_modules" -type d -exec chmod 775 {} \;
find "$BOT_DIR/node_modules" -type f -exec chmod 664 {} \;
echo "✅ Diretório node_modules configurado"

# 3. Auth_info - PRIVADO (apenas botzap)
mkdir -p "$BOT_DIR/auth_info"
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/auth_info"
chmod 700 "$BOT_DIR/auth_info"
echo "✅ Diretório auth_info configurado (privado)"

# 4. Sessions directory para PHP
chown -R "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR/sessions"
chmod 770 "$WEB_DIR/sessions"
echo "✅ Diretório sessions configurado"

# =====================================================
# PACKAGE.JSON E DEPENDÊNCIAS
# =====================================================
echo "📦 Criando package.json..."
cat > "$BOT_DIR/package.json" <<'PKGEOF'
{
  "name": "whatsapp-bot",
  "version": "1.0.0",
  "main": "bot.js",
  "scripts": {
    "start": "node bot.js",
    "test": "echo \"Error: no test specified\" && exit 1"
  },
  "keywords": [],
  "author": "WebLine Telecom",
  "license": "ISC",
  "description": "Bot WhatsApp para atendimento automático",
  "dependencies": {
    "@whiskeysockets/baileys": "^7.0.0-rc.9",
    "pino": "^10.3.0"
  }
}
PKGEOF

# Package.json - privado do bot
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/package.json"
chmod 640 "$BOT_DIR/package.json"
echo "✅ package.json criado"

# =====================================================
# INSTALAR DEPENDÊNCIAS NPM
# =====================================================
echo "📥 Instalando dependências Node.js..."
cd "$BOT_DIR"
sudo -u "$BOT_USER" npm install --silent

# Ajustar permissões do package-lock.json
if [ -f "$BOT_DIR/package-lock.json" ]; then
    chown "$BOT_USER:$BOT_USER" "$BOT_DIR/package-lock.json"
    chmod 640 "$BOT_DIR/package-lock.json"
fi
echo "✅ Dependências Node.js instaladas"

# =====================================================
# ARQUIVOS DE CONFIGURAÇÃO DO BOT
# =====================================================
echo "⚙️ Criando arquivos de configuração do bot..."

# 1. config.json - COMPARTILHADO (bot e php podem ler/escrever)
cat > "$BOT_DIR/config.json" <<'CFGEOF'
{
    "empresa": "WebLine Telecom",
    "menu": "Olá! 👋\nBem-vindo ao atendimento da *{{empresa}}*\n\n1️⃣ Baixar Fatura\n2️⃣ Falar com Atendente\n\nDigite o número da opção desejada:",
    "boleto_url": "https://www.weblinetelecom.com.br/pix.php",
    "atendente_numero": "5583982277238",
    "tempo_atendimento_humano": 15,
    "feriados_ativos": "Sim"
}
CFGEOF
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/config.json"
chmod 664 "$BOT_DIR/config.json"
echo "✅ config.json criado"

# 2. status.json - COMPARTILHADO (bot escreve, php lê)
cat > "$BOT_DIR/status.json" <<'STATEOF'
{
  "status": "offline",
  "updated": "$(date -Iseconds)"
}
STATEOF
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/status.json"
chmod 664 "$BOT_DIR/status.json"
echo "✅ status.json criado"

# 3. usuarios.json - COMPARTILHADO (bot escreve, php lê)
cat > "$BOT_DIR/usuarios.json" <<'USEREOF'
{
  "5583982277238": {
    "numero": "5583982277238",
    "tipo": "atendente",
    "pushName": "Webline Info",
    "cadastradoEm": "$(date -Iseconds)"
  }
}
USEREOF
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/usuarios.json"
chmod 664 "$BOT_DIR/usuarios.json"
echo "✅ usuarios.json criado"

# 4. qrcode.txt - COMPARTILHADO (bot escreve, php lê)
touch "$BOT_DIR/qrcode.txt"
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/qrcode.txt"
chmod 664 "$BOT_DIR/qrcode.txt"
echo "✅ qrcode.txt criado"

# =====================================================
# BAIXAR ARQUIVOS DO GITHUB - LISTA COMPLETA
# =====================================================
echo "📥 Baixando arquivos do repositório GitHub..."
echo "🌐 Repositório: $REPO_URL"

TEMP_DIR="/tmp/botzap_install_$(date +%s)"
mkdir -p "$TEMP_DIR"
cd "$TEMP_DIR"

# LISTA COMPLETA DOS ARQUIVOS WEB
WEB_FILES=(
    "auth.php"
    "index.php"
    "login.php"
    "logo.jpg"
    "logout.php"
    "pix.php"
    "qrcode_online.png"
    "qrcode_view.php"
    "qrcode_wait.png"
    "save.php"
    "status.php"
    "users.php"
    "bot.js"
)

echo "📋 Baixando arquivos necessários:"
echo "---------------------------------"

# Baixar arquivos principais
DOWNLOAD_COUNT=0
for FILE in "${WEB_FILES[@]}"; do
    echo -n "   📄 $FILE ... "
    if curl -sL -f "$REPO_URL/$FILE" -o "$FILE" 2>/dev/null; then
        echo "✅"
        DOWNLOAD_COUNT=$((DOWNLOAD_COUNT + 1))
    else
        echo "❌ (não encontrado)"
        # Criar versões básicas para arquivos essenciais
        case "$FILE" in
            "index.php")
                cat > "$FILE" <<'EOF'
<?php
session_start();
require_once 'auth.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$config = json_decode(file_get_contents('/opt/whatsapp-bot/config.json'), true);
$status = json_decode(file_get_contents('/opt/whatsapp-bot/status.json'), true);
$qrcode = @file_get_contents('/opt/whatsapp-bot/qrcode.txt');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot WhatsApp - <?php echo htmlspecialchars($config['empresa'] ?? 'WebLine Telecom'); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px; }
        .status { padding: 15px; border-radius: 5px; margin: 20px 0; font-size: 16px; }
        .online { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .offline { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .qrcode-container { text-align: center; margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px; }
        .qrcode-img { max-width: 300px; border: 2px solid #dee2e6; padding: 10px; background: white; }
        .menu { display: flex; justify-content: center; gap: 15px; margin-top: 30px; }
        .menu a { padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; }
        .menu a:hover { background: #0056b3; }
        .info-box { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #007bff; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🤖 Bot WhatsApp - <?php echo htmlspecialchars($config['empresa'] ?? 'WebLine Telecom'); ?></h1>
            <p>Painel de Controle do Bot de Atendimento Automático</p>
        </div>
        
        <div class="status <?php echo $status['status'] ?? 'offline'; ?>">
            <strong>Status do Bot:</strong> <?php echo strtoupper($status['status'] ?? 'offline'); ?>
            <br><small>Última atualização: <?php echo $status['updated'] ?? 'N/A'; ?></small>
        </div>
        
        <div class="info-box">
            <strong>📋 Instruções:</strong>
            <ol>
                <li>Verifique o status acima</li>
                <li>Se estiver offline, escaneie o QR Code abaixo</li>
                <li>Após escanear, aguarde a conexão automática</li>
                <li>Use o menu abaixo para gerenciar o bot</li>
            </ol>
        </div>
        
        <div class="qrcode-container">
            <h3>📱 QR Code para Login no WhatsApp</h3>
            <?php if (!empty($qrcode) && ($status['status'] ?? '') === 'offline'): ?>
                <img src="data:image/png;base64,<?php echo base64_encode($qrcode); ?>" 
                     alt="QR Code WhatsApp" class="qrcode-img">
                <p>Abra o WhatsApp → Menu → Dispositivos vinculados → Vincular um dispositivo</p>
            <?php elseif (($status['status'] ?? '') === 'online'): ?>
                <div style="color: #155724; font-size: 18px; padding: 20px;">
                    ✅ <strong>Bot conectado e pronto para uso!</strong>
                    <p>O bot está online e respondendo mensagens automaticamente.</p>
                </div>
            <?php else: ?>
                <div style="color: #856404; padding: 20px;">
                    ⏳ <strong>Aguardando QR Code...</strong>
                    <p>O bot está gerando o QR Code. Aguarde alguns segundos e atualize a página.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="menu">
            <a href="status.php">📊 Status</a>
            <a href="users.php">👥 Usuários</a>
            <a href="config.php">⚙️ Configurações</a>
            <a href="logout.php">🚪 Sair</a>
        </div>
        
        <div style="text-align: center; margin-top: 30px; color: #6c757d; font-size: 12px;">
            <p>WebLine Telecom &copy; <?php echo date('Y'); ?> | Bot WhatsApp v1.0</p>
            <p>Problemas? Verifique os logs ou entre em contato com o suporte.</p>
        </div>
    </div>
</body>
</html>
EOF
                echo "   ✅ index.php criado (básico)"
                DOWNLOAD_COUNT=$((DOWNLOAD_COUNT + 1))
                ;;
            "auth.php")
                cat > "$FILE" <<'EOF'
<?php
session_start();

// Configurações de autenticação
$valid_username = 'admin';
$valid_password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // admin123

// Verificar se usuário está logado
function check_login() {
    return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
}

// Verificar credenciais
function verify_credentials($username, $password) {
    global $valid_username, $valid_password_hash;
    
    if ($username === $valid_username && password_verify($password, $valid_password_hash)) {
        return true;
    }
    return false;
}

// Registrar tentativa de login
function log_login_attempt($username, $success, $ip = null) {
    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    $log_file = '/var/log/botzap_auth.log';
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'SUCESSO' : 'FALHA';
    $message = "[$timestamp] [$status] Usuário: $username - IP: $ip\n";
    
    @file_put_contents($log_file, $message, FILE_APPEND | LOCK_EX);
}

// Redirecionar para login se não autenticado
function require_login() {
    if (!check_login()) {
        header('Location: login.php');
        exit;
    }
}

// Logout
function logout() {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
EOF
                echo "   ✅ auth.php criado (básico)"
                DOWNLOAD_COUNT=$((DOWNLOAD_COUNT + 1))
                ;;
            "login.php")
                cat > "$FILE" <<'EOF'
<?php
session_start();
require_once 'auth.php';

// Redirecionar se já estiver logado
if (check_login()) {
    header('Location: index.php');
    exit;
}

$error = '';

// Processar formulário de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Por favor, preencha todos os campos';
    } elseif (verify_credentials($username, $password)) {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['login_time'] = time();
        log_login_attempt($username, true);
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuário ou senha inválidos';
        log_login_attempt($username, false);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Bot WhatsApp WebLine Telecom</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh;
            padding: 20px;
        }
        .login-container { 
            width: 100%;
            max-width: 400px;
        }
        .login-box { 
            background: white; 
            padding: 40px; 
            border-radius: 15px; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            text-align: center;
        }
        .logo { 
            margin-bottom: 30px; 
        }
        .logo h1 { 
            color: #333; 
            margin-bottom: 10px;
            font-size: 24px;
        }
        .logo p { 
            color: #666; 
            font-size: 14px;
        }
        .input-group { 
            margin-bottom: 20px; 
            text-align: left;
        }
        label { 
            display: block; 
            margin-bottom: 8px; 
            color: #555;
            font-weight: 500;
        }
        input[type="text"], 
        input[type="password"] { 
            width: 100%; 
            padding: 12px 15px; 
            border: 2px solid #e0e0e0; 
            border-radius: 8px; 
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input[type="text"]:focus, 
        input[type="password"]:focus {
            border-color: #667eea;
            outline: none;
        }
        button { 
            width: 100%; 
            padding: 14px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 16px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        button:hover { 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .error { 
            background: #fee; 
            color: #c33; 
            padding: 12px; 
            border-radius: 8px; 
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
        .credentials { 
            margin-top: 25px; 
            padding: 15px;
            background: #f8f9fa; 
            border-radius: 8px; 
            font-size: 13px; 
            color: #666;
            border: 1px solid #e9ecef;
        }
        .credentials strong { 
            color: #333; 
        }
        .footer { 
            margin-top: 30px; 
            color: #999; 
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <div class="logo">
                <h1>🔐 Bot WhatsApp</h1>
                <p>WebLine Telecom - Painel de Controle</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="input-group">
                    <label for="username">Usuário:</label>
                    <input type="text" id="username" name="username" required 
                           placeholder="Digite seu usuário" autocomplete="username">
                </div>
                
                <div class="input-group">
                    <label for="password">Senha:</label>
                    <input type="password" id="password" name="password" required 
                           placeholder="Digite sua senha" autocomplete="current-password">
                </div>
                
                <button type="submit">Entrar no Painel</button>
            </form>
            
            <div class="credentials">
                <strong>Credenciais padrão para primeiro acesso:</strong><br>
                Usuário: <code>admin</code><br>
                Senha: <code>admin123</code><br>
                <small style="color: #dc3545;">Altere estas credenciais após o primeiro login!</small>
            </div>
            
            <div class="footer">
                <p>WebLine Telecom &copy; <?php echo date('Y'); ?></p>
                <p>Versão 1.0 | Suporte: suporte@weblinetelecom.com.br</p>
            </div>
        </div>
    </div>
</body>
</html>
EOF
                echo "   ✅ login.php criado (básico)"
                DOWNLOAD_COUNT=$((DOWNLOAD_COUNT + 1))
                ;;
            "logout.php")
                cat > "$FILE" <<'EOF'
<?php
session_start();
session_destroy();
header('Location: login.php');
exit;
?>
EOF
                echo "   ✅ logout.php criado (básico)"
                DOWNLOAD_COUNT=$((DOWNLOAD_COUNT + 1))
                ;;
        esac
    fi
done

echo ""
echo "📊 Total de arquivos baixados/criados: $DOWNLOAD_COUNT"

# =====================================================
# COPIAR ARQUIVOS PARA DESTINOS FINAIS
# =====================================================
echo ""
echo "📋 Copiando arquivos para destinos finais..."

# 1. Copiar bot.js para diretório do bot
if [ -f "bot.js" ]; then
    cp "bot.js" "$BOT_DIR/"
    chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/bot.js"
    chmod 664 "$BOT_DIR/bot.js"
    echo "✅ bot.js copiado para $BOT_DIR/"
else
    echo "⚠️  bot.js não encontrado! O bot pode não funcionar."
fi

# 2. Copiar TODOS os arquivos web para diretório web
WEB_FILES_COPIED=0
echo "🌐 Copiando arquivos para $WEB_DIR/:"
for file in *; do
    if [ "$file" != "bot.js" ] && [ -f "$file" ]; then
        cp "$file" "$WEB_DIR/"
        WEB_FILES_COPIED=$((WEB_FILES_COPIED + 1))
        echo "   ✅ $file"
    fi
done

echo "📦 Total de $WEB_FILES_COPIED arquivos web copiados"

# 3. Ajustar permissões dos arquivos web
echo "🔐 Ajustando permissões dos arquivos web..."
chown -R "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR"/

# Permissões específicas por tipo de arquivo
find "$WEB_DIR" -type f -name "*.php" -exec chmod 755 {} \;
find "$WEB_DIR" -type f \( -name "*.css" -o -name "*.js" -o -name "*.json" \) -exec chmod 644 {} \;
find "$WEB_DIR" -type f \( -name "*.png" -o -name "*.jpg" -o -name "*.jpeg" -o -name "*.gif" \) -exec chmod 644 {} \;
find "$WEB_DIR" -type f -name "*.txt" -exec chmod 644 {} \;

# Permissão especial para save.php se existir
if [ -f "$WEB_DIR/save.php" ]; then
    chmod 755 "$WEB_DIR/save.php"
    echo "✅ save.php com permissão especial (755)"
fi

echo "✅ Permissões dos arquivos web ajustadas"

# Limpar diretório temporário
cd /
rm -rf "$TEMP_DIR"

# =====================================================
# CRIAR ARQUIVO .HTACCESS PARA SEGURANÇA
# =====================================================
echo "🛡️ Criando arquivo .htaccess para segurança..."
cat > "$WEB_DIR/.htaccess" <<'HTACCESSEOF'
# Ativar rewrite engine
RewriteEngine On

# Proteger contra acesso direto a arquivos sensíveis
<FilesMatch "\.(json|txt|log|ini|md|sql)$">
    Require all denied
</FilesMatch>

# Não listar diretórios
Options -Indexes

# Redirecionar HTTP para HTTPS se disponível
RewriteCond %{HTTPS} off
RewriteCond %{HTTP_HOST} !^localhost$ [NC]
RewriteCond %{HTTP_HOST} !^127\.0\.0\.1$
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Proteger arquivos de configuração
<FilesMatch "^\.ht">
    Require all denied
</FilesMatch>

# Proteger diretórios de sistema
RedirectMatch 403 ^/sessions/.*$

# Cache para arquivos estáticos
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
</IfModule>

# Compressão GZIP
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>

# Prevenir hotlinking de imagens
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?(botwhatsapp\.weblinetelecom\.com\.br|localhost|127\.0\.0\.1) [NC]
RewriteRule \.(jpg|jpeg|png|gif)$ - [F,NC,L]
HTACCESSEOF

chown "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR/.htaccess"
chmod 644 "$WEB_DIR/.htaccess"
echo "✅ .htaccess criado"

# =====================================================
# CRIAR ARQUIVO DE CONFIGURAÇÃO PHP
# =====================================================
echo "⚙️ Criando configuração PHP personalizada..."
cat > "$WEB_DIR/config_web.php" <<'PHPCFGEOF'
<?php
// ====================================================
// CONFIGURAÇÕES DO SISTEMA BOT WHATSAPP
// ====================================================

// Diretórios do sistema
define('BOT_ROOT', '/opt/whatsapp-bot');
define('WEB_ROOT', '/var/www/botzap');

// Arquivos de dados do bot
define('BOT_CONFIG_FILE', BOT_ROOT . '/config.json');
define('BOT_STATUS_FILE', BOT_ROOT . '/status.json');
define('BOT_USERS_FILE', BOT_ROOT . '/usuarios.json');
define('BOT_QRCODE_FILE', BOT_ROOT . '/qrcode.txt');
define('BOT_AUTH_DIR', BOT_ROOT . '/auth_info');

// Configurações de segurança
define('SESSION_TIMEOUT', 3600); // 1 hora em segundos
define('LOG_FILE', '/var/log/botzap_web.log');
define('AUTH_LOG_FILE', '/var/log/botzap_auth.log');

// Configurações da empresa
$empresa_config = [
    'nome' => 'WebLine Telecom',
    'suporte_email' => 'suporte@weblinetelecom.com.br',
    'telefone_suporte' => '+55 (83) 98227-7238',
    'url_site' => 'https://weblinetelecom.com.br',
    'versao_sistema' => '1.0.0'
];

// ====================================================
// FUNÇÕES UTILITÁRIAS
// ====================================================

/**
 * Ler arquivo JSON com tratamento de erros
 */
function read_json_file($filepath) {
    if (!file_exists($filepath) || !is_readable($filepath)) {
        error_log("Arquivo não encontrado ou sem permissão: $filepath");
        return [];
    }
    
    $content = file_get_contents($filepath);
    if ($content === false) {
        error_log("Falha ao ler arquivo: $filepath");
        return [];
    }
    
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON inválido em $filepath: " . json_last_error_msg());
        return [];
    }
    
    return $data;
}

/**
 * Escrever arquivo JSON com tratamento de erros
 */
function write_json_file($filepath, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    if ($json === false) {
        error_log("Falha ao codificar JSON para: $filepath");
        return false;
    }
    
    $result = file_put_contents($filepath, $json, LOCK_EX);
    
    if ($result === false) {
        error_log("Falha ao escrever arquivo: $filepath");
        return false;
    }
    
    // Ajustar permissões após escrever
    chmod($filepath, 0664);
    chown($filepath, 'www-data');
    chgrp($filepath, 'www-data');
    
    return true;
}

/**
 * Obter status atual do bot
 */
function get_bot_status() {
    $status = read_json_file(BOT_STATUS_FILE);
    
    if (empty($status)) {
        $status = [
            'status' => 'offline',
            'updated' => date('c'),
            'message' => 'Bot não configurado'
        ];
        write_json_file(BOT_STATUS_FILE, $status);
    }
    
    return $status;
}

/**
 * Atualizar status do bot
 */
function update_bot_status($new_status, $message = '') {
    $status = [
        'status' => $new_status,
        'updated' => date('c'),
        'message' => $message
    ];
    
    return write_json_file(BOT_STATUS_FILE, $status);
}

/**
 * Obter configurações do bot
 */
function get_bot_config() {
    $config = read_json_file(BOT_CONFIG_FILE);
    
    if (empty($config)) {
        $config = [
            'empresa' => 'WebLine Telecom',
            'menu' => "Olá! 👋\nBem-vindo ao atendimento da *{{empresa}}*\n\n1️⃣ Baixar Fatura\n2️⃣ Falar com Atendente\n\nDigite o número da opção desejada:",
            'boleto_url' => 'https://www.weblinetelecom.com.br/pix.php',
            'atendente_numero' => '5583982277238',
            'tempo_atendimento_humano' => 15,
            'feriados_ativos' => 'Sim'
        ];
        write_json_file(BOT_CONFIG_FILE, $config);
    }
    
    return $config;
}

/**
 * Obter lista de usuários
 */
function get_bot_users() {
    $users = read_json_file(BOT_USERS_FILE);
    
    if (empty($users)) {
        $users = [
            '5583982277238' => [
                'numero' => '5583982277238',
                'tipo' => 'atendente',
                'pushName' => 'Webline Info',
                'cadastradoEm' => date('c'),
                'ultimaConexao' => date('c')
            ]
        ];
        write_json_file(BOT_USERS_FILE, $users);
    }
    
    return $users;
}

/**
 * Verificar permissões de arquivos
 */
function check_file_permissions() {
    $files = [
        BOT_CONFIG_FILE => 'Configurações',
        BOT_STATUS_FILE => 'Status',
        BOT_USERS_FILE => 'Usuários',
        BOT_QRCODE_FILE => 'QR Code'
    ];
    
    $results = [];
    
    foreach ($files as $filepath => $descricao) {
        $exists = file_exists($filepath);
        $readable = $exists && is_readable($filepath);
        $writable = $exists && is_writable($filepath);
        $perms = $exists ? substr(sprintf('%o', fileperms($filepath)), -4) : '0000';
        
        $results[] = [
            'arquivo' => $descricao,
            'caminho' => $filepath,
            'existe' => $exists,
            'leitura' => $readable,
            'escrita' => $writable,
            'permissoes' => $perms,
            'ok' => $exists && $readable && $writable
        ];
    }
    
    return $results;
}

/**
 * Registrar log do sistema
 */
function system_log($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user = $_SESSION['username'] ?? 'anonymous';
    
    $log_message = "[$timestamp] [$type] [$ip] [$user] $message\n";
    
    @file_put_contents(LOG_FILE, $log_message, FILE_APPEND | LOCK_EX);
}

/**
 * Validar número de WhatsApp
 */
function validate_whatsapp_number($number) {
    // Remover caracteres não numéricos
    $clean_number = preg_replace('/[^0-9]/', '', $number);
    
    // Verificar se tem pelo menos 10 dígitos
    if (strlen($clean_number) < 10) {
        return false;
    }
    
    // Adicionar código do país se não tiver
    if (!preg_match('/^55/', $clean_number)) {
        $clean_number = '55' . $clean_number;
    }
    
    return $clean_number;
}

/**
 * Formatar data para exibição
 */
function format_date($date_string, $format = 'd/m/Y H:i:s') {
    try {
        $date = new DateTime($date_string);
        return $date->format($format);
    } catch (Exception $e) {
        return $date_string;
    }
}

// ====================================================
// INICIALIZAÇÃO
// ====================================================

// Configurar tratamento de erros
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', LOG_FILE);

// Configurar fuso horário
date_default_timezone_set('America/Sao_Paulo');

// Verificar e criar arquivos necessários
if (!file_exists(BOT_STATUS_FILE)) {
    update_bot_status('offline', 'Sistema recém-instalado');
}

// Iniciar sessão se não estiver iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_TIMEOUT,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_name('BOTZAP_SESSION');
    session_start();
}

// Verificar timeout da sessão
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > SESSION_TIMEOUT)) {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
PHPCFGEOF

chown "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR/config_web.php"
chmod 644 "$WEB_DIR/config_web.php"
echo "✅ config_web.php criado"

# =====================================================
# CRIAR ARQUIVOS BÁSICOS PARA OS QUE FALTARAM
# =====================================================
echo "📝 Criando arquivos básicos para funcionalidade completa..."

# Criar status.php se não existe
if [ ! -f "$WEB_DIR/status.php" ]; then
    cat > "$WEB_DIR/status.php" <<'EOF'
<?php
require_once 'auth.php';
require_once 'config_web.php';
require_login();

$status = get_bot_status();
$config = get_bot_config();
$permissions = check_file_permissions();

// Log de acesso
system_log("Acessou página de status");

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status do Sistema - Bot WhatsApp</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px 10px 0 0; margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .status-badge { display: inline-block; padding: 5px 15px; border-radius: 20px; color: white; font-weight: bold; }
        .status-online { background: #28a745; }
        .status-offline { background: #dc3545; }
        .status-connecting { background: #ffc107; color: #333; }
        .permission-table { width: 100%; border-collapse: collapse; }
        .permission-table th, .permission-table td { padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; }
        .permission-table th { background: #f8f9fa; }
        .permission-ok { color: #28a745; }
        .permission-error { color: #dc3545; }
        .btn-back { display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; }
        .btn-back:hover { background: #5a6268; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Status do Sistema</h1>
            <p>Verificação completa do bot WhatsApp</p>
            <a href="index.php" class="btn-back">← Voltar ao Painel</a>
        </div>
        
        <div class="card">
            <h2>🤖 Status do Bot</h2>
            <?php
            $status_class = 'status-' . $status['status'];
            ?>
            <p>Status atual: 
                <span class="status-badge <?php echo $status_class; ?>">
                    <?php echo strtoupper($status['status']); ?>
                </span>
            </p>
            <p>Última atualização: <?php echo format_date($status['updated']); ?></p>
            <?php if (!empty($status['message'])): ?>
                <p>Mensagem: <?php echo htmlspecialchars($status['message']); ?></p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>🔐 Permissões de Arquivos</h2>
            <table class="permission-table">
                <thead>
                    <tr>
                        <th>Arquivo</th>
                        <th>Existe</th>
                        <th>Leitura</th>
                        <th>Escrita</th>
                        <th>Permissões</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($permissions as $perm): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($perm['arquivo']); ?></td>
                        <td><?php echo $perm['existe'] ? '✅' : '❌'; ?></td>
                        <td><?php echo $perm['leitura'] ? '✅' : '❌'; ?></td>
                        <td><?php echo $perm['escrita'] ? '✅' : '❌'; ?></td>
                        <td><code><?php echo $perm['permissoes']; ?></code></td>
                        <td>
                            <?php if ($perm['ok']): ?>
                                <span class="permission-ok">✅ OK</span>
                            <?php else: ?>
                                <span class="permission-error">❌ Problema</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <h2>📈 Informações do Sistema</h2>
            <ul>
                <li>Servidor: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'N/A'; ?></li>
                <li>PHP: <?php echo phpversion(); ?></li>
                <li>Memória: <?php echo ini_get('memory_limit'); ?></li>
                <li>Timezone: <?php echo date_default_timezone_get(); ?></li>
                <li>Usuário: <?php echo htmlspecialchars($_SESSION['username'] ?? 'N/A'); ?></li>
                <li>IP: <?php echo $_SERVER['REMOTE_ADDR'] ?? 'N/A'; ?></li>
            </ul>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="index.php" class="btn-back">← Voltar ao Painel Principal</a>
        </div>
    </div>
</body>
</html>
EOF
    echo "✅ status.php criado (básico)"
fi

# Criar users.php se não existe
if [ ! -f "$WEB_DIR/users.php" ]; then
    cat > "$WEB_DIR/users.php" <<'EOF'
<?php
require_once 'auth.php';
require_once 'config_web.php';
require_login();

$users = get_bot_users();
$config = get_bot_config();

// Log de acesso
system_log("Acessou página de usuários");

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Bot WhatsApp</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px 10px 0 0; margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .user-table { width: 100%; border-collapse: collapse; }
        .user-table th, .user-table td { padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; }
        .user-table th { background: #f8f9fa; }
        .user-type { display: inline-block; padding: 3px 10px; border-radius: 15px; font-size: 12px; }
        .type-atendente { background: #007bff; color: white; }
        .type-cliente { background: #6c757d; color: white; }
        .type-admin { background: #28a745; color: white; }
        .btn-back { display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; }
        .btn-back:hover { background: #5a6268; }
        .btn-add { display: inline-block; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; margin-left: 10px; }
        .btn-add:hover { background: #218838; }
        .empty-state { text-align: center; padding: 40px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>👥 Gerenciar Usuários</h1>
            <p>Usuários cadastrados no sistema do bot</p>
            <div>
                <a href="index.php" class="btn-back">← Voltar ao Painel</a>
                <a href="#" class="btn-add" onclick="alert('Funcionalidade de adicionar usuário em desenvolvimento');">+ Adicionar Usuário</a>
            </div>
        </div>
        
        <div class="card">
            <h2>📋 Lista de Usuários</h2>
            
            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <p>Nenhum usuário cadastrado no momento.</p>
                    <p>Os usuários serão automaticamente cadastrados quando interagirem com o bot.</p>
                </div>
            <?php else: ?>
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>Número WhatsApp</th>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Cadastrado em</th>
                            <th>Última Conexão</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $numero => $user): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($numero); ?></code></td>
                            <td><?php echo htmlspecialchars($user['pushName'] ?? 'Não identificado'); ?></td>
                            <td>
                                <span class="user-type type-<?php echo htmlspecialchars($user['tipo'] ?? 'cliente'); ?>">
                                    <?php echo htmlspecialchars($user['tipo'] ?? 'cliente'); ?>
                                </span>
                            </td>
                            <td><?php echo format_date($user['cadastradoEm'] ?? ''); ?></td>
                            <td><?php echo format_date($user['ultimaConexao'] ?? $user['cadastradoEm'] ?? ''); ?></td>
                            <td>
                                <button onclick="alert('Editar usuário <?php echo $numero; ?>')">Editar</button>
                                <button onclick="if(confirm('Tem certeza?')) alert('Usuário <?php echo $numero; ?> removido')">Remover</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p style="margin-top: 20px; color: #6c757d; font-size: 14px;">
                    Total: <?php echo count($users); ?> usuário(s) cadastrado(s)
                </p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>ℹ️ Informações</h2>
            <ul>
                <li>Usuários são cadastrados automaticamente ao interagir com o bot</li>
                <li>Atendentes têm acesso a funcionalidades especiais</li>
                <li>Clientes são usuários comuns que enviam mensagens</li>
                <li>Administradores podem gerenciar todo o sistema</li>
            </ul>
        </div>
    </div>
</body>
</html>
EOF
    echo "✅ users.php criado (básico)"
fi

# Criar save.php se não existe
if [ ! -f "$WEB_DIR/save.php" ]; then
    cat > "$WEB_DIR/save.php" <<'EOF'
<?php
require_once 'auth.php';
require_once 'config_web.php';
require_login();

// Log de acesso
system_log("Acessou página save.php");

// Verificar se é requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Processar salvamento de configurações
$response = ['success' => false, 'message' => '', 'errors' => []];

try {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_config':
            $config = $_POST['config'] ?? [];
            
            // Validar campos obrigatórios
            if (empty($config['empresa'])) {
                $response['errors'][] = 'Nome da empresa é obrigatório';
            }
            if (empty($config['atendente_numero'])) {
                $response['errors'][] = 'Número do atendente é obrigatório';
            }
            
            if (empty($response['errors'])) {
                // Validar número de WhatsApp
                $clean_number = validate_whatsapp_number($config['atendente_numero']);
                if (!$clean_number) {
                    $response['errors'][] = 'Número de WhatsApp inválido';
                } else {
                    $config['atendente_numero'] = $clean_number;
                }
            }
            
            if (empty($response['errors'])) {
                $current_config = get_bot_config();
                $updated_config = array_merge($current_config, $config);
                
                if (write_json_file(BOT_CONFIG_FILE, $updated_config)) {
                    $response['success'] = true;
                    $response['message'] = 'Configurações salvas com sucesso!';
                    system_log("Configurações atualizadas por " . $_SESSION['username']);
                } else {
                    $response['message'] = 'Erro ao salvar configurações';
                }
            }
            break;
            
        case 'update_status':
            $status = $_POST['status'] ?? '';
            $valid_statuses = ['online', 'offline', 'connecting'];
            
            if (!in_array($status, $valid_statuses)) {
                $response['errors'][] = 'Status inválido';
            } else {
                if (update_bot_status($status, 'Atualizado via painel web')) {
                    $response['success'] = true;
                    $response['message'] = "Status alterado para: $status";
                    system_log("Status alterado para $status por " . $_SESSION['username']);
                } else {
                    $response['message'] = 'Erro ao atualizar status';
                }
            }
            break;
            
        default:
            $response['message'] = 'Ação não reconhecida';
    }
    
} catch (Exception $e) {
    $response['message'] = 'Erro no servidor: ' . $e->getMessage();
    system_log("ERRO em save.php: " . $e->getMessage(), 'ERROR');
}

// Retornar resposta JSON
header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>
EOF
    echo "✅ save.php criado (básico)"
fi

# Criar pix.php se não existe
if [ ! -f "$WEB_DIR/pix.php" ]; then
    cat > "$WEB_DIR/pix.php" <<'EOF'
<?php
require_once 'auth.php';
require_once 'config_web.php';

// Se não estiver logado, redirecionar
if (!check_login()) {
    header('Location: login.php');
    exit;
}

$config = get_bot_config();

// Log de acesso
system_log("Acessou página PIX");

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento PIX - <?php echo htmlspecialchars($config['empresa']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; }
        .card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); text-align: center; }
        .header { margin-bottom: 30px; }
        .qrcode-container { margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 10px; }
        .qrcode-img { max-width: 250px; border: 1px solid #dee2e6; }
        .pix-key { background: #e9ecef; padding: 15px; border-radius: 8px; margin: 20px 0; font-family: monospace; font-size: 16px; }
        .instructions { text-align: left; margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 10px; }
        .btn-back { display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        .btn-back:hover { background: #5a6268; }
        .success-message { color: #28a745; padding: 10px; background: #d4edda; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>💰 Pagamento PIX</h1>
                <p><?php echo htmlspecialchars($config['empresa']); ?></p>
            </div>
            
            <div class="success-message">
                ✅ Pague via PIX para liberar seu serviço
            </div>
            
            <div class="qrcode-container">
                <h3>QR Code PIX</h3>
                <?php if (file_exists('qrcode_pix.png')): ?>
                    <img src="qrcode_pix.png" alt="QR Code PIX" class="qrcode-img">
                <?php else: ?>
                    <div style="padding: 30px; color: #856404; background: #fff3cd; border-radius: 5px;">
                        <p>QR Code PIX não configurado</p>
                        <p>Entre em contato com o suporte</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="pix-key">
                <strong>Chave PIX:</strong><br>
                <?php 
                $pix_key = 'suporte@weblinetelecom.com.br';
                echo htmlspecialchars($pix_key);
                ?>
                <button onclick="copyToClipboard('<?php echo $pix_key; ?>')" 
                        style="margin-left: 10px; padding: 5px 10px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer;">
                    Copiar
                </button>
            </div>
            
            <div class="instructions">
                <h4>📋 Instruções para pagamento:</h4>
                <ol>
                    <li>Abra o app do seu banco</li>
                    <li>Selecione a opção PIX</li>
                    <li>Escaneie o QR Code acima ou cole a chave PIX</li>
                    <li>Confira os dados e confirme o pagamento</li>
                    <li>Após o pagamento, seu serviço será liberado automaticamente</li>
                </ol>
                
                <p><strong>Valor:</strong> R$ 99,90 (Mensalidade)</p>
                <p><strong>Beneficiário:</strong> <?php echo htmlspecialchars($config['empresa']); ?></p>
                <p><strong>Prazo de liberação:</strong> Até 15 minutos após confirmação</p>
            </div>
            
            <div style="margin-top: 30px;">
                <a href="index.php" class="btn-back">← Voltar ao Painel</a>
            </div>
            
            <div style="margin-top: 20px; font-size: 12px; color: #6c757d;">
                <p>Problemas com o pagamento? Entre em contato: <?php echo htmlspecialchars($config['atendente_numero']); ?></p>
            </div>
        </div>
    </div>
    
    <script>
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Chave PIX copiada: ' + text);
        }, function(err) {
            console.error('Erro ao copiar: ', err);
        });
    }
    </script>
</body>
</html>
EOF
    echo "✅ pix.php criado (básico)"
fi

# Criar qrcode_view.php se não existe
if [ ! -f "$WEB_DIR/qrcode_view.php" ]; then
    cat > "$WEB_DIR/qrcode_view.php" <<'EOF'
<?php
require_once 'auth.php';
require_once 'config_web.php';
require_login();

$status = get_bot_status();
$qrcode_content = @file_get_contents(BOT_QRCODE_FILE);

// Log de acesso
system_log("Acessou visualização de QR Code");

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code WhatsApp - Bot WhatsApp</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; text-align: center; }
        .header { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .qrcode-box { background: white; padding: 40px; border-radius: 10px; margin-bottom: 20px; }
        .qrcode-img { max-width: 400px; border: 2px solid #dee2e6; padding: 10px; background: white; }
        .instructions { background: #e7f3ff; padding: 20px; border-radius: 10px; text-align: left; margin: 20px 0; }
        .btn-back { display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; }
        .btn-back:hover { background: #5a6268; }
        .btn-refresh { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-left: 10px; }
        .btn-refresh:hover { background: #0056b3; }
        .status-info { padding: 10px; border-radius: 5px; margin: 10px 0; }
        .status-offline { background: #f8d7da; color: #721c24; }
        .status-online { background: #d4edda; color: #155724; }
        .auto-refresh { color: #6c757d; font-size: 14px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📱 QR Code WhatsApp</h1>
            <p>Use este código para vincular o WhatsApp ao bot</p>
            
            <div style="margin-top: 20px;">
                <a href="index.php" class="btn-back">← Voltar ao Painel</a>
                <a href="javascript:location.reload()" class="btn-refresh">🔄 Atualizar QR Code</a>
            </div>
        </div>
        
        <div class="status-info status-<?php echo $status['status']; ?>">
            Status atual: <strong><?php echo strtoupper($status['status']); ?></strong>
            <br>Última atualização: <?php echo format_date($status['updated']); ?>
        </div>
        
        <div class="qrcode-box">
            <h2>QR Code para Vinculação</h2>
            
            <?php if (!empty($qrcode_content) && $status['status'] === 'offline'): ?>
                <img src="data:image/png;base64,<?php echo base64_encode($qrcode_content); ?>" 
                     alt="QR Code WhatsApp" class="qrcode-img">
                <p style="color: #28a745; margin-top: 15px;">✅ QR Code disponível para escaneamento</p>
            <?php elseif ($status['status'] === 'online'): ?>
                <div style="padding: 40px; background: #d4edda; border-radius: 10px;">
                    <h3 style="color: #155724;">✅ WhatsApp Conectado!</h3>
                    <p>O bot já está conectado ao WhatsApp e pronto para uso.</p>
                    <p>Não é necessário escanear QR Code no momento.</p>
                </div>
            <?php else: ?>
                <div style="padding: 40px; background: #fff3cd; border-radius: 10px;">
                    <h3 style="color: #856404;">⏳ Aguardando QR Code...</h3>
                    <p>O bot está gerando um novo QR Code.</p>
                    <p>Por favor, aguarde alguns segundos e atualize esta página.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="instructions">
            <h3>📋 Como vincular seu WhatsApp:</h3>
            <ol>
                <li>Abra o aplicativo do WhatsApp no seu celular</li>
                <li>Toque no menu (três pontos) → "Dispositivos vinculados"</li>
                <li>Toque em "Vincular um dispositivo"</li>
                <li>Aponte a câmera para o QR Code acima</li>
                <li>Aguarde a confirmação de vinculação</li>
                <li>O bot estará pronto para uso em instantes</li>
            </ol>
            
            <p><strong>⚠️ Importante:</strong></p>
            <ul>
                <li>Mantenha o celular com internet ativa</li>
                <li>O WhatsApp Web precisa estar ativo no celular</li>
                <li>O QR Code expira após alguns minutos</li>
                <li>Se expirar, atualize a página para gerar um novo</li>
            </ul>
        </div>
        
        <div class="auto-refresh">
            <p>Esta página será atualizada automaticamente a cada 30 segundos</p>
            <p>Última atualização: <?php echo date('H:i:s'); ?></p>
        </div>
    </div>
    
    <script>
    // Auto-refresh a cada 30 segundos
    setTimeout(function() {
        location.reload();
    }, 30000);
    
    // Atualizar horário
    function updateTime() {
        const now = new Date();
        document.querySelector('.auto-refresh p:last-child').textContent = 
            'Última atualização: ' + now.toLocaleTimeString();
    }
    setInterval(updateTime, 1000);
    </script>
</body>
</html>
EOF
    echo "✅ qrcode_view.php criado (básico)"
fi

# Verificar se as imagens existem, se não, criar placeholders
if [ ! -f "$WEB_DIR/logo.jpg" ]; then
    echo "⚠️  logo.jpg não encontrado no repositório"
    echo "   ℹ️  Você precisará adicionar manualmente ou usar uma imagem padrão"
fi

if [ ! -f "$WEB_DIR/qrcode_online.png" ]; then
    echo "⚠️  qrcode_online.png não encontrado no repositório"
fi

if [ ! -f "$WEB_DIR/qrcode_wait.png" ]; then
    echo "⚠️  qrcode_wait.png não encontrado no repositório"
fi

# =====================================================
# CONFIGURAÇÕES FINAIS DO SISTEMA
# =====================================================

echo ""
echo "⚙️  Aplicando configurações finais do sistema..."

# Configurar cron para limpeza de sessões
echo "🕐 Configurando cron para limpeza de sessões..."
(crontab -l 2>/dev/null | grep -v "botzap_sessions_cleanup"; echo "0 3 * * * find /var/www/botzap/sessions -type f -mtime +7 -delete") | crontab -
echo "✅ Cron job configurado"

# Configurar política de segurança para Apache
echo "🛡️  Configurando segurança do Apache..."
cat > /etc/apache2/conf-available/botzap-security.conf <<'APACHESECEOF'
# Configurações de segurança para BotZap
ServerTokens Prod
ServerSignature Off
TraceEnable Off
FileETag None

# Headers de segurança
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Limites de requisição
LimitRequestBody 10485760
LimitXMLRequestBody 10485760

# Timeouts
Timeout 60
KeepAlive On
MaxKeepAliveRequests 100
KeepAliveTimeout 5
APACHESECEOF

a2enconf botzap-security.conf

# Configurar PHP para produção
echo "⚙️  Configurando PHP para produção..."
cat > /etc/php/*/apache2/conf.d/99-botzap.ini <<'PHPINIEOF'
; Configurações PHP para BotZap
upload_max_filesize = 10M
post_max_size = 10M
memory_limit = 256M
max_execution_time = 300
max_input_time = 300

; Exibição de erros (desativar em produção)
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php_errors.log
error_reporting = E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT

; Configurações de sessão
session.save_path = "/var/www/botzap/sessions"
session.gc_maxlifetime = 1440
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Strict"
session.use_strict_mode = 1

; Segurança
allow_url_fopen = Off
allow_url_include = Off
expose_php = Off
PHPINIEOF

# Recarregar configurações
systemctl reload apache2

# =====================================================
# TESTES FINAIS E RESUMO
# =====================================================
echo ""
echo "🧪 Executando testes finais..."

echo ""
echo "1. Testando serviços:"
echo "   • Apache: $(systemctl is-active apache2)"
echo "   • Bot: $(systemctl is-active botzap 2>/dev/null || echo 'não iniciado')"

echo ""
echo "2. Testando arquivos web críticos:"
WEB_CRITICAL_FILES=("index.php" "auth.php" "login.php" "config_web.php")
for file in "${WEB_CRITICAL_FILES[@]}"; do
    if [ -f "$WEB_DIR/$file" ]; then
        echo "   ✅ $file"
    else
        echo "   ❌ $file (FALTA)"
    fi
done

echo ""
echo "3. Testando permissões de escrita:"
WRITE_TEST_FILES=(
    "$BOT_DIR/config.json"
    "$BOT_DIR/status.json"
    "$BOT_DIR/usuarios.json"
)

for file in "${WRITE_TEST_FILES[@]}"; do
    if sudo -u "$BOT_USER" touch "$file" 2>/dev/null; then
        echo "   ✅ botzap pode escrever em $(basename "$file")"
    else
        echo "   ❌ botzap NÃO pode escrever em $(basename "$file")"
    fi
    
    if sudo -u "$WEB_GROUP" touch "$file" 2>/dev/null; then
        echo "   ✅ www-data pode escrever em $(basename "$file")"
    else
        echo "   ❌ www-data NÃO pode escrever em $(basename "$file")"
    fi
done

echo ""
echo "4. Testando conexão web:"
if curl -s -o /dev/null -w "%{http_code}" http://localhost/ | grep -q "200\|302"; then
    echo "   ✅ Apache respondendo corretamente"
else
    echo "   ⚠️  Apache pode não estar respondendo corretamente"
fi

# =====================================================
# INICIAR O BOT
# =====================================================
echo ""
echo "🚀 Iniciando o bot WhatsApp..."
systemctl start botzap
sleep 3

BOT_STATUS=$(systemctl is-active botzap 2>/dev/null || echo "failed")
if [ "$BOT_STATUS" = "active" ]; then
    echo "✅ Bot iniciado com sucesso!"
    echo "📊 Verifique os logs: sudo journalctl -u botzap -f"
else
    echo "⚠️  Bot não iniciou automaticamente"
    echo "📋 Verifique: sudo systemctl status botzap"
    echo "📋 Verifique logs: sudo journalctl -u botzap"
fi

# =====================================================
# RESUMO FINAL
# =====================================================
echo ""
echo "================================================"
echo "🎉 INSTALAÇÃO CONCLUÍDA COM SUCESSO!"
echo "================================================"
echo ""
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "localhost")

cat << EOF
📋 RESUMO DA INSTALAÇÃO:
------------------------
• Diretório do bot:       $BOT_DIR
• Diretório web:          $WEB_DIR
• Usuário do bot:         $BOT_USER
• Grupo web:              $WEB_GROUP
• Arquivos instalados:    $(ls -1 "$WEB_DIR" | wc -l) arquivos
• Bot status:             $(systemctl is-active botzap 2>/dev/null || echo "não iniciado")
• Apache status:          $(systemctl is-active apache2)

🔐 INFORMAÇÕES DE ACESSO:
-------------------------
• Painel web (HTTP):      http://$SERVER_IP/
• Painel web (HTTPS):     https://$SERVER_IP/
• Login padrão:           admin / admin123
• Logs do bot:            $LOG_FILE
• Logs do Apache:         /var/log/apache2/botzap_error.log
• Logs de autenticação:   /var/log/botzap_auth.log

⚡ COMANDOS RÁPIDOS:
-------------------
• Status do bot:          sudo systemctl status botzap
• Iniciar bot:            sudo systemctl start botzap
• Parar bot:              sudo systemctl stop botzap
• Reiniciar bot:          sudo systemctl restart botzap
• Ver logs em tempo real: sudo journalctl -u botzap -f
• Ver QR Code atual:      sudo cat $BOT_DIR/qrcode.txt
• Testar painel web:      curl -I http://localhost/

📁 ARQUIVOS INSTALADOS EM $WEB_DIR/:
-----------------------------------
$(ls -1 "$WEB_DIR" | head -20 | while read f; do echo "  • $f"; done)
$(if [ $(ls -1 "$WEB_DIR" | wc -l) -gt 20 ]; then echo "  ... e mais $(($(ls -1 "$WEB_DIR" | wc -l) - 20)) arquivos"; fi)

🔧 PRÓXIMOS PASSOS:
------------------
1. Acesse http://$SERVER_IP/ no navegador
2. Faça login com: admin / admin123
3. Vá para "QR Code WhatsApp" no menu
4. Escaneie o QR Code com seu WhatsApp
5. Configure as opções do bot em "Configurações"
6. Altere a senha padrão imediatamente!

⚠️  IMPORTANTE:
--------------
• Altere a senha padrão 'admin123' após o primeiro login!
• Configure um domínio real (botwhatsapp.weblinetelecom.com.br)
• Instale um certificado SSL válido
• Configure backup regular dos arquivos em $BOT_DIR/
• Monitore os logs regularmente

📞 SUPORTE:
----------
• Verifique permissões: sudo chown -R $BOT_USER:$WEB_GROUP $BOT_DIR
• Bot não conecta: Verifique $LOG_FILE
• Painel não carrega: Verifique /var/log/apache2/botzap_error.log
• Problemas de login: Verifique /var/log/botzap_auth.log

✅ Instalação finalizada em: $(date)
⏱️  Tempo total de instalação: Aproximadamente 3-5 minutos
EOF

echo ""
echo "================================================"
echo "🌟 Seu bot WhatsApp está pronto para uso!"
echo "================================================"
