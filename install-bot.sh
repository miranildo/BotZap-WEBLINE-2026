#!/bin/bash
set -e

echo "🚀 Iniciando instalação do Bot WhatsApp – Debian 12"

# =====================================================
# VARIÁVEIS
# =====================================================
BOT_DIR="/opt/whatsapp-bot"
WEB_DIR="/var/www/botzap"
BOT_USER="botzap"
WEB_GROUP="www-data"
NODE_VERSION="20"
LOG_FILE="/var/log/botzap.log"

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

node -v
npm -v

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
  php-zip

systemctl enable apache2
systemctl start apache2

# =====================================================
# USUÁRIO E GRUPOS
# =====================================================
echo "👤 Configurando usuários e grupos..."
if ! id "$BOT_USER" &>/dev/null; then
  useradd -r -s /bin/false -d "$BOT_DIR" "$BOT_USER"
fi

# Adicionar usuário botzap ao grupo www-data para acesso a arquivos
usermod -a -G "$WEB_GROUP" "$BOT_USER"

# =====================================================
# DIRETÓRIOS
# =====================================================
echo "📁 Criando diretórios..."
mkdir -p "$BOT_DIR"
mkdir -p "$WEB_DIR"

# =====================================================
# PERMISSÕES COMPARTILHADAS
# =====================================================
echo "🔐 Configurando permissões compartilhadas..."

# 1. Diretório principal do bot - ACESSO COMPARTILHADO
chown -R "$BOT_USER:$WEB_GROUP" "$BOT_DIR"
chmod 775 "$BOT_DIR"

# 2. Node_modules
mkdir -p "$BOT_DIR/node_modules"
chown -R "$BOT_USER:$WEB_GROUP" "$BOT_DIR/node_modules"
find "$BOT_DIR/node_modules" -type d -exec chmod 775 {} \;
find "$BOT_DIR/node_modules" -type f -exec chmod 664 {} \;

# 3. Auth_info - PRIVADO (apenas botzap)
mkdir -p "$BOT_DIR/auth_info"
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/auth_info"
chmod 700 "$BOT_DIR/auth_info"

# =====================================================
# PACKAGE.JSON E DEPENDÊNCIAS
# =====================================================
echo "📦 Criando package.json..."
cat > "$BOT_DIR/package.json" <<'PKGEOF'
{
  "name": "whatsapp-bot",
  "version": "1.0.0",
  "main": "index.js",
  "scripts": {
    "test": "echo \"Error: no test specified\" && exit 1"
  },
  "keywords": [],
  "author": "",
  "license": "ISC",
  "description": "",
  "dependencies": {
    "@whiskeysockets/baileys": "^7.0.0-rc.9",
    "pino": "^10.3.0"
  }
}
PKGEOF

# Package.json - privado do bot
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/package.json"
chmod 640 "$BOT_DIR/package.json"

# =====================================================
# INSTALAR DEPENDÊNCIAS NPM
# =====================================================
echo "📥 Instalando dependências Node.js..."
cd "$BOT_DIR"
sudo -u "$BOT_USER" npm install

# Ajustar permissões do package-lock.json
if [ -f "$BOT_DIR/package-lock.json" ]; then
    chown "$BOT_USER:$BOT_USER" "$BOT_DIR/package-lock.json"
    chmod 640 "$BOT_DIR/package-lock.json"
fi

# =====================================================
# ARQUIVOS DE CONFIGURAÇÃO - PERMISSÕES CORRIGIDAS
# =====================================================
echo "⚙️ Criando arquivos de configuração..."

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

# 2. status.json - COMPARTILHADO (bot escreve, php lê)
cat > "$BOT_DIR/status.json" <<'STATEOF'
{
  "status": "offline",
  "updated": "$(date -Iseconds)"
}
STATEOF
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/status.json"
chmod 664 "$BOT_DIR/status.json"

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

# 4. qrcode.txt - COMPARTILHADO (bot escreve, php lê)
touch "$BOT_DIR/qrcode.txt"
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/qrcode.txt"
chmod 664 "$BOT_DIR/qrcode.txt"

# 5. bot.js - COMPARTILHADO (será copiado depois)
touch "$BOT_DIR/bot.js"
chown "$BOT_USER:$WEB_GROUP" "$BOT_DIR/bot.js"
chmod 664 "$BOT_DIR/bot.js"

# =====================================================
# SYSTEMD – SERVIÇO DO BOT
# =====================================================
echo "⚙️ Criando serviço systemd..."

cat > /etc/systemd/system/botzap.service <<'SERVICEEOF'
[Unit]
Description=Bot WhatsApp – WebLine Telecom
After=network.target

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

LimitNOFILE=65535
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
SERVICEEOF

systemctl daemon-reload
systemctl enable botzap

# =====================================================
# LOGRATE – ROTAÇÃO AUTOMÁTICA DE LOGS
# =====================================================
echo "📊 Configurando logrotate para rotação automática de logs..."

# Criar arquivo de log se não existir
mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"
chown "$BOT_USER:adm" "$LOG_FILE"
chmod 640 "$LOG_FILE"

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
# CONFIGURAÇÃO WEB
# =====================================================
echo "🌐 Configurando diretório web..."

# Configurar permissões do diretório web
chown -R "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR"
chmod 755 "$WEB_DIR"

# Configurar VirtualHost do Apache
cat > /etc/apache2/sites-available/botzap.conf <<'VHOSTEOF'
<VirtualHost *:80>
    ServerName botwhatsapp.weblinetelecom.com.br
    ServerAlias www.botwhatsapp.weblinetelecom.com.br
    ServerAdmin webmaster@weblinetelecom.com.br
    DocumentRoot /var/www/botzap
    
    <Directory /var/www/botzap>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/botzap_error.log
    CustomLog ${APACHE_LOG_DIR}/botzap_access.log combined
</VirtualHost>
VHOSTEOF

a2ensite botzap.conf
a2dissite 000-default.conf

# Configurar HTTPS (SSL) se necessário
echo "🔒 Configurando SSL..."
a2enmod ssl
systemctl reload apache2

# =====================================================
# CONFIGURAR FIREWALL
# =====================================================
echo "🛡️ Configurando firewall..."
if command -v ufw &> /dev/null; then
    ufw allow 80/tcp
    ufw allow 443/tcp
    echo "✅ Firewall configurado"
else
    echo "⚠️ UFW não instalado, pulando configuração de firewall"
fi

# =====================================================
# TESTAR PERMISSÕES
# =====================================================
echo "🧪 Testando permissões de acesso..."

echo "1. Testando acesso do bot (botzap):"
if sudo -u "$BOT_USER" ls -la "$BOT_DIR/" > /dev/null 2>&1; then
    echo "✅ botzap pode acessar diretório"
else
    echo "❌ botzap NÃO pode acessar diretório"
fi

if sudo -u "$BOT_USER" cat "$BOT_DIR/status.json" > /dev/null 2>&1; then
    echo "✅ botzap pode ler status.json"
else
    echo "❌ botzap NÃO pode ler status.json"
fi

echo ""
echo "2. Testando acesso do PHP (www-data):"
if sudo -u "$WEB_GROUP" ls -la "$BOT_DIR/" > /dev/null 2>&1; then
    echo "✅ www-data pode acessar diretório"
else
    echo "❌ www-data NÃO pode acessar diretório"
fi

if sudo -u "$WEB_GROUP" cat "$BOT_DIR/config.json" > /dev/null 2>&1; then
    echo "✅ www-data pode ler config.json"
else
    echo "❌ www-data NÃO pode ler config.json"
fi

# Teste de escrita no config.json
if sudo -u "$WEB_GROUP" bash -c "echo '// teste' >> $BOT_DIR/config.json 2>/dev/null"; then
    echo "✅ www-data pode ESCREVER em config.json"
    # Limpar teste
    sudo sed -i '$ d' "$BOT_DIR/config.json"
else
    echo "❌ www-data NÃO pode escrever em config.json"
fi

echo ""
echo "3. Verificando grupos:"
echo "   botzap grupos: $(id -Gn $BOT_USER 2>/dev/null || echo 'usuário não existe')"
echo "   www-data grupos: $(id -Gn $WEB_GROUP 2>/dev/null || echo 'grupo não existe')"

# =====================================================
# VERIFICAR PERMISSÕES FINAIS
# =====================================================
echo ""
echo "🔍 PERMISSÕES CONFIGURADAS:"
echo "==========================="
echo "📍 $BOT_DIR/"
ls -ld "$BOT_DIR" | awk '{print "  • " $1 " " $3 ":" $4 " " $9}'

echo ""
echo "📄 ARQUIVOS EM $BOT_DIR/:"
for file in config.json package.json status.json usuarios.json qrcode.txt bot.js; do
    if [ -f "$BOT_DIR/$file" ]; then
        ls -l "$BOT_DIR/$file" | awk '{print "  • " $1 " " $3 ":" $4 " " $9}'
    fi
done 2>/dev/null

echo ""
echo "📁 $WEB_DIR/"
ls -ld "$WEB_DIR" | awk '{print "  • " $1 " " $3 ":" $4 " " $9}'

# =====================================================
# FINALIZAÇÃO
# =====================================================
echo ""
echo "🎉 ✅ INSTALAÇÃO CONCLUÍDA COM SUCESSO!"
echo ""
echo "📋 RESUMO:"
echo "=========="
echo "📁 Bot: $BOT_DIR"
echo "👤 Usuário: $BOT_USER"
echo "🌐 Web: $WEB_GROUP"
echo ""
echo "🔐 PERMISSÕES CONFIGURADAS:"
echo "=========================="
echo "• Diretório bot: 775 (botzap:www-data)"
echo "• Arquivos compartilhados: 664 (botzap:www-data)"
echo "• Arquivos privados: 640 (botzap:botzap)"
echo "• auth_info/: 700 (botzap:botzap)"
echo ""
echo "🚀 PRÓXIMOS PASSOS:"
echo "=================="
echo "1️⃣ Copie o arquivo bot.js (com todas as correções) para:"
echo "   sudo cp bot.js $BOT_DIR/"
echo "   sudo chown $BOT_USER:$WEB_GROUP $BOT_DIR/bot.js"
echo "   sudo chmod 664 $BOT_DIR/bot.js"
echo ""
echo "2️⃣ Copie os arquivos web para:"
echo "   sudo cp *.php *.jpg *.png $WEB_DIR/ 2>/dev/null || true"
echo "   sudo chown $WEB_GROUP:$WEB_GROUP $WEB_DIR/*.php"
echo "   sudo chmod 755 $WEB_DIR/*.php"
echo ""
echo "3️⃣ Inicie o bot:"
echo "   sudo systemctl start botzap"
echo ""
echo "4️⃣ Acesse o painel web:"
echo "   https://botwhatsapp.weblinetelecom.com.br/"
echo ""
echo "⚡ COMANDOS ÚTEIS:"
echo "================="
echo "• Status do bot:        sudo systemctl status botzap"
echo "• Iniciar bot:          sudo systemctl start botzap"
echo "• Parar bot:            sudo systemctl stop botzap"
echo "• Reiniciar bot:        sudo systemctl restart botzap"
echo "• Ver logs em tempo:    sudo journalctl -u botzap -f"
echo "• Ver arquivo de log:   tail -f $LOG_FILE"
echo ""
echo "✅ Sistema configurado com permissões compartilhadas funcionais!"
