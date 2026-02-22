# 🤖 BotZap WEBLINE 2026

Bot WhatsApp automatizado para atendimento ao cliente, integrado com sistema de emissão de faturas PIX e dashboard completo de monitoramento.

![Version](https://img.shields.io/badge/version-2.0.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.2-purple)
![Node](https://img.shields.io/badge/Node-20-green)
![License](https://img.shields.io/badge/license-MIT-orange)

## 📋 Índice

- [Sobre o Projeto](#sobre-o-projeto)
- [Funcionalidades](#funcionalidades)
- [Arquitetura do Sistema](#arquitetura-do-sistema)
- [Pré-requisitos](#pré-requisitos)
- [Instalação Rápida](#instalação-rápida)
- [Instalação Manual](#instalação-manual)
- [Configuração Pós-Instalação](#configuração-pós-instalação)
- [Uso do Sistema](#uso-do-sistema)
- [Gerenciamento de Usuários](#gerenciamento-de-usuários)
- [Dashboard PIX](#dashboard-pix)
- [Comandos Úteis](#comandos-úteis)
- [Solução de Problemas](#solução-de-problemas)
- [Manutenção](#manutenção)
- [Contribuição](#contribuição)
- [Licença](#licença)
- [Contato](#contato)

## 🎯 Sobre o Projeto

O **BotZap WEBLINE 2026** é um sistema completo de atendimento automatizado via WhatsApp, desenvolvido para provedores de internet e empresas que necessitam de:

- 🤖 Atendimento automático 24/7
- 💳 Emissão de faturas via PIX
- 📊 Dashboard de consultas em tempo real
- 👥 Gerenciamento multi-usuário
- 🔐 Sistema de autenticação seguro
- 📱 Monitoramento via Telegram

## ✨ Funcionalidades

### 🤖 Bot WhatsApp
- ✅ Atendimento automático com menu interativo
- ✅ Integração com API para consulta de faturas
- ✅ Validação de CPF/CNPJ na base de clientes
- ✅ Suporte a feriados nacionais e locais
- ✅ Timeout configurável por sessão
- ✅ Logs detalhados de todas as interações
- ✅ Notificações via Telegram (conexão, desconexão, QR Code)

### 📊 Dashboard PIX
- ✅ Consultas de faturas em tempo real
- ✅ Estatísticas diárias, semanais e mensais
- ✅ Filtros por data e exportação CSV
- ✅ Bloqueio de IPs e User-Agents suspeitos
- ✅ Logs de acesso detalhados
- ✅ Estatísticas de bloqueios para admin

### 👥 Sistema de Usuários
- ✅ Login seguro com hash de senha
- ✅ Níveis de acesso (admin/usuário)
- ✅ Logs completos de acesso
- ✅ Auto-logout configurável
- ✅ Gerenciamento de usuários (admin)
- ✅ Alteração de senha própria e por admin

## 🏗 Arquitetura do Sistema

/opt/whatsapp-bot/ # Diretório do bot Node.js

├── bot.js # Script principal do bot

├── config.json # Configurações do bot

├── status.json # Status atual do bot

├── qrcode.txt # QR Code para conexão

├── auth_info/ # Sessão do WhatsApp

└── node_modules/ # Dependências Node.js


/var/www/botzap/ # Diretório web (painel)

├── index.php # Painel principal unificado

├── auth.php # Sistema de autenticação

├── pix.php # Gerador de faturas PIX

├── qrcode_view.php # Visualizador de QR Code

├── hora.php # Utilitário de hora

├── info.php # Informações do PHP

├── login.php # Tela de login (fallback)

├── logout.php # Logout do sistema

├── save.php # Salvamento de config

├── status.php # Status do sistema

└── teste_ipv6.php # Teste de conectividade


/var/log/ # Logs do sistema

├── botzap.log # Log principal do bot

├── pix_acessos/ # Logs do dashboard PIX

│ ├── usuarios.json # Banco de usuários

│ ├── acessos_usuarios.log # Logs de acesso

│ ├── pix_log_*.log # Logs diários de consultas

│ └── pix_filtros.log # Logs de bloqueios


## 📦 Pré-requisitos

- **Sistema Operacional:** Debian 12 (Bookworm)
- **RAM:** Mínimo 1GB (recomendado 2GB)
- **Armazenamento:** 10GB livres
- **Domínio:** Um domínio apontado para o servidor (para SSL)
- **WhatsApp:** Número válido para conexão

## 🚀 Instalação Rápida

Execute o script de instalação automatizada como root:

# Baixar o script de instalação
apt install curl

wget -O install_bot.sh https://raw.githubusercontent.com/seu-usuario/BotZap-WEBLINE-2026/main/install-bot-nginx.sh

# Tornar executável
chmod +x install-bot-nginx.sh

# Executar como root
sudo ./install-bot-nginx.sh

O script irá solicitar:

🌐 Domínio para o bot (ex: bot.seusite.com.br)

🔐 Usuário e senha para acesso ao painel

📧 Email para certificado SSL

🔧 Instalação Manual

1. Clonar o repositório

git clone https://github.com/seu-usuario/BotZap-WEBLINE-2026.git
cd BotZap-WEBLINE-2026

2. Executar instalação passo a passo

# Tornar executável
chmod +x install.sh

# Executar
sudo ./install.sh

⚙️ Configuração Pós-Instalação

1. Configurar o bot
Acesse o painel web: https://seu-dominio.com.br

Login com as credenciais configuradas durante a instalação.

Configure:

📝 Empresa - Nome da sua empresa

📋 Mensagem do Menu - Texto do atendimento

🔗 URL do Boleto - Endpoint da API de faturas

📱 Número do Atendente - Telefone para atendimento humano

⏱️ Tempos de timeout - Duração das sessões

🎯 Feriados - Configuração de feriados nacionais/locais

📱 Telegram - Token e Chat ID para notificações

🔐 MK-Auth - Credenciais da API de clientes

2. Conectar o WhatsApp
No painel, acesse a aba Configurações

O QR Code será exibido automaticamente

Abra o WhatsApp no seu celular

Menu > WhatsApp Web > Escanear QR Code

Pronto! O bot estará online

3. Configurar API de Faturas
Configure o arquivo /var/www/botzap/pix.php com suas credenciais MK-Auth:

$URL_PROV = "https://www.seuprovedor.com.br";

$API_BASE = "https://www.seuprovedor.com.br/api/";

$CLIENT_ID = "seu_client_id";

$CLIENT_SECRET = "seu_client_secret";0

📱 Uso do Sistema
Acessos do Sistema

URL	Descrição

https://seu-dominio.com.br/	Painel principal (requer login)

https://seu-dominio.com.br/?aba=config	Configurações do bot

https://seu-dominio.com.br/?aba=log	Logs do bot (terminal)

https://seu-dominio.com.br/?aba=dashboard	Dashboard de consultas PIX

https://seu-dominio.com.br/?aba=usuarios	Gerenciamento de usuários (admin)

Atendimento do Bot
Cliente envia mensagem no WhatsApp

Bot responde com menu interativo

Opção 1: Geração de fatura PIX (solicita CPF/CNPJ)

Opção 2: Encaminhamento para atendente humano

👥 Gerenciamento de Usuários

Níveis de Acesso

👤 Usuário: Acesso apenas ao dashboard de consultas

👑 Admin: Acesso total + gerenciamento de usuários

Comandos Rápidos (Atalhos)
Atalho	Função

ESC	Sair do sistema

Alt + L	Alternar auto-logout

Alt + S	Sair rapidamente

Alt + P	Alterar minha senha

Alt + U	Gerenciar usuários (admin)

📊 Dashboard PIX
Estatísticas Disponíveis
✅ Total de consultas do dia

✅ Comparativo com ontem

✅ Média dos últimos 7 dias

✅ Exportação para CSV

✅ Filtro por data específica

Logs de Acesso
Data e hora da consulta

IP do cliente

Nome e CPF consultado

Vencimento da fatura

Título do boleto

Sistema de Segurança
✅ Bloqueio automático de IPs suspeitos

✅ Detecção de acessos simultâneos

✅ Validação de User-Agent

✅ Limite de tentativas por CPF

🛠 Comandos Úteis
Gerenciamento do Bot

# Status do bot
systemctl status botzap

# Iniciar/Parar/Reiniciar
systemctl start botzap

systemctl stop botzap

systemctl restart botzap

# Logs em tempo real
journalctl -u botzap -f

tail -f /var/log/botzap.log

Limpeza de Sessão
Quando o bot apresentar problemas de conexão:

systemctl stop botzap

cd /opt/whatsapp-bot

node bot.js --clear-auth

systemctl start botzap

Gerenciamento do Nginx

# Testar configuração
nginx -t

# Recarregar configurações
systemctl reload nginx

# Logs de erro
tail -f /var/log/nginx/botzap_error.log

Logs do Dashboard PIX

# Listar logs disponíveis
ls -la /var/log/pix_acessos/

# Ver logs de hoje
cat /var/log/pix_acessos/pix_log_$(date +%Y-%m-%d).log

# Ver logs de acesso dos usuários
tail -f /var/log/pix_acessos/acessos_usuarios.log

🔍 Solução de Problemas
O bot não conecta
Verifique o QR Code

cat /opt/whatsapp-bot/qrcode.txt
Limpe a sessão

systemctl stop botzap
cd /opt/whatsapp-bot

node bot.js --clear-auth

systemctl start botzap

Verifique os logs

tail -f /var/log/botzap.log
Dashboard não carrega

Verifique permissões

chown -R www-data:www-data /var/log/pix_acessos/

chmod 755 /var/www/botzap/

Verifique logs do PHP

tail -f /var/log/nginx/botzap_error.log
Esqueci a senha do admin

Acesse o servidor via SSH

Edite o arquivo de usuários

nano /var/log/pix_acessos/usuarios.json
Substitua o hash da senha pelo hash de uma nova senha:

php -r "echo password_hash('NovaSenha123', PASSWORD_DEFAULT);"
🔄 Manutenção
Backup
# Backup completo
tar -czf backup_bot_$(date +%Y%m%d).tar.gz \
  /opt/whatsapp-bot \
  /var/www/botzap \
  /var/log/pix_acessos \
  /etc/nginx/sites-available/botzap
Atualização
# Parar o bot
systemctl stop botzap

# Fazer backup
cp /opt/whatsapp-bot/config.json /tmp/
cp /var/log/pix_acessos/usuarios.json /tmp/

# Reinstalar
cd /tmp
wget -O install.sh https://raw.githubusercontent.com/seu-usuario/BotZap-WEBLINE-2026/main/install-bot-nginx.sh

chmod +x install-bot-nginx.sh

sudo ./install-bot-nginx.sh

# Restaurar configurações
cp /tmp/config.json /opt/whatsapp-bot/

cp /tmp/usuarios.json /var/log/pix_acessos/

# Reiniciar
systemctl restart botzap

🤝 Contribuição
Contribuições são bem-vindas! Siga estes passos:

Fork o projeto

Crie sua branch (git checkout -b feature/AmazingFeature)

Commit suas mudanças (git commit -m 'Add some AmazingFeature')

Push para a branch (git push origin feature/AmazingFeature)

Abra um Pull Request

📄 Licença
Distribuído sob a licença MIT. Veja LICENSE para mais informações.

📞 Contato
Desenvolvedor: Miranildo de Lima Santos

GitHub: @miranildo

Projeto: BotZap WEBLINE 2026

⚠️ Avisos Importantes

Use com responsabilidade: Respeite os termos de serviço do WhatsApp

Backup regular: Faça backup das configurações e logs periodicamente

Atualizações: Mantenha o sistema sempre atualizado

Segurança: Use senhas fortes e mantenha o SSL ativo

Desenvolvido com ❤️ para provedores de internet

