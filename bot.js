/*************************************************
 * ✅ BOT WHATSAPP - ÍNICIO DO PROJETO EM ‎Segunda-feira, ‎2‎ de ‎fevereiro‎ de ‎2026, ‏‎19:12:50 por MIRANILDO DE LIMA SANTOS
 * ✅ BOT WHATSAPP - VERSÃO COMPLETA COM FERIADOS
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
 * ✅ ADICIONADO: Timeout automático para tela PIX (10 minutos)
 * ✅ ADICIONADO: Comandos #FECHAR [número] e #FECHAR [nome] para encerrar individualmente
 * ✅ ADICIONADO: Comando #CLIENTES para listar atendimentos ativos
 * ✅ CORRIGIDO: Bot NÃO responde em grupos - apenas individualmente
 * ✅ ADICIONADO: Verificação MK-Auth para CPF/CNPJ existentes antes de gerar link PIX
 * ✅ ATUALIZADO: Credenciais MK-Auth configuráveis via painel web
 * ✅ CORRIGIDO: Não gera link se credenciais não estiverem configuradas
 * ✅ CORRIGIDO: "Para Fatura" fora do horário e "Tentar outro CPF" agora vão para tela CPF
 * ✅ ATUALIZADO: Permite cliente inativo COM fatura em aberto acessar PIX normalmente
 * ✅ ADICIONADO: Exibe nome do cliente quando CPF/CNPJ é encontrado
 * ✅ BOT WHATSAPP - VERSÃO LID-PROOF CORRIGIDA
 * ✅ CORRIGIDO: Loop de timeout para usuários individuais
 * ✅ MANTIDO: Todas mensagens do fluxo original
 * ✅ CORRIGIDO: Sistema de encerramento completo
 * ✅ CORRIGIDO: Apenas status@broadcast ignorado
 * ✅ CORRIGIDO: Clientes @lid e @broadcast atendidos
 *************************************************/

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason
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

// ESTRUTURAS GLOBAIS ATUALIZADAS
const atendimentos = {};
const contextos = {};
let sockInstance = null;

// NOVA ESTRUTURA DE USUÁRIOS
let usuarios = {
    byId: {},
    byWhatsappId: {},
    byNumero: {}
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

// FERIADOS FIXOS
const FERIADOS_NACIONAIS = [
    '01-01', // Ano Novo
    '04-21', // Tiradentes
    '05-01', // Dia do Trabalho
    '09-07', // Independência
    '10-12', // Nossa Senhora Aparecida
    '11-02', // Finados
    '11-15', // Proclamação da República
    '12-25', // Natal
];

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
            'pre-key.txt',
            'session.txt',
            'sender-key.txt',
            'app-state-sync-key.txt',
            'app-state-sync-version.txt'
        ];
        
        for (const arquivo of arquivosParaLimpar) {
            const caminhoArquivo = path.join(BASE_DIR, arquivo);
            if (fs.existsSync(caminhoArquivo)) {
                try {
                    fs.unlinkSync(caminhoArquivo);
                    console.log(`${formatarDataHora()} ✅ Removido: ${arquivo}`);
                } catch (err) {
                    console.error(`${formatarDataHora()} ⚠️ Erro ao remover ${arquivo}:`, err.message);
                }
            }
        }
        
        if (fs.existsSync(QR_PATH)) {
            fs.unlinkSync(QR_PATH);
            console.log(`${formatarDataHora()} ✅ QR Code antigo removido`);
        }
        
        setStatus('offline');
        
        await new Promise(resolve => setTimeout(resolve, 2000));
        
        console.log(`${formatarDataHora()} 🎉 LIMPEZA CONCLUÍDA!`);
        console.log(`${formatarDataHora()} 🔄 Reinicie o bot para gerar novo QR Code`);
        
        return true;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro na limpeza:`, error);
        return false;
    }
}

// ================= CLASSE WHATSAPP IDENTITY =================
class WhatsAppIdentity {
    constructor(rawJid) {
        this.raw = rawJid || '';
        this.normalized = this.normalizeJID(rawJid);
        this.type = this.detectType();
        this.internalId = this.generateInternalId();
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
        
        if (jid.includes('@g.us')) return 'group';
        if (jid.includes('@lid') || jid.includes('@broadcast')) return 'broadcast';
        if (jid.includes('@s.whatsapp.net')) return 'individual';
        
        if (jid.includes('@')) {
            console.log(`${formatarDataHora()} 🔍 NOVO TIPO DE JID DETECTADO: ${jid}`);
            this.logNovoFormato();
            return 'new_format';
        }
        
        return 'unknown';
    }
    
    generateInternalId() {
        if (!this.raw) return null;
        const hash = crypto.createHash('sha256')
            .update(this.raw)
            .digest('hex')
            .substring(0, 16);
        return `wa_${hash}`;
    }
    
    determineSendCapability() {
        return {
            individual: this.type === 'individual',
            broadcast: this.type === 'broadcast',
            group: this.type === 'group',
            new_format: this.type === 'new_format',
            canSend: ['individual', 'broadcast'].includes(this.type),
            canReceive: true
        };
    }
    
    extractPhoneNumber() {
        if (this.type !== 'individual') return null;
        
        try {
            let numero = this.normalized.identifier;
            
            if (numero.includes(':')) {
                numero = numero.split(':')[0];
            }
            
            numero = numero.replace(/\D/g, '');
            
            if (numero.length >= 10 && numero.length <= 13) {
                if (!numero.startsWith('55')) {
                    numero = '55' + numero;
                }
                return numero;
            }
            
            return null;
        } catch (error) {
            console.error(`${formatarDataHora()} ❌ Erro ao extrair número:`, error);
            return null;
        }
    }
    
    getSendJID() {
        if (!this.raw) return null;

        if (this.type === 'individual') {
            return this.raw;
        }

        if (this.type === 'broadcast') {
            console.log(`${formatarDataHora()} ⚠️ Usando JID de broadcast: ${this.raw}`);
            return this.raw;
        }

        if (this.type === 'new_format') {
            console.log(`${formatarDataHora()} ⚠️ Tentativa de envio para new_format: ${this.raw}`);
            return null;
        }

        return null;
    }
    
    logNovoFormato() {
        const novidade = {
            timestamp: new Date().toISOString(),
            jid: this.raw,
            tipo: 'novo_formato',
            normalized: this.normalized,
            domain: this.normalized.domain,
            internalId: this.internalId
        };
        
        formatosDetectados.push(novidade);
        fs.appendFileSync(MUDANCAS_LOG_PATH, JSON.stringify(novidade, null, 2) + '\n---\n');
        
        console.warn(`${formatarDataHora()} ⚠️ NOVO FORMATO DETECTADO!`);
        console.warn(`${formatarDataHora()} JID: ${this.raw}`);
        console.warn(`${formatarDataHora()} Domínio: ${this.normalized.domain}`);
        console.warn(`${formatarDataHora()} Internal ID: ${this.internalId}`);
    }
}

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
                console.log(`${formatarDataHora()} 🗑️ Removido: ${file}`);
            }
            fs.rmdirSync(AUTH_DIR);
            console.log(`${formatarDataHora()} ✅ Pasta auth_info removida com sucesso!`);
            return true;
        } else {
            console.log(`${formatarDataHora()} ℹ️ Pasta auth_info não existe`);
            return false;
        }
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao limpar auth_info:`, error);
        return false;
    }
}

function extrairNumeroDoJID(jid) {
    try {
        const identity = new WhatsAppIdentity(jid);
        return identity.extractPhoneNumber();
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro em extrairNumeroDoJID:`, error);
        return null;
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
            return `${numeroFormatado}@s.whatsapp.net`;
        }
    }
    
    return null;
}

function atualizarAtendenteNoConfig(numeroAtendente) {
    try {
        console.log(`${formatarDataHora()} ⚙️ Atualizando número do atendente no config.json: ${numeroAtendente}`);
        const configAtual = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
        const numeroAnterior = configAtual.atendente_numero || 'não definido';
        configAtual.atendente_numero = numeroAtendente;
        fs.writeFileSync(CONFIG_PATH, JSON.stringify(configAtual, null, 2));
        console.log(`${formatarDataHora()} ✅ Número do atendente atualizado: ${numeroAnterior} → ${numeroAtendente}`);
        return true;
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao atualizar config.json:`, error);
        return false;
    }
}

function ehFeriado(data = new Date()) {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        if (config.feriados_ativos !== 'Sim') {
            return false;
        }
        const diaMes = formatarData(data);
        if (FERIADOS_NACIONAIS.includes(diaMes)) {
            console.log(`${formatarDataHora()} 🎉 Hoje é feriado nacional: ${diaMes}`);
            return true;
        }
        return false;
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao verificar feriado:`, error);
        return false;
    }
}

function dentroHorarioComercial() {
    const d = new Date();
    const dia = d.getDay();
    const h = d.getHours() + d.getMinutes() / 60;

    if (ehFeriado(d)) {
        return false;
    }

    if (dia === 0) return false;
    
    if (dia >= 1 && dia <= 5) {
        return (h >= 8 && h < 12) || (h >= 14 && h < 18);
    }
    
    if (dia === 6) {
        return (h >= 8 && h < 12);
    }
    
    return false;
}

// ================= GESTÃO DE USUÁRIOS =================
function adicionarUsuario(usuario) {
    if (!usuario || !usuario.id) {
        console.error(`${formatarDataHora()} ❌ Tentativa de adicionar usuário sem ID`);
        return false;
    }
    
    try {
        usuarios.byId[usuario.id] = usuario;
        
        if (usuario.whatsappId) {
            usuarios.byWhatsappId[usuario.whatsappId] = usuario.id;
        }
        
        if (usuario.numero && typeof usuario.numero === 'string' && usuario.numero.length >= 10) {
            usuarios.byNumero[usuario.numero] = usuario.id;
        }
        
        console.log(`${formatarDataHora()} ✅ Usuário adicionado: ${usuario.pushName || 'Sem nome'} (ID: ${usuario.id})`);
        return true;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao adicionar usuário:`, error);
        return false;
    }
}

function buscarUsuario(criterio) {
    if (!criterio) return null;
    
    if (usuarios.byId[criterio]) {
        return usuarios.byId[criterio];
    }
    
    if (usuarios.byWhatsappId[criterio]) {
        const id = usuarios.byWhatsappId[criterio];
        return usuarios.byId[id] || null;
    }
    
    if (usuarios.byNumero[criterio]) {
        const id = usuarios.byNumero[criterio];
        return usuarios.byId[id] || null;
    }
    
    return null;
}

// ⚠️ FORMATAR HORÁRIO COMERCIAL
function formatarHorarioComercial() {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        
        let mensagem = "🕐 *Horário Comercial:*\n";
        mensagem += "• Segunda a Sexta: 8h às 12h e 14h às 18h\n";
        mensagem += "• Sábado: 8h às 12h\n";
        mensagem += "• Domingo: Fechado\n";
        
        // ⚠️ ADICIONAR INFORMAÇÃO SOBRE FERIADOS
        if (config.feriados_ativos === 'Sim') {
            mensagem += "• Feriados: Fechado\n\n";
        } else {
            mensagem += "\n*Feriados não estão sendo considerados* (configurado no painel)";
        }
        
        return mensagem;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao formatar horário:`, error);
        return "🕐 Horário comercial padrão";
    }
}

// ⚠️ SALVAR USUÁRIOS
function salvarUsuarios() {
    try {
        fs.writeFileSync(USUARIOS_PATH, JSON.stringify(usuarios, null, 2));
        console.log(`${formatarDataHora()} 💾 Usuários salvos: ${Object.keys(usuarios.byId).length} usuário(s)`);
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao salvar usuários:`, error);
    }
}

// ⚠️ CARREGAR USUÁRIOS
function carregarUsuarios() {
    try {
        if (fs.existsSync(USUARIOS_PATH)) {
            const dados = JSON.parse(fs.readFileSync(USUARIOS_PATH, 'utf8'));
            
            if (!dados.byId && !dados.byWhatsappId && !dados.byNumero) {
                console.log(`${formatarDataHora()} 🔄 Migrando estrutura antiga de usuários...`);
                usuarios = migrarEstruturaAntiga(dados);
            } else {
                usuarios = dados;
            }
            
            console.log(`${formatarDataHora()} 📂 ${Object.keys(usuarios.byId).length} usuário(s) carregado(s)`);
            
            const atendentes = Object.values(usuarios.byId).filter(u => u.tipo === 'atendente');
            console.log(`${formatarDataHora()} 👨‍💼 ${atendentes.length} atendente(s) registrado(s)`);
            
            if (atendentes.length > 0) {
                const primeiroAtendente = atendentes[0];
                console.log(`${formatarDataHora()} 🔄 Verificando consistência: atendente ${primeiroAtendente.numero} encontrado`);
                
                try {
                    const configAtual = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
                    if (configAtual.atendente_numero !== primeiroAtendente.numero) {
                        console.log(`${formatarDataHora()} ⚠️ Atualizando config.json...`);
                        atualizarAtendenteNoConfig(primeiroAtendente.numero);
                    }
                } catch (error) {
                    console.error(`${formatarDataHora()} ❌ Erro ao verificar config.json:`, error);
                }
            }
            
        } else {
            usuarios = {
                byId: {},
                byWhatsappId: {},
                byNumero: {}
            };
            console.log(`${formatarDataHora()} 📂 Mapa de usuários inicializado (vazio)`);
        }
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao carregar usuários:`, error);
        usuarios = {
            byId: {},
            byWhatsappId: {},
            byNumero: {}
        };
    }
}

function migrarEstruturaAntiga(usuarioMapAntigo) {
    const novaEstrutura = {
        byId: {},
        byWhatsappId: {},
        byNumero: {}
    };
    
    let usuariosMigrados = 0;
    let duplicatasRemovidas = 0;
    const usuariosUnicos = new Map();
    
    for (const [chave, usuario] of Object.entries(usuarioMapAntigo)) {
        if (!usuario || typeof usuario !== 'object') continue;
        
        let usuarioId = usuario.id;
        
        if (!usuarioId) {
            const identity = new WhatsAppIdentity(usuario.whatsappId || usuario.numero);
            usuarioId = identity.internalId;
            usuario.id = usuarioId;
        }
        
        if (usuariosUnicos.has(usuarioId)) {
            duplicatasRemovidas++;
            console.log(`${formatarDataHora()} ⚠️ Removendo duplicata: ${usuario.pushName || 'Sem nome'} (ID: ${usuarioId})`);
            continue;
        }
        
        novaEstrutura.byId[usuarioId] = usuario;
        usuariosUnicos.set(usuarioId, true);
        
        if (usuario.whatsappId) {
            novaEstrutura.byWhatsappId[usuario.whatsappId] = usuarioId;
        }
        
        if (usuario.numero && typeof usuario.numero === 'string' && usuario.numero.length >= 10) {
            novaEstrutura.byNumero[usuario.numero] = usuarioId;
        }
        
        usuariosMigrados++;
    }
    
    console.log(`${formatarDataHora()} 🔄 Migração concluída: ${usuariosMigrados} usuários migrados, ${duplicatasRemovidas} duplicatas removidas`);
    return novaEstrutura;
}

function identificarUsuario(jid, pushName, texto = '', ignorarExtracaoNumero = false) {
    if (!jid) {
        console.error(`${formatarDataHora()} ❌ JID não fornecido`);
        return null;
    }
    
    const identity = new WhatsAppIdentity(jid);
    
    if (identity.type === 'group') {
        console.log(`${formatarDataHora()} 🚫 Ignorando mensagem de GRUPO: ${jid}`);
        return null;
    }
    
    if (!['individual', 'broadcast', 'new_format'].includes(identity.type)) {
        console.log(`${formatarDataHora()} 🚫 Tipo não suportado: ${identity.type}`);
        return null;
    }
    
    console.log(`${formatarDataHora()} 🔍 Identificando usuário: "${pushName}" (${identity.type})`);
    
    let usuario = buscarUsuario(identity.internalId);
    
    if (usuario) {
        console.log(`${formatarDataHora()} ✅ Usuário encontrado por ID interno: ${usuario.pushName}`);
        return usuario;
    }
    
    usuario = buscarUsuario(identity.raw);
    if (usuario) {
        console.log(`${formatarDataHora()} ✅ Usuário encontrado por WhatsApp ID: ${usuario.pushName}`);
        return usuario;
    }
    
    const phoneNumber = identity.extractPhoneNumber();
    if (phoneNumber) {
        usuario = buscarUsuario(phoneNumber);
        
        if (usuario) {
            console.log(`${formatarDataHora()} ✅ Usuário conhecido: ${usuario.pushName} -> ${phoneNumber}`);
            return usuario;
        }
        
        for (const [id, user] of Object.entries(usuarios.byId)) {
            if (user.numero === phoneNumber && user.tipo === 'atendente') {
                console.log(`${formatarDataHora()} ✅ Este número já é atendente: ${pushName} -> ${phoneNumber}`);
                return usuarios.byId[id];
            }
        }
    }
    
    console.log(`${formatarDataHora()} 👤 NOVO USUÁRIO: ${pushName || 'Sem nome'} -> ${identity.type}`);
    
    let sessionId;
    if (identity.type === 'broadcast') {
        const timestamp = Date.now();
        sessionId = `lid_${identity.normalized.identifier}_${timestamp}`;
    } else {
        sessionId = identity.internalId;
    }
    
    const novoUsuario = {
        id: sessionId,
        whatsappId: identity.raw,
        identityType: identity.type,
        sendCapability: identity.sendCapability,
        numero: phoneNumber,
        tipo: 'cliente',
        pushName: pushName || 'Cliente',
        cadastradoEm: new Date().toISOString(),
        origem: identity.type === 'broadcast' ? 'lista' : 'individual',
        metadata: {
            domain: identity.normalized.domain,
            identifier: identity.normalized.identifier,
            raw: identity.raw
        },
        temporario: identity.type === 'broadcast',
        lidSession: identity.type === 'broadcast'
    };
    
    if (adicionarUsuario(novoUsuario)) {
        salvarUsuarios();
        console.log(`${formatarDataHora()} ✅ Usuário cadastrado: ${pushName || 'Cliente'} (${identity.type})`);
        return novoUsuario;
    }
    
    return null;
}

// ================= FUNÇÕES PRINCIPAIS DO BOT =================
async function enviarMensagemParaUsuario(sock, usuario, mensagem) {
    console.log(`${formatarDataHora()} 📤 [ENVIAR] Iniciando envio para: ${usuario.id} (${usuario.identityType})`);
    
    try {
        let jidDestino = null;
        
        if (usuario.identityType === 'broadcast' || usuario.lidSession) {
            jidDestino = usuario.whatsappId;
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Usando JID de broadcast/LID: ${jidDestino}`);
        } 
        else if (usuario.identityType === 'individual' && usuario.numero) {
            jidDestino = getJID(usuario.numero);
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Convertendo número para JID: ${usuario.numero} -> ${jidDestino}`);
        }
        else if (usuario.whatsappId) {
            const identity = new WhatsAppIdentity(usuario.whatsappId);
            jidDestino = identity.getSendJID();
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Usando JID da identity: ${jidDestino}`);
        }
        
        if (!jidDestino) {
            console.error(`${formatarDataHora()} 📤 [ENVIAR] ❌ Não foi possível obter JID de envio`);
            return false;
        }
        
        console.log(`${formatarDataHora()} 📤 [ENVIAR] JID final: ${jidDestino}`);
        
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
        
        const menuText = 
`Olá! 👋  ${pushName ? pushName + ' ' : ''}

Bem-vindo ao atendimento da *${config.empresa}*

 1️⃣ Baixar Fatura PIX
 2️⃣ Falar com Atendente

Digite o número da opção desejada:`;

        const resultado = await enviarMensagemParaUsuario(sock, usuario, menuText);
        
        if (resultado) {
            console.log(`${formatarDataHora()} ✅ Menu enviado para ${pushName || 'usuário'}`);
        } else {
            console.error(`${formatarDataHora()} ❌ Falha ao enviar menu para ${pushName || 'usuário'}`);
        }
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao enviar menu:`, error);
    }
}

// ⚠️ CORREÇÃO CRÍTICA: Função de encerramento corrigida
async function encerrarAtendimento(usuario, config, motivo = "encerrado", chaveExplicita = null) {
    if (!sockInstance) {
        console.error(`${formatarDataHora()} ❌ sockInstance não disponível`);
        return false;
    }
    
    // ⚠️ CORREÇÃO: Usar chave consistente
    let chaveAtendimento = chaveExplicita;
    
    if (!chaveAtendimento) {
        // Para usuários individuais, usar número como chave principal
        if (usuario.identityType === 'individual' && usuario.numero) {
            chaveAtendimento = usuario.numero;
        } else {
            chaveAtendimento = usuario.id;
        }
    }
    
    const pushName = usuario.pushName || 'Cliente';
    
    console.log(`${formatarDataHora()} 🚪 Encerrando ${pushName} (${motivo}) - Chave: ${chaveAtendimento}`);
    
    // Remover de todos os lugares possíveis
    const chavesParaRemover = new Set();
    chavesParaRemover.add(chaveAtendimento);
    
    if (usuario.numero && usuario.numero !== chaveAtendimento) {
        chavesParaRemover.add(usuario.numero);
    }
    if (usuario.id && usuario.id !== chaveAtendimento) {
        chavesParaRemover.add(usuario.id);
    }
    if (usuario.whatsappId && usuario.whatsappId !== chaveAtendimento) {
        chavesParaRemover.add(usuario.whatsappId);
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
        mensagem = `⏰ *Atendimento encerrado por inatividade*\n\nA *${config.empresa}* agradece o seu contato!`;
    } else if (motivo === "atendente") {
        mensagem = `✅ *Atendimento encerrado pelo atendente*\n\nA *${config.empresa}* agradece o seu contato! 😊`;
    } else {
        mensagem = `✅ *Atendimento encerrado!*\n\nA *${config.empresa}* agradece o seu contato! 😊`;
    }
    
    try {
        await new Promise(resolve => setTimeout(resolve, 500));
        await enviarMensagemParaUsuario(sockInstance, usuario, mensagem);
        console.log(`${formatarDataHora()} 📤 Mensagem de encerramento enviada para ${pushName}`);
        return true;
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao enviar mensagem de encerramento:`, error);
        return false;
    }
}

// ⚠️ CORREÇÃO CRÍTICA: Função de timeout corrigida
async function verificarTimeouts() {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        const agora = Date.now();
        
        // Criar cópia das chaves para evitar modificação durante iteração
        const chavesAtendimentos = Object.keys(atendimentos);
        
        for (const chave of chavesAtendimentos) {
            const atendimento = atendimentos[chave];
            if (!atendimento) continue;
            
            // Buscar usuário
            let usuario = buscarUsuario(chave);
            if (!usuario && atendimento.usuarioId) {
                usuario = buscarUsuario(atendimento.usuarioId);
            }
            
            if (!usuario) {
                console.log(`${formatarDataHora()} ⚠️ Usuário não encontrado para chave: ${chave} - removendo`);
                delete atendimentos[chave];
                delete contextos[chave];
                continue;
            }
            
            const pushName = usuario.pushName || 'Cliente';
            
            // Verificar timeouts
            if (atendimento.tipo === 'humano' && atendimento.timeout && agora > atendimento.timeout) {
                console.log(`${formatarDataHora()} ⏰ Timeout expirado para ${pushName}`);
                await encerrarAtendimento(usuario, config, "timeout", chave);
                continue;
            }
            
            if (atendimento.tipo === 'aguardando_cpf' && atendimento.inicio && 
                (agora - atendimento.inicio) > (5 * 60 * 1000)) {
                console.log(`${formatarDataHora()} ⏰ Timeout CPF expirado para ${pushName}`);
                await encerrarAtendimento(usuario, config, "timeout", chave);
                continue;
            }
            
            if (atendimento.tipo === 'pos_pix' && atendimento.inicio && 
                (agora - atendimento.inicio) > (10 * 60 * 1000)) {
                console.log(`${formatarDataHora()} ⏰ Timeout PIX expirado para ${pushName}`);
                await encerrarAtendimento(usuario, config, "timeout", chave);
                continue;
            }
        }
        
        const totalAtendimentos = Object.keys(atendimentos).length;
        if (totalAtendimentos !== ultimoLogVerificacao.quantidade) {
            console.log(`${formatarDataHora()} 🔄 Verificando ${totalAtendimentos} atendimento(s) ativos`);
            ultimoLogVerificacao.quantidade = totalAtendimentos;
            ultimoLogVerificacao.timestamp = agora;
        }
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao verificar timeouts:`, error);
    }
}

async function reconectarComSeguranca() {
    if (reconexaoEmAndamento) {
        console.log(`${formatarDataHora()} ⏳ Reconexão já em andamento...`);
        return;
    }
    
    reconexaoEmAndamento = true;
    tentativasReconexao++;
    
    try {
        const delay = Math.min(1000 * Math.pow(2, tentativasReconexao), 30000);
        console.log(`${formatarDataHora()} ⏱️ Aguardando ${delay/1000}s antes de reconectar...`);
        
        await new Promise(resolve => setTimeout(resolve, delay));
        
        if (tentativasReconexao >= 3) {
            console.log(`${formatarDataHora()} 🧹 Múltiplas falhas - limpando sessões...`);
            await limparSessoesECredenciais();
            tentativasReconexao = 0;
            await new Promise(resolve => setTimeout(resolve, 5000));
        }
        
        console.log(`${formatarDataHora()} 🔄 Reconectando (tentativa ${tentativasReconexao})...`);
        await startBot();
        
    } finally {
        reconexaoEmAndamento = false;
    }
}

// ================= FUNÇÕES EXTRAS =================
function extrairNomeCliente(dadosMKAuth) {
    try {
        if (dadosMKAuth.nome && dadosMKAuth.nome.trim() !== '') {
            return dadosMKAuth.nome.trim();
        }
        
        if (dadosMKAuth.cli_nome && dadosMKAuth.cli_nome.trim() !== '') {
            return dadosMKAuth.cli_nome.trim();
        }
        
        if (dadosMKAuth.nome_cliente && dadosMKAuth.nome_cliente.trim() !== '') {
            return dadosMKAuth.nome_cliente.trim();
        }
        
        if (dadosMKAuth.titulos && Array.isArray(dadosMKAuth.titulos) && dadosMKAuth.titulos.length > 0) {
            for (const titulo of dadosMKAuth.titulos) {
                if (titulo.nome && titulo.nome.trim() !== '') {
                    return titulo.nome.trim();
                }
                
                if (titulo.cli_nome && titulo.cli_nome.trim() !== '') {
                    return titulo.cli_nome.trim();
                }
                
                if (titulo.nome_cliente && titulo.nome_cliente.trim() !== '') {
                    return titulo.nome_cliente.trim();
                }
            }
        }
        
        if (dadosMKAuth.cliente && typeof dadosMKAuth.cliente === 'object') {
            if (dadosMKAuth.cliente.nome && dadosMKAuth.cliente.nome.trim() !== '') {
                return dadosMKAuth.cliente.nome.trim();
            }
            
            if (dadosMKAuth.cliente.nome_completo && dadosMKAuth.cliente.nome_completo.trim() !== '') {
                return dadosMKAuth.cliente.nome_completo.trim();
            }
        }
        
        return null;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao extrair nome do cliente:`, error);
        return null;
    }
}

function verificarClienteMKAuth(doc) {
    return new Promise((resolve, reject) => {
        console.log(`${formatarDataHora()} 🔍 Verificando cliente no MK-Auth: ${doc}`);
        
        try {
            const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
            
            if (!config.mkauth_url || !config.mkauth_client_id || !config.mkauth_client_secret) {
                console.log(`${formatarDataHora()} ❌ Credenciais MK-Auth não configuradas no painel`);
                resolve({ 
                    sucesso: false, 
                    erro: true, 
                    configurado: false,
                    mensagem: "Sistema de verificação não configurado. Entre em contato com o suporte." 
                });
                return;
            }
            
            let apiBase = config.mkauth_url;
            
            if (!apiBase.endsWith('/')) {
                apiBase += '/';
            }
            if (!apiBase.includes('/api/')) {
                apiBase += 'api/';
            }
            
            const clientId = config.mkauth_client_id;
            const clientSecret = config.mkauth_client_secret;
            
            console.log(`${formatarDataHora()} 🔧 Usando configurações MK-Auth do painel`);
            
            obterTokenMKAuth(apiBase, clientId, clientSecret)
                .then(token => {
                    if (!token) {
                        console.log(`${formatarDataHora()} ❌ Erro ao obter token MK-Auth`);
                        resolve({ sucesso: false, erro: true, mensagem: "Erro na autenticação do sistema" });
                        return;
                    }
                    
                    consultarTitulosMKAuth(doc, token, apiBase)
                        .then(resultado => {
                            resolve(resultado);
                        })
                        .catch(error => {
                            console.error(`${formatarDataHora()} ❌ Erro na consulta:`, error.message);
                            resolve({ sucesso: false, erro: true, mensagem: "Erro ao consultar o sistema" });
                        });
                })
                .catch(error => {
                    console.error(`${formatarDataHora()} ❌ Erro ao obter token:`, error.message);
                    resolve({ sucesso: false, erro: true, mensagem: "Erro na autenticação do sistema" });
                });
                
        } catch (error) {
            console.error(`${formatarDataHora()} ❌ Erro ao carregar configurações:`, error);
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
            
            res.on('data', (chunk) => {
                data += chunk;
            });
            
            res.on('end', () => {
                if (res.statusCode === 200) {
                    const token = data.trim();
                    if (token && token.length >= 20) {
                        console.log(`${formatarDataHora()} ✅ Token obtido com sucesso`);
                        resolve(token);
                    } else {
                        console.log(`${formatarDataHora()} ❌ Token inválido recebido`);
                        reject(new Error('Token inválido'));
                    }
                } else {
                    console.log(`${formatarDataHora()} ❌ Erro HTTP ${res.statusCode} ao obter token`);
                    reject(new Error(`HTTP ${res.statusCode}`));
                }
            });
        });
        
        req.on('error', (error) => {
            console.error(`${formatarDataHora()} ❌ Erro de conexão ao obter token:`, error.message);
            reject(error);
        });
        
        req.on('timeout', () => {
            console.log(`${formatarDataHora()} ❌ Timeout ao obter token`);
            req.destroy();
            reject(new Error('Timeout'));
        });
        
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
            
            res.on('data', (chunk) => {
                data += chunk;
            });
            
            res.on('end', () => {
                try {
                    const parsedData = JSON.parse(data);
                    
                    if (parsedData && parsedData.mensagem && 
                        parsedData.mensagem.toLowerCase().includes('não encontrado')) {
                        console.log(`${formatarDataHora()} ❌ Cliente não encontrado no MK-Auth: ${doc}`);
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
                        let tituloAtivoEncontrado = false;
                        
                        for (const titulo of parsedData.titulos) {
                            if (titulo.cli_ativado === 's') {
                                tituloAtivoEncontrado = true;
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
                        console.log(`${formatarDataHora()} ⚠️ Cliente marcado como INATIVO: ${doc} (cli_ativado: ${cliAtivadoStr})`);
                        
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
                            
                            if (temFaturaAberta && temFaturaComPix) {
                                console.log(`${formatarDataHora()} ⚠️ Cliente INATIVO mas com fatura(s) em aberto e PIX - PERMITINDO ACESSO: ${doc}`);
                            } else {
                                console.log(`${formatarDataHora()} ❌ Cliente INATIVO sem faturas em aberto com PIX: ${doc}`);
                                
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
                            console.log(`${formatarDataHora()} ❌ Cliente INATIVO sem faturas: ${doc}`);
                            
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
                    
                    if (!parsedData.titulos || !Array.isArray(parsedData.titulos) || 
                        parsedData.titulos.length === 0) {
                        console.log(`${formatarDataHora()} ❌ Cliente encontrado mas sem faturas: ${doc}`);
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
                        console.log(`${formatarDataHora()} ❌ Cliente encontrado mas sem PIX: ${doc}`);
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
                    
                    console.log(`${formatarDataHora()} ✅ Cliente válido no MK-Auth: ${doc}`);
                    console.log(`${formatarDataHora()} 📊 Total de títulos: ${parsedData.titulos.length}`);
                    console.log(`${formatarDataHora()} 👤 Nome do cliente: ${nomeCliente || 'Não encontrado'}`);
                    
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
                    console.error(`${formatarDataHora()} ❌ Erro ao processar resposta:`, error.message);
                    reject(error);
                }
            });
        });
        
        req.on('error', (error) => {
            console.error(`${formatarDataHora()} ❌ Erro de conexão na consulta:`, error.message);
            reject(error);
        });
        
        req.on('timeout', () => {
            console.log(`${formatarDataHora()} ❌ Timeout na consulta`);
            req.destroy();
            reject(new Error('Timeout'));
        });
        
        req.end();
    });
}

// ================= FUNÇÃO PARA CORRIGIR ATENDIMENTOS CORROMPIDOS =================
function corrigirAtendimentosCorrompidos() {
    console.log(`${formatarDataHora()} 🔧 Verificando atendimentos corrompidos...`);
    
    let removidos = 0;
    const agora = Date.now();
    const umaHora = 60 * 60 * 1000;
    
    for (const [chave, atendimento] of Object.entries(atendimentos)) {
        // Se o atendimento tem início muito antigo (mais de 1 hora)
        if (atendimento.inicio && (agora - atendimento.inicio) > umaHora) {
            console.log(`${formatarDataHora()} 🗑️ Removendo atendimento antigo: ${chave} (início: ${new Date(atendimento.inicio).toLocaleTimeString()})`);
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
    // Verificar argumentos
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
    
    // Corrigir atendimentos corrompidos antes de iniciar
    corrigirAtendimentosCorrompidos();
    
    carregarUsuarios();
    
    // Limpeza programada
    setInterval(() => {
        const agora = new Date();
        if (agora.getHours() === 2 && agora.getMinutes() === 0) {
            console.log(`${formatarDataHora()} 🧹 Executando limpeza programada...`);
            corrigirAtendimentosCorrompidos();
        }
    }, 60000);

    if (!fs.existsSync(AUTH_DIR)) {
        console.log(`${formatarDataHora()} ℹ️ Pasta auth_info não existe - será criada ao gerar QR Code`);
    }

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

    const sock = makeWASocket({
        auth: state,
        logger: P({ level: 'silent' }),
        printQRInTerminal: true
    });

    sockInstance = sock;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async ({ connection, lastDisconnect, qr }) => {
        if (qr) {
            fs.writeFileSync(QR_PATH, qr);
            setStatus('qr');
            console.log(`${formatarDataHora()} 📱 QR Code gerado. Escaneie com o WhatsApp.`);
        }

        if (connection === 'open') {
            fs.writeFileSync(QR_PATH, '');
            setStatus('online');
            tentativasReconexao = 0;
            
            try {
                const user = sock.user;
                if (user && user.id) {
                    const identity = new WhatsAppIdentity(user.id);
                    const phoneNumber = identity.extractPhoneNumber();
                    const pushName = user.name || 'Atendente WhatsApp';
                    
                    if (phoneNumber) {
                        console.log(`${formatarDataHora()} 🔐 WhatsApp conectado como: ${pushName} (${phoneNumber})`);
                        
                        const novoAtendente = {
                            id: identity.internalId,
                            whatsappId: identity.raw,
                            identityType: identity.type,
                            sendCapability: identity.sendCapability,
                            numero: phoneNumber,
                            tipo: 'atendente',
                            pushName: pushName,
                            cadastradoEm: new Date().toISOString(),
                            metadata: {
                                domain: identity.normalized.domain,
                                identifier: identity.normalized.identifier,
                                raw: identity.raw
                            }
                        };
                        
                        if (adicionarUsuario(novoAtendente)) {
                            salvarUsuarios();
                            console.log(`${formatarDataHora()} ✅ Atendente registrado: ${pushName} (${phoneNumber})`);
                            atualizarAtendenteNoConfig(phoneNumber);
                            
                            try {
                                await enviarMensagemParaUsuario(sock, novoAtendente, 
                                    `👨‍💼 *ATENDENTE CONFIGURADO*\n\nOlá ${pushName}! Você foi configurado como atendente do bot.\n\n*Comandos disponíveis:*\n• #FECHAR - Encerra todos os atendimentos\n• #FECHAR [número] - Encerra cliente específico\n• #FECHAR [nome] - Encerra por nome\n• #CLIENTES - Lista clientes ativos`
                                );
                            } catch (error) {
                                console.error(`${formatarDataHora()} ❌ Erro ao enviar mensagem para atendente:`, error);
                            }
                        }
                    }
                }
            } catch (error) {
                console.error(`${formatarDataHora()} ❌ Erro ao capturar credenciais:`, error);
            }
            
            console.log(`${formatarDataHora()} ✅ WhatsApp conectado com sucesso!`);
            console.log(`${formatarDataHora()} 👥 ${Object.keys(usuarios.byId).length} usuário(s)`);
            
            setInterval(verificarTimeouts, 30000);
            console.log(`${formatarDataHora()} ⏱️ Sistema de timeout ativo (verifica a cada 30s)`);
        }

        if (connection === 'close') {
            setStatus('offline');
            
            const errorMessage = lastDisconnect?.error?.message || '';
            const errorOutput = lastDisconnect?.error?.output || {};
            
            console.log(`${formatarDataHora()} 🔌 Desconectado. Último erro:`, errorMessage);
            
            if (errorMessage.includes('Bad MAC') || 
                errorMessage.includes('Failed to decrypt') ||
                errorMessage.includes('MAC mismatch') ||
                (errorOutput.statusCode === 401 && errorMessage.includes('session'))) {
                
                console.log(`${formatarDataHora()} 🚨 ERRO DE CRIPTOGRAFIA DETECTADO!`);
                console.log(`${formatarDataHora()} 🧹 Limpando automaticamente...`);
                
                await limparSessoesECredenciais();
                
                setTimeout(() => {
                    console.log(`${formatarDataHora()} 🔄 Reiniciando bot após limpeza automática...`);
                    reconectarComSeguranca();
                }, 5000);
                return;
            }
            
            if (lastDisconnect?.error?.output?.statusCode === DisconnectReason.loggedOut) {
                console.log(`${formatarDataHora()} 🔐 WhatsApp desconectado pelo usuário (loggedOut)`);
                
                const limpezaRealizada = limparAuthInfo();
                
                if (limpezaRealizada) {
                    setTimeout(() => {
                        console.log(`${formatarDataHora()} 🔄 Reiniciando bot...`);
                        reconectarComSeguranca();
                    }, 2000);
                } else {
                    console.log(`${formatarDataHora()} 🔄 Tentando reconectar...`);
                    reconectarComSeguranca();
                }
            } else {
                console.log(`${formatarDataHora()} 🔄 Tentando reconectar...`);
                reconectarComSeguranca();
            }
        }
    });

    sock.ev.on('messages.upsert', async ({ messages }) => {
        if (!messages || !Array.isArray(messages) || messages.length === 0) {
            return;
        }

        const msg = messages[0];
        
        const texto = (
            msg.message.conversation ||
            msg.message.extendedTextMessage?.text ||
            ''
        ).trim();
        
        const jidRemetente = msg.key.remoteJid;
        
        if (msg.key.fromMe) {
            console.log(`${formatarDataHora()} 🤖 Ignorando mensagem do próprio bot`);
            return;
        }
        
        if (!msg.message || msg.message.protocolMessage || msg.message.senderKeyDistributionMessage) {
            return;
        }
        
        if (!jidRemetente) {
            console.error(`${formatarDataHora()} ❌ Não foi possível obter JID do remetente`);
            return;
        }
        
        const pushName = msg.pushName || 'Cliente';
        
        console.log(`\n${formatarDataHora()} 📨 MENSAGEM DE: ${pushName} (${jidRemetente}) - "${texto}"`);

        const usuario = identificarUsuario(jidRemetente, pushName, texto, false);
        
        if (!usuario) {
            console.log(`${formatarDataHora()} ❌ Usuário não identificado`);
            return;
        }

        // ============ CORREÇÃO FINAL: Apenas status@broadcast é ignorado ============
        // WhatsApp NÃO permite responder para visualizações de status
        // WhatsApp PERMITE responder para números com formato @lid ou @broadcast (clientes legítimos)
        
        const isStatusView = jidRemetente === 'status@broadcast';
        
        if (isStatusView) {
            console.log(`${formatarDataHora()} 📱 Visualização de STATUS de ${pushName} - IGNORANDO (WhatsApp não permite resposta para visualizações de status)`);
            return; // IGNORA APENAS status@broadcast
        }
        
        // Para números @lid e @broadcast que NÃO são status@broadcast, são clientes legítimos
        if (usuario.identityType === 'broadcast' && !isStatusView) {
            console.log(`${formatarDataHora()} 📢 Cliente com formato especial: ${jidRemetente} - PROCESSANDO NORMALMENTE`);
            // CONTINUA O FLUXO NORMAL
        }
        // ====================================================================================

        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        const isAtendente = usuario.tipo === 'atendente';
        
        if (isAtendente) {
            console.log(`${formatarDataHora()} 👨‍💼 Mensagem do atendente ignorada`);
            return;
        }

        // ⚠️ CORREÇÃO CRÍTICA: Determinar chave correta
        let chaveAtendimento;
        if (usuario.identityType === 'individual' && usuario.numero) {
            chaveAtendimento = usuario.numero; // Para individuais, usar número
        } else {
            chaveAtendimento = usuario.id; // Para broadcasts, usar ID
        }
        
        const contextoAtual = contextos[chaveAtendimento] || 'menu';
        
        console.log(`${formatarDataHora()} 🔢 ${pushName} -> ${usuario.id} (${usuario.tipo})`);
        console.log(`${formatarDataHora()} 📊 Contexto atual: ${contextoAtual}`);

        // Tratar comando "0"
        if (texto === '0') {
            console.log(`${formatarDataHora()} 🔄 Cliente digitou "0" - contexto: ${contextoAtual}`);
            
            if (contextoAtual === 'pos_pix' || contextoAtual === 'em_atendimento' || contextoAtual === 'aguardando_cpf') {
                console.log(`${formatarDataHora()} 🚪 Encerrando atendimento por comando do cliente`);
                await encerrarAtendimento(usuario, config, "cliente", chaveAtendimento);
                return;
            } else {
                console.log(`${formatarDataHora()} ℹ️ Comando "0" ignorado - não está em contexto de atendimento`);
                await enviarMenuPrincipal(sock, usuario, texto);
                return;
            }
        }

        // Tratar comando "9"
        if (texto === '9') {
            console.log(`${formatarDataHora()} 🔄 Cliente digitou "9" - voltando ao menu`);
            contextos[chaveAtendimento] = 'menu';
            delete atendimentos[chaveAtendimento];
            await enviarMenuPrincipal(sock, usuario, texto);
            return;
        }

        // MENU PRINCIPAL
        if (contextoAtual === 'menu') {
            if (texto === '1') {
                console.log(`${formatarDataHora()} 💠 Cliente escolheu PIX`);
                contextos[chaveAtendimento] = 'aguardando_cpf';
                atendimentos[chaveAtendimento] = {
                    tipo: 'aguardando_cpf',
                    inicio: Date.now(),
                    timeout: null,
                    usuarioId: usuario.id,
                    usuarioNumero: usuario.numero,
                    usuarioWhatsappId: usuario.whatsappId,
                    chaveUsada: chaveAtendimento
                };
                
                await enviarMensagemParaUsuario(sock, usuario, `🔐 Informe seu CPF ou CNPJ:`);
                return;
                
            } else if (texto === '2') {
                console.log(`${formatarDataHora()} 👨‍💼 Cliente escolheu atendimento`);
                
                // ⚠️ MANTIDO: Mensagem original do fluxo quando fora do horário
                if (!dentroHorarioComercial()) {
                    console.log(`${formatarDataHora()} ⏰ Fora do horário comercial ou feriado`);
                    
                    const hoje = new Date();
                    const ehFeriadoHoje = ehFeriado(hoje);
                    
                    let mensagemErro = `⏰ *${pushName}*, `;
                    
                    if (ehFeriadoHoje) {
                        mensagemErro += `hoje é feriado nacional.\n\n`;
                    } else if (hoje.getDay() === 0) {
                        mensagemErro += `hoje é domingo.\n\n`;
                    } else {
                        mensagemErro += `porfavor, retorne seu contato em *horário comercial*.\n\n`;
                    }
                    mensagemErro += `${formatarHorarioComercial()}`;
                    mensagemErro += `1️⃣  Para Fatura  |  9️⃣  Retornar ao Menu`;
                    
                    await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                    return;
                }
                
                const tempoTimeout = config.tempo_atendimento_humano || 5;
                atendimentos[chaveAtendimento] = {
                    tipo: 'humano',
                    inicio: Date.now(),
                    timeout: Date.now() + (tempoTimeout * 60 * 1000),
                    usuarioId: usuario.id,
                    usuarioNumero: usuario.numero,
                    usuarioWhatsappId: usuario.whatsappId,
                    chaveUsada: chaveAtendimento
                };
                contextos[chaveAtendimento] = 'em_atendimento';
                
                console.log(`${formatarDataHora()} ⏱️ Atendimento iniciado (${tempoTimeout}min)`);
                
                await enviarMensagemParaUsuario(sock, usuario, 
                    `👨‍💼 *ATENDIMENTO INICIADO*\n\n*${pushName}*, um atendente falará com você em instantes, aguarde...\n\n⏱️ Duração: ${tempoTimeout} minutos\n\n 0️⃣ Encerrar Atendimento`
                );
                return;
                
            } else {
                await enviarMenuPrincipal(sock, usuario, texto);
                return;
            }
        }

        // AGUARDANDO CPF
        if (contextoAtual === 'aguardando_cpf') {
            console.log(`${formatarDataHora()} 📄 Contexto aguardando_cpf ATIVADO`);
            
            if (atendimentos[chaveAtendimento]) {
                atendimentos[chaveAtendimento].inicio = Date.now();
            }
            
            if (texto === '1' || texto === '2') {
                console.log(`${formatarDataHora()} 📄 Comando detectado: ${texto}`);
                
                if (texto === '2') {
                    console.log(`${formatarDataHora()} 👨‍💼 Cliente escolheu atendimento após erro no CPF`);
                    
                    if (!dentroHorarioComercial()) {
                        console.log(`${formatarDataHora()} ⏰ Fora do horário comercial ou feriado`);
                        
                        const hoje = new Date();
                        const ehFeriadoHoje = ehFeriado(hoje);
                        
                        let mensagemErro = `⏰ *${pushName}*, `;
                        
                        if (ehFeriadoHoje) {
                            mensagemErro += `hoje é feriado nacional.\n\n`;
                        } else if (hoje.getDay() === 0) {
                            mensagemErro += `hoje é domingo.\n\n`;
                        } else {
                            mensagemErro += `porfavor, retorne seu contato em *horário comercial*.\n\n`;
                        }
                        mensagemErro += `${formatarHorarioComercial()}`;
                        mensagemErro += `1️⃣  Para Fatura  |  9️⃣  Retornar ao Menu`;
                        
                        await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                        return;
                    }
                    
                    const tempoTimeout = config.tempo_atendimento_humano || 5;
                    atendimentos[chaveAtendimento] = {
                        tipo: 'humano',
                        inicio: Date.now(),
                        timeout: Date.now() + (tempoTimeout * 60 * 1000),
                        usuarioId: usuario.id,
                        usuarioNumero: usuario.numero,
                        usuarioWhatsappId: usuario.whatsappId,
                        chaveUsada: chaveAtendimento
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
                            timeout: Date.now() + (10 * 60 * 1000),
                            usuarioId: usuario.id,
                            usuarioNumero: usuario.numero,
                            usuarioWhatsappId: usuario.whatsappId,
                            chaveUsada: chaveAtendimento
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

        // CONTEXTO PÓS-PIX
        if (contextoAtual === 'pos_pix') {
            await enviarMensagemParaUsuario(sock, usuario, 
                `PIX já gerado. Acesse o link enviado anteriormente.\n\n⏱️ *Link válido por 10 minutos*\n\n0️⃣  Encerrar  |  9️⃣  Retornar ao Menu`
            );
            return;
        }

        // CONTEXTO EM ATENDIMENTO
        if (contextoAtual === 'em_atendimento') {
            console.log(`${formatarDataHora()} 🤐 Cliente em atendimento humano`);
            
            if (atendimentos[chaveAtendimento]) {
                const tempoTimeout = (config.tempo_atendimento_humano || 5) * 60 * 1000;
                atendimentos[chaveAtendimento].timeout = Date.now() + tempoTimeout;
                console.log(`${formatarDataHora()} ⏰ Timeout renovado para ${pushName}`);
            }
            return;
        }
        
        // Se chegou aqui e não é um contexto conhecido, enviar menu
        await enviarMenuPrincipal(sock, usuario, texto);
    });
}

// ================= INICIALIZAÇÃO =================

console.log('\n' + '='.repeat(70));
console.log('🤖 BOT WHATSAPP - VERSÃO CORRIGIDA FINAL');
console.log('✅ Loop de timeout resolvido');
console.log('✅ Mensagens do fluxo mantidas');
console.log('✅ Apenas status@broadcast ignorado');
console.log('✅ Clientes @lid e @broadcast atendidos');
console.log('='.repeat(70));
console.log('🚀 INICIANDO BOT...');
console.log('='.repeat(70));
console.log('📌 Comandos disponíveis:');
console.log('   node bot.js              - Inicia normalmente');
console.log('   node bot.js --clear-auth - Limpa sessões corrompidas');
console.log('='.repeat(70));

// Verificar dependências
try {
    require('@whiskeysockets/baileys');
} catch (error) {
    console.error('❌ Erro: @whiskeysockets/baileys não encontrado!');
    console.error('   Execute: npm install @whiskeysockets/baileys');
    process.exit(1);
}

// Iniciar o bot
startBot();

// Tratamento de exceções
process.on('uncaughtException', (error) => {
    console.error(`${formatarDataHora()} 🚨 EXCEÇÃO NÃO CAPTURADA:`, error.message);
    
    if (error.message.includes('Bad MAC') || error.message.includes('session')) {
        console.log(`${formatarDataHora()} 🔧 Detectado erro de sessão, limpando...`);
        limparSessoesECredenciais().then(() => {
            setTimeout(() => {
                console.log(`${formatarDataHora()} 🔄 Reiniciando após erro grave...`);
                startBot();
            }, 5000);
        });
    }
});

process.on('unhandledRejection', (reason, promise) => {
    console.error(`${formatarDataHora()} 🚨 PROMISE REJEITADA NÃO TRATADA:`, reason);
});
