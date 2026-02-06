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
  php-session \
  php-xml

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

# LISTA COMPLETA DOS ARQUIVOS WEB (conforme você forneceu)
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
    "bot.js"  # Arquivo principal do bot
)

# Adicionar arquivos CSS/JS adicionais se existirem
ADDITIONAL_FILES=(
    "style.css"
    "script.js"
    "config.php"
    "dashboard.php"
    "api.php"
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
        # Se for um arquivo crítico, criar versão básica
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
$qrcode = file_get_contents('/opt/whatsapp-bot/qrcode.txt');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot WhatsApp - <?php echo $config['empresa']; ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; }
        .header { text-align: center; margin-bottom: 30px; }
        .status { padding: 10px; border-radius: 5px; margin: 10px 0; }
        .online { background: #d4edda; color: #155724; }
        .offline { background: #f8d7da; color: #721c24; }
        .qrcode-container { text-align: center; margin: 20px 0; }
        .qrcode-img { max-width: 300px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🤖 Bot WhatsApp - <?php echo $config['empresa']; ?></h1>
            <p>Painel de Controle</p>
        </div>
        
        <div class="status <?php echo $status['status']; ?>">
            Status: <strong><?php echo strtoupper($status['status']); ?></strong>
            <br>Atualizado: <?php echo $status['updated']; ?>
        </div>
        
        <div class="qrcode-container">
            <h3>QR Code para Login</h3>
            <?php if (!empty($qrcode) && $status['status'] === 'offline'): ?>
                <img src="data:image/png;base64,<?php echo base64_encode($qrcode); ?>" 
                     alt="QR Code" class="qrcode-img">
                <p>Escaneie com o WhatsApp</p>
            <?php elseif ($status['status'] === 'online'): ?>
                <p>✅ Bot conectado e pronto!</p>
            <?php else: ?>
                <p>⏳ Aguardando QR Code...</p>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="status.php">Status</a> | 
            <a href="users.php">Usuários</a> | 
            <a href="logout.php">Sair</a>
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
$valid_password_hash = password_hash('admin123', PASSWORD_DEFAULT); // Senha padrão: admin123

// Verificar se usuário está logado
function check_login() {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        return false;
    }
    return true;
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
function log_login_attempt($username, $success) {
    $log_file = '/var/log/botzap_auth.log';
    $message = date('Y-m-d H:i:s') . " - ";
    $message .= "Usuário: $username - ";
    $message .= $success ? "SUCESSO" : "FALHA";
    $message .= " - IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
    
    file_put_contents($log_file, $message, FILE_APPEND | LOCK_EX);
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
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

// Processar formulário de login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (verify_credentials($username, $password)) {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
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
    <title>Login - Bot WhatsApp</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); width: 300px; }
        h2 { text-align: center; margin-bottom: 30px; color: #333; }
        .input-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #666; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        button { width: 100%; padding: 10px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .error { color: #dc3545; text-align: center; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>🔐 Login</h2>
        
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="input-group">
                <label for="username">Usuário:</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="input-group">
                <label for="password">Senha:</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">Entrar</button>
        </form>
        
        <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #666;">
            Credenciais padrão: admin / admin123
        </div>
    </div>
</body>
</html>
EOF
                echo "   ✅ login.php criado (básico)"
                DOWNLOAD_COUNT=$((DOWNLOAD_COUNT + 1))
                ;;
        esac
    fi
done

# Tentar baixar arquivos adicionais
echo ""
echo "🔍 Procurando arquivos adicionais:"
for FILE in "${ADDITIONAL_FILES[@]}"; do
    echo -n "   📄 $FILE ... "
    if curl -sL -f "$REPO_URL/$FILE" -o "$FILE" 2>/dev/null; then
        echo "✅"
        DOWNLOAD_COUNT=$((DOWNLOAD_COUNT + 1))
    else
        echo "⚪ (não encontrado, pulando)"
        rm -f "$FILE" 2>/dev/null
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
# Proteger contra acesso direto a arquivos sensíveis
<FilesMatch "\.(json|txt|log|ini)$">
    Require all denied
</FilesMatch>

# Proteger diretórios
Options -Indexes

# Redirecionar HTTPS se disponível
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Proteger contra hotlinking de imagens
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?botwhatsapp\.weblinetelecom\.com\.br/.*$ [NC]
RewriteRule \.(jpg|jpeg|png|gif)$ - [F]

# Cache para arquivos estáticos
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
</IfModule>
HTACCESSEOF

chown "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR/.htaccess"
chmod 644 "$WEB_DIR/.htaccess"
echo "✅ .htaccess criado"

# =====================================================
# CRIAR ARQUIVO DE CONFIGURAÇÃO PHP
# =====================================================
echo "⚙️ Criando configuração PHP personalizada..."
cat > "$WEB_DIR/php_config.php" <<'PHPCFGEOF'
<?php
// Configurações do sistema
define('BOT_DIR', '/opt/whatsapp-bot');
define('CONFIG_FILE', BOT_DIR . '/config.json');
define('STATUS_FILE', BOT_DIR . '/status.json');
define('USERS_FILE', BOT_DIR . '/usuarios.json');
define('QRCODE_FILE', BOT_DIR . '/qrcode.txt');

// Função para ler arquivo JSON
function read_json_file($file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        return json_decode($content, true);
    }
    return [];
}

// Função para escrever arquivo JSON
function write_json_file($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($file, $json);
}

// Verificar permissões de escrita
function check_write_permissions() {
    $files = [CONFIG_FILE, STATUS_FILE, USERS_FILE, QRCODE_FILE];
    $results = [];
    
    foreach ($files as $file) {
        $results[basename($file)] = [
            'exists' => file_exists($file),
            'readable' => is_readable($file),
            'writable' => is_writable($file),
            'permissions' => substr(sprintf('%o', fileperms($file)), -4)
        ];
    }
    
    return $results;
}

// Obter status atual do bot
function get_bot_status() {
    return read_json_file(STATUS_FILE);
}

// Obter configurações
function get_bot_config() {
    return read_json_file(CONFIG_FILE);
}

// Obter usuários
function get_bot_users() {
    return read_json_file(USERS_FILE);
}
?>
PHPCFGEOF

chown "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR/php_config.php"
chmod 644 "$WEB_DIR/php_config.php"
echo "✅ php_config.php criado"

# =====================================================
# SYSTEMD – SERVIÇO DO BOT
# =====================================================
echo "⚙️ Criando serviço systemd..."

cat > /etc/systemd/system/botzap.service <<'SERVICEEOF'
[Unit]
Description=Bot WhatsApp – WebLine Telecom
After=network.target
Wants=network-online.target

[Service]
Type=simple
User=botzap
Group=botzap
WorkingDirectory=/opt/whatsapp-bot
ExecStart=/usr/bin/node bot.js
Restart=on-failure
RestartSec=10
StartLimitIntervalSec=60
StartLimitBurst=3
Environment=NODE_ENV=production

StandardOutput=append:/var/log/botzap.log
StandardError=append:/var/log/botzap.log

# Adicionar grupo suplementar para acesso a arquivos
SupplementaryGroups=www-data

# Limites de sistema
LimitNOFILE=65535
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=/opt/whatsapp-bot /var/log/botzap.log

[Install]
WantedBy=multi-user.target
SERVICEEOF

systemctl daemon-reload
systemctl enable botzap
echo "✅ Serviço systemd criado e habilitado"

# =====================================================
# LOGRATE – ROTAÇÃO AUTOMÁTICA DE LOGS
# =====================================================
echo "📊 Configurando logrotate..."

# Criar arquivo de log
mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"
chown "$BOT_USER:adm" "$LOG_FILE"
chmod 640 "$LOG_FILE"

# Criar log de autenticação
touch "/var/log/botzap_auth.log"
chown "$WEB_GROUP:$WEB_GROUP" "/var/log/botzap_auth.log"
chmod 640 "/var/log/botzap_auth.log"

cat > /etc/logrotate.d/botzap <<'LOGEOF'
/var/log/botzap.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 botzap adm
    sharedscripts
    postrotate
        systemctl restart botzap.service 2>/dev/null || true
    endscript
}

/var/log/botzap_auth.log {
    weekly
    missingok
    rotate 8
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
LOGEOF

echo "✅ Logrotate configurado"

# =====================================================
# CONFIGURAÇÃO WEB - APACHE
# =====================================================
echo "🌐 Configurando Apache..."

# Habilitar módulos necessários
a2enmod rewrite
a2enmod headers
a2enmod expires

# Configurar VirtualHost
cat > /etc/apache2/sites-available/botzap.conf <<'VHOSTEOF'
<VirtualHost *:80>
    ServerName botwhatsapp.weblinetelecom.com.br
    ServerAlias www.botwhatsapp.weblinetelecom.com.br
    ServerAdmin webmaster@weblinetelecom.com.br
    DocumentRoot /var/www/botzap
    
    # Logs
    ErrorLog ${APACHE_LOG_DIR}/botzap_error.log
    CustomLog ${APACHE_LOG_DIR}/botzap_access.log combined
    
    <Directory /var/www/botzap>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # Configurações PHP
        <IfModule mod_php.c>
            php_value session.save_path "/var/www/botzap/sessions"
            php_value session.gc_maxlifetime 1440
            php_value upload_max_filesize 10M
            php_value post_max_size 10M
            php_value memory_limit 256M
            php_value max_execution_time 300
            php_value max_input_time 300
        </IfModule>
    </Directory>
    
    # Proteção de headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    # Cache para arquivos estáticos
    <FilesMatch "\.(jpg|jpeg|png|gif|css|js)$">
        Header set Cache-Control "max-age=2592000, public"
    </FilesMatch>
</VirtualHost>
VHOSTEOF

a2ensite botzap.conf
a2dissite 000-default.conf

# Configurar SSL (opcional - comente se não quiser)
echo "🔒 Configurando SSL (opcional)..."
a2enmod ssl

# Criar certificado autoassinado para desenvolvimento
if [ ! -f /etc/ssl/certs/botzap-selfsigned.crt ]; then
    echo "📝 Criando certificado SSL autoassinado para desenvolvimento..."
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout /etc/ssl/private/botzap-selfsigned.key \
        -out /etc/ssl/certs/botzap-selfsigned.crt \
        -subj "/C=BR/ST=Estado/L=Cidade/O=WebLine Telecom/CN=botwhatsapp.weblinetelecom.com.br" 2>/dev/null
fi

# Configurar VirtualHost HTTPS
cat > /etc/apache2/sites-available/botzap-ssl.conf <<'SSLHOSTEOF'
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName botwhatsapp.weblinetelecom.com.br
    ServerAlias www.botwhatsapp.weblinetelecom.com.br
    ServerAdmin webmaster@weblinetelecom.com.br
    DocumentRoot /var/www/botzap
    
    # SSL
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/botzap-selfsigned.crt
    SSLCertificateKeyFile /etc/ssl/private/botzap-selfsigned.key
    
    # Logs
    ErrorLog ${APACHE_LOG_DIR}/botzap_ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/botzap_ssl_access.log combined
    
    <Directory /var/www/botzap>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # Configurações PHP
        <IfModule mod_php.c>
            php_value session.save_path "/var/www/botzap/sessions"
            php_value session.gc_maxlifetime 1440
            php_value upload_max_filesize 10M
            php_value post_max_size 10M
            php_value memory_limit 256M
            php_value max_execution_time 300
            php_value max_input_time 300
        </IfModule>
    </Directory>
    
    # Headers de segurança
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
</VirtualHost>
</IfModule>
SSLHOSTEOF

a2ensite botzap-ssl.conf
systemctl reload apache2
echo "✅ Apache configurado com HTTP e HTTPS"

# =====================================================
# CONFIGURAR FIREWALL
# =====================================================
echo "🛡️ Configurando firewall..."
if command -v ufw &> /dev/null; then
    ufw allow 80/tcp
    ufw allow 443/tcp
    ufw allow 22/tcp
    echo "✅ Firewall configurado (HTTP, HTTPS, SSH)"
else
    # Configurar iptables diretamente
    iptables -A INPUT -p tcp --dport 80 -j ACCEPT
    iptables -A INPUT -p tcp --dport 443 -j ACCEPT
    iptables -A INPUT -p tcp --dport 22 -j ACCEPT
    echo "✅ Regras iptables configuradas"
fi

# =====================================================
# TESTES FINAIS
# =====================================================
echo "🧪 Executando testes finais..."

echo ""
echo "1. Testando serviços:"
echo "   • Apache: $(systemctl is-active apache2)"
echo "   • Bot (após iniciar): $(systemctl is-enabled botzap)"

echo ""
echo "2. Testando arquivos críticos:"
CRITICAL_FILES=(
    "$BOT_DIR/bot.js"
    "$BOT_DIR/config.json"
    "$WEB_DIR/index.php"
    "$WEB_DIR/auth.php"
    "$WEB_DIR/login.php"
)

for file in "${CRITICAL_FILES[@]}"; do
    if [ -f "$file" ]; then
        echo "   ✅ $(basename "$file") existe"
    else
        echo "   ❌ $(basename "$file") NÃO existe"
    fi
done

echo ""
echo "3. Testando permissões:"
echo "   • Bot pode escrever em config.json: $(
    sudo -u "$BOT_USER" touch "$BOT_DIR/config.json" 2>/dev/null && echo "✅" || echo "❌"
)"
echo "   • PHP pode escrever em config.json: $(
    sudo -u "$WEB_GROUP" touch "$BOT_DIR/config.json" 2>/dev/null && echo "✅" || echo "❌"
)"

echo ""
echo "4. Testando PHP:"
if php -v &>/dev/null; then
    echo "   ✅ PHP instalado: $(php -v | head -1)"
else
    echo "   ❌ PHP não está funcionando"
fi

# =====================================================
# INICIAR O BOT
# =====================================================
echo ""
echo "🚀 Iniciando o bot WhatsApp..."
systemctl start botzap
sleep 2

BOT_STATUS=$(systemctl is-active botzap)
if [ "$BOT_STATUS" = "active" ]; then
    echo "✅ Bot iniciado com sucesso!"
    echo "📊 Verifique os logs: sudo journalctl -u botzap -f"
else
    echo "⚠️  Bot não iniciou automaticamente"
    echo "📋 Verifique: sudo systemctl status botzap"
fi

# =====================================================
# RESUMO FINAL
# =====================================================
echo ""
echo "================================================"
echo "🎉 INSTALAÇÃO CONCLUÍDA!"
echo "================================================"
echo ""
echo "📋 RESUMO DA INSTALAÇÃO:"
echo "-----------------------"
echo "• Diretório do bot:       $BOT_DIR"
echo "• Diretório web:          $WEB_DIR"
echo "• Usuário do bot:         $BOT_USER"
echo "• Grupo web:              $WEB_GROUP"
echo "• Arquivos instalados:    $DOWNLOAD_COUNT"
echo "• Bot status:             $(systemctl is-active botzap)"
echo "• Apache status:          $(systemctl is-active apache2)"
echo ""

echo "🔐 INFORMAÇÕES DE ACESSO:"
echo "------------------------"
echo "• Painel web:             http://$(hostname -I 2>/dev/null | awk '{print $1}')/"
echo "• Painel web (HTTPS):     https://$(hostname -I 2>/dev/null | awk '{print $1}')/"
echo "• Login padrão:           admin / admin123"
echo "• Logs do bot:            $LOG_FILE"
echo "• Logs do Apache:         /var/log/apache2/botzap_error.log"
echo ""

echo "⚡ COMANDOS RÁPIDOS:"
echo "------------------"
echo "• Ver status do bot:      sudo systemctl status botzap"
echo "• Reiniciar bot:          sudo systemctl restart botzap"
echo "• Ver logs em tempo real: sudo journalctl -u botzap -f"
echo "• Ver QR Code:            sudo cat $BOT_DIR/qrcode.txt"
echo "• Testar painel web:      curl -I http://localhost/"
echo ""

echo "🔧 ARQUIVOS INSTALADOS:"
echo "----------------------"
ls -1 "$WEB_DIR/" | while read file; do
    echo "  • $file"
done | head -15

if [ $(ls -1 "$WEB_DIR/" | wc -l) -gt 15 ]; then
    echo "  ... e mais $(($(ls -1 "$WEB_DIR/" | wc -l) - 15)) arquivos"
fi

echo ""
echo "📞 SUPORTE:"
echo "----------"
echo "• Problemas de permissão: sudo chown -R $BOT_USER:$WEB_GROUP $BOT_DIR"
echo "• Bot não conecta: Verifique $LOG_FILE"
echo "• Painel não carrega: Verifique /var/log/apache2/botzap_error.log"
echo "• Atualizar arquivos: Execute o script novamente"
echo ""

echo "✅ Instalação finalizada em: $(date)"
echo "⏱️  Próximo passo: Acesse o painel web e escaneie o QR Code!"
echo ""
echo "================================================"
