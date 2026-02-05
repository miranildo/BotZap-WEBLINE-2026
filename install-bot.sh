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
# USUÁRIO DO BOT
# =====================================================
echo "👤 Criando usuário do bot..."
if ! id "$BOT_USER" &>/dev/null; then
  useradd -r -s /bin/false -d "$BOT_DIR" "$BOT_USER"
fi

# Adicionar usuário botzap ao grupo www-data para acesso compartilhado
usermod -a -G "$WEB_GROUP" "$BOT_USER"

# =====================================================
# DIRETÓRIOS
# =====================================================
echo "📁 Criando diretórios..."
mkdir -p "$BOT_DIR"
mkdir -p "$WEB_DIR"

# =====================================================
# CONFIGURAR PERMISSÕES DA PASTA /opt/whatsapp-bot
# =====================================================
echo "🔐 Configurando permissões do diretório do bot..."
chown -R "$BOT_USER:$WEB_GROUP" "$BOT_DIR"
chmod 755 "$BOT_DIR"

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

# Definir permissões do package.json conforme especificado
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/package.json"
chmod 750 "$BOT_DIR/package.json"

# =====================================================
# INSTALAR DEPENDÊNCIAS NPM
# =====================================================
echo "📥 Instalando dependências Node.js..."
cd "$BOT_DIR"
sudo -u "$BOT_USER" npm install

# Ajustar permissões da node_modules após instalação
chown -R "$BOT_USER:$WEB_GROUP" "$BOT_DIR/node_modules"
find "$BOT_DIR/node_modules" -type d -exec chmod 755 {} \;
find "$BOT_DIR/node_modules" -type f -exec chmod 644 {} \;

# =====================================================
# AUTH_INFO (PERMISSÕES RESTRITAS)
# =====================================================
echo "🔒 Configurando diretório auth_info..."
mkdir -p "$BOT_DIR/auth_info"
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/auth_info"
chmod 700 "$BOT_DIR/auth_info"

# =====================================================
# ARQUIVOS DE CONFIGURAÇÃO DO BOT
# =====================================================
echo "⚙️ Criando arquivos de configuração..."

# 1. config.json
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
chmod 674 "$BOT_DIR/config.json"

# 2. status.json
cat > "$BOT_DIR/status.json" <<'STATEOF'
{
  "status": "offline",
  "updated": "$(date -Iseconds)"
}
STATEOF
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/status.json"
chmod 770 "$BOT_DIR/status.json"

# 3. usuarios.json
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
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/usuarios.json"
chmod 770 "$BOT_DIR/usuarios.json"

# 4. qrcode.txt
touch "$BOT_DIR/qrcode.txt"
chown "$BOT_USER:$BOT_USER" "$BOT_DIR/qrcode.txt"
chmod 644 "$BOT_DIR/qrcode.txt"

# 5. package-lock.json (se gerado pelo npm install)
if [ -f "$BOT_DIR/package-lock.json" ]; then
    chown "$BOT_USER:$BOT_USER" "$BOT_DIR/package-lock.json"
    chmod 750 "$BOT_DIR/package-lock.json"
fi

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

echo "✅ Logrotate configurado:"
echo "   - Rotação diária"
echo "   - Manter 30 dias de logs"
echo "   - Compactação automática"
echo "   - Restart do serviço após rotação"

# =====================================================
# CONFIGURAÇÃO WEB (PAINEL DE CONTROLE)
# =====================================================
echo "🌐 Configurando diretório web..."

# Configurar permissões do diretório web
chown -R "$WEB_GROUP:$WEB_GROUP" "$WEB_DIR"
chmod 755 "$WEB_DIR"

# Configurar VirtualHost do Apache
cat > /etc/apache2/sites-available/botzap.conf <<'VHOSTEOF'
<VirtualHost *:80>
    ServerName botzap.local
    ServerAdmin webmaster@localhost
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
systemctl reload apache2

# =====================================================
# CONFIGURAR FIREWALL (OPCIONAL)
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
# VERIFICAR PERMISSÕES
# =====================================================
echo "🔍 Verificando permissões configuradas..."
echo ""
echo "📁 PERMISSÕES CONFIGURADAS:"
echo "==========================="
echo "📍 $BOT_DIR/"
ls -ld "$BOT_DIR" | awk '{print "  • " $1 " " $3 ":" $4 " " $9}'

echo ""
echo "📁 $BOT_DIR/auth_info/"
ls -ld "$BOT_DIR/auth_info" | awk '{print "  • " $1 " " $3 ":" $4 " " $9}'

echo ""
echo "📁 $BOT_DIR/node_modules/"
ls -ld "$BOT_DIR/node_modules" 2>/dev/null | awk '{print "  • " $1 " " $3 ":" $4 " " $9}' || echo "  • (não existe ainda)"

echo ""
echo "📄 ARQUIVOS EM $BOT_DIR/:"
for file in config.json package.json status.json usuarios.json qrcode.txt package-lock.json bot.js; do
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
echo "📋 RESUMO DA INSTALAÇÃO:"
echo "========================"
echo "📁 Bot instalado em: $BOT_DIR"
echo "📦 Dependências Node.js instaladas"
echo "👤 Usuário do bot: $BOT_USER (membro do grupo $WEB_GROUP)"
echo "🌐 Grupo web: $WEB_GROUP"
echo "📊 Logs do bot: $LOG_FILE"
echo "⚙️ Serviço systemd: botzap.service"
echo "🔄 Logrotate configurado (rotação diária, 30 dias)"
echo ""
echo "📁 PERMISSÕES CONFIGURADAS:"
echo "=========================="
echo "• $BOT_DIR/              - 755 - $BOT_USER:$WEB_GROUP"
echo "• $BOT_DIR/auth_info/    - 700 - $BOT_USER:$BOT_USER"
echo "• $BOT_DIR/node_modules/ - 755 - $BOT_USER:$WEB_GROUP"
echo "• $BOT_DIR/config.json   - 674 - $BOT_USER:$WEB_GROUP"
echo "• $BOT_DIR/package.json  - 750 - $BOT_USER:$BOT_USER"
echo "• $BOT_DIR/status.json   - 770 - $BOT_USER:$BOT_USER"
echo "• $BOT_DIR/usuarios.json - 770 - $BOT_USER:$BOT_USER"
echo "• $BOT_DIR/qrcode.txt    - 644 - $BOT_USER:$BOT_USER"
echo "• $WEB_DIR/              - 755 - $WEB_GROUP:$WEB_GROUP"
echo ""
echo "🚀 PRÓXIMOS PASSOS:"
echo "=================="
echo "1️⃣ Copie o arquivo bot.js para:"
echo "   sudo cp bot.js $BOT_DIR/"
echo "   sudo chown $BOT_USER:$WEB_GROUP $BOT_DIR/bot.js"
echo "   sudo chmod 644 $BOT_DIR/bot.js"
echo ""
echo "2️⃣ Copie os arquivos web para:"
echo "   sudo cp *.php *.jpg *.png $WEB_DIR/ 2>/dev/null || true"
echo ""
echo "3️⃣ Configure as permissões dos arquivos web:"
echo "   # Arquivos PHP"
echo "   sudo chown $WEB_GROUP:$WEB_GROUP $WEB_DIR/*.php"
echo "   sudo chmod 755 $WEB_DIR/*.php"
echo ""
echo "   # Arquivos de imagem"
echo "   sudo chown root:root $WEB_DIR/logo.jpg $WEB_DIR/pix.php 2>/dev/null || true"
echo "   sudo chmod 644 $WEB_DIR/logo.jpg $WEB_DIR/pix.php 2>/dev/null || true"
echo ""
echo "   sudo chown $WEB_GROUP:$WEB_GROUP $WEB_DIR/qrcode_*.png 2>/dev/null || true"
echo "   sudo chmod 644 $WEB_DIR/qrcode_*.png 2>/dev/null || true"
echo ""
echo "4️⃣ Inicie o bot:"
echo "   sudo systemctl start botzap"
echo ""
echo "5️⃣ Acesse o painel web:"
echo "   http://$(hostname -I | awk '{print $1}')/"
echo ""
echo "⚡ COMANDOS ÚTEIS:"
echo "================="
echo "• Status do bot:        sudo systemctl status botzap"
echo "• Iniciar bot:          sudo systemctl start botzap"
echo "• Parar bot:            sudo systemctl stop botzap"
echo "• Reiniciar bot:        sudo systemctl restart botzap"
echo "• Ver logs em tempo:    sudo journalctl -u botzap -f"
echo "• Ver arquivo de log:   tail -f $LOG_FILE"
echo "• Monitorar erros:      tail -f /var/log/apache2/botzap_error.log"
echo ""
echo "🔧 CONFIGURAÇÕES ESPECIAIS INCLUÍDAS:"
echo "===================================="
echo "✅ Permissões exatas conforme especificação"
echo "✅ Acesso compartilhado entre botzap e www-data"
echo "✅ Logs com data/hora formatada"
echo "✅ Limpeza automática de usuários inativos"
echo "✅ Rotação automática de logs (30 dias)"
echo "✅ Restart automático do serviço após rotação"
echo "✅ Suporte a feriados nacionais"
echo "✅ Controle de horário comercial"
echo ""
echo "⚠️ IMPORTANTE:"
echo "============="
echo "• O arquivo bot.js deve ser copiado manualmente após instalação"
echo "• Arquivos PHP devem ter permissão 755 (www-data:www-data)"
echo "• Imagens estáticas devem ter permissão 644"
echo "• O botzap tem acesso a config.json (674) para leitura/escrita"
echo ""
echo "🎯 Sistema pronto para receber os arquivos!"
