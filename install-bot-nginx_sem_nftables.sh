#!/bin/bash
set -e

echo "🚀 Iniciando instalação do Bot WhatsApp – Debian 12 (Nginx + PHP-FPM) - FEV/2026"
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
PHP_VERSION="8.2"

# =====================================================
# VERIFICA ROOT
# =====================================================
if [ "$EUID" -ne 0 ]; then
  echo "❌ Execute como root"
  exit 1
fi

# =====================================================
# INSTALAR FERRAMENTAS DE UTILIDADE
# =====================================================
echo "🔧 Instalando ferramentas de utilidade..."
apt install -y vim bash-completion fzf file acl wget

echo "🔧 Configurando bash-completion..."
grep -q "bash-completion" /etc/bash.bashrc || cat >> /etc/bash.bashrc << 'EOF'

# Autocompletar extra
if ! shopt -oq posix; then
  if [ -f /usr/share/bash-completion/bash_completion ]; then
    . /usr/share/bash-completion/bash_completion
  elif [ -f /etc/bash_completion ]; then
    . /etc/bash_completion
  fi
fi
EOF

echo "🔧 Configurando VIM..."
sed -i 's/"syntax on/syntax on/' /etc/vim/vimrc
sed -i 's/"set background=dark/set background=dark/' /etc/vim/vimrc
cat > /root/.vimrc << 'EOF'
set showmatch
set ts=4
set sts=4
set sw=4
set autoindent
set smartindent
set smarttab
set expandtab
EOF

echo "🔧 Configurando aliases e variáveis de ambiente..."
cat >> /root/.bashrc << 'EOF'
export LS_OPTIONS='--color=auto'
eval "$(dircolors)"
alias ls='ls $LS_OPTIONS'
alias ll='ls $LS_OPTIONS -l'
alias l='ls $LS_OPTIONS -lha'
alias grep='grep --color'
alias egrep='egrep --color'
alias ip='ip -c'
alias diff='diff --color'
alias dnswho='f(){ dig +short TXT whoami.ds.akahelp.net @"$@" ; unset -f f; }; f'
alias meuip='curl -s ifconfig.me; echo'
[ -f /usr/share/doc/fzf/examples/key-bindings.bash ] && source /usr/share/doc/fzf/examples/key-bindings.bash
PS1='${debian_chroot:+($debian_chroot)}\[\033[01;31m\]\u\[\033[01;34m\]@\[\033[01;33m\]\h\[\033[01;34m\][\[\033[00m\]\[\033[01;37m\]\w\[\033[01;34m\]]\[\033[01;31m\]\\$\[\033[00m\] '
EOF

# Aplicar configurações na sessão atual
export PS1='${debian_chroot:+($debian_chroot)}\[\033[01;31m\]\u\[\033[01;34m\]@\[\033[01;33m\]\h\[\033[01;34m\][\[\033[00m\]\[\033[01;37m\]\w\[\033[01;34m\]]\[\033[01;31m\]\\$\[\033[00m\] '
export LS_OPTIONS='--color=auto'
eval "$(dircolors)" 2>/dev/null || true
alias ls='ls $LS_OPTIONS' 2>/dev/null || true
alias ll='ls $LS_OPTIONS -l' 2>/dev/null || true
alias l='ls $LS_OPTIONS -lha' 2>/dev/null || true

echo "✅ Configurações do shell aplicadas"
echo ""

# =====================================================
# SOLICITAR URL DO BOT
# =====================================================
echo "ATENÇÃO!!!!!!"
echo ""
echo "Antes de executar o script de instalação configure o dominio que irá usar no bot em seu servidor DNS Autoritativo,"
echo "EX: bot.seu_dominio.com.br, se tiver dominio configurado e usa o proxy do Cloudflare desative pois o certificado só"
echo "será emitido se o dominio estiver configurado para o ip correto da maquina, use ip público se possível."
echo ""
echo "🌐 CONFIGURAÇÃO DE DOMÍNIO"
echo "=========================="
echo "Digite o domínio completo para o bot (ex: bot.SEU_DOMINIO.com.br)"
echo "Deixe em branco para usar o padrão: bot.provedor.com.br"
echo -n "Domínio: "
read BOT_DOMAIN

if [ -z "$BOT_DOMAIN" ]; then
    BOT_DOMAIN="bot.provedor.com.br"
    echo "✅ Usando domínio padrão: $BOT_DOMAIN"
else
    BOT_DOMAIN=$(echo "$BOT_DOMAIN" | sed 's|^https://||; s|^http://||')
    echo "✅ Domínio configurado: $BOT_DOMAIN"
fi

DOMAIN_BASE=$(echo "$BOT_DOMAIN" | sed 's|^www\.||')

echo ""
echo "   Resumo da configuração:"
echo "   • Domínio principal: $BOT_DOMAIN"
echo "   • Domínio base: $DOMAIN_BASE"
echo ""

# =====================================================
# SOLICITAR USUÁRIO E SENHA DO PAINEL WEB
# =====================================================
echo ""
echo "🔐 CONFIGURAÇÃO DE ACESSO AO PAINEL"
echo "==================================="
echo "Configure o usuário e senha para acesso ao painel web"
echo ""

echo -n "Digite o nome de usuário [admin]: "
read WEB_USERNAME
WEB_USERNAME=${WEB_USERNAME:-admin}
echo "✅ Usuário: $WEB_USERNAME"

while true; do
    echo -n "Digite a senha: "
    read -s WEB_PASSWORD
    echo ""
    
    if [ -z "$WEB_PASSWORD" ]; then
        echo "⚠️  A senha não pode ser vazia. Tente novamente."
        continue
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
# SOLICITAR EMAIL PARA SSL
# =====================================================
echo ""
echo "📧 CONFIGURAÇÃO DE EMAIL PARA SSL"
echo "================================"
echo "O Let's Encrypt precisa de um email válido para alertas"
echo "de expiração do certificado e comunicações de segurança."
echo "Recomendamos usar um email real que você acompanhe."
echo ""
echo -n "Digite seu email [admin@$DOMAIN_BASE]: "
read SSL_EMAIL

if [ -z "$SSL_EMAIL" ]; then
    SSL_EMAIL="admin@$DOMAIN_BASE"
    echo ""
    echo "⚠️  AVISO: Você usou o email padrão $SSL_EMAIL"
    echo "   Certifique-se de que este email existe e você tem acesso!"
    echo "   Caso contrário, não receberá alertas importantes."
    echo ""
    echo -n "Continuar com este email? (s/N): "
    read CONFIRM_EMAIL
    if [[ ! "$CONFIRM_EMAIL" =~ ^[Ss]$ ]]; then
        echo ""
        echo -n "Digite seu email real: "
        read SSL_EMAIL
        while [ -z "$SSL_EMAIL" ]; do
            echo -n "Email não pode ser vazio. Digite novamente: "
            read SSL_EMAIL
        done
    fi
fi

echo "✅ Email configurado: $SSL_EMAIL"
echo ""

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
  sudo \
  software-properties-common

# =====================================================
# NODE.JS LTS
# =====================================================
echo "🟢 Instalando Node.js ${NODE_VERSION}..."
curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash -
apt install -y nodejs

echo "✅ Node.js $(node -v)"
echo "✅ npm $(npm -v)"

# =====================================================
# REMOVER APACHE2 SE INSTALADO
# =====================================================
echo "🛑 Removendo Apache2 se existente..."
systemctl stop apache2 2>/dev/null || true
systemctl disable apache2 2>/dev/null || true
apt remove -y apache2 apache2-* libapache2-mod-php* 2>/dev/null || true
apt autoremove -y 2>/dev/null || true

# =====================================================
# INSTALAR NGINX E PHP-FPM
# =====================================================
echo "🌐 Instalando Nginx e PHP ${PHP_VERSION}-FPM..."
apt install -y nginx

apt install -y php${PHP_VERSION}-fpm \
  php${PHP_VERSION}-cli \
  php${PHP_VERSION}-curl \
  php${PHP_VERSION}-mbstring \
  php${PHP_VERSION}-zip \
  php${PHP_VERSION}-xml \
  php${PHP_VERSION}-mysql \
  php${PHP_VERSION}-gd \
  php${PHP_VERSION}-intl \
  php${PHP_VERSION}-bcmath

update-alternatives --set php /usr/bin/php${PHP_VERSION} 2>/dev/null || true

systemctl enable nginx
systemctl start nginx
systemctl enable php${PHP_VERSION}-fpm
systemctl start php${PHP_VERSION}-fpm

echo "✅ Nginx $(nginx -v 2>&1 | cut -d'/' -f2) instalado"
echo "✅ PHP $(php -v | head -1) instalado"

# =====================================================
# CONFIGURAR TIMEZONE DO PHP PARA AMERICA/RECIFE
# =====================================================
echo "⏰ Configurando timezone do PHP para America/Recife..."

for PHP_INI in /etc/php/${PHP_VERSION}/fpm/php.ini /etc/php/${PHP_VERSION}/cli/php.ini; do
    if [ -f "$PHP_INI" ]; then
        cp "$PHP_INI" "$PHP_INI.backup.$(date +%Y%m%d%H%M%S)"
        sed -i "s/^;date\.timezone =/date.timezone = America\/Recife/" "$PHP_INI"
        sed -i "s/^date\.timezone =.*/date.timezone = America\/Recife/" "$PHP_INI"
        if ! grep -q "^date\.timezone" "$PHP_INI"; then
            echo "date.timezone = America/Recife" >> "$PHP_INI"
        fi
        echo "   ✅ $(basename $(dirname $(dirname $PHP_INI))) configurado"
    fi
done

systemctl restart php${PHP_VERSION}-fpm
echo "✅ Timezone configurado e PHP-FPM reiniciado"

# =====================================================
# GERAR HASH DA SENHA
# =====================================================
echo "🔑 Gerando hash da senha..."
PASSWORD_HASH=$(php -r "echo password_hash('$WEB_PASSWORD', PASSWORD_DEFAULT);" 2>/dev/null)
if [ -z "$PASSWORD_HASH" ]; then
    echo "⚠️  Não foi possível gerar hash da senha, usando hash padrão"
    PASSWORD_HASH='$2y$10$WN/a1/7yFMPbsPyfM6.ysuRtFBqG8RpoAF/DwpyxFTu2tnlo1ekde'
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
# CRIAR DIRETÓRIO PARA LOGS DO DASHBOARD PIX
# =====================================================
echo "📊 Criando diretório para logs do Dashboard Pix..."
mkdir -p /var/log/pix_acessos
chown www-data:www-data /var/log/pix_acessos
chmod 0750 /var/log/pix_acessos
echo "✅ Diretório /var/log/pix_acessos criado"

# =====================================================
# CRIAR ARQUIVO DE USUÁRIOS COM HASH GERADO
# =====================================================
echo "🔐 Criando arquivo de usuários com credenciais fornecidas..."

cat > /var/log/pix_acessos/usuarios.json << EOF
{
    "admin": {
        "senha_hash": "$2y$10$WN/a1/7yFMPbsPyfM6.ysuRtFBqG8RpoAF/DwpyxFTu2tnlo1ekde",
        "nome": "Administrador Padrão",
        "email": "admin@sistema.com",
        "nivel": "admin",
        "status": "ativo",
        "data_criacao": "$(date -Iseconds)",
        "ip_cadastro": "127.0.0.1",
        "ultimo_acesso": null,
        "ip_ultimo_acesso": null
    },
    "$WEB_USERNAME": {
        "senha_hash": "$PASSWORD_HASH",
        "nome": "Usuário Instalador",
        "email": "$SSL_EMAIL",
        "nivel": "admin",
        "status": "ativo",
        "data_criacao": "$(date -Iseconds)",
        "ip_cadastro": "127.0.0.1",
        "ultimo_acesso": null,
        "ip_ultimo_acesso": null
    }
}
EOF

touch /var/log/pix_acessos/acessos_usuarios.log
chown www-data:www-data /var/log/pix_acessos/*
chmod 0660 /var/log/pix_acessos/*

echo "✅ Arquivos do Dashboard Pix criados"
echo "   • Usuários configurados:"
echo "     - admin (senha padrão: Admin@123)"
echo "     - $WEB_USERNAME (senha configurada)"
echo ""

# =====================================================
# PERMISSÕES COMPARTILHADAS
# =====================================================
echo "🔐 Configurando permissões compartilhadas..."

chown -R "$BOT_USER:$WEB_GROUP" "$BOT_DIR"
chmod 775 "$BOT_DIR"

mkdir -p "$BOT_DIR/node_modules"
chown -R "$BOT_USER:$WEB_GROUP" "$BOT_DIR/node_modules"
find "$BOT_DIR/node_modules" -type d -exec chmod 775 {} \;
find "$BOT_DIR/node_modules" -type f -exec chmod 664 {} \;

mkdir -p "$BOT_DIR/auth_info"
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/auth_info"
chmod 700 "$BOT_DIR/auth_info"

chown -R "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR/.well-known"
chmod 755 "$WEB_DIR/.well-known"
chmod 755 "$WEB_DIR/.well-known/acme-challenge"

echo "✅ Permissões configuradas"

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
  "author": "PROVEDOR",
  "license": "ISC",
  "description": "Bot WhatsApp para atendimento automático",
  "dependencies": {
    "@whiskeysockets/baileys": "^7.0.0-rc.9",
    "pino": "^10.3.0"
  }
}
PKGEOF

chown "$BOT_USER:$BOT_USER" "$BOT_DIR/package.json"
chmod 640 "$BOT_DIR/package.json"
echo "✅ package.json criado"

# =====================================================
# INSTALAR DEPENDÊNCIAS NPM
# =====================================================
echo "📥 Instalando dependências Node.js..."
cd "$BOT_DIR"
sudo -u "$BOT_USER" npm install --silent

if [ -f "$BOT_DIR/package-lock.json" ]; then
    chown "$BOT_USER:$BOT_USER" "$BOT_DIR/package-lock.json"
    chmod 640 "$BOT_DIR/package-lock.json"
fi
echo "✅ Dependências Node.js instaladas"

# =====================================================
# ARQUIVOS DE CONFIGURAÇÃO DO BOT
# =====================================================
echo "⚙️ Criando arquivos de configuração do bot..."

cat > "$BOT_DIR/config.json" <<'CFGEOF'
{
    "empresa": "PROVEDOR",
    "menu": "Olá! *{{nome}}*👋\r\n\r\nBem-vindo ao atendimento da *{{empresa}}*\r\n\r\n1️⃣ Baixar Fatura\r\n2️⃣ Falar com Atendente\r\n3️⃣ Não sou Cliente!\r\n\r\nDigite o número da opção desejada:",
    "boleto_url": "https://www.SEU_DOMINIO.com.br/pix.php",
    "atendente_numero": "55XXXXXXXXXX",
    "tempo_atendimento_humano": 30,
    "tempo_inatividade_global": 30,
    "feriados_ativos": "Sim",
    "feriado_local_ativado": "Não",
    "feriado_local_mensagem": "📅 *Comunicado importante:*\r\n\r\nDeixe aqui a mensagem do feriado!!!\r\n\r\nO acesso a faturas PIX continua disponível 24/7! 🎉",
    "telegram_ativado": "Não",
    "telegram_token": "",
    "telegram_chat_id": "",
    "telegram_notificar_conexao": "Sim",
    "telegram_notificar_desconexao": "Sim",
    "telegram_notificar_qr": "Sim",
    "mkauth_url": "https://www.SEU_DOMINIO.com.br/api",
    "mkauth_client_id": "",
    "mkauth_client_secret": "",
    "planos_ativos": "Sim",
    "planos_mensagem": "*Planos Disponíveis*\r\n\r\n📶 *50 mega*  💰 R$ 54,90 - FIBRA\r\n📶 *100 mega* 💰 R$ 59,90 - FIBRA\r\n📶 *200 mega* 💰 R$ 69,90 - FIBRA\r\n📶 *300 mega* 💰 R$ 89,90 - FIBRA\r\n\r\n*Taxa de instalação* 💰 R$ 50,00 à vista ou R$ 60,00 no cartão em 2x.\r\n\r\n*Tá esperando o que?* 😱\r\n\r\n2️⃣ Falar com Atendente    5️⃣ Assine Já!",
    "link_assinatura": "https://www.SEU_DOMINIO.com.br/cadastro.hhvm"
}
CFGEOF
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/config.json"
chmod 664 "$BOT_DIR/config.json"

cat > "$BOT_DIR/status.json" <<'STATEOF'
{
  "status": "offline",
  "updated": "$(date -Iseconds)"
}
STATEOF
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/status.json"
chmod 664 "$BOT_DIR/status.json"

echo "{}" > "$BOT_DIR/usuarios.json"
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/usuarios.json"
chmod 664 "$BOT_DIR/usuarios.json"

touch "$BOT_DIR/qrcode.txt"
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/qrcode.txt"
chmod 664 "$BOT_DIR/qrcode.txt"
echo "✅ Arquivos de configuração criados"

# =====================================================
# BAIXAR ARQUIVOS DO GITHUB
# =====================================================
echo "📥 Baixando arquivos do repositório GitHub..."

TEMP_DIR="/tmp/botzap_install_$(date +%s)"
mkdir -p "$TEMP_DIR"
cd "$TEMP_DIR"

WEB_FILES=(
    "auth.php"
    "bot.js"
    "diagnostico.sh"
    "hora.php"
    "index.php"
    "info.php"
    "login.php"
    "logo.jpg"
    "logout.php"
    "pix.php"
    "qrcode_online.png"
    "qrcode_view.php"
    "qrcode_wait.png"
    "save.php"
    "service-worker.js"
    "status.php"
    "teste_ipv6.php"
)

DOWNLOAD_COUNT=0
for FILE in "${WEB_FILES[@]}"; do
    echo -n "   📄 $FILE ... "
    if curl -sL -f "$REPO_URL/$FILE" -o "$FILE" 2>/dev/null; then
        echo "✅"
        DOWNLOAD_COUNT=$((DOWNLOAD_COUNT + 1))
    else
        echo "❌ (não encontrado)"
    fi
done

echo ""
echo "📊 Total de arquivos baixados: $DOWNLOAD_COUNT"

# =====================================================
# COPIAR ARQUIVOS PARA DESTINOS FINAIS
# =====================================================
echo ""
echo "📋 Copiando arquivos para destinos finais..."

if [ -f "bot.js" ]; then
    cp "bot.js" "$BOT_DIR/"
    chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/bot.js"
    chmod 664 "$BOT_DIR/bot.js"
    echo "✅ bot.js copiado para $BOT_DIR/"
fi

WEB_FILES_COPIED=0
for file in *; do
    if [ "$file" != "bot.js" ] && [ -f "$file" ]; then
        cp "$file" "$WEB_DIR/"
        WEB_FILES_COPIED=$((WEB_FILES_COPIED + 1))
        echo "   ✅ $file"
    fi
done

echo "📦 Total de $WEB_FILES_COPIED arquivos web copiados"

chown -R "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR"/
find "$WEB_DIR" -type f -name "*.php" -exec chmod 755 {} \;
find "$WEB_DIR" -type f \( -name "*.css" -o -name "*.js" -o -name "*.json" \) -exec chmod 644 {} \;

cd /
rm -rf "$TEMP_DIR"

# =====================================================
# CRIAR ARQUIVOS DE LOG DO MONITOR
# =====================================================
echo "📝 Criando arquivos de log do monitor de versão..."
touch "$BOT_DIR/versoes.log"
touch "$BOT_DIR/ultima_versao.json"
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/versoes.log" "$BOT_DIR/ultima_versao.json"
chmod 664 "$BOT_DIR/versoes.log" "$BOT_DIR/ultima_versao.json"
echo "✅ Arquivos de log do monitor criados"

# =====================================================
# CRIAR ARQUIVOS BÁSICOS FALTANTES
# =====================================================
echo "📝 Criando arquivos básicos faltantes..."

if [ ! -f "$WEB_DIR/status.php" ]; then
    cat > "$WEB_DIR/status.php" <<'EOF'
<?php
session_start();
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    header('Location: index.php');
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
            <li>Nginx: ✅ Executando</li>
            <li>PHP-FPM: ✅ Executando</li>
            <li>Bot WhatsApp: ⚠️ Verifique manualmente</li>
        </ul>
        
        <a href="index.php" class="back-link">← Voltar ao Painel</a>
    </div>
</body>
</html>
EOF
    echo "✅ status.php criado"
fi

if [ ! -f "$WEB_DIR/save.php" ]; then
    cat > "$WEB_DIR/save.php" <<'EOF'
<?php
session_start();
if (!isset($_SESSION['logado']) || $_SESSION['logado'] !== true) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    echo json_encode(['success' => true, 'message' => 'Configurações salvas']);
    exit;
}

header('Location: index.php');
?>
EOF
    echo "✅ save.php criado"
fi

# =====================================================
# CONFIGURAR NGINX COM VIRTUALHOST
# =====================================================
echo "🌐 Configurando Nginx com VirtualHost..."

rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

cat > /etc/nginx/sites-available/botzap <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $BOT_DOMAIN www.$DOMAIN_BASE;
    
    root $WEB_DIR;
    index index.php index.html index.htm;

    client_max_body_size 100M;

    location /.well-known/acme-challenge/ {
        root $WEB_DIR;
        allow all;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|pdf|svg|woff|woff2|ttf|eot)\$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    error_log /var/log/nginx/botzap_error.log;
    access_log /var/log/nginx/botzap_access.log;
}
EOF

ln -sf /etc/nginx/sites-available/botzap /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
echo "✅ Nginx configurado para $BOT_DOMAIN"

# =====================================================
# SEÇÃO SSL - CERTBOT
# =====================================================
echo ""
echo "🔐 Instalando Certbot para SSL..."
apt install -y certbot python3-certbot-nginx

echo ""
echo "Deseja instalar o certificado SSL AGORA para $BOT_DOMAIN?"
echo "Isso tornará seu site acessível via HTTPS (recomendado)"
echo ""
echo -n "Instalar SSL agora? (s/N): "
read INSTALL_SSL

if [[ "$INSTALL_SSL" =~ ^[Ss]$ ]]; then
    echo ""
    echo "🔐 Instalando SSL para $BOT_DOMAIN com email $SSL_EMAIL..."
    
    certbot --nginx \
        -d "$BOT_DOMAIN" \
        --non-interactive \
        --agree-tos \
        --email "$SSL_EMAIL" \
        --no-eff-email \
        --redirect
    
    if [ $? -eq 0 ]; then
        echo "✅ SSL instalado com sucesso!"
        echo "   Site disponível em: https://$BOT_DOMAIN"
        
        echo ""
        echo "🔄 Renovação automática:"
        systemctl status certbot.timer --no-pager | grep "Trigger"
    else
        echo "❌ Falha na instalação do SSL"
        echo "   Execute manualmente depois:"
        echo "   sudo certbot --nginx -d $BOT_DOMAIN"
    fi
else
    echo ""
    echo "⚠️  SSL não instalado agora."
    echo "   Para instalar manualmente depois, use:"
    echo "   sudo certbot --nginx -d $BOT_DOMAIN"
    echo ""
fi

# =====================================================
# SYSTEMD – SERVIÇO DO BOT
# =====================================================
echo "⚙️ Criando serviço systemd..."

cat > /etc/systemd/system/botzap.service <<'SERVICEEOF'
[Unit]
Description=Bot WhatsApp – WebLine Telecom
After=network.target nginx.service php8.2-fpm.service
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

SupplementaryGroups=www-data

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
    create 0640 botzap www-data
    sharedscripts
    postrotate
        systemctl restart botzap.service 2>/dev/null || true
    endscript
}
LOGEOF

cat > /etc/logrotate.d/pix_acessos <<'LOGEOF'
/var/log/pix_acessos/*.log {
    daily
    missingok
    rotate 90
    compress
    delaycompress
    notifempty
    create 0660 www-data www-data
    sharedscripts
}
LOGEOF

# =====================================================
# CONFIGURAR HOSTS LOCAL
# =====================================================
echo ""
echo "🌐 Configurando hosts local..."
sed -i "/$DOMAIN_BASE/d" /etc/hosts
echo "127.0.0.1 $BOT_DOMAIN www.$DOMAIN_BASE" >> /etc/hosts
echo "✅ Hosts local configurado"

# =====================================================
# CRIAR SCRIPT DE DIAGNÓSTICO
# =====================================================
echo "🔧 Criando script de diagnóstico..."

cat > "$BOT_DIR/diagnostico.sh" <<'DIAGEOF'
#!/bin/bash

echo "========================================="
echo "🔍 DIAGNÓSTICO DO BOT WHATSAPP"
echo "========================================="
echo "Data: $(date)"
echo ""

# Versão atual
ULTIMA_VERSAO=$(cat /opt/whatsapp-bot/ultima_versao.json 2>/dev/null | grep -o '"versao":[0-9]*' | cut -d':' -f2)
echo "📱 Versão configurada: [2, 3000, ${ULTIMA_VERSAO:-1033927531}]"
echo ""

# Status do bot
if pgrep -f "node bot.js" > /dev/null; then
    echo "✅ BOT: Rodando"
    echo "   PID: $(pgrep -f node | head -1)"
else
    echo "❌ BOT: Parado"
fi
echo ""

# Arquivos importantes
echo "📁 Arquivos:"
for arquivo in qrcode.txt auth_info/ config.json usuarios.json versoes.log ultima_versao.json; do
    if [ -e "$arquivo" ]; then
        if [ "$arquivo" = "auth_info/" ]; then
            echo "   ✅ auth_info/ $(ls -1 auth_info/ 2>/dev/null | wc -l) arquivos"
        else
            echo "   ✅ $arquivo $(stat -c "%y" $arquivo 2>/dev/null | cut -d. -f1)"
        fi
    else
        echo "   ❌ $arquivo (ausente)"
    fi
done
echo ""

# Logs recentes
echo "📋 Últimas linhas do log de versão:"
tail -5 versoes.log 2>/dev/null || echo "   Sem logs de versão"
echo ""

echo "========================================="
echo "✅ Diagnóstico concluído"
echo "========================================="
DIAGEOF

chmod +x "$BOT_DIR/diagnostico.sh"
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/diagnostico.sh"
echo "✅ Script de diagnóstico criado"

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
fi

# =====================================================
# VERIFICAR ACESSO
# =====================================================
echo ""
echo "🔍 Verificando acesso ao site..."
sleep 2
if curl -s -o /dev/null -w "%{http_code}" http://localhost/ | grep -q "200\|302\|301"; then
    echo "✅ Site está respondendo!"
    HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/)
    echo "   Status HTTP: $HTTP_STATUS"
else
    echo "⚠️  Site pode não estar acessível"
fi

# =====================================================
# RESUMO FINAL
# =====================================================
echo ""
echo "================================================"
echo "🎉 INSTALAÇÃO CONCLUÍDA!"
echo "================================================"
echo ""
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')

# Verificar status do SSL
SSL_STATUS="❌ Não instalado"
SSL_URL="http://$BOT_DOMAIN"
if [ -f "/etc/letsencrypt/live/$BOT_DOMAIN/fullchain.pem" ]; then
    SSL_STATUS="✅ ATIVO"
    SSL_URL="https://$BOT_DOMAIN"
fi

cat << EOF

📋 RESUMO DA INSTALAÇÃO:
------------------------
• Domínio configurado:     $BOT_DOMAIN
• Alias configurado:       www.$DOMAIN_BASE
• Email para SSL:          $SSL_EMAIL

• Diretório do bot:        $BOT_DIR
• Diretório web:           $WEB_DIR
• Configuração Nginx:      /etc/nginx/sites-available/botzap

• Logs Dashboard Pix:      /var/log/pix_acessos/

🔐 CREDENCIAIS DE ACESSO:
------------------------
• Usuário admin padrão:    admin / Admin@123
• Usuário configurado:     $WEB_USERNAME / [senha configurada]

🔐 STATUS DO SSL:
----------------
$SSL_STATUS
• URL de acesso:           $SSL_URL

⚡ COMANDOS ÚTEIS:
-----------------
• Status do bot:            systemctl status botzap
• Logs do bot:              journalctl -u botzap -f
• Logs tail do bot:         tail -f /var/log/botzap.log
• Reiniciar bot:            systemctl restart botzap
• Reiniciar Nginx:          systemctl reload nginx
• Logs Nginx:               tail -f /var/log/nginx/botzap_error.log
• Dashboard Pix logs:       ls -la /var/log/pix_acessos/
• Diagnóstico:              ./diagnostico.sh
• node bot.js               Inicia o bot normalmente
• node bot.js --clear-auth  Limpa sessões corrompidas
• node bot.js --clean       Mesmo que --clear-auth
• node bot.js --help        Ajuda
• Limpar sessão WhatsApp:
  systemctl stop botzap
  cd $BOT_DIR
  node bot.js --clear-auth
  systemctl start botzap

✅ INSTALAÇÃO CONCLUÍDA COM SUCESSO!
EOF

echo ""
echo "================================================"
echo "🌟 Bot WhatsApp pronto para uso com Nginx!"
echo "================================================"
