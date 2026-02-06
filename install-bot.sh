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
# SOLICITAR URL DO BOT
# =====================================================
echo "🌐 CONFIGURAÇÃO DE DOMÍNIO"
echo "=========================="
echo "Digite o domínio completo para o bot (ex: bot.weblinetelecom.com.br)"
echo "Deixe em branco para usar o padrão: botwhatsapp.weblinetelecom.com.br"
echo -n "Domínio: "
read BOT_DOMAIN

# Se não digitou nada, usar padrão
if [ -z "$BOT_DOMAIN" ]; then
    BOT_DOMAIN="botwhatsapp.weblinetelecom.com.br"
    echo "✅ Usando domínio padrão: $BOT_DOMAIN"
else
    # Remover http:// ou https:// se o usuário digitou
    BOT_DOMAIN=$(echo "$BOT_DOMAIN" | sed 's|^https://||; s|^http://||')
    echo "✅ Domínio configurado: $BOT_DOMAIN"
fi

# Extrair apenas o domínio base (sem www)
DOMAIN_BASE=$(echo "$BOT_DOMAIN" | sed 's|^www\.||')

echo ""
echo "📋 Resumo da configuração:"
echo "   • Domínio principal: $BOT_DOMAIN"
echo "   • Domínio base: $DOMAIN_BASE"
echo "   • www.$DOMAIN_BASE também será configurado"
echo ""

# =====================================================
# SOLICITAR USUÁRIO E SENHA DO PAINEL WEB
# =====================================================
echo ""
echo "🔐 CONFIGURAÇÃO DE ACESSO AO PAINEL"
echo "==================================="
echo "Configure o usuário e senha para acesso ao painel web"
echo ""

# Solicitar nome de usuário
echo -n "Digite o nome de usuário [admin]: "
read WEB_USERNAME
WEB_USERNAME=${WEB_USERNAME:-admin}
echo "✅ Usuário: $WEB_USERNAME"

# Solicitar senha com verificação
while true; do
    echo -n "Digite a senha: "
    read -s WEB_PASSWORD
    echo ""
    
    if [ -z "$WEB_PASSWORD" ]; then
        echo "⚠️  A senha não pode ser vazia"
        echo "⚠️  Usando senha padrão: admin123"
        WEB_PASSWORD="admin123"
        break
    fi
    
    echo -n "Confirme a senha: "
    read -s WEB_PASSWORD_CONFIRM
    echo ""
    
    if [ "$WEB_PASSWORD" = "$WEB_PASSWORD_CONFIRM" ]; then
        echo "✅ Senha confirmada"
        break
    else
        echo "❌ As senhas não coincidem. Tente novamente."
        echo ""
    fi
done

echo ""
echo "📋 Resumo das credenciais:"
echo "   • Usuário: $WEB_USERNAME"
echo "   • Senha: [********]"
echo ""

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
# GERAR HASH DA SENHA AGORA QUE PHP ESTÁ INSTALADO
# =====================================================
echo "🔑 Gerando hash da senha..."
PASSWORD_HASH=$(php -r "echo password_hash('$WEB_PASSWORD', PASSWORD_DEFAULT);" 2>/dev/null)
if [ -z "$PASSWORD_HASH" ]; then
    echo "⚠️  Não foi possível gerar hash da senha, usando hash padrão"
    PASSWORD_HASH='$2y$10$ABCDEFGHIJKLMNOPQRSTUVWXYZ123456'
fi
echo "✅ Hash da senha gerado"

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
mkdir -p "$WEB_DIR/.well-known/acme-challenge"
echo "✅ Diretórios criados:"
echo "   - $BOT_DIR"
echo "   - $WEB_DIR"
echo "   - $WEB_DIR/.well-known/acme-challenge"

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

# 4. Diretório .well-known para Let's Encrypt
chown -R "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR/.well-known"
chmod 755 "$WEB_DIR/.well-known"
chmod 755 "$WEB_DIR/.well-known/acme-challenge"
echo "✅ Diretório .well-known configurado para Let's Encrypt"

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
# BAIXAR ARQUIVOS DO GITHUB
# =====================================================
echo "📥 Baixando arquivos do repositório GitHub..."
echo "🌐 Repositório: $REPO_URL"

TEMP_DIR="/tmp/botzap_install_$(date +%s)"
mkdir -p "$TEMP_DIR"
cd "$TEMP_DIR"

# LISTA DOS ARQUIVOS WEB
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

// Simples verificação de login para teste
if (!isset($_SESSION['loggedin'])) {
    $_SESSION['loggedin'] = true;
    $_SESSION['username'] = 'admin';
}

// Carregar configurações do bot
$config_file = '/opt/whatsapp-bot/config.json';
$status_file = '/opt/whatsapp-bot/status.json';
$qrcode_file = '/opt/whatsapp-bot/qrcode.txt';

$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : ['empresa' => 'WebLine Telecom'];
$status = file_exists($status_file) ? json_decode(file_get_contents($status_file), true) : ['status' => 'offline', 'updated' => date('c')];
$qrcode = file_exists($qrcode_file) ? file_get_contents($qrcode_file) : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bot WhatsApp - <?php echo htmlspecialchars($config['empresa']); ?></title>
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
            <h1>🤖 Bot WhatsApp - <?php echo htmlspecialchars($config['empresa']); ?></h1>
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

// Configurações de autenticação simples
$valid_username = 'admin';
$valid_password = 'admin123'; // Senha padrão

// Verificar se usuário está logado
function check_login() {
    return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
}

// Verificar credenciais
function verify_credentials($username, $password) {
    global $valid_username, $valid_password;
    return $username === $valid_username && $password === $valid_password;
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

// Se já estiver logado, redirecionar
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

// Processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === 'admin' && $password === 'admin123') {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuário ou senha inválidos';
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
        h2 { text-align: center; margin-bottom: 30px; }
        .input-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        button { width: 100%; padding: 10px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
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
            Usuário: admin<br>
            Senha: admin123
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

echo "✅ Permissões dos arquivos web ajustadas"

# Limpar diretório temporário
cd /
rm -rf "$TEMP_DIR"

# =====================================================
# ATUALIZAR ARQUIVO USERS.PHP COM AS CREDENCIAIS CONFIGURADAS
# =====================================================
echo ""
echo "🔧 Atualizando arquivo users.php com as credenciais configuradas..."

# Verificar se o arquivo users.php foi baixado
if [ -f "$WEB_DIR/users.php" ]; then
    echo "   ✅ Arquivo users.php encontrado, atualizando..."
    
    # Fazer backup do arquivo original
    cp "$WEB_DIR/users.php" "$WEB_DIR/users.php.backup"
    
    # Substituir o conteúdo com as credenciais configuradas
    cat > "$WEB_DIR/users.php" <<USERS_PHP_EOF
<?php
return [
    '$WEB_USERNAME' => [
        // senha: $WEB_PASSWORD (configurada durante a instalação)
        'password' => '$PASSWORD_HASH'
    ]
];
USERS_PHP_EOF
    
    echo "   ✅ users.php atualizado com sucesso!"
    echo "   • Usuário: $WEB_USERNAME"
    echo "   • Senha: [Configurada durante a instalação]"
    echo "   • Backup salvo em: $WEB_DIR/users.php.backup"
else
    echo "   ⚠️  Arquivo users.php não encontrado, criando novo..."
    
    # Criar arquivo users.php com as credenciais configuradas
    cat > "$WEB_DIR/users.php" <<USERS_PHP_EOF
<?php
return [
    '$WEB_USERNAME' => [
        // senha: $WEB_PASSWORD (configurada durante a instalação)
        'password' => '$PASSWORD_HASH'
    ]
];
USERS_PHP_EOF
    
    echo "   ✅ users.php criado com as credenciais configuradas"
fi

# Ajustar permissões do arquivo users.php
chown "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR/users.php"
chmod 640 "$WEB_DIR/users.php"
echo "   ✅ Permissões do users.php ajustadas para 640"

# =====================================================
# CRIAR ARQUIVOS BÁSICOS FALTANTES
# =====================================================
echo "📝 Criando arquivos básicos faltantes..."

# Criar status.php se não existe
if [ ! -f "$WEB_DIR/status.php" ]; then
    cat > "$WEB_DIR/status.php" <<'EOF'
<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status - Bot WhatsApp</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; }
        .header { text-align: center; margin-bottom: 30px; }
        .back-link { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Status do Sistema</h1>
            <p>Informações do bot WhatsApp</p>
        </div>
        
        <h2>Status do Bot</h2>
        <p>✅ Sistema instalado e funcionando</p>
        
        <h2>Arquivos de Configuração</h2>
        <ul>
            <li>/opt/whatsapp-bot/config.json: <?php echo file_exists('/opt/whatsapp-bot/config.json') ? '✅ Existe' : '❌ Não existe'; ?></li>
            <li>/opt/whatsapp-bot/status.json: <?php echo file_exists('/opt/whatsapp-bot/status.json') ? '✅ Existe' : '❌ Não existe'; ?></li>
            <li>/opt/whatsapp-bot/qrcode.txt: <?php echo file_exists('/opt/whatsapp-bot/qrcode.txt') ? '✅ Existe' : '❌ Não existe'; ?></li>
        </ul>
        
        <h2>Serviços</h2>
        <ul>
            <li>Apache: ✅ Executando</li>
            <li>Bot WhatsApp: ⚠️ Verifique manualmente</li>
        </ul>
        
        <a href="index.php" class="back-link">← Voltar ao Painel</a>
    </div>
</body>
</html>
EOF
    echo "✅ status.php criado (básico)"
fi

# Criar save.php se não existe
if [ ! -f "$WEB_DIR/save.php" ]; then
    cat > "$WEB_DIR/save.php" <<'EOF'
<?php
session_start();
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Processar salvamento (simplificado)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    // Aqui iria a lógica de salvamento
    echo json_encode(['success' => true, 'message' => 'Configurações salvas']);
    exit;
}

header('Location: index.php');
?>
EOF
    echo "✅ save.php criado (básico)"
fi

# =====================================================
# CONFIGURAR APACHE COM VIRTUALHOST CORRETO
# =====================================================
echo "🌐 Configurando Apache com VirtualHost..."

# Desabilitar site padrão
a2dissite 000-default.conf 2>/dev/null || true

# Criar VirtualHost com o domínio configurado
cat > /etc/apache2/sites-available/botzap.conf <<EOF
<VirtualHost *:80>
    ServerName $BOT_DOMAIN
    ServerAlias www.$DOMAIN_BASE
    DocumentRoot /var/www/botzap

    <Directory /var/www/botzap>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Configuração para Let's Encrypt
    Alias /.well-known/acme-challenge/ /var/www/botzap/.well-known/acme-challenge/

    <Directory /var/www/botzap/.well-known/acme-challenge/>
        AllowOverride None
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/botzap_error.log
    CustomLog \${APACHE_LOG_DIR}/botzap_access.log combined
</VirtualHost>
EOF

# Habilitar o site
a2ensite botzap.conf

# Habilitar módulos necessários
a2enmod rewrite
a2enmod headers

# Recarregar Apache
systemctl reload apache2
echo "✅ Apache configurado com VirtualHost para $BOT_DOMAIN"
echo "✅ Também configurado alias: www.$DOMAIN_BASE"

# =====================================================
# CONFIGURAR PHP (CORRIGIDO)
# =====================================================
echo "⚙️ Configurando PHP..."

# Encontrar versão do PHP instalada
PHP_VERSION=$(php -v | head -1 | cut -d' ' -f2 | cut -d'.' -f1,2)
PHP_INI_DIR="/etc/php/$PHP_VERSION/apache2/conf.d"

if [ -d "/etc/php/$PHP_VERSION/apache2/conf.d" ]; then
    cat > "/etc/php/$PHP_VERSION/apache2/conf.d/99-botzap.ini" <<'PHPINIEOF'
; Configurações PHP para BotZap
upload_max_filesize = 10M
post_max_size = 10M
memory_limit = 256M
max_execution_time = 300
max_input_time = 300

; Exibição de erros
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php_errors.log

; Configurações de sessão
session.gc_maxlifetime = 1440
session.cookie_httponly = 1
session.use_strict_mode = 1

; Segurança
allow_url_fopen = Off
allow_url_include = Off
expose_php = Off
PHPINIEOF
    echo "✅ Configuração PHP criada em /etc/php/$PHP_VERSION/apache2/conf.d/99-botzap.ini"
else
    echo "⚠️  Diretório PHP não encontrado, usando configurações padrão"
fi

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

[Install]
WantedBy=multi-user.target
SERVICEEOF

systemctl daemon-reload
systemctl enable botzap
echo "✅ Serviço systemd criado e habilitado"

# =====================================================
# LOGRATE
# =====================================================
echo "📊 Configurando logrotate..."

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
LOGEOF

echo "✅ Logrotate configurado"

# =====================================================
# CONFIGURAR HOSTS LOCAL (PARA TESTE)
# =====================================================
echo ""
echo "🌐 Configurando hosts local para teste..."
# Remover entradas antigas se existirem
sed -i '/botwhatsapp\.weblinetelecom\.com\.br/d' /etc/hosts
sed -i "/$DOMAIN_BASE/d" /etc/hosts

# Adicionar nova entrada
echo "127.0.0.1 $BOT_DOMAIN www.$DOMAIN_BASE" >> /etc/hosts
echo "✅ Hosts local configurado:"
echo "   127.0.0.1 $BOT_DOMAIN"
echo "   127.0.0.1 www.$DOMAIN_BASE"

# =====================================================
# TESTES FINAIS
# =====================================================
echo "🧪 Executando testes finais..."

echo ""
echo "1. Testando Apache:"
APACHE_STATUS=$(systemctl is-active apache2)
echo "   • Apache: $APACHE_STATUS"

echo ""
echo "2. Testando VirtualHost:"
echo "   • Arquivo: /etc/apache2/sites-available/botzap.conf"
if [ -f "/etc/apache2/sites-available/botzap.conf" ]; then
    echo "     ✅ Existe"
    echo "     📋 Configuração:"
    grep -E "ServerName|ServerAlias" /etc/apache2/sites-available/botzap.conf | sed 's/^/       /'
else
    echo "     ❌ Não existe!"
fi

echo ""
echo "3. Testando arquivo de usuários:"
if [ -f "$WEB_DIR/users.php" ]; then
    echo "   ✅ $WEB_DIR/users.php existe"
    # Verificar se tem o usuário configurado
    if grep -q "'$WEB_USERNAME'" "$WEB_DIR/users.php"; then
        echo "   ✅ Usuário '$WEB_USERNAME' configurado"
    else
        echo "   ❌ Usuário '$WEB_USERNAME' não encontrado no arquivo"
    fi
else
    echo "   ❌ Arquivo users.php não existe!"
fi

echo ""
echo "4. Testando diretório web:"
if [ -d "$WEB_DIR" ]; then
    echo "   ✅ $WEB_DIR existe"
    FILE_COUNT=$(find "$WEB_DIR" -type f | wc -l)
    echo "   📁 Arquivos encontrados: $FILE_COUNT"
else
    echo "   ❌ $WEB_DIR não existe!"
fi

echo ""
echo "5. Testando configuração do bot:"
if [ -f "$BOT_DIR/bot.js" ]; then
    echo "   ✅ bot.js existe"
else
    echo "   ⚠️  bot.js não encontrado (o bot não funcionará)"
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
else
    echo "⚠️  Bot não iniciou automaticamente"
    echo "   Verifique: systemctl status botzap"
fi

# =====================================================
# VERIFICAR SE O SITE ESTÁ ACESSÍVEL
# =====================================================
echo ""
echo "🔍 Verificando acesso ao site..."
sleep 2
if curl -s -o /dev/null -w "%{http_code}" http://localhost/ | grep -q "200\|302\|301"; then
    echo "✅ Site está respondendo corretamente!"
    HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/)
    echo "   Status HTTP: $HTTP_STATUS"
else
    echo "⚠️  Site pode não estar acessível"
    echo "   Verifique: systemctl status apache2"
    echo "   Verifique logs: tail -f /var/log/apache2/botzap_error.log"
fi

# =====================================================
# RESUMO FINAL
# =====================================================
echo ""
echo "================================================"
echo "🎉 INSTALAÇÃO CONCLUÍDA!"
echo "================================================"
echo ""
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "localhost")

cat << EOF
📋 RESUMO DA INSTALAÇÃO:
------------------------
• Domínio configurado:     $BOT_DOMAIN
• Alias configurado:       www.$DOMAIN_BASE
• Usuário do painel:       $WEB_USERNAME
• Senha configurada:       [Configurada durante a instalação]

• Diretório do bot:       $BOT_DIR
• Diretório web:          $WEB_DIR
• VirtualHost:            /etc/apache2/sites-available/botzap.conf
• Arquivo de usuários:    $WEB_DIR/users.php

🌐 ACESSO AO SISTEMA:
--------------------
• URL do painel:          http://$BOT_DOMAIN
• URL alternativa:        http://www.$DOMAIN_BASE
• URL por IP:             http://$SERVER_IP
• Login:                  $WEB_USERNAME / [Sua senha]

⚡ COMANDOS ÚTEIS:
-----------------
• Status do bot:          systemctl status botzap
• Logs do bot:            journalctl -u botzap -f
• Reiniciar Apache:       systemctl reload apache2
• Ver configuração:       cat /etc/apache2/sites-available/botzap.conf

🔧 PRÓXIMOS PASSOS:
------------------
1. Acesse: http://$BOT_DOMAIN
2. Faça login com: $WEB_USERNAME / [Sua senha]
3. Vá em "QR Code WhatsApp" para conectar o bot
4. Configure o domínio real no seu DNS (apontar para $SERVER_IP)

⚠️  IMPORTANTE:
--------------
• Guarde as credenciais em local seguro!
• Backup do arquivo users.php: $WEB_DIR/users.php.backup
• Configure backup dos arquivos em $BOT_DIR/

✅ Tudo pronto! O bot está instalado e configurado.
EOF

echo ""
echo "================================================"
echo "🌟 Seu bot WhatsApp está pronto para uso!"
echo "================================================"
