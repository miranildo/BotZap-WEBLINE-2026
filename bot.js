/*************************************************
 * ✅ BOT WHATSAPP - ÍNICIO DO PROJETO EM ‎Segunda-feira, ‎2‎ de ‎fevereiro‎ de ‎2026, ‏‎19:12:50 por MIRANILDO DE LIMA SANTOS
 *    BOT WHATSAPP - VERSÃO COMPLETA COM FERIADOS v1.0
 * ✅ Controle de feriados via painel web
 * ✅ CORRIGIDO: Bloqueia grupos (@g.us), permite listas (@lid) e individuais (@s.whatsapp.net)
 * ✅ ADICIONADO: Data/hora nos logs + Limpeza automática de usuários
 * ✅ CORRIGIDO: Bug CPF/CNPJ apenas números (não confundir com telefone)
 * ✅ ATUALIZADO: Identificação automática do atendente via conexão QR Code
 * ✅ CORRIGIDO: Captura correta do número do WhatsApp conectado (com formato :sessao)
 * ✅ CORRIGIDO: Prevenção de duplicação atendente/cliente
 * ✅ CORRIGIDO: Ignorar mensagens de sistema/sincronização
 * ✅ ADICIONADO: Atualização automática do número do atendente no config.json
 * ✅ ADICIONADO: Limpeza automática da pasta auth_info ao detectar desconexão (loggedOut)
 * ✅ CORRIGIDO: Comando #FECHAR do atendente agora funciona corretamente
 * ✅ ADICIONADO: Comando #FECHAR [número] e #FECHAR [nome] para encerrar individualmente
 * ✅ ADICIONADO: Comando #CLIENTES para listar atendimentos ativos
 * ✅ CORRIGIDO: Bot NÃO responde em grupos - apenas individualmente
 * ✅ ADICIONADO: Verificação MK-Auth para CPF/CNPJ existentes antes de gerar link PIX
 * ✅ ATUALIZADO: Credenciais MK-Auth configuráveis via painel web
 * ✅ CORRIGIDO: Não gera link se credenciais não estiverem configuradas
 * ✅ CORRIGIDO: "Para Fatura" fora do horário e "Tentar outro CPF" agora vão para tela CPF
 * ✅ ATUALIZADO: Permite cliente inativo COM fatura em aberto acessar PIX normalmente
 * ✅ ADICIONADO: Exibe nome do cliente quando CPF/CNPJ é encontrado
 *    BOT WHATSAPP - VERSÃO LID-PROOF CORRIGIDA v1.1
 * ✅ CORRIGIDO: Loop de timeout para usuários individuais
 * ✅ MANTIDO: Todas mensagens do fluxo original
 * ✅ CORRIGIDO: Sistema de encerramento completo
 * ✅ CORRIGIDO: Apenas status@broadcast ignorado
 * ✅ CORRIGIDO: Clientes @lid e @broadcast atendidos
 *    BOT WHATSAPP - VERSÃO LID-PROOF ULTRA v1.2
 * ✅ 100% AGNÓSTICO A NÚMERO
 * ✅ LID como tipo próprio
 * ✅ Primary Key universal (stable ID para JIDs rotativos)
 * ✅ Versionamento automático de estrutura
 * ✅ Suporte a JID criptografado com identificador estável
 * ✅ Extração robusta de JID (participant/remoteJid/contextInfo)
 * ✅ Gerenciamento profissional de intervalos
 * ✅ Health check e debug integrado
 * ✅ Migração automática V1.2 → V1.3
 * ✅ TODAS as mensagens e fluxo ORIGINAIS preservados
 * 
 * 🆕 SISTEMA UNIFICADO DE TIMEOUT - v1.4
 * ✅ Tempo único configurável via index.php (tempo_inatividade_global)
 * ✅ Aplica-se a TODOS os contextos: menu, CPF, PIX, atendimento humano
 * ✅ Cliente inativo volta ao menu inicial automaticamente
 * ✅ Mantém compatibilidade com timeout específico do atendimento humano
 * ✅ CORREÇÃO: Menu inicial agora é monitorado pelo sistema de timeout
 * 
 * 🆕 CORREÇÃO DE MENSAGENS INDEVIDAS - v1.5
 * ✅ Ignora mensagens de contexto de grupo (participant/participant_lid)
 * ✅ Ignora mensagens de broadcast não direcionadas
 * ✅ Processa apenas mensagens diretas (@lid, @s.whatsapp.net)
 * 
 * 🆕 FERIADO LOCAL PERSONALIZÁVEL - v1.6
 * ✅ Checkbox no painel para ativar/desativar feriado local
 * ✅ Mensagem personalizável para feriados locais
 * ✅ Mantém compatibilidade com feriados nacionais
 * ✅ Se ativado, bloqueia atendimento humano com mensagem customizada
 * ✅ Fluxo do PIX permanece 100% intacto
 * 
 * 🆕 NOTIFICAÇÕES TELEGRAM - v1.7
 * ✅ Monitoramento da conexão do WhatsApp
 * ✅ Notificações via Telegram quando conectar, desconectar ou gerar QR Code
 * ✅ Configurável via painel web
 * ✅ Número do atendente identificado em todas as notificações
 * 
 * 🆕 DETECÇÃO AUTOMÁTICA DE VERSÃO v1.8
 * ✅ Versão WhatsApp via fetchLatestBaileysVersion()
 * ✅ Versão Baileys lida do package.json
 * ✅ Sempre atualizado sem intervenção manual
 * ✅ Comando #VERSAO para consultar
 * 
 * 🆕 OPÇÃO 3 - NÃO SOU CLIENTE v1.9
 * ✅ Planos configuráveis via painel web
 * ✅ Link de assinatura personalizável
 * ✅ Opções 2 (Atendente) e 5 (Assinar) no submenu
 * ✅ Totalmente customizável
 * 
 * 🆕 CORREÇÃO DE DESCONEXÃO v2.0 - 02/05/2026
 * ✅ Diferencia problemas de rede de desconexão real da Meta
 * ✅ Mantém sessão em caso de queda de internet
 * ✅ Apenas limpa sessão quando Meta realmente deslogar
 * ✅ Após loggedOut, NÃO reconecta automaticamente (aguarda QR)
 * ✅ ✅ NOTIFICAÇÕES TELEGRAM APENAS PARA CONEXÕES REAIS E DESCONEXÕES REAIS
 * ✅ ✅ NENHUMA NOTIFICAÇÃO para problemas de rede ou reconexões automáticas
 * 
 * 🏆 NÍVEL: 10/10 - PREPARADO PARA 2025+
 *************************************************/

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion
} = require('@whiskeysockets/baileys');

const fs = require('fs');
const path = require('path');
const P = require('pino');
const https = require('https');
const crypto = require('crypto');

const BASE_DIR = __dirname;
const AUTH_DIR = path.join(BASE_DIR, 'auth_info');
const CONFIG_PATH = path.join(BASE_DIR, 'config.json');
const STATUS_PATH = path.join(BASE_DIR, 'status.json');
const QR_PATH = path.join(BASE_DIR, 'qrcode.txt');
const USUARIOS_PATH = path.join(BASE_DIR, 'usuarios.json');
const MUDANCAS_LOG_PATH = path.join(BASE_DIR, 'mudancas_formatos.log');
const IDENTITY_MAP_PATH = path.join(BASE_DIR, 'identity_map.json');
const VERSAO_LOG_PATH = path.join(BASE_DIR, 'versoes.log');
const ULTIMA_VERSAO_PATH = path.join(BASE_DIR, 'ultima_versao.json');

// ================= VARIÁVEIS GLOBAIS DE VERSÃO =================
global.WHATSAPP_VERSION = 1033927531;  // Versão fallback
global.WHATSAPP_VERSION_COMPLETA = null;
global.VERSAO_BAILEYS = 'desconhecida';
global.WHATSAPP_VERSION_DETECTADA = null;

// ================= CONTROLE DE NOTIFICAÇÕES =================
let ultimaNotificacaoDesconexao = 0;
let limiteMinimoNotificacao = 30000; // 30 segundos entre notificações

// ================= VERSIONAMENTO E CONTROLE =================
const ESTRUTURA_VERSION = '2.0.0';

// ESTRUTURAS GLOBAIS - VERSÃO 2.0
const atendimentos = {};
const contextos = {};
let sockInstance = null;

// 🔥 ESTRUTURA DE USUÁRIOS 100% AGNÓSTICA COM VERSIONAMENTO
let usuarios = {
    __version: ESTRUTURA_VERSION,
    __migratedAt: new Date().toISOString(),
    byPrimaryKey: {},     // ÚNICA FONTE DA VERDADE
    byJid: {},           // Mapeamento JID -> PrimaryKey
    byNumero: {},        // APENAS CONSULTA - NUNCA usado como chave!
    byLegacyId: {}       // Compatibilidade retroativa
};

// Monitoramento de formatos
let formatosDetectados = [];

// Variável para controle de logs
let ultimoLogVerificacao = {
    quantidade: 0,
    timestamp: 0
};

// Controle de reconexão
let reconexaoEmAndamento = false;
let tentativasReconexao = 0;
let aguardandoQR = false;
let reconexaoAposProblemaRede = false;
let foiProblemaRede = false;
let enviouNotificacaoDesconexaoReal = false;
let isReconnecting = false;  // 🔥 FLAG CRÍTICA: Indica se é uma reconexão automática
let primeiraConexao = true;   // 🔥 FLAG: Primeira inicialização do bot
let modoLoggedOut = false;  // 🔥 Indica que estamos em processo de loggedOut

// ================= FUNÇÕES AUXILIARES =================
function formatarDataHora() {
    const agora = new Date();
    const dia = String(agora.getDate()).padStart(2, '0');
    const mes = String(agora.getMonth() + 1).padStart(2, '0');
    const ano = agora.getFullYear();
    const horas = String(agora.getHours()).padStart(2, '0');
    const minutos = String(agora.getMinutes()).padStart(2, '0');
    const segundos = String(agora.getSeconds()).padStart(2, '0');
    return `[${dia}/${mes}/${ano} ${horas}:${minutos}:${segundos}]`;
}

// Função para registrar logs de versão
function registrarLogVersao(mensagem) {
    const logEntry = `${formatarDataHora()} ${mensagem}\n`;
    fs.appendFileSync(VERSAO_LOG_PATH, logEntry, 'utf8');
    console.log(logEntry.trim());
}

// Função para salvar informação de versão
function salvarInfoVersao(versao, versao_completa, fonte) {
    try {
        const versaoInfo = {
            data: new Date().toISOString(),
            versao: versao,
            versao_completa: versao_completa || `${versao}`,
            fonte: fonte,
            detectada_em: formatarDataHora()
        };
        fs.writeFileSync(ULTIMA_VERSAO_PATH, JSON.stringify(versaoInfo, null, 2));
        registrarLogVersao(`💾 Versão salva: ${versao} (fonte: ${fonte})`);
    } catch (error) {
        registrarLogVersao(`⚠️ Erro ao salvar info versão: ${error.message}`);
    }
}

// ================= FUNÇÃO PARA OBTER VERSÃO DO BAILEYS DO PACKAGE.JSON =================
function obterVersaoBaileys() {
    try {
        const packagePath = path.join(BASE_DIR, 'node_modules', '@whiskeysockets', 'baileys', 'package.json');
        if (fs.existsSync(packagePath)) {
            const packageJson = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
            return packageJson.version || 'desconhecida';
        }
    } catch (error) {
        console.log(`${formatarDataHora()} ⚠️ Erro ao ler versão do Baileys: ${error.message}`);
    }
    
    try {
        const mainPackagePath = path.join(BASE_DIR, 'package.json');
        if (fs.existsSync(mainPackagePath)) {
            const mainPackage = JSON.parse(fs.readFileSync(mainPackagePath, 'utf8'));
            const baileysVersion = mainPackage.dependencies?.['@whiskeysockets/baileys'] || 
                                   mainPackage.devDependencies?.['@whiskeysockets/baileys'] || 
                                   'desconhecida';
            return baileysVersion.replace(/^[\^~]/, '');
        }
    } catch (error) {}
    
    return 'desconhecida';
}

// ================= FUNÇÃO PARA ENVIAR NOTIFICAÇÃO TELEGRAM =================
async function enviarNotificacaoTelegram(mensagem, tipo = 'info') {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
        
        if (config.telegram_ativado !== 'Sim') {
            return false;
        }
        
        const token = config.telegram_token;
        const chatId = config.telegram_chat_id;
        
        if (!token || !chatId) {
            console.log(`${formatarDataHora()} ⚠️ Telegram: Token ou Chat ID não configurados`);
            return false;
        }
        
        if (tipo === 'conexao' && config.telegram_notificar_conexao !== 'Sim') return false;
        if (tipo === 'desconexao' && config.telegram_notificar_desconexao !== 'Sim') return false;
        if (tipo === 'qr' && config.telegram_notificar_qr !== 'Sim') return false;
        
        const postData = JSON.stringify({
            chat_id: chatId,
            text: mensagem,
            parse_mode: 'Markdown'
        });
        
        const options = {
            hostname: 'api.telegram.org',
            port: 443,
            path: `/bot${token}/sendMessage`,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData)
            }
        };
        
        return new Promise((resolve) => {
            const req = https.request(options, (res) => {
                let data = '';
                res.on('data', (chunk) => { data += chunk; });
                res.on('end', () => {
                    if (res.statusCode === 200) {
                        console.log(`${formatarDataHora()} 📱 Notificação Telegram enviada (${tipo})`);
                        resolve(true);
                    } else {
                        console.log(`${formatarDataHora()} ⚠️ Erro ao enviar Telegram: HTTP ${res.statusCode}`);
                        resolve(false);
                    }
                });
            });
            
            req.on('error', (error) => {
                console.log(`${formatarDataHora()} ⚠️ Erro ao enviar Telegram:`, error.message);
                resolve(false);
            });
            
            req.write(postData);
            req.end();
        });
        
    } catch (error) {
        console.log(`${formatarDataHora()} ⚠️ Erro ao enviar Telegram:`, error.message);
        return false;
    }
}

// ================= GERENCIAMENTO DE INTERVALOS =================
let intervalos = {
    timeout: null,
    limpeza: null
};

function iniciarIntervalos() {
    pararIntervalos();
    
    intervalos.timeout = setInterval(verificarTimeouts, 30000);
    console.log(`${formatarDataHora()} ⏱️ Sistema de timeout ativo (verifica a cada 30s) - ID: ${intervalos.timeout}`);
    
    intervalos.limpeza = setInterval(() => {
        const agora = new Date();
        if (agora.getHours() === 2 && agora.getMinutes() === 0) {
            console.log(`${formatarDataHora()} 🧹 Executando limpeza programada...`);
            corrigirAtendimentosCorrompidos();
            salvarUsuarios();
        }
    }, 60000);
    console.log(`${formatarDataHora()} 🧹 Sistema de limpeza ativo (verifica a cada 60s)`);
}

function pararIntervalos() {
    if (intervalos.timeout) {
        clearInterval(intervalos.timeout);
        console.log(`${formatarDataHora()} ⏹️ Intervalo de timeout removido: ${intervalos.timeout}`);
        intervalos.timeout = null;
    }
    
    if (intervalos.limpeza) {
        clearInterval(intervalos.limpeza);
        console.log(`${formatarDataHora()} ⏹️ Intervalo de limpeza removido: ${intervalos.limpeza}`);
        intervalos.limpeza = null;
    }
}

// FERIADOS FIXOS
const FERIADOS_NACIONAIS = [
    '01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '12-25'
];

// ================= FUNÇÕES DE EXTRAÇÃO DE JID =================
function extrairJIDCompleto(msg) {
    try {
        const key = msg.key || {};
        const message = msg.message || {};
        
        if (key.participant) {
            const jid = key.participant;
            if (jid.includes('@lid')) {
                return { jid, source: 'participant_lid', ignore: false };
            }
            return { jid, source: 'participant', ignore: false };
        }
        
        if (key.remoteJid) {
            const jid = key.remoteJid;
            if (jid === 'status@broadcast') {
                return { jid, source: 'status', ignore: true };
            }
            return { jid, source: 'remote', ignore: false };
        }
        
        if (message.extendedTextMessage?.contextInfo?.participant) {
            const jid = message.extendedTextMessage.contextInfo.participant;
            return { jid, source: 'context_info', ignore: false };
        }
        
        return { jid: null, source: 'none', ignore: true };
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao extrair JID:`, error);
        return { jid: null, source: 'error', ignore: true };
    }
}

// ================= SISTEMA DE HEALTH CHECK =================
function gerarRelatorioSistema() {
    const relatorio = {
        timestamp: new Date().toISOString(),
        versao: ESTRUTURA_VERSION,
        versao_baileys: global.VERSAO_BAILEYS,
        versao_whatsapp: global.WHATSAPP_VERSION,
        versao_completa: global.WHATSAPP_VERSION_COMPLETA || 'desconhecida',
        estatisticas: {
            usuarios: {
                total: Object.keys(usuarios.byPrimaryKey || {}).length,
                porTipo: {},
                comLID: 0,
                comNumero: 0,
                apenasLID: 0,
                comStableId: 0
            },
            atendimentos: {
                ativos: Object.keys(atendimentos).length,
                porTipo: {}
            },
            formatosDetectados: formatosDetectados?.length || 0
        }
    };
    
    Object.values(usuarios.byPrimaryKey || {}).forEach(u => {
        const tipo = u.identityType || 'unknown';
        relatorio.estatisticas.usuarios.porTipo[tipo] = (relatorio.estatisticas.usuarios.porTipo[tipo] || 0) + 1;
        
        if (u.jids?.lid) relatorio.estatisticas.usuarios.comLID++;
        if (u.numero) relatorio.estatisticas.usuarios.comNumero++;
        if (u.jids?.lid && !u.numero) relatorio.estatisticas.usuarios.apenasLID++;
        if (u.stableId) relatorio.estatisticas.usuarios.comStableId++;
    });
    
    Object.values(atendimentos).forEach(a => {
        relatorio.estatisticas.atendimentos.porTipo[a.tipo] = (relatorio.estatisticas.atendimentos.porTipo[a.tipo] || 0) + 1;
    });
    
    return relatorio;
}

// ================= FUNÇÃO DE LIMPEZA DE SESSÕES =================
async function limparSessoesECredenciais() {
    console.log(`${formatarDataHora()} 🧹 INICIANDO LIMPEZA DE SESSÕES...`);
    
    try {
        if (fs.existsSync(AUTH_DIR)) {
            console.log(`${formatarDataHora()} 🗑️ Removendo pasta auth_info...`);
            const files = fs.readdirSync(AUTH_DIR);
            for (const file of files) {
                try {
                    fs.unlinkSync(path.join(AUTH_DIR, file));
                    console.log(`${formatarDataHora()} ✅ Removido: ${file}`);
                } catch (err) {
                    console.error(`${formatarDataHora()} ⚠️ Erro ao remover ${file}:`, err.message);
                }
            }
            try {
                fs.rmdirSync(AUTH_DIR);
                console.log(`${formatarDataHora()} ✅ Pasta auth_info removida`);
            } catch (err) {
                console.error(`${formatarDataHora()} ⚠️ Erro ao remover pasta:`, err.message);
            }
        }
        
        const arquivosParaLimpar = [
            'pre-key.txt', 'session.txt', 'sender-key.txt',
            'app-state-sync-key.txt', 'app-state-sync-version.txt'
        ];
        
        for (const arquivo of arquivosParaLimpar) {
            const caminhoArquivo = path.join(BASE_DIR, arquivo);
            if (fs.existsSync(caminhoArquivo)) {
                try {
                    fs.unlinkSync(caminhoArquivo);
                    console.log(`${formatarDataHora()} ✅ Removido: ${arquivo}`);
                } catch (err) {}
            }
        }
        
        if (fs.existsSync(QR_PATH)) {
            fs.unlinkSync(QR_PATH);
            console.log(`${formatarDataHora()} ✅ QR Code antigo removido`);
        }
        
        setStatus('offline');
        await new Promise(resolve => setTimeout(resolve, 2000));
        console.log(`${formatarDataHora()} 🎉 LIMPEZA CONCLUÍDA!`);
        return true;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro na limpeza:`, error);
        return false;
    }
}

function setStatus(status) {
    fs.writeFileSync(
        STATUS_PATH,
        JSON.stringify({ status, updated: new Date().toISOString() }, null, 2)
    );
}

setStatus('offline');

function limparDoc(v) {
    return v.replace(/\D+/g, '');
}

function formatarData(data) {
    const mes = (data.getMonth() + 1).toString().padStart(2, '0');
    const dia = data.getDate().toString().padStart(2, '0');
    return `${mes}-${dia}`;
}

function limparAuthInfo() {
    try {
        if (fs.existsSync(AUTH_DIR)) {
            console.log(`${formatarDataHora()} 🗑️ Limpando pasta auth_info...`);
            const files = fs.readdirSync(AUTH_DIR);
            for (const file of files) {
                fs.unlinkSync(path.join(AUTH_DIR, file));
            }
            fs.rmdirSync(AUTH_DIR);
            console.log(`${formatarDataHora()} ✅ Pasta auth_info removida`);
            return true;
        }
        return false;
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao limpar auth_info:`, error);
        return false;
    }
}

function getJID(numeroOuIdentity) {
    if (!numeroOuIdentity) return null;
    
    if (numeroOuIdentity instanceof WhatsAppIdentity) {
        return numeroOuIdentity.getSendJID();
    }
    
    if (typeof numeroOuIdentity === 'string' && numeroOuIdentity.includes('@')) {
        const identity = new WhatsAppIdentity(numeroOuIdentity);
        return identity.getSendJID();
    }
    
    if (typeof numeroOuIdentity === 'string' || typeof numeroOuIdentity === 'number') {
        const num = numeroOuIdentity.toString().replace(/\D/g, '');
        if (num.length >= 10) {
            const numeroFormatado = num.startsWith('55') ? num : `55${num}`;
            console.log(`${formatarDataHora()} ⚠️ FALLBACK: convertendo número para JID: ${numeroFormatado}`);
            return `${numeroFormatado}@s.whatsapp.net`;
        }
    }
    
    return null;
}

function atualizarAtendenteNoConfig(numeroAtendente) {
    try {
        console.log(`${formatarDataHora()} ⚙️ Atualizando número do atendente: ${numeroAtendente}`);
        const configAtual = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
        configAtual.atendente_numero = numeroAtendente;
        fs.writeFileSync(CONFIG_PATH, JSON.stringify(configAtual, null, 2));
        return true;
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao atualizar config.json:`, error);
        return false;
    }
}

// ================= FUNÇÕES DE VERIFICAÇÃO DE FERIADOS =================
function ehFeriado(data = new Date()) {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        if (config.feriados_ativos !== 'Sim') return false;
        const diaMes = formatarData(data);
        return FERIADOS_NACIONAIS.includes(diaMes);
    } catch (error) {
        return false;
    }
}

function ehFeriadoLocal() {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        return config.feriado_local_ativado === 'Sim';
    } catch (error) {
        return false;
    }
}

function getMensagemFeriadoLocal() {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        return config.feriado_local_mensagem || "📅 *Comunicado importante:*\nHoje é feriado local e não estamos funcionando.\nRetornaremos amanhã em horário comercial.\n\nO acesso a faturas PIX continua disponível 24/7! 😊";
    } catch (error) {
        return "📅 Hoje é feriado local. Retornaremos amanhã!";
    }
}

function formatarHorarioComercial() {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        let mensagem = "🕐 *Horário Comercial:*\n";
        mensagem += "• Segunda a Sexta: 8h às 12h e 14h às 18h\n";
        mensagem += "• Sábado: 8h às 12h\n";
        mensagem += "• Domingo: Fechado\n";
        
        if (config.feriados_ativos === 'Sim') {
            mensagem += "• Feriados Nacionais: Fechado\n";
        }
        
        if (config.feriado_local_ativado === 'Sim') {
            mensagem += "• Feriado Local ATIVO (verifique comunicado)\n";
        }
        
        mensagem += "\n";
        
        if (config.feriados_ativos === 'Não' && config.feriado_local_ativado !== 'Sim') {
            mensagem += "\n*Feriados não estão sendo considerados* (configurado no painel)";
        }
        
        return mensagem;
    } catch (error) {
        return "🕐 Horário comercial padrão";
    }
}

function dentroHorarioComercial() {
    const d = new Date();
    const dia = d.getDay();
    const h = d.getHours() + d.getMinutes() / 60;

    if (ehFeriado(d)) return false;
    if (ehFeriadoLocal()) return false;
    
    if (dia === 0) return false;
    
    if (dia >= 1 && dia <= 5) {
        return (h >= 8 && h < 12) || (h >= 14 && h < 18);
    }
    
    if (dia === 6) {
        return (h >= 8 && h < 12);
    }
    
    return false;
}

// ================= CLASSE WHATSAPP IDENTITY =================
class WhatsAppIdentity {
    constructor(rawJid) {
        this.raw = rawJid || '';
        this.normalized = this.normalizeJID(rawJid);
        this.type = this.detectType();
        this.subType = this.detectSubType();
        this.internalId = this.generateInternalId();
        this.stableId = this.generateStableId();
        this.primaryKey = this.generatePrimaryKey();
        this.sendCapability = this.determineSendCapability();
    }
    
    normalizeJID(jid) {
        if (!jid) return { identifier: '', domain: 'unknown', full: '' };
        const parts = jid.split('@');
        return {
            identifier: parts[0] || '',
            domain: parts[1] || 'unknown',
            full: jid
        };
    }
    
    detectType() {
        const jid = this.raw;
        if (!jid) return 'unknown';
        
        if (jid.includes('@lid')) return 'lid';
        if (jid === 'status@broadcast') return 'status';
        if (jid.includes('@broadcast')) return 'broadcast';
        if (jid.includes('@g.us')) return 'group';
        if (jid.includes('@s.whatsapp.net')) return 'individual';
        
        if (jid.includes('@wa.encrypted') || 
            jid.includes('@lid.enc') || 
            /^[a-f0-9]{32,64}@/.test(jid) ||
            /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}@/.test(jid)) {
            console.log(`${formatarDataHora()} 🔐 JID CRIPTOGRAFADO DETECTADO: ${jid}`);
            return 'encrypted_jid';
        }
        
        if (jid.includes('@')) {
            this.logNovoFormato();
            return 'new_format';
        }
        
        return 'unknown';
    }
    
    detectSubType() {
        if (this.type === 'lid') return 'individual_lid';
        if (this.type === 'broadcast' && this.raw !== 'status@broadcast') return 'list_broadcast';
        if (this.type === 'individual') return 'legacy_individual';
        if (this.type === 'encrypted_jid') return 'encrypted_identity';
        return 'standard';
    }
    
    generateStableId() {
        if (this.type === 'encrypted_jid') {
            const parts = this.raw.split('@');
            const identifier = parts[0] || '';
            
            if (/^[a-f0-9]{64}$/i.test(identifier)) {
                return `enc_stable:${identifier.substring(0, 16)}`;
            }
            
            if (/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(identifier)) {
                return `enc_uuid:${identifier}`;
            }
            
            if (/^[a-f0-9]{32}$/i.test(identifier)) {
                return `enc_md5:${identifier.substring(0, 16)}`;
            }
        }
        
        if (this.type === 'lid') {
            const lidPart = this.normalized.identifier;
            return `lid:${lidPart}`;
        }
        
        return null;
    }
    
    generatePrimaryKey() {
        if (this.stableId) {
            return this.stableId;
        }
        
        if (this.type === 'lid') {
            const lidPart = this.normalized.identifier;
            return `lid:${lidPart}`;
        }
        
        if (this.type === 'broadcast' && this.raw !== 'status@broadcast') {
            const identifier = this.normalized.identifier;
            return `broadcast:${identifier}`;
        }
        
        if (this.type === 'individual') {
            const phoneNumber = this.extractPhoneNumber();
            if (phoneNumber) {
                return `tel:${phoneNumber}`;
            }
            return `jid:${this.normalized.identifier}`;
        }
        
        if (this.type === 'new_format') {
            return `new:${this.internalId.substring(5)}`;
        }
        
        return this.internalId;
    }
    
    generateInternalId() {
        if (!this.raw) return null;
        const hash = crypto.createHash('sha256')
            .update(this.raw)
            .digest('hex')
            .substring(0, 16);
        return `hash:${hash}`;
    }
    
    extractPhoneNumber() {
        try {
            if (this.type === 'individual') {
                let numero = this.normalized.identifier;
                if (numero.includes(':')) numero = numero.split(':')[0];
                numero = numero.replace(/\D/g, '');
                if (numero.length >= 10 && numero.length <= 13) {
                    if (!numero.startsWith('55')) numero = '55' + numero;
                    return numero;
                }
            }
            return null;
        } catch (error) {
            return null;
        }
    }
    
    getSendJID() {
        if (!this.raw) return null;
        
        if (['lid', 'broadcast', 'individual', 'encrypted_jid', 'new_format'].includes(this.type)) {
            return this.raw;
        }
        
        return null;
    }
    
    determineSendCapability() {
        return {
            lid: this.type === 'lid',
            individual: this.type === 'individual',
            broadcast: this.type === 'broadcast',
            encrypted: this.type === 'encrypted_jid',
            new_format: this.type === 'new_format',
            canSend: ['lid', 'individual', 'broadcast', 'encrypted_jid', 'new_format'].includes(this.type),
            canReceive: !['status', 'group', 'unknown'].includes(this.type)
        };
    }
    
    logNovoFormato() {
        const novidade = {
            timestamp: new Date().toISOString(),
            jid: this.raw,
            tipo: 'novo_formato',
            normalized: this.normalized,
            domain: this.normalized.domain,
            internalId: this.internalId,
            stableId: this.stableId,
            primaryKey: this.primaryKey
        };
        
        formatosDetectados.push(novidade);
        fs.appendFileSync(MUDANCAS_LOG_PATH, JSON.stringify(novidade, null, 2) + '\n---\n');
        
        console.warn(`${formatarDataHora()} ⚠️ NOVO FORMATO DETECTADO!`);
        console.warn(`${formatarDataHora()} JID: ${this.raw}`);
        console.warn(`${formatarDataHora()} Primary Key: ${this.primaryKey}`);
        console.warn(`${formatarDataHora()} Stable ID: ${this.stableId || 'N/A'}`);
    }
}

// ================= GESTÃO DE USUÁRIOS =================
function adicionarUsuario(usuario) {
    if (!usuario || !usuario.primaryKey) {
        console.error(`${formatarDataHora()} ❌ Tentativa de adicionar usuário sem primaryKey`);
        return false;
    }
    
    try {
        usuarios.byPrimaryKey[usuario.primaryKey] = usuario;
        
        if (usuario.whatsappId) {
            usuarios.byJid[usuario.whatsappId] = usuario.primaryKey;
        }
        
        if (usuario.jids) {
            Object.values(usuario.jids).forEach(jid => {
                if (jid) usuarios.byJid[jid] = usuario.primaryKey;
            });
        }
        
        if (usuario.numero && typeof usuario.numero === 'string') {
            usuarios.byNumero[usuario.numero] = usuario.primaryKey;
        }
        
        if (usuario.id && usuario.id !== usuario.primaryKey) {
            usuarios.byLegacyId[usuario.id] = usuario.primaryKey;
        }
        
        console.log(`${formatarDataHora()} ✅ Usuário adicionado: ${usuario.pushName || 'Sem nome'} (PK: ${usuario.primaryKey})`);
        return true;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao adicionar usuário:`, error);
        return false;
    }
}

function buscarUsuario(criterio) {
    if (!criterio) return null;
    
    if (usuarios.byPrimaryKey[criterio]) {
        return usuarios.byPrimaryKey[criterio];
    }
    
    const primaryKeyFromJid = usuarios.byJid[criterio];
    if (primaryKeyFromJid) {
        return usuarios.byPrimaryKey[primaryKeyFromJid];
    }
    
    const primaryKeyFromNumero = usuarios.byNumero[criterio];
    if (primaryKeyFromNumero) {
        console.log(`${formatarDataHora()} ⚠️ Busca por NÚMERO (fallback): ${criterio}`);
        return usuarios.byPrimaryKey[primaryKeyFromNumero];
    }
    
    const primaryKeyFromLegacy = usuarios.byLegacyId[criterio];
    if (primaryKeyFromLegacy) {
        return usuarios.byPrimaryKey[primaryKeyFromLegacy];
    }
    
    return null;
}

function salvarUsuarios() {
    try {
        const dadosParaSalvar = {
            __version: ESTRUTURA_VERSION,
            __savedAt: new Date().toISOString(),
            byPrimaryKey: usuarios.byPrimaryKey,
            byJid: usuarios.byJid,
            byNumero: usuarios.byNumero,
            byLegacyId: usuarios.byLegacyId
        };
        
        fs.writeFileSync(USUARIOS_PATH, JSON.stringify(dadosParaSalvar, null, 2));
        
        const identityMap = {
            version: ESTRUTURA_VERSION,
            timestamp: new Date().toISOString(),
            byPrimaryKey: Object.keys(usuarios.byPrimaryKey || {}).length,
            byJid: Object.keys(usuarios.byJid || {}).length,
            byNumero: Object.keys(usuarios.byNumero || {}).length,
            byLegacyId: Object.keys(usuarios.byLegacyId || {}).length
        };
        fs.writeFileSync(IDENTITY_MAP_PATH, JSON.stringify(identityMap, null, 2));
        
        console.log(`${formatarDataHora()} 💾 Usuários salvos (v${ESTRUTURA_VERSION}): ${Object.keys(usuarios.byPrimaryKey || {}).length} usuário(s)`);
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao salvar usuários:`, error);
    }
}

function resetarEstruturaUsuarios() {
    usuarios = {
        __version: ESTRUTURA_VERSION,
        __migratedAt: new Date().toISOString(),
        byPrimaryKey: {},
        byJid: {},
        byNumero: {},
        byLegacyId: {}
    };
}

function carregarUsuarios() {
    try {
        if (fs.existsSync(USUARIOS_PATH)) {
            const dados = JSON.parse(fs.readFileSync(USUARIOS_PATH, 'utf8'));
            const versaoArquivo = dados.__version || '1.0.0';
            
            if (versaoArquivo !== ESTRUTURA_VERSION) {
                console.log(`${formatarDataHora()} 🔄 Migrando estrutura v${versaoArquivo} → v${ESTRUTURA_VERSION}...`);
                usuarios = dados;
                usuarios.__version = ESTRUTURA_VERSION;
                usuarios.__migratedAt = new Date().toISOString();
                salvarUsuarios();
            } else {
                usuarios = dados;
            }
            
            console.log(`${formatarDataHora()} 📂 ${Object.keys(usuarios.byPrimaryKey || {}).length} usuário(s) carregado(s) (v${ESTRUTURA_VERSION})`);
        } else {
            resetarEstruturaUsuarios();
            console.log(`${formatarDataHora()} 📂 Mapa de usuários inicializado (v${ESTRUTURA_VERSION})`);
        }
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao carregar usuários:`, error);
        resetarEstruturaUsuarios();
    }
}

function identificarUsuario(jid, pushName, texto = '', ignorarExtracaoNumero = false) {
    if (!jid) {
        console.error(`${formatarDataHora()} ❌ JID não fornecido`);
        return null;
    }
    
    const identity = new WhatsAppIdentity(jid);
    
    if (identity.type === 'status') {
        console.log(`${formatarDataHora()} 📱 Visualização de STATUS - IGNORANDO`);
        return null;
    }
    
    if (identity.type === 'group') {
        console.log(`${formatarDataHora()} 🚫 Mensagem de GRUPO - IGNORANDO`);
        return null;
    }
    
    if (!['lid', 'individual', 'broadcast', 'encrypted_jid', 'new_format'].includes(identity.type)) {
        console.log(`${formatarDataHora()} 🚫 Tipo não suportado: ${identity.type}`);
        return null;
    }
    
    console.log(`${formatarDataHora()} 🔍 Identificando: "${pushName}" (${identity.type})`);
    
    let usuario = buscarUsuario(identity.primaryKey);
    if (usuario) {
        console.log(`${formatarDataHora()} ✅ Usuário encontrado por Primary Key`);
        return usuario;
    }
    
    usuario = buscarUsuario(identity.raw);
    if (usuario) {
        console.log(`${formatarDataHora()} ✅ Usuário encontrado por JID`);
        return usuario;
    }
    
    const phoneNumber = identity.extractPhoneNumber();
    if (phoneNumber) {
        usuario = buscarUsuario(phoneNumber);
        if (usuario) {
            console.log(`${formatarDataHora()} ⚠️ Usuário encontrado por NÚMERO (fallback): ${phoneNumber}`);
            
            if (!usuario.jids) usuario.jids = {};
            usuario.jids[identity.type] = identity.raw;
            usuario.whatsappId = identity.raw;
            usuarios.byJid[identity.raw] = usuario.primaryKey;
            salvarUsuarios();
            
            return usuario;
        }
    }
    
    console.log(`${formatarDataHora()} 👤 NOVO USUÁRIO: ${pushName || 'Sem nome'} (${identity.type})`);
    
    const novoUsuario = {
        id: identity.primaryKey,
        primaryKey: identity.primaryKey,
        stableId: identity.stableId,
        identityType: identity.type,
        identitySubType: identity.subType,
        whatsappId: identity.raw,
        jids: {
            current: identity.raw,
            lid: identity.type === 'lid' ? identity.raw : null,
            broadcast: identity.type === 'broadcast' ? identity.raw : null,
            individual: identity.type === 'individual' ? identity.raw : null,
            encrypted: identity.type === 'encrypted_jid' ? identity.raw : null
        },
        sendCapability: identity.sendCapability,
        numero: identity.extractPhoneNumber(),
        pushName: pushName || 'Cliente',
        tipo: 'cliente',
        origem: identity.type === 'lid' ? 'lid' : 
                identity.type === 'broadcast' ? 'lista' : 
                identity.type === 'individual' ? 'individual' : 
                identity.type === 'encrypted_jid' ? 'encrypted' : 'novo_formato',
        cadastradoEm: new Date().toISOString(),
        ultimaInteracao: new Date().toISOString(),
        temporario: false,
        lidSession: identity.type === 'lid',
        metadata: {
            domain: identity.normalized.domain,
            identifier: identity.normalized.identifier,
            raw: identity.raw,
            primaryKey: identity.primaryKey,
            stableId: identity.stableId
        }
    };
    
    if (adicionarUsuario(novoUsuario)) {
        salvarUsuarios();
        console.log(`${formatarDataHora()} ✅ NOVO USUÁRIO CADASTRADO: ${pushName || 'Cliente'}`);
        console.log(`${formatarDataHora()}    ├─ Tipo: ${identity.type}`);
        console.log(`${formatarDataHora()}    ├─ Primary Key: ${identity.primaryKey}`);
        console.log(`${formatarDataHora()}    ├─ Stable ID: ${identity.stableId || 'N/A'}`);
        console.log(`${formatarDataHora()}    └─ Número: ${novoUsuario.numero || 'NÃO DISPONÍVEL'}`);
        return novoUsuario;
    }
    
    return null;
}

// ================= FUNÇÕES PRINCIPAIS DO BOT =================
async function enviarMensagemParaUsuario(sock, usuario, mensagem) {
    console.log(`${formatarDataHora()} 📤 [ENVIAR] Iniciando envio para: ${usuario.pushName} (${usuario.identityType})`);
    
    try {
        let jidDestino = null;
        
        if (usuario.jids?.lid) {
            jidDestino = usuario.jids.lid;
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Usando LID: ${jidDestino}`);
        }
        else if (usuario.jids?.encrypted) {
            jidDestino = usuario.jids.encrypted;
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Usando JID criptografado: ${jidDestino}`);
        }
        else if (usuario.identityType === 'broadcast' || usuario.lidSession) {
            jidDestino = usuario.whatsappId;
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Usando Broadcast: ${jidDestino}`);
        }
        else if (usuario.identityType === 'individual' && usuario.whatsappId) {
            jidDestino = usuario.whatsappId;
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Usando Individual: ${jidDestino}`);
        }
        else if (usuario.whatsappId) {
            const identity = new WhatsAppIdentity(usuario.whatsappId);
            jidDestino = identity.getSendJID();
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Usando JID: ${jidDestino}`);
        }
        else if (usuario.numero) {
            jidDestino = getJID(usuario.numero);
            console.log(`${formatarDataHora()} ⚠️ [ENVIAR] FALLBACK para número: ${usuario.numero} -> ${jidDestino}`);
        }
        
        if (!jidDestino) {
            console.error(`${formatarDataHora()} 📤 [ENVIAR] ❌ Não foi possível obter JID de envio`);
            return false;
        }
        
        await sock.sendMessage(jidDestino, { text: mensagem });
        console.log(`${formatarDataHora()} 📤 [ENVIAR] ✅ Mensagem enviada para ${usuario.pushName}`);
        return true;
        
    } catch (error) {
        console.error(`${formatarDataHora()} 📤 [ENVIAR] ❌ ERRO:`, error.message);
        return false;
    }
}

async function enviarMenuPrincipal(sock, usuario, texto = '') {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        const pushName = usuario?.pushName || '';
        
        if (!atendimentos[usuario.primaryKey]) {
            atendimentos[usuario.primaryKey] = {
                tipo: 'menu',
                inicio: Date.now(),
                ultimaAtividade: Date.now(),
                usuarioPrimaryKey: usuario.primaryKey
            };
            console.log(`${formatarDataHora()} 📋 Atendimento criado para ${pushName} (menu)`);
        }
        
        let menuText = config.menu || 
`Olá! 👋  ${pushName ? pushName + ' ' : ''}

Bem-vindo ao atendimento da *${config.empresa}*

 1️⃣ Baixar Fatura
 2️⃣ Falar com Atendente
 3️⃣ Não sou Cliente!

Digite o número da opção desejada:`;

        menuText = menuText.replace(/\{\{empresa\}\}/g, config.empresa);
        menuText = menuText.replace(/\{\{nome\}\}/g, pushName || 'Cliente');

        const resultado = await enviarMensagemParaUsuario(sock, usuario, menuText);
        
        if (resultado) {
            console.log(`${formatarDataHora()} ✅ Menu enviado para ${pushName || 'usuário'}`);
        }
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao enviar menu:`, error);
    }
}

async function encerrarAtendimento(usuario, config, motivo = "encerrado", chaveExplicita = null) {
    if (!sockInstance) {
        console.error(`${formatarDataHora()} ❌ sockInstance não disponível`);
        return false;
    }
    
    let chaveAtendimento = chaveExplicita || usuario.primaryKey;
    const pushName = usuario.pushName || 'Cliente';
    
    console.log(`${formatarDataHora()} 🚪 Encerrando ${pushName} (${motivo}) - PK: ${chaveAtendimento}`);
    
    if (!usuario.metadata) usuario.metadata = {};
    usuario.metadata.ultimoEncerramento = Date.now();
    
    const chavesParaRemover = new Set();
    chavesParaRemover.add(chaveAtendimento);
    chavesParaRemover.add(usuario.primaryKey);
    
    if (usuario.id && usuario.id !== chaveAtendimento) {
        chavesParaRemover.add(usuario.id);
    }
    if (usuario.whatsappId) {
        chavesParaRemover.add(usuario.whatsappId);
    }
    if (usuario.numero) {
        chavesParaRemover.add(usuario.numero);
    }
    
    let removidos = 0;
    for (const chave of chavesParaRemover) {
        if (atendimentos[chave]) {
            delete atendimentos[chave];
            removidos++;
        }
        if (contextos[chave]) {
            delete contextos[chave];
            removidos++;
        }
    }
    
    console.log(`${formatarDataHora()} ✅ ${pushName}: ${removidos} registro(s) removido(s)`);
    
    let mensagem = '';
    if (motivo === "timeout") {
        mensagem = `⏰ *Atendimento encerrado por inatividade*\n\n` +
                  `A *${config.empresa}* agradece o seu contato! 😊`;
    } else if (motivo === "atendente") {
        mensagem = `✅ *Atendimento encerrado pelo atendente*\n\n` +
                  `A *${config.empresa}* agradece o seu contato! 😊`;
    } else if (motivo === "cliente") {
        mensagem = `✅ *Atendimento encerrado*\n\n` +
                  `A *${config.empresa}* agradece o seu contato! 😊`;
    } else {
        mensagem = `✅ *Atendimento encerrado!*\n\n` +
                  `A *${config.empresa}* agradece o seu contato! 😊`;
    }
    
    try {
        await new Promise(resolve => setTimeout(resolve, 500));
        await enviarMensagemParaUsuario(sockInstance, usuario, mensagem);
        salvarUsuarios();
        return true;
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao enviar mensagem de encerramento:`, error);
        return false;
    }
}

async function verificarTimeouts() {
    try {
        const configRaw = fs.readFileSync(CONFIG_PATH, 'utf8');
        const config = JSON.parse(configRaw);
        
        const agora = Date.now();
        
        let tempoGlobalMinutos = config.tempo_inatividade_global;
        if (!tempoGlobalMinutos || tempoGlobalMinutos < 1) {
            tempoGlobalMinutos = 30;
            console.log(`${formatarDataHora()} ⚠️ tempo_inatividade_global não configurado, usando 30 minutos`);
        }
        
        const tempoInatividadeGlobal = tempoGlobalMinutos * 60 * 1000;
        
        const totalAtendimentos = Object.keys(atendimentos).length;
        if (totalAtendimentos > 0) {
            console.log(`${formatarDataHora()} 🔍 Verificando ${totalAtendimentos} atendimento(s)...`);
        }
        
        const chavesAtendimentos = Object.keys(atendimentos);
        
        for (const chave of chavesAtendimentos) {
            const atendimento = atendimentos[chave];
            if (!atendimento) continue;
            
            let usuario = buscarUsuario(chave);
            
            if (!usuario && atendimento.usuarioPrimaryKey) {
                usuario = buscarUsuario(atendimento.usuarioPrimaryKey);
            }
            
            if (!usuario) {
                console.log(`${formatarDataHora()} ⚠️ Usuário não encontrado para chave: ${chave} - removendo`);
                delete atendimentos[chave];
                delete contextos[chave];
                continue;
            }
            
            const pushName = usuario.pushName || 'Cliente';
            const referenciaTempo = atendimento.ultimaAtividade || atendimento.inicio || agora;
            const tempoInativo = agora - referenciaTempo;
            const minutosInativo = Math.round(tempoInativo / 60000);
            
            if (tempoInativo > tempoInatividadeGlobal) {
                console.log(`${formatarDataHora()} ⏰ ENCERRANDO ${pushName} - ${minutosInativo}min inativo > ${tempoGlobalMinutos}min`);
                await encerrarAtendimento(usuario, config, "timeout", usuario.primaryKey);
                continue;
            }
            
            if (minutosInativo % 5 === 0 && minutosInativo > 0) {
                console.log(`${formatarDataHora()} ⏱️ ${pushName} - ${minutosInativo}min inativo (limite: ${tempoGlobalMinutos}min)`);
            }
            
            if (atendimento.tipo === 'humano' && atendimento.timeout && agora > atendimento.timeout) {
                console.log(`${formatarDataHora()} ⏰ Timeout específico do atendimento humano - Encerrando ${pushName}`);
                await encerrarAtendimento(usuario, config, "timeout", usuario.primaryKey);
                continue;
            }
        }
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao verificar timeouts:`, error);
    }
}

async function reconectarComSeguranca() {
    if (aguardandoQR) {
        console.log(`${formatarDataHora()} ⏸️ Bot aguardando escaneamento de QR Code - ignorando reconexão automática`);
        return;
    }
    
    if (reconexaoEmAndamento) return;
    
    reconexaoEmAndamento = true;
    tentativasReconexao++;
    isReconnecting = true;  // 🔥 Marcar que é reconexão automática   
    console.log(`${formatarDataHora()} 🔄 Reconexão automática iniciada (tentativa ${tentativasReconexao})`);
    
    try {
        const delay = Math.min(1000 * Math.pow(2, tentativasReconexao), 30000);
        console.log(`${formatarDataHora()} ⏱️ Aguardando ${delay/1000}s antes de reconectar (tentativa ${tentativasReconexao})...`);
        
        await new Promise(resolve => setTimeout(resolve, delay));
        
        if (aguardandoQR) {
            console.log(`${formatarDataHora()} ⏸️ Estado alterado para aguardando QR - cancelando reconexão`);
            return;
        }
        
        if (tentativasReconexao > 10) {
            console.log(`${formatarDataHora()} ⚠️ Múltiplas tentativas de reconexão (${tentativasReconexao}) - verifique sua conexão de internet`);
        }
        
        await startBot();
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro na reconexão:`, error.message);
    } finally {
        reconexaoEmAndamento = false;
    }
}

// ================= FUNÇÕES MK-AUTH =================
function extrairNomeCliente(dadosMKAuth) {
    try {
        if (dadosMKAuth.nome && dadosMKAuth.nome.trim() !== '') return dadosMKAuth.nome.trim();
        if (dadosMKAuth.cli_nome && dadosMKAuth.cli_nome.trim() !== '') return dadosMKAuth.cli_nome.trim();
        if (dadosMKAuth.nome_cliente && dadosMKAuth.nome_cliente.trim() !== '') return dadosMKAuth.nome_cliente.trim();
        
        if (dadosMKAuth.titulos && Array.isArray(dadosMKAuth.titulos) && dadosMKAuth.titulos.length > 0) {
            for (const titulo of dadosMKAuth.titulos) {
                if (titulo.nome && titulo.nome.trim() !== '') return titulo.nome.trim();
                if (titulo.cli_nome && titulo.cli_nome.trim() !== '') return titulo.cli_nome.trim();
                if (titulo.nome_cliente && titulo.nome_cliente.trim() !== '') return titulo.nome_cliente.trim();
            }
        }
        
        if (dadosMKAuth.cliente && typeof dadosMKAuth.cliente === 'object') {
            if (dadosMKAuth.cliente.nome && dadosMKAuth.cliente.nome.trim() !== '') return dadosMKAuth.cliente.nome.trim();
            if (dadosMKAuth.cliente.nome_completo && dadosMKAuth.cliente.nome_completo.trim() !== '') return dadosMKAuth.cliente.nome_completo.trim();
        }
        
        return null;
    } catch (error) {
        return null;
    }
}

function verificarClienteMKAuth(doc) {
    return new Promise((resolve, reject) => {
        console.log(`${formatarDataHora()} 🔍 Verificando cliente no MK-Auth: ${doc}`);
        
        try {
            const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
            
            if (!config.mkauth_url || !config.mkauth_client_id || !config.mkauth_client_secret) {
                resolve({ 
                    sucesso: false, 
                    erro: true, 
                    configurado: false,
                    mensagem: "Sistema de verificação não configurado. Entre em contato com o suporte." 
                });
                return;
            }
            
            let apiBase = config.mkauth_url;
            if (!apiBase.endsWith('/')) apiBase += '/';
            if (!apiBase.includes('/api/')) apiBase += 'api/';
            
            const clientId = config.mkauth_client_id;
            const clientSecret = config.mkauth_client_secret;
            
            obterTokenMKAuth(apiBase, clientId, clientSecret)
                .then(token => {
                    if (!token) {
                        resolve({ sucesso: false, erro: true, mensagem: "Erro na autenticação do sistema" });
                        return;
                    }
                    
                    consultarTitulosMKAuth(doc, token, apiBase)
                        .then(resultado => resolve(resultado))
                        .catch(error => resolve({ sucesso: false, erro: true, mensagem: "Erro ao consultar o sistema" }));
                })
                .catch(error => resolve({ sucesso: false, erro: true, mensagem: "Erro na autenticação do sistema" }));
                
        } catch (error) {
            resolve({ 
                sucesso: false, 
                erro: true, 
                configurado: false,
                mensagem: "Erro no sistema de verificação. Tente novamente mais tarde." 
            });
        }
    });
}

function obterTokenMKAuth(apiBase, clientId, clientSecret) {
    return new Promise((resolve, reject) => {
        const url = new URL(apiBase);
        const hostname = url.hostname;
        const path = url.pathname;
        
        const auth = Buffer.from(`${clientId}:${clientSecret}`).toString('base64');
        
        const options = {
            hostname: hostname,
            port: 443,
            path: path,
            method: 'GET',
            headers: {
                'Authorization': `Basic ${auth}`,
                'Content-Type': 'application/json'
            },
            timeout: 10000
        };
        
        const req = https.request(options, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                if (res.statusCode === 200) {
                    const token = data.trim();
                    if (token && token.length >= 20) resolve(token);
                    else reject(new Error('Token inválido'));
                } else reject(new Error(`HTTP ${res.statusCode}`));
            });
        });
        
        req.on('error', (error) => reject(error));
        req.on('timeout', () => { req.destroy(); reject(new Error('Timeout')); });
        req.end();
    });
}

function consultarTitulosMKAuth(doc, token, apiBase) {
    return new Promise((resolve, reject) => {
        const url = new URL(apiBase);
        const hostname = url.hostname;
        const path = `/api/titulo/titulos/${doc}`;
        
        const options = {
            hostname: hostname,
            port: 443,
            path: path,
            method: 'GET',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            timeout: 15000
        };
        
        const req = https.request(options, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                try {
                    const parsedData = JSON.parse(data);
                    
                    if (parsedData && parsedData.mensagem && 
                        parsedData.mensagem.toLowerCase().includes('não encontrado')) {
                        resolve({ 
                            sucesso: false, 
                            existe: false,
                            mensagem: "CPF/CNPJ não encontrado na base de clientes"
                        });
                        return;
                    }
                    
                    const nomeCliente = extrairNomeCliente(parsedData);
                    
                    let cliAtivado = null;
                    if (parsedData.cli_ativado !== undefined) {
                        cliAtivado = parsedData.cli_ativado;
                    } else if (parsedData.titulos && Array.isArray(parsedData.titulos)) {
                        for (const titulo of parsedData.titulos) {
                            if (titulo.cli_ativado === 's') {
                                cliAtivado = 's';
                                break;
                            } else if (titulo.cli_ativado === 'n') {
                                cliAtivado = 'n';
                            }
                        }
                    } else {
                        cliAtivado = 's';
                    }
                    
                    const cliAtivadoStr = String(cliAtivado).toLowerCase().trim();
                    
                    if (cliAtivadoStr !== 's') {
                        if (parsedData.titulos && Array.isArray(parsedData.titulos)) {
                            let temFaturaAberta = false;
                            let temFaturaComPix = false;
                            
                            for (const titulo of parsedData.titulos) {
                                const status = titulo.status ? titulo.status.toLowerCase() : '';
                                const statusValidos = ['aberto', 'pendente', 'vencido', 'em aberto', 'aberta', 'atrasada'];
                                
                                if (statusValidos.some(s => status.includes(s))) {
                                    temFaturaAberta = true;
                                    if (titulo.pix && titulo.pix.trim() !== '') {
                                        temFaturaComPix = true;
                                        break;
                                    }
                                }
                            }
                            
                            if (!(temFaturaAberta && temFaturaComPix)) {
                                resolve({ 
                                    sucesso: false, 
                                    existe: true,
                                    ativo: false,
                                    cli_ativado: cliAtivadoStr,
                                    nome_cliente: nomeCliente,
                                    mensagem: "CPF/CNPJ com cadastro INATIVO. Favor entrar em contato com o Atendente."
                                });
                                return;
                            }
                        } else {
                            resolve({ 
                                sucesso: false, 
                                existe: true,
                                ativo: false,
                                cli_ativado: cliAtivadoStr,
                                nome_cliente: nomeCliente,
                                mensagem: "CPF/CNPJ com cadastro INATIVO. Favor entrar em contato com o Atendente."
                            });
                            return;
                        }
                    }
                    
                    if (!parsedData.titulos || !Array.isArray(parsedData.titulos) || parsedData.titulos.length === 0) {
                        resolve({ 
                            sucesso: false, 
                            existe: true,
                            ativo: true,
                            temFaturas: false,
                            nome_cliente: nomeCliente,
                            mensagem: "Cliente encontrado, mas sem faturas disponíveis"
                        });
                        return;
                    }
                    
                    let temFaturaComPix = false;
                    for (const titulo of parsedData.titulos) {
                        if (titulo.pix && titulo.pix.trim() !== '') {
                            temFaturaComPix = true;
                            break;
                        }
                    }
                    
                    if (!temFaturaComPix) {
                        resolve({ 
                            sucesso: false, 
                            existe: true,
                            ativo: true,
                            temFaturas: true,
                            temPix: false,
                            nome_cliente: nomeCliente,
                            mensagem: "Cliente encontrado, mas sem faturas para pagamento via PIX"
                        });
                        return;
                    }
                    
                    resolve({ 
                        sucesso: true, 
                        existe: true,
                        ativo: cliAtivadoStr === 's',
                        cli_ativado: cliAtivadoStr,
                        temFaturas: true,
                        temPix: true,
                        nome_cliente: nomeCliente,
                        mensagem: "Cliente válido",
                        data: parsedData
                    });
                    
                } catch (error) {
                    reject(error);
                }
            });
        });
        
        req.on('error', (error) => reject(error));
        req.on('timeout', () => { req.destroy(); reject(new Error('Timeout')); });
        req.end();
    });
}

function corrigirAtendimentosCorrompidos() {
    console.log(`${formatarDataHora()} 🔧 Verificando atendimentos corrompidos...`);
    
    let removidos = 0;
    const agora = Date.now();
    const umaHora = 60 * 60 * 1000;
    
    for (const [chave, atendimento] of Object.entries(atendimentos)) {
        if (atendimento.inicio && (agora - atendimento.inicio) > umaHora) {
            delete atendimentos[chave];
            delete contextos[chave];
            removidos++;
        }
    }
    
    if (removidos > 0) {
        console.log(`${formatarDataHora()} ✅ ${removidos} atendimento(s) corrompido(s) removido(s)`);
    }
    
    return removidos;
}

// ================= FUNÇÃO PRINCIPAL DO BOT =================
async function startBot() {
    modoLoggedOut = false;  // 🔥 ADICIONAR no início da função
    const args = process.argv.slice(2);
    
    if (args.includes('--clear-auth') || args.includes('--clean')) {
        console.log(`${formatarDataHora()} 🧹 Modo de limpeza ativado`);
        await limparSessoesECredenciais();
        console.log(`${formatarDataHora()} ✅ Limpeza concluída. Execute sem parâmetros para iniciar o bot.`);
        process.exit(0);
    }
    
    if (args.includes('--help') || args.includes('-h')) {
        console.log(`
🤖 BOT WHATSAPP - COMANDOS:

  node bot.js              - Inicia o bot normalmente
  node bot.js --clear-auth - Limpa todas as sessões e credenciais
  node bot.js --clean      - Limpa sessões (atalho)
  node bot.js --help       - Mostra esta ajuda
        `);
        process.exit(0);
    }
    
    // Obtém a versão do Baileys do package.json
    global.VERSAO_BAILEYS = obterVersaoBaileys();
    console.log(`${formatarDataHora()} 📱 Versão do Baileys instalada: ${global.VERSAO_BAILEYS}`);
    
    // Obtém a versão mais recente do Baileys para o WhatsApp
    console.log(`${formatarDataHora()} 📱 Buscando versão mais recente do WhatsApp via Baileys...`);
    let waVersion = [2, 3000, 1033927531];

    try {
        const { version, isLatest } = await fetchLatestBaileysVersion();
        if (version && version.length >= 3) {
            waVersion = version;
            global.WHATSAPP_VERSION = version[2];
            global.WHATSAPP_VERSION_COMPLETA = version.join('.');
            
            console.log(`${formatarDataHora()} ✅ Versão do WhatsApp obtida: ${global.WHATSAPP_VERSION_COMPLETA} ${isLatest ? '(mais recente)' : ''}`);
            console.log(`${formatarDataHora()} 📱 Versão do Baileys: ${global.VERSAO_BAILEYS}`);
            console.log(`${formatarDataHora()} 📱 Versão do WhatsApp: ${global.WHATSAPP_VERSION}`);
            
            salvarInfoVersao(global.WHATSAPP_VERSION, global.WHATSAPP_VERSION_COMPLETA, 'fetchLatestBaileysVersion');
        } else {
            console.log(`${formatarDataHora()} ⚠️ Não foi possível obter versão, usando fallback: ${waVersion[2]}`);
            global.WHATSAPP_VERSION = waVersion[2];
            global.WHATSAPP_VERSION_COMPLETA = waVersion.join('.');
            salvarInfoVersao(waVersion[2], waVersion.join('.'), 'fallback');
        }
    } catch (error) {
        console.log(`${formatarDataHora()} ⚠️ Erro ao buscar versão: ${error.message}`);
        console.log(`${formatarDataHora()} 📱 Usando versão fallback: ${waVersion[2]}`);
        global.WHATSAPP_VERSION = waVersion[2];
        global.WHATSAPP_VERSION_COMPLETA = waVersion.join('.');
        salvarInfoVersao(waVersion[2], waVersion.join('.'), 'fallback');
    }
    
    corrigirAtendimentosCorrompidos();
    carregarUsuarios();

    if (!fs.existsSync(AUTH_DIR)) {
        console.log(`${formatarDataHora()} ℹ️ Pasta auth_info não existe - será criada ao gerar QR Code`);
    }

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

    // ================= CONFIGURAÇÃO DO SOCKET WHATSAPP =================
    const sock = makeWASocket({
        auth: state,
        logger: P({ level: 'silent' }),
        browser: ['Chrome (Linux)', '', ''],
        version: waVersion,
        syncFullHistory: false,
        connectTimeoutMs: 60000,
        generateHighQualityLinkPreview: false,
        patch: true,
        retryRequestDelayMs: 1000,
        maxRetries: 10,
        defaultQueryTimeoutMs: 60000,
        keepAliveIntervalMs: 25000,
        markOnlineOnConnect: true,
        shouldSyncHistoryMessage: () => false,
        emitOwnEvents: false
    });

    sockInstance = sock;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async ({ connection, lastDisconnect, qr }) => {
        if (qr) {
            fs.writeFileSync(QR_PATH, qr);
            setStatus('qr');
            console.log(`${formatarDataHora()} 📱 QR Code gerado. Escaneie com o WhatsApp.`);
            
            if (aguardandoQR) {
                //aguardandoQR = false;
                console.log(`${formatarDataHora()} 🔓 Flag de aguardando QR liberada - novo QR disponível`);
            }
            
            // 🔥 Resetar flags de reconexão quando novo QR é gerado
            isReconnecting = false;
            primeiraConexao = true;
            
            try {
                const config = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
                const empresa = config.empresa || 'Bot WhatsApp';
                const numeroAtendente = config.atendente_numero || 'NÃO CONFIGURADO';
                
                console.log(`${formatarDataHora()} 🔧 Chamando notificação de QR CODE...`);
                enviarNotificacaoTelegram(
                    `📱 *QR CODE GERADO*\n\n` +
                    `📱 *Bot:* ${empresa}\n` +
                    `📞 *Número:* ${numeroAtendente}\n` +
                    `🆕 Um novo QR Code foi gerado.\n` +
                    `⏰ ${formatarDataHora()}\n\n` +
                    `🔗 Acesse o painel para escanear.`,
                    'qr'
                ).then(resultado => {
                    console.log(`${formatarDataHora()} 🔧 Resultado notificação QR CODE:`, resultado ? 'ENVIADA' : 'FALHOU');
                });
            } catch (error) {
                console.error(`${formatarDataHora()} ❌ Erro ao enviar notificação de QR Code:`, error.message);
            }
        }

        if (connection === 'open') {
            fs.writeFileSync(QR_PATH, '');
            setStatus('online');
            tentativasReconexao = 0;
            
            let pushName = 'Atendente';
            let phoneNumber = 'Número não disponível';
            let userJid = null;
            
            try {
                const user = sock.user;
                if (user && user.id) {
                    userJid = user.id;
                    const identity = new WhatsAppIdentity(user.id);
                    phoneNumber = identity.extractPhoneNumber() || 'Número não disponível';
                    pushName = user.name || 'Atendente WhatsApp';
                    
                    if (identity.primaryKey) {
                        console.log(`${formatarDataHora()} 🔐 WhatsApp conectado como: ${pushName}`);
                        console.log(`${formatarDataHora()}    ├─ Tipo: ${identity.type}`);
                        console.log(`${formatarDataHora()}    ├─ Primary Key: ${identity.primaryKey}`);
                        console.log(`${formatarDataHora()}    └─ Número: ${phoneNumber}`);
                        
                        const novoAtendente = {
                            id: identity.primaryKey,
                            primaryKey: identity.primaryKey,
                            stableId: identity.stableId,
                            whatsappId: identity.raw,
                            jids: {
                                current: identity.raw,
                                lid: identity.type === 'lid' ? identity.raw : null,
                                individual: identity.type === 'individual' ? identity.raw : null
                            },
                            identityType: identity.type,
                            identitySubType: identity.subType,
                            sendCapability: identity.sendCapability,
                            numero: phoneNumber,
                            tipo: 'atendente',
                            pushName: pushName,
                            cadastradoEm: new Date().toISOString(),
                            metadata: {
                                domain: identity.normalized.domain,
                                identifier: identity.normalized.identifier,
                                raw: identity.raw,
                                primaryKey: identity.primaryKey,
                                stableId: identity.stableId
                            }
                        };
                        
                        if (adicionarUsuario(novoAtendente)) {
                            salvarUsuarios();
                            if (phoneNumber !== 'Número não disponível') {
                                atualizarAtendenteNoConfig(phoneNumber);
                            }
                            
                            try {
                                await enviarMensagemParaUsuario(sock, novoAtendente, 
                                    `👨‍💼 *ATENDENTE CONFIGURADO*\n\nOlá ${pushName}! Você foi configurado como atendente do bot.\n\n*Comandos disponíveis:*\n• #STATUS - Relatório do sistema\n• #VERSAO - Versão do WhatsApp\n• #FECHAR - Encerra todos os atendimentos\n• #FECHAR [número] - Encerra cliente específico\n• #FECHAR [nome] - Encerra por nome\n• #CLIENTES - Lista clientes ativos`
                                );
                            } catch (error) {}
                        }
                    }
                }
            } catch (error) {
                console.error(`${formatarDataHora()} ❌ Erro ao capturar credenciais:`, error);
            }
            
            console.log(`${formatarDataHora()} ✅ WhatsApp conectado com sucesso!`);
            console.log(`${formatarDataHora()} 👥 ${Object.keys(usuarios.byPrimaryKey || {}).length} usuário(s)`);
            console.log(`${formatarDataHora()} 📱 Versão do WhatsApp: ${global.WHATSAPP_VERSION_COMPLETA || global.WHATSAPP_VERSION}`);

            // 🔥 ADICIONE ESTA LINHA AQUI (força a notificação na primeira conexão)
            if (primeiraConexao === true) {
            aguardandoQR = true;
            }
            
            // 🔥 NOTIFICAÇÃO DE CONEXÃO: Apenas se NÃO for reconexão automática E NÃO for primeira inicialização
            // Ou se for primeira inicialização
            const deveNotificarConexao = aguardandoQR || !isReconnecting;
            //const deveNotificarConexao = !isReconnecting && (primeiraConexao || !reconexaoEmAndamento);
            
            if (deveNotificarConexao) {
                try {
                    const config = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
                    const empresa = config.empresa || 'Bot WhatsApp';
                    const numeroAtendente = config.atendente_numero || 'NÃO CONFIGURADO';
                    
                    console.log(`${formatarDataHora()} 🔧 Enviando notificação de CONEXÃO (conexão real)...`);
                    enviarNotificacaoTelegram(
                        `✅ *WHATSAPP CONECTADO*\n\n` +
                        `📱 *Bot:* ${empresa}\n` +
                        `📞 *Número:* ${numeroAtendente}\n` +
                        `👤 *Atendente:* ${pushName}\n` +
                        `📱 *Versão WhatsApp:* ${global.WHATSAPP_VERSION}\n` +
                        `📱 *Baileys:* ${global.VERSAO_BAILEYS}\n` +
                        `⏰ ${formatarDataHora()}`,
                        'conexao'
                    ).then(resultado => {
                        console.log(`${formatarDataHora()} 🔧 Resultado conexão: ${resultado ? '✅ enviada' : '❌ falhou'}`);
                    });
                } catch (error) {
                    console.error(`${formatarDataHora()} ❌ Erro ao enviar notificação de conexão:`, error.message);
                }
            } else {
                console.log(`${formatarDataHora()} 🔧 Notificação de CONEXÃO SUPRIMIDA (reconexão automática após problema de rede)`);
            }
            
            // Resetar flags após conexão bem sucedida
            isReconnecting = false;
            primeiraConexao = false;
            aguardandoQR = false;  // 🔥 IMPORTANTE: Resetar flag de aguardando QR
            
            iniciarIntervalos();
        }

        if (connection === 'close') {
            pararIntervalos();
            setStatus('offline');
            
            const errorMessage = lastDisconnect?.error?.message || '';
            const errorOutput = lastDisconnect?.error?.output || {};
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const agoraMs = Date.now();
            
            console.log(`${formatarDataHora()} 🔌 Desconectado. Código: ${statusCode} - Erro: ${errorMessage}`);
            
        // =====================================================
        // 🔥 CORREÇÃO: PRIORIDADE CORRETA DOS TIPOS DE DESCONEXÃO
        // =====================================================
        
        let motivo = 'Desconexão detectada';
        let deveLimparSessao = false;
        
        // 🔥 PRIORIDADE 1: LOGGED OUT (NÃO pode ser sobrescrito)
        const isLoggedOut = (statusCode === DisconnectReason.loggedOut) ||
                            errorMessage.includes('logged out') ||
                            errorOutput?.statusCode === DisconnectReason.loggedOut ||
                            errorMessage.includes('Stream Errored (conflict)');
        
        if (isLoggedOut) {
            motivo = 'Usuário deslogou do WhatsApp (desconexão solicitada)';
            deveLimparSessao = true;
            aguardandoQR = true;
            reconexaoAposProblemaRede = false;
            foiProblemaRede = false;
            isReconnecting = false;  // 🔥 IMPORTANTE: Resetar para false em loggedOut
            console.log(`${formatarDataHora()} 🔐 LOGGED OUT DETECTADO - Sessão será limpa e novo QR gerado`);
            
            // 🔥 Enviar notificação APENAS para loggedOut (desconexão real da Meta)
            if (!enviouNotificacaoDesconexaoReal) {
                enviouNotificacaoDesconexaoReal = true;
                
                const configNotif = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
                const empresa = configNotif.empresa || 'Bot WhatsApp';
                const numeroAtendente = configNotif.atendente_numero || 'NÃO CONFIGURADO';
                
                enviarNotificacaoTelegram(
                    `⚠️ *WHATSAPP DESCONECTADO*\n\n` +
                    `📱 *Bot:* ${empresa}\n` +
                    `📞 *Número:* ${numeroAtendente}\n` +
                    `🔐 Desconexão real da Meta detectada (LoggedOut).\n` +
                    `📱 *Versão WhatsApp:* ${global.WHATSAPP_VERSION}\n` +
                    `✅ Um novo QR Code será gerado em instantes.\n` +
                    `⏰ ${formatarDataHora()}`,
                    'desconexao'
                );
            } else {
                console.log(`${formatarDataHora()} 🔧 Notificação de DESCONEXÃO REAL suprimida (já enviada)`);
            }
            
            // EXECUTAR AÇÃO DE LIMPEZA E REINICIO
            console.log(`${formatarDataHora()} 🧹 Limpando sessão e credenciais...`);
            await limparSessoesECredenciais();
            
            console.log(`${formatarDataHora()} 📱 Aguardando escaneamento do novo QR Code...`);
            console.log(`${formatarDataHora()} 🔄 O bot será reiniciado para gerar um novo QR Code`);
            
            // Resetar flags
            reconexaoEmAndamento = false;
            tentativasReconexao = 0;
            foiProblemaRede = false;
            
            // Aguardar e reiniciar o bot completamente
            setTimeout(async () => {
                console.log(`${formatarDataHora()} 🔄 Reiniciando bot para gerar novo QR Code...`);
                aguardandoQR = false;
                enviouNotificacaoDesconexaoReal = false;
                isReconnecting = false;
                await startBot();
            }, 3000);
            
            return; // ⚠️ IMPORTANTE: SAIR AQUI para não executar outras condições
        }
        
        // 🔥 PRIORIDADE 2: Problemas de rede - NÃO ENVIAR NENHUMA NOTIFICAÇÃO
        const isNetworkError = (errorMessage.includes('ETIMEDOUT') ||
            errorMessage.includes('ECONNRESET') ||
            errorMessage.includes('ENOTFOUND') ||
            errorMessage.includes('ECONNREFUSED') ||
            errorMessage.includes('socket hang up') ||
            errorMessage.includes('timeout') ||
            errorMessage.includes('network') ||
            errorMessage.includes('getaddrinfo') ||
            errorMessage.includes('WebSocket Error') ||
            errorMessage.includes('Connection Failure') ||
            errorMessage.includes('Connection was lost') ||
            statusCode === 408 ||
            statusCode === 405 ||
            statusCode === 515);
        
        if (isNetworkError) {
           console.log(`${formatarDataHora()} 🌐 PROBLEMA DE REDE/INTERNET - Mantendo sessão`);
           console.log(`${formatarDataHora()} 🔕 NENHUMA NOTIFICAÇÃO TELEGRAM SERÁ ENVIADA (problema de rede)`);
           deveLimparSessao = false;
           foiProblemaRede = true;
           isReconnecting = true;  // Marcar que é reconexão automática
        }
        // Erro de sessão/criptografia
        else if (errorMessage.includes('Bad MAC') || 
                 errorMessage.includes('Failed to decrypt') ||
                 errorMessage.includes('MAC mismatch') ||
                 errorMessage.includes('session')) {
            console.log(`${formatarDataHora()} 🧹 SESSÃO CORROMPIDA - Limpando...`);
            console.log(`${formatarDataHora()} 🔕 NENHUMA NOTIFICAÇÃO TELEGRAM SERÁ ENVIADA (erro de sessão)`);
            deveLimparSessao = true;
        }
        // Conexão substituída
        else if (statusCode === DisconnectReason.connectionReplaced) {
            console.log(`${formatarDataHora()} 🔄 CONEXÃO SUBSTITUÍDA - Sessão será limpa`);
            console.log(`${formatarDataHora()} 🔕 NENHUMA NOTIFICAÇÃO TELEGRAM SERÁ ENVIADA (conexão substituída)`);
            deveLimparSessao = true;
        }
        // Stream Errored (sem ser conflict)
        else if (errorMessage.includes('Stream Errored') && errorMessage.includes('restart required')) {
            console.log(`${formatarDataHora()} 🔌 INSTABILIDADE - Mantendo sessão`);
            console.log(`${formatarDataHora()} 🔕 NENHUMA NOTIFICAÇÃO TELEGRAM SERÁ ENVIADA (instabilidade)`);
            deveLimparSessao = false;
        }
        // Erro desconhecido
        else {
            console.log(`${formatarDataHora()} ⚠️ ERRO DESCONHECIDO - Mantendo sessão`);
            console.log(`${formatarDataHora()} 🔕 NENHUMA NOTIFICAÇÃO TELEGRAM SERÁ ENVIADA (erro desconhecido)`);
            deveLimparSessao = false;
        }
        
        // NENHUMA NOTIFICAÇÃO TELEGRAM PARA PROBLEMAS DE REDE OU OUTROS ERROS
        // Apenas loggedOut envia notificação de desconexão
        
        // AÇÃO BASEADA NO TIPO DE DESCONEXÃO (apenas para não-loggedOut)
        if (deveLimparSessao) {
            console.log(`${formatarDataHora()} 🧹 Limpando sessão e credenciais...`);
            await limparSessoesECredenciais();
            console.log(`${formatarDataHora()} 🔄 Reiniciando bot após limpeza...`);
            setTimeout(async () => {
                isReconnecting = false;
                await startBot();
            }, 2000);
        } else {
            console.log(`${formatarDataHora()} 💾 Mantendo sessão intacta, apenas reconectando...`);
            if (fs.existsSync(AUTH_DIR)) {
                console.log(`${formatarDataHora()} 📁 Sessão mantida em: ${AUTH_DIR}`);
            }
            
            if (!aguardandoQR && !reconexaoEmAndamento) {
                setTimeout(() => {
                    reconectarComSeguranca();
                }, 2000);
            }
        }
     }
    });

    // ============ INÍCIO DO BLOCO MESSAGES ============
    sock.ev.on('messages.upsert', async ({ messages }) => {
        if (!messages || !Array.isArray(messages) || messages.length === 0) return;

        const msg = messages[0];
        
        if (!msg || !msg.message) {
            console.log(`${formatarDataHora()} ⚠️ Mensagem sem conteúdo ignorada`);
            return;
        }
        
        let texto = '';
        try {
            texto = msg.message.conversation || 
                    msg.message.extendedTextMessage?.text || 
                    '';
            texto = texto.trim();
        } catch (error) {
            console.log(`${formatarDataHora()} ⚠️ Erro ao extrair texto:`, error.message);
            texto = '';
        }
        
        const jidInfo = extrairJIDCompleto(msg);
        if (jidInfo.ignore) {
            if (jidInfo.source === 'status') {
                console.log(`${formatarDataHora()} 📱 Visualização de STATUS - IGNORANDO`);
            }
            return;
        }

        const jidRemetente = jidInfo.jid;
        const sourceType = jidInfo.source;

        if (msg.key.fromMe) return;
        if (msg.message.protocolMessage || msg.message.senderKeyDistributionMessage) return;
        if (!jidRemetente) {
            console.error(`${formatarDataHora()} ❌ Não foi possível obter JID do remetente`);
            return;
        }

        const isGroupMessage = jidRemetente.includes('@g.us');
        const isParticipantSource = sourceType === 'participant' || sourceType === 'participant_lid';
        const isBroadcastSource = sourceType === 'broadcast';
        
        if (isGroupMessage || isParticipantSource || isBroadcastSource) {
            console.log(`${formatarDataHora()} 🚫 Mensagem IGNORADA - fonte: ${sourceType}, jid: ${jidRemetente}`);
            return;
        }

        if (sourceType !== 'remote') {
            console.log(`${formatarDataHora()} 🚫 Mensagem ignorada - apenas mensagens diretas (remote) são processadas`);
            return;
        }

        const pushName = msg.pushName || 'Cliente';
        console.log(`\n${formatarDataHora()} 📨 MENSAGEM DE: ${pushName} (${jidRemetente}) [fonte: ${sourceType}] - "${texto}"`);

        const usuario = identificarUsuario(jidRemetente, pushName, texto, false);
        
        if (!usuario) {
            console.log(`${formatarDataHora()} ❌ Usuário não identificado`);
            return;
        }

        const agora = Date.now();
        const ultimoEncerramento = usuario.metadata?.ultimoEncerramento || 0;
        const tempoDesdeEncerramento = agora - ultimoEncerramento;
        
        if (ultimoEncerramento > 0 && tempoDesdeEncerramento < 30000) {
            console.log(`${formatarDataHora()} 🔄 Encerramento recente (${Math.round(tempoDesdeEncerramento/1000)}s) - REENVIANDO MENU`);
            
            if (atendimentos[usuario.primaryKey]) {
                delete atendimentos[usuario.primaryKey];
                console.log(`${formatarDataHora()} 🗑️ Atendimento residual removido`);
            }
            if (contextos[usuario.primaryKey]) {
                delete contextos[usuario.primaryKey];
                console.log(`${formatarDataHora()} 🗑️ Contexto residual removido`);
            }
            
            if (usuario.metadata) {
                delete usuario.metadata.ultimoEncerramento;
                salvarUsuarios();
                console.log(`${formatarDataHora()} 🗑️ Marca de encerramento removida`);
            }
            
            await enviarMenuPrincipal(sock, usuario, texto);
            return;
        }

        const atendimentoExistente = atendimentos[usuario.primaryKey];
        
        if (!atendimentoExistente) {
            atendimentos[usuario.primaryKey] = {
                tipo: 'menu',
                inicio: Date.now(),
                ultimaAtividade: Date.now(),
                usuarioPrimaryKey: usuario.primaryKey
            };
            console.log(`${formatarDataHora()} 📋 NOVO atendimento criado para ${usuario.pushName}`);
        } else {
            atendimentos[usuario.primaryKey].ultimaAtividade = Date.now();
            console.log(`${formatarDataHora()} 📋 Atendimento existente atualizado para ${usuario.pushName}`);
        }

        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        const isAtendente = usuario.tipo === 'atendente';
        
        if (isAtendente) {
            console.log(`${formatarDataHora()} 👨‍💼 Mensagem do atendente: ${texto}`);
            
            if (texto.toUpperCase() === '#STATUS' || texto.toUpperCase() === '#RELATORIO') {
                const relatorio = gerarRelatorioSistema();
                const mensagem = 
`📊 *RELATÓRIO DO SISTEMA v${relatorio.versao}*
📱 *Baileys:* ${relatorio.versao_baileys}
📱 *WhatsApp:* ${relatorio.versao_whatsapp} (${relatorio.versao_completa})
⏰ ${formatarDataHora()}

👥 *USUÁRIOS*
Total: ${relatorio.estatisticas.usuarios.total}
├─ LIDs: ${relatorio.estatisticas.usuarios.comLID}
├─ Com número: ${relatorio.estatisticas.usuarios.comNumero}
├─ Apenas LID: ${relatorio.estatisticas.usuarios.apenasLID}
└─ Stable IDs: ${relatorio.estatisticas.usuarios.comStableId}

🟢 *ATENDIMENTOS ATIVOS*
Total: ${relatorio.estatisticas.atendimentos.ativos}

🔍 *NOVOS FORMATOS*
${relatorio.estatisticas.formatosDetectados} registro(s)`;

                await enviarMensagemParaUsuario(sock, usuario, mensagem);
                return;
            }
            
            if (texto.toUpperCase() === '#VERSAO' || texto.toUpperCase() === '#VERSION') {
                const mensagem = 
`📱 *VERSÃO DO WHATSAPP*

📌 *Versão completa:* ${global.WHATSAPP_VERSION_COMPLETA || 'desconhecida'}
📌 *Versão numérica:* ${global.WHATSAPP_VERSION}
📱 *Baileys:* ${global.VERSAO_BAILEYS}
⏰ ${formatarDataHora()}

✅ O bot está usando a versão recomendada pela biblioteca.`;

                await enviarMensagemParaUsuario(sock, usuario, mensagem);
                return;
            }
            
            return;
        }

        let chaveAtendimento = usuario.primaryKey;
        const contextoAtual = contextos[chaveAtendimento] || 'menu';
        
        console.log(`${formatarDataHora()} 🔢 ${pushName} -> ${usuario.primaryKey} (${usuario.tipo})`);
        console.log(`${formatarDataHora()} 📊 Contexto atual: ${contextoAtual}`);

        if (texto === '0') {
            console.log(`${formatarDataHora()} 🔄 Cliente digitou "0" - contexto: ${contextoAtual}`);
            
            if (contextoAtual === 'pos_pix' || contextoAtual === 'em_atendimento' || contextoAtual === 'aguardando_cpf' || contextoAtual === 'planos') {
                console.log(`${formatarDataHora()} 🚪 Encerrando atendimento por comando do cliente`);
                await encerrarAtendimento(usuario, config, "cliente", chaveAtendimento);
                return;
            } else {
                console.log(`${formatarDataHora()} ℹ️ Comando "0" ignorado - não está em contexto de atendimento`);
                await enviarMenuPrincipal(sock, usuario, texto);
                return;
            }
        }

        if (texto === '9') {
            console.log(`${formatarDataHora()} 🔄 Cliente digitou "9" - voltando ao menu`);
            
            contextos[chaveAtendimento] = 'menu';
            
            if (atendimentos[chaveAtendimento]) {
                atendimentos[chaveAtendimento].tipo = 'menu';
                atendimentos[chaveAtendimento].ultimaAtividade = Date.now();
            }
            
            await enviarMenuPrincipal(sock, usuario, texto);
            return;
        }

        if (contextoAtual === 'menu') {
            const atendimento = atendimentos[chaveAtendimento];
            const primeiraInteracao = atendimento && 
                                      (atendimento.ultimaAtividade - atendimento.inicio) < 2000;
            
            if (primeiraInteracao) {
                console.log(`${formatarDataHora()} 📋 Primeira interação - enviando menu`);
                await enviarMenuPrincipal(sock, usuario, texto);
                return;
            }
            
            if (texto === '1') {
                console.log(`${formatarDataHora()} 💠 Cliente escolheu PIX`);
                contextos[chaveAtendimento] = 'aguardando_cpf';
                atendimentos[chaveAtendimento].tipo = 'aguardando_cpf';
                await enviarMensagemParaUsuario(sock, usuario, `🔐 Informe seu CPF ou CNPJ:`);
                return;
                
            } else if (texto === '2') {
                console.log(`${formatarDataHora()} 👨‍💼 Cliente escolheu atendimento`);
                
                if (!dentroHorarioComercial()) {
                    console.log(`${formatarDataHora()} ⏰ Fora do horário comercial ou feriado`);
                    
                    const hoje = new Date();
                    const ehFeriadoHoje = ehFeriado(hoje);
                    const ehFeriadoLocalHoje = ehFeriadoLocal();
                    
                    let mensagemErro = `⏰ *${pushName}*, `;
                    
                    if (ehFeriadoHoje) {
                        mensagemErro += `hoje é feriado nacional.\n\n`;
                    } else if (ehFeriadoLocalHoje) {
                        mensagemErro = getMensagemFeriadoLocal() + `\n\n`;
                    } else if (hoje.getDay() === 0) {
                        mensagemErro += `hoje é domingo.\n\n`;
                    } else {
                        mensagemErro += `por favor, retorne seu contato em *horário comercial*.\n\n`;
                    }
                    
                    if (!ehFeriadoLocalHoje) {
                        mensagemErro += `${formatarHorarioComercial()}`;
                    }
                    
                    mensagemErro += `1️⃣  Para Fatura  |  3️⃣  Não sou Cliente  |  9️⃣  Retornar ao Menu`;
                    
                    await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                    return;
                }
                
                const tempoTimeout = config.tempo_atendimento_humano || 5;
                atendimentos[chaveAtendimento].tipo = 'humano';
                atendimentos[chaveAtendimento].timeout = Date.now() + (tempoTimeout * 60 * 1000);
                contextos[chaveAtendimento] = 'em_atendimento';
                
                console.log(`${formatarDataHora()} ⏱️ Atendimento iniciado (${tempoTimeout}min)`);
                
                await enviarMensagemParaUsuario(sock, usuario, 
                    `👨‍💼 *ATENDIMENTO INICIADO*\n\n*${pushName}*, um atendente falará com você em instantes, aguarde...\n\n⏱️ Duração: ${tempoTimeout} minutos\n\n 0️⃣ Encerrar Atendimento`
                );
                return;
                
            } else if (texto === '3') {
                console.log(`${formatarDataHora()} 📶 Cliente escolheu "Não sou Cliente!"`);
                
                if (config.planos_ativos !== 'Sim') {
                    console.log(`${formatarDataHora()} ⚠️ Planos desativados no config.json`);
                    await enviarMensagemParaUsuario(sock, usuario, 
                        `❌ Desculpe, a consulta de planos está temporariamente indisponível.\n\n9️⃣ Retornar ao Menu`
                    );
                    return;
                }
                
                const planosMensagem = config.planos_mensagem || 
                    `📶 *100 megas* 💰 R$ 59,90 - FIBRA\n📶 *200 megas* 💰 R$ 69,90 - FIBRA\n📶 *300 megas* 💰 R$ 89,90 - FIBRA\n\n*Taxa de instalação* 💰 R$ 50,00 à vista ou R$ 60,00 no cartão em 2x.\n\n*Tá esperando o que?* 😱\n\n2️⃣ Falar com um Atendente    5️⃣ Assine Já!`;
                
                contextos[chaveAtendimento] = 'planos';
                atendimentos[chaveAtendimento].tipo = 'planos';
                
                await enviarMensagemParaUsuario(sock, usuario, planosMensagem);
                return;
                
            } else if (texto === '0' || texto === '9') {
                console.log(`${formatarDataHora()} ℹ️ Comando ${texto} já deveria ser tratado`);
                return;
                
            } else {
                console.log(`${formatarDataHora()} 🤐 Mensagem ignorada - comando inválido no menu: "${texto}"`);
                
                if (atendimentos[chaveAtendimento]) {
                    atendimentos[chaveAtendimento].ultimaAtividade = Date.now();
                }
                return;
            }
        }

        if (contextoAtual === 'planos') {
            console.log(`${formatarDataHora()} 📶 Contexto planos ATIVADO`);
            
            if (atendimentos[chaveAtendimento]) {
                atendimentos[chaveAtendimento].ultimaAtividade = Date.now();
            }
            
            if (texto === '2') {
                console.log(`${formatarDataHora()} 👨‍💼 Cliente escolheu atendimento após ver planos`);
                
                if (!dentroHorarioComercial()) {
                    console.log(`${formatarDataHora()} ⏰ Fora do horário comercial ou feriado`);
                    
                    const hoje = new Date();
                    const ehFeriadoHoje = ehFeriado(hoje);
                    const ehFeriadoLocalHoje = ehFeriadoLocal();
                    
                    let mensagemErro = `⏰ *${pushName}*, `;
                    
                    if (ehFeriadoHoje) {
                        mensagemErro += `hoje é feriado nacional.\n\n`;
                    } else if (ehFeriadoLocalHoje) {
                        mensagemErro = getMensagemFeriadoLocal() + `\n\n`;
                    } else if (hoje.getDay() === 0) {
                        mensagemErro += `hoje é domingo.\n\n`;
                    } else {
                        mensagemErro += `por favor, retorne seu contato em *horário comercial*.\n\n`;
                    }
                    
                    if (!ehFeriadoLocalHoje) {
                        mensagemErro += `${formatarHorarioComercial()}`;
                    }
                    
                    mensagemErro += `1️⃣  Para Fatura  |  3️⃣  Ver Planos  |  9️⃣  Retornar ao Menu`;
                    
                    contextos[chaveAtendimento] = 'menu';
                    await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                    return;
                }
                
                const tempoTimeout = config.tempo_atendimento_humano || 5;
                atendimentos[chaveAtendimento].tipo = 'humano';
                atendimentos[chaveAtendimento].timeout = Date.now() + (tempoTimeout * 60 * 1000);
                contextos[chaveAtendimento] = 'em_atendimento';
                
                console.log(`${formatarDataHora()} ⏱️ Atendimento humano iniciado após planos (${tempoTimeout}min)`);
                
                await enviarMensagemParaUsuario(sock, usuario, 
                    `👨‍💼 *ATENDIMENTO INICIADO*\n\n*${pushName}*, um atendente falará com você em instantes, aguarde...\n\n⏱️ Duração: ${tempoTimeout} minutos\n\n 0️⃣ Encerrar Atendimento`
                );
                return;
                
            } else if (texto === '5') {
                console.log(`${formatarDataHora()} 🔗 Cliente escolheu "Assine Já!"`);
                
                const linkAssinatura = config.link_assinatura || 'https://www.weblinetelecom.com.br/cadastro.hhvm';
                
                await enviarMensagemParaUsuario(sock, usuario, 
                    `🔗 *LINK PARA ASSINATURA*\n\nAcesse o link abaixo para realizar sua assinatura:\n\n${linkAssinatura}\n\nApós a assinatura, entre em contato para ativação.\n\n9️⃣ Retornar ao Menu`
                );
                
                contextos[chaveAtendimento] = 'menu';
                atendimentos[chaveAtendimento].tipo = 'menu';
                return;
                
            } else if (texto === '9') {
                console.log(`${formatarDataHora()} 🔄 Cliente voltou ao menu a partir dos planos`);
                
                contextos[chaveAtendimento] = 'menu';
                atendimentos[chaveAtendimento].tipo = 'menu';
                await enviarMenuPrincipal(sock, usuario, texto);
                return;
                
            } else {
                console.log(`${formatarDataHora()} 📶 Cliente enviou texto inválido no contexto planos: "${texto}"`);
                
                const planosMensagem = config.planos_mensagem || 
                    `📶 *100 megas* 💰 R$ 59,90 - FIBRA\n📶 *200 megas* 💰 R$ 69,90 - FIBRA\n📶 *300 megas* 💰 R$ 89,90 - FIBRA\n\n*Taxa de instalação* 💰 R$ 50,00 à vista ou R$ 60,00 no cartão em 2x.\n\n*Tá esperando o que?* 😱\n\n2️⃣ Falar com um Atendente    5️⃣ Assine Já!`;
                
                await enviarMensagemParaUsuario(sock, usuario, planosMensagem + '\n\n(Use 2 para Atendente, 5 para Assinar ou 9 para Voltar)');
                return;
            }
        }

        if (contextoAtual === 'aguardando_cpf') {
            console.log(`${formatarDataHora()} 📄 Contexto aguardando_cpf ATIVADO`);
            
            if (atendimentos[chaveAtendimento]) {
                atendimentos[chaveAtendimento].ultimaAtividade = Date.now();
            }
            
            if (texto === '1' || texto === '2') {
                console.log(`${formatarDataHora()} 📄 Comando detectado: ${texto}`);
                
                if (texto === '2') {
                    console.log(`${formatarDataHora()} 👨‍💼 Cliente escolheu atendimento após erro no CPF`);
                    
                    if (!dentroHorarioComercial()) {
                        console.log(`${formatarDataHora()} ⏰ Fora do horário comercial ou feriado`);
                        
                        const hoje = new Date();
                        const ehFeriadoHoje = ehFeriado(hoje);
                        const ehFeriadoLocalHoje = ehFeriadoLocal();
                        
                        let mensagemErro = `⏰ *${pushName}*, `;
                        
                        if (ehFeriadoHoje) {
                            mensagemErro += `hoje é feriado nacional.\n\n`;
                        } else if (ehFeriadoLocalHoje) {
                            mensagemErro = getMensagemFeriadoLocal() + `\n\n`;
                        } else if (hoje.getDay() === 0) {
                            mensagemErro += `hoje é domingo.\n\n`;
                        } else {
                            mensagemErro += `por favor, retorne seu contato em *horário comercial*.\n\n`;
                        }
                        
                        if (!ehFeriadoLocalHoje) {
                            mensagemErro += `${formatarHorarioComercial()}`;
                        }
                        
                        mensagemErro += `1️⃣  Para Fatura  |  3️⃣  Não sou Cliente  |  9️⃣  Retornar ao Menu`;
                        
                        await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                        return;
                    }
                    
                    const tempoTimeout = config.tempo_atendimento_humano || 5;
                    atendimentos[chaveAtendimento] = {
                        tipo: 'humano',
                        inicio: Date.now(),
                        ultimaAtividade: Date.now(),
                        timeout: Date.now() + (tempoTimeout * 60 * 1000),
                        usuarioPrimaryKey: usuario.primaryKey
                    };
                    contextos[chaveAtendimento] = 'em_atendimento';
                    
                    console.log(`${formatarDataHora()} ⏱️ Atendimento humano iniciado após erro CPF (${tempoTimeout}min)`);
                    
                    await enviarMensagemParaUsuario(sock, usuario, 
                        `👨‍💼 *ATENDIMENTO INICIADO*\n\n*${pushName}*, um atendente falará com você em instantes, aguarde...\n\n⏱️ Duração: ${tempoTimeout} minutos\n\n 0️⃣ Encerrar Atendimento`
                    );
                    return;
                } else if (texto === '1') {
                    await enviarMensagemParaUsuario(sock, usuario, `🔐 Informe seu CPF ou CNPJ:`);
                    return;
                }
            }
            
            const doc = limparDoc(texto);
            console.log(`${formatarDataHora()} 📄 Documento após limpar: "${doc}"`);
            
            const temApenasNumeros = /^\d+$/.test(doc);
            
            if ((doc.length === 11 || doc.length === 14) && temApenasNumeros) {
                console.log(`${formatarDataHora()} 📄 ✅ DOCUMENTO VÁLIDO DETECTADO!`);
                
                try {
                    await enviarMensagemParaUsuario(sock, usuario, 
                        `🔍 Verificando ${doc.length === 11 ? 'CPF' : 'CNPJ'} ${doc} na base de clientes...`
                    );
                    
                    const resultado = await verificarClienteMKAuth(doc);
                    
                    if (!resultado.sucesso) {
                        console.log(`${formatarDataHora()} 📄 ❌ Documento não encontrado ou inativo: ${doc}`);
                        
                        let mensagemErro = `❌ *`;
                        
                        if (resultado.ativo === false) {
                            mensagemErro += `${doc.length === 11 ? 'CPF' : 'CNPJ'} com cadastro inativo*\n\n`;
                            mensagemErro += `O ${doc.length === 11 ? 'CPF' : 'CNPJ'} *${doc}* está com o cadastro *INATIVO*.\n\n`;
                            mensagemErro += `*Favor entrar em contato com o Atendente.*\n\n`;
                            mensagemErro += `2️⃣  Falar com Atendente  |  9️⃣  Retornar ao Menu`;
                            
                            await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                            return;
                        } else if (resultado.existe === false) {
                            mensagemErro += `${doc.length === 11 ? 'CPF' : 'CNPJ'} não encontrado*\n\n`;
                            mensagemErro += `O ${doc.length === 11 ? 'CPF' : 'CNPJ'} *${doc}* não foi encontrado na base de clientes da *${config.empresa}*.\n\n`;
                        } else if (resultado.temFaturas === false) {
                            mensagemErro += `Cliente sem faturas*\n\n`;
                            mensagemErro += `Cliente encontrado, mas não há faturas disponíveis.\n\n`;
                        } else if (resultado.temPix === false) {
                            mensagemErro += `Cliente sem PIX*\n\n`;
                            mensagemErro += `Cliente encontrado, mas não há faturas para pagamento via PIX.\n\n`;
                        } else {
                            mensagemErro += `${resultado.mensagem}*\n\n`;
                        }
                        
                        mensagemErro += `Verifique se o ${doc.length === 11 ? 'CPF' : 'CNPJ'} está correto ou entre em contato com nosso atendimento.\n\n`;
                        mensagemErro += `1️⃣  Tentar outro ${doc.length === 11 ? 'CPF' : 'CNPJ'}  |  2️⃣  Falar com Atendente  |  9️⃣  Retornar ao Menu`;
                        
                        await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                        return;
                    }
                    
                    console.log(`${formatarDataHora()} 📄 ✅ Documento válido no MK-Auth! Gerando link...`);
                    
                    let mensagemPix = '';
                    
                    if (resultado.ativo === false) {
                        mensagemPix = `⚠️ *ATENÇÃO: Cadastro INATIVO*\n\n` +
                                     `Seu cadastro está *INATIVO* na *${config.empresa}*.\n\n` +
                                     `Você possui faturas em aberto que precisam ser pagas.\n\n` +
                                     `🔍 ${doc.length === 11 ? 'CPF' : 'CNPJ'} encontrado!\n\n` +
                                     `${doc.length === 11 ? '👤 Nome' : '🏢 Nome/Razão Social'}: ${resultado.nome_cliente || 'Não disponível'}\n\n` +
                                     `🔗 Clique no link abaixo para acessar suas faturas PIX:\n\n` +
                                     `${config.boleto_url}?doc=${doc}\n\n` +
                                     `⏱️ *Link válido por 10 minutos*\n\n` +
                                     `0️⃣  Encerrar  |  9️⃣  Retornar ao Menu`;
                    } else {
                        mensagemPix = `✅ *${doc.length === 11 ? 'CPF' : 'CNPJ'} encontrado!*\n\n` +
                                     `${doc.length === 11 ? '👤 Nome' : '🏢 Nome/Razão Social'}: ${resultado.nome_cliente || 'Não disponível'}\n\n` +
                                     `Clique no link abaixo para acessar sua fatura PIX:\n\n` +
                                     `🔗 ${config.boleto_url}?doc=${doc}\n\n` +
                                     `⏱️ *Link válido por 10 minutos*\n\n` +
                                     `0️⃣  Encerrar  |  9️⃣  Retornar ao Menu`;
                    }
                    
                    const resultadoEnvio = await enviarMensagemParaUsuario(sock, usuario, mensagemPix);
                    
                    if (resultadoEnvio) {
                        console.log(`${formatarDataHora()} 📄 ✅ Mensagem PIX enviada com sucesso!`);
                        
                        atendimentos[chaveAtendimento] = {
                            tipo: 'pos_pix',
                            inicio: Date.now(),
                            ultimaAtividade: Date.now(),
                            usuarioPrimaryKey: usuario.primaryKey
                        };
                        
                        contextos[chaveAtendimento] = 'pos_pix';
                    } else {
                        console.log(`${formatarDataHora()} 📄 ❌ Falha ao enviar mensagem PIX!`);
                        await enviarMensagemParaUsuario(sock, usuario, 
                            `❌ Ocorreu um erro ao gerar o link. Tente novamente.`
                        );
                    }
                    
                } catch (error) {
                    console.error(`${formatarDataHora()} 📄 ❌ ERRO:`, error);
                    await enviarMensagemParaUsuario(sock, usuario, 
                        `❌ Erro ao consultar ${doc.length === 11 ? 'CPF' : 'CNPJ'}. Tente novamente em alguns instantes.\n\n2️⃣  Falar com Atendente  |  9️⃣  Retornar ao Menu`
                    );
                }
                return;
                
            } else {
                console.log(`${formatarDataHora()} 📄 ❌ DOCUMENTO INVÁLIDO`);
                
                try {
                    let mensagemErro = `❌ ${pushName}, formato inválido.\n\n`;
                    
                    if (doc.length > 0 && !temApenasNumeros) {
                        mensagemErro += `⚠️ Contém caracteres inválidos.\n`;
                    }
                    
                    mensagemErro += `\n📋 *Formatos aceitos:*\n`;
                    mensagemErro += `• CPF: 11 dígitos (ex: 12345678901)\n`;
                    mensagemErro += `• CNPJ: 14 dígitos (ex: 12345678000199)\n\n`;
                    mensagemErro += `Digite novamente:\n\n`;
                    mensagemErro += `2️⃣  Falar com Atendente  |  9️⃣  Retornar ao Menu`;
                    
                    await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                    
                } catch (error) {
                    console.error(`${formatarDataHora()} 📄 ❌ ERRO ao enviar mensagem de erro:`, error);
                }
            }
            
            return;
        }

        if (contextoAtual === 'pos_pix') {
            if (atendimentos[chaveAtendimento]) {
                atendimentos[chaveAtendimento].ultimaAtividade = Date.now();
            }
            
            await enviarMensagemParaUsuario(sock, usuario, 
                `PIX já gerado. Acesse o link enviado anteriormente.\n\n⏱️ *Link válido por 10 minutos*\n\n0️⃣  Encerrar  |  9️⃣  Retornar ao Menu`
            );
            return;
        }

        if (contextoAtual === 'em_atendimento') {
            console.log(`${formatarDataHora()} 🤐 Cliente em atendimento humano`);
            
            if (atendimentos[chaveAtendimento]) {
                atendimentos[chaveAtendimento].ultimaAtividade = Date.now();
                const tempoTimeout = (config.tempo_atendimento_humano || 5) * 60 * 1000;
                atendimentos[chaveAtendimento].timeout = Date.now() + tempoTimeout;
                console.log(`${formatarDataHora()} ⏰ Timeout renovado para ${pushName}`);
            }
            return;
        }
        
        await enviarMenuPrincipal(sock, usuario, texto);
    });
}

// ================= INICIALIZAÇÃO =================

console.log('\n' + '='.repeat(70));
console.log('🤖 BOT WHATSAPP - VERSÃO LID-PROOF ULTRA v8.1');
console.log('✅ 100% AGNÓSTICO A NÚMERO');
console.log('✅ LID como tipo próprio');
console.log('✅ Primary Key universal com Stable ID');
console.log('✅ Versionamento automático');
console.log('✅ Suporte a JID criptografado rotativo');
console.log('✅ Gerenciamento profissional de intervalos');
console.log('✅ Health check e debug integrado');
console.log('✅ Pronto para futuras mudanças da Meta');
console.log('🆕 SISTEMA UNIFICADO DE TIMEOUT v3.0');
console.log('🆕 FILTRO DE MENSAGENS v3.1');
console.log('🆕 FERIADO LOCAL PERSONALIZÁVEL v4.0');
console.log('🆕 NOTIFICAÇÕES TELEGRAM v5.0');
console.log('🆕 DETECÇÃO AUTOMÁTICA DE VERSÃO v6.0');
console.log('🆕 OPÇÃO 3 - NÃO SOU CLIENTE v7.0');
console.log('🆕 CORREÇÃO DE DESCONEXÃO v8.1');
console.log('   • Diferencia problemas de rede de desconexão real da Meta');
console.log('   • Mantém sessão em caso de queda de internet');
console.log('   • Após loggedOut, NÃO reconecta automaticamente (aguarda QR)');
console.log('   • ✅ NOTIFICAÇÕES TELEGRAM APENAS PARA CONEXÕES REAIS');
console.log('   • ✅ NENHUMA NOTIFICAÇÃO para problemas de rede');
console.log('   • ✅ NENHUMA NOTIFICAÇÃO para reconexões automáticas');
console.log('='.repeat(70));
console.log('🚀 INICIANDO BOT...');
console.log('='.repeat(70));
console.log('📌 Comandos disponíveis:');
console.log('   node bot.js              - Inicia normalmente');
console.log('   node bot.js --clear-auth - Limpa sessões corrompidas');
console.log('   node bot.js --help       - Mostra ajuda');
console.log('='.repeat(70));

// Verificar dependências
try {
    require('@whiskeysockets/baileys');
} catch (error) {
    console.error('❌ Erro: @whiskeysockets/baileys não encontrado!');
    console.error('   Execute: npm install @whiskeysockets/baileys');
    process.exit(1);
}

// ================= HANDLERS DE ENCERRAMENTO =================
process.on('SIGINT', () => {
    console.log(`${formatarDataHora()} 👋 Bot encerrado pelo usuário (SIGINT)`);
    pararIntervalos();
    setStatus('offline');
    process.exit(0);
});

process.on('SIGTERM', () => {
    console.log(`${formatarDataHora()} 👋 Bot encerrado (SIGTERM)`);
    pararIntervalos();
    setStatus('offline');
    process.exit(0);
});

// Iniciar o bot
startBot().catch(error => {
    console.error(`${formatarDataHora()} ❌ Erro fatal:`, error);
    setTimeout(() => {
        console.log(`${formatarDataHora()} 🔄 Reiniciando bot em 5 segundos...`);
        setTimeout(() => startBot(), 5000);
    }, 3000);
});

// Tratamento de exceções
process.on('uncaughtException', (error) => {
    console.error(`${formatarDataHora()} 🚨 EXCEÇÃO NÃO CAPTURADA:`, error.message);
    
    if (error.message.includes('Bad MAC') || error.message.includes('session')) {
        console.log(`${formatarDataHora()} 🔧 Detectado erro de sessão, limpando...`);
        limparSessoesECredenciais().then(() => {
            setTimeout(() => startBot(), 5000);
        });
    }
});

process.on('unhandledRejection', (reason, promise) => {
    console.error(`${formatarDataHora()} 🚨 PROMISE REJEITADA NÃO TRATADA:`, reason);
});
