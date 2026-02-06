/*************************************************
 * BOT WHATSAPP - VERSÃO COMPLETA COM FERIADOS
 * Controle de feriados via painel web
 * CORRIGIDO: Suporte para mensagens individuais e grupos
 * ADICIONADO: Data/hora nos logs + Limpeza automática de usuários
 * CORRIGIDO: Bug CPF/CNPJ apenas números (não confundir com telefone)
 *************************************************/

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason
} = require('@whiskeysockets/baileys');

const fs = require('fs');
const path = require('path');
const P = require('pino');

const BASE_DIR = __dirname;
const AUTH_DIR = path.join(BASE_DIR, 'auth_info');
const CONFIG_PATH = path.join(BASE_DIR, 'config.json');
const STATUS_PATH = path.join(BASE_DIR, 'status.json');
const QR_PATH = path.join(BASE_DIR, 'qrcode.txt');
const USUARIOS_PATH = path.join(BASE_DIR, 'usuarios.json');

// ⚠️ ESTRUTURAS GLOBAIS
const atendimentos = {};
const contextos = {};
let sockInstance = null;
let usuarioMap = {};

// Variável para controle de logs de verificação
let ultimoLogVerificacao = {
    quantidade: 0,
    timestamp: 0
};

// ⚠️ FERIADOS FIXOS (NACIONAIS DO BRASIL)
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

/* ================= FUNÇÕES AUXILIARES ================= */
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

// Verificar se é um JID individual (não grupo/lista)
function isIndividualJID(jid) {
    return jid && jid.includes('@s.whatsapp.net');
}

// Verificar se é JID de grupo
function isGroupJID(jid) {
    return jid && jid.includes('@g.us');
}

// Verificar se é JID de lista de transmissão
function isBroadcastJID(jid) {
    return jid && jid.includes('@lid');
}

// Extrair número do JID
function extrairNumeroDoJID(jid) {
    if (!jid) return null;
    
    // Se for JID individual
    if (isIndividualJID(jid)) {
        const numero = jid.split('@')[0];
        // Garantir que comece com 55
        if (numero && numero.length >= 10) {
            return numero.startsWith('55') ? numero : `55${numero}`;
        }
    }
    
    // Se for JID de lista/grupo, não podemos extrair número individual
    return null;
}

// Função para obter JID a partir do número
function getJID(numero) {
    if (!numero) return null;
    
    // Se já for um JID, verificar tipo
    if (numero.includes('@')) {
        // Só podemos enviar para JIDs individuais
        if (isIndividualJID(numero)) {
            return numero;
        }
        return null; // Não podemos enviar para grupos/listas
    }
    
    // Limpa o número
    const num = numero.toString().replace(/\D/g, '');
    
    if (num.length >= 10) {
        // Garantir que tenha país (55) e retornar como JID individual
        const numeroFormatado = num.startsWith('55') ? num : `55${num}`;
        return `${numeroFormatado}@s.whatsapp.net`;
    }
    
    return null;
}

// ⚠️ VERIFICAR SE É FERIADO
function ehFeriado(data = new Date()) {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        
        // ⚠️ VERIFICAR SE FERIADOS ESTÃO ATIVADOS
        if (config.feriados_ativos !== 'Sim') {
            return false; // Feriados desativados no painel
        }
        
        const diaMes = formatarData(data);
        
        // Verificar feriados nacionais fixos
        if (FERIADOS_NACIONAIS.includes(diaMes)) {
            console.log(`${formatarDataHora()} 🎉 Hoje é feriado nacional: ${diaMes}`);
            return true;
        }
        
        return false;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao verificar feriado:`, error);
        return false; // Em caso de erro, considera não feriado
    }
}

// ⚠️ VERIFICAR HORÁRIO COMERCIAL
function dentroHorarioComercial() {
    const d = new Date();
    const dia = d.getDay();
    const h = d.getHours() + d.getMinutes() / 60;

    // ⚠️ VERIFICAR SE É FERIADO (SE ESTIVER ATIVADO NO CONFIG)
    if (ehFeriado(d)) {
        return false;
    }

    if (dia === 0) return false; // Domingo
    
    if (dia >= 1 && dia <= 5) { // Segunda a Sexta
        return (h >= 8 && h < 12) || (h >= 14 && h < 18);
    }
    
    if (dia === 6) { // Sábado
        return (h >= 8 && h < 12);
    }
    
    return false;
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

// ⚠️ CARREGAR USUÁRIOS
function carregarUsuarios() {
    try {
        if (fs.existsSync(USUARIOS_PATH)) {
            usuarioMap = JSON.parse(fs.readFileSync(USUARIOS_PATH, 'utf8'));
            console.log(`${formatarDataHora()} 📂 ${Object.keys(usuarioMap).length} usuário(s) carregado(s)`);
        } else {
            usuarioMap = {
                '5583982277238': { 
                    numero: '5583982277238', 
                    tipo: 'atendente',
                    pushName: 'Webline Info',
                    cadastradoEm: new Date().toISOString()
                }
            };
            console.log(`${formatarDataHora()} 📂 Mapa de usuários inicializado`);
        }
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao carregar usuários:`, error);
        usuarioMap = {};
    }
}

// ⚠️ SALVAR USUÁRIOS
function salvarUsuarios() {
    try {
        fs.writeFileSync(USUARIOS_PATH, JSON.stringify(usuarioMap, null, 2));
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao salvar usuários:`, error);
    }
}

// ⚠️ LIMPAR USUÁRIOS INATIVOS
function limparUsuariosInativos() {
    try {
        const agora = new Date();
        let removidos = 0;
        const usuariosParaManter = {};
        
        for (const [chave, usuario] of Object.entries(usuarioMap)) {
            // SEMPRE manter o atendente
            if (usuario.numero === '5583982277238' && usuario.tipo === 'atendente') {
                usuariosParaManter[chave] = usuario;
                continue;
            }
            
            // Para usuários TEMP (de listas/grupos), remover após 3 dias
            if (usuario.temporario || usuario.numero.startsWith('TEMP')) {
                const dataCadastro = new Date(usuario.cadastradoEm);
                const diasInativo = (agora - dataCadastro) / (1000 * 60 * 60 * 24);
                
                if (diasInativo > 3) {
                    removidos++;
                    console.log(`${formatarDataHora()} 🗑️ Removendo usuário temporário: ${usuario.pushName} (${diasInativo.toFixed(1)} dias)`);
                    continue;
                }
            }
            
            // Para clientes normais, remover após 15 dias de inatividade
            const dataCadastro = new Date(usuario.cadastradoEm);
            const diasInativo = (agora - dataCadastro) / (1000 * 60 * 60 * 24);
            
            if (diasInativo > 15) {
                removidos++;
                console.log(`${formatarDataHora()} 🗑️ Removendo cliente inativo: ${usuario.pushName} (${diasInativo.toFixed(1)} dias)`);
                continue;
            }
            
            usuariosParaManter[chave] = usuario;
        }
        
        if (removidos > 0) {
            usuarioMap = usuariosParaManter;
            salvarUsuarios();
            console.log(`${formatarDataHora()} ✅ Limpeza concluída: ${removidos} usuário(s) removido(s)`);
        }
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao limpar usuários:`, error);
    }
}

// ⚠️ IDENTIFICAR OU CRIAR USUÁRIO (CORRIGIDA - NÃO CONFUNDE CPF/CNPJ COM TELEFONE)
function identificarUsuario(jid, pushName, texto = '', ignorarExtracaoNumero = false) {
    if (!jid) {
        console.error(`${formatarDataHora()} ❌ JID não fornecido`);
        return null;
    }
    
    // Se for lista/grupo, não podemos identificar usuário individual
    if (!isIndividualJID(jid)) {
        console.log(`${formatarDataHora()} ⚠️ JID não individual: ${jid} (lista/grupo)`);
        
        // ⚠️ CORREÇÃO: NÃO extrair número se estivermos em aguardando_cpf
        // (para evitar confundir CPF/CNPJ com número de telefone)
        if (!ignorarExtracaoNumero && texto) {
            const match = texto.match(/\d{10,}/g);
            if (match && match.length > 0) {
                const num = match[0].replace(/\D/g, '');
                
                // ⚠️ CORREÇÃO CRÍTICA: Verificar se não é CPF/CNPJ
                // CPF tem 11 dígitos, CNPJ tem 14 dígitos
                // Número de telefone normalmente tem 10-13 dígitos (com país)
                if (num.length >= 10 && num.length !== 11 && num.length !== 14) {
                    const numeroExtraido = num.startsWith('55') ? num : '55' + num;
                    console.log(`${formatarDataHora()} 📱 Número extraído do texto: ${numeroExtraido} (${num.length} dígitos)`);
                    
                    // Verificar se já existe
                    if (usuarioMap[numeroExtraido]) {
                        return usuarioMap[numeroExtraido];
                    }
                    
                    // Criar novo cliente com número extraído
                    const novoCliente = {
                        numero: numeroExtraido,
                        tipo: 'cliente',
                        pushName: pushName || 'Cliente',
                        cadastradoEm: new Date().toISOString()
                    };
                    
                    usuarioMap[numeroExtraido] = novoCliente;
                    salvarUsuarios();
                    
                    console.log(`${formatarDataHora()} ✅ Cliente cadastrado via número extraído: ${pushName} -> ${numeroExtraido}`);
                    return novoCliente;
                } else {
                    console.log(`${formatarDataHora()} ⚠️ Ignorando extração: parece CPF/CNPJ (${num.length} dígitos)`);
                }
            }
        }
        
        // Se não conseguiu extrair número, criar com JID temporário
        const jidTemp = jid.replace(/[^a-zA-Z0-9]/g, '').substring(0, 15);
        const numeroTemp = `TEMP${jidTemp}`;
        
        console.log(`${formatarDataHora()} ⚠️ Criando usuário temporário para ${pushName} em ${jid}`);
        
        const usuarioTemp = {
            numero: numeroTemp,
            tipo: 'cliente',
            pushName: pushName || 'Cliente',
            jidOriginal: jid,
            cadastradoEm: new Date().toISOString(),
            temporario: true
        };
        
        usuarioMap[numeroTemp] = usuarioTemp;
        salvarUsuarios();
        
        return usuarioTemp;
    }
    
    // Se for JID individual, extrair número normalmente
    const numero = extrairNumeroDoJID(jid);
    if (!numero) {
        console.error(`${formatarDataHora()} ❌ Não foi possível extrair número do JID:`, jid);
        return null;
    }
    
    console.log(`${formatarDataHora()} 🔍 Identificando: "${pushName}" (${numero})`);
    
    // 1. Buscar pelo número (chave principal)
    if (usuarioMap[numero]) {
        console.log(`${formatarDataHora()} ✅ Usuário conhecido: ${pushName} -> ${numero}`);
        
        // Atualizar pushName se necessário
        if (pushName && pushName !== usuarioMap[numero].pushName) {
            usuarioMap[numero].pushName = pushName;
            salvarUsuarios();
        }
        
        return usuarioMap[numero];
    }
    
    // 2. É atendente? (verificar pelo número)
    if (numero === '5583982277238') {
        const atendente = {
            numero: numero,
            tipo: 'atendente',
            pushName: pushName || 'Webline Info',
            cadastradoEm: new Date().toISOString()
        };
        usuarioMap[numero] = atendente;
        salvarUsuarios();
        console.log(`${formatarDataHora()} ✅ Atendente cadastrado: ${pushName} -> ${numero}`);
        return atendente;
    }
    
    // 3. NOVO CLIENTE
    console.log(`${formatarDataHora()} 👤 NOVO CLIENTE: ${pushName || 'Sem nome'} -> ${numero}`);
    
    const novoCliente = {
        numero: numero,
        tipo: 'cliente',
        pushName: pushName || 'Cliente',
        cadastradoEm: new Date().toISOString()
    };
    
    usuarioMap[numero] = novoCliente;
    salvarUsuarios();
    
    console.log(`${formatarDataHora()} ✅ Cliente cadastrado: ${pushName || 'Cliente'} -> ${numero}`);
    
    return novoCliente;
}

// ⚠️ ENCERRAR ATENDIMENTO
async function encerrarAtendimento(numeroCliente, pushName, config, motivo = "encerrado") {
    if (!sockInstance) {
        console.error(`${formatarDataHora()} ❌ sockInstance não disponível`);
        return;
    }
    
    console.log(`${formatarDataHora()} 🚪 Encerrando ${pushName} (${motivo})`);
    
    delete atendimentos[numeroCliente];
    delete contextos[numeroCliente];
    
    let mensagem = '';
    if (motivo === "timeout") {
        mensagem = `⏰ *Atendimento encerrado por inatividade*\n\nA *${config.empresa}* agradece o seu contato!`;
    } else if (motivo === "atendente") {
        mensagem = `✅ *Atendimento encerrado pelo atendente*\n\nA *${config.empresa}* agradece o seu contato! 😊`;
    } else {
        mensagem = `✅ *Atendimento encerrado!*\n\nA *${config.empresa}* agradece o seu contato! 😊`;
    }
    
    try {
        // Verificar se é um usuário temporário (de lista/grupo)
        const usuario = usuarioMap[numeroCliente];
        let jidDestino = null;
        
        if (usuario?.temporario && usuario?.jidOriginal) {
            // Para usuários temporários, usar o JID original da lista/grupo
            jidDestino = usuario.jidOriginal;
            console.log(`${formatarDataHora()} 📨 Enviando para JID original (lista/grupo): ${jidDestino}`);
        } else {
            // Para usuários normais, converter número para JID individual
            jidDestino = getJID(numeroCliente);
        }
        
        if (jidDestino) {
            await sockInstance.sendMessage(jidDestino, { text: mensagem });
            console.log(`${formatarDataHora()} 📨 Mensagem enviada para ${pushName} (${jidDestino})`);
        } else {
            console.error(`${formatarDataHora()} ❌ JID inválido para:`, numeroCliente);
        }
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao enviar:`, error);
    }
}

// ⚠️ VERIFICAR TIMEOUTS
async function verificarTimeouts() {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        const agora = Date.now();
        const tempoAtendimento = (config.tempo_atendimento_humano || 5) * 60 * 1000;
        
        const totalAtendimentos = Object.keys(atendimentos).length;
        
        // Só logar se a quantidade mudou ou se houver ação
        let houveAcao = false;
        
        for (const [numeroCliente, atendimento] of Object.entries(atendimentos)) {
            // Buscar usuário pelo número
            const usuario = usuarioMap[numeroCliente];
            const pushName = usuario?.pushName || 'Cliente';
            
            // Timeout para atendimento humano
            if (atendimento.tipo === 'humano' && atendimento.timeout && agora > atendimento.timeout) {
                console.log(`${formatarDataHora()} ⏰ Timeout expirado para ${pushName}`);
                await encerrarAtendimento(numeroCliente, pushName, config, "timeout");
                houveAcao = true;
            }
            
            // Timeout para CPF (5 minutos)
            if (atendimento.tipo === 'aguardando_cpf' && atendimento.inicio && 
                (agora - atendimento.inicio) > (5 * 60 * 1000)) {
                console.log(`${formatarDataHora()} ⏰ Timeout CPF expirado para ${pushName}`);
                await encerrarAtendimento(numeroCliente, pushName, config, "timeout");
                houveAcao = true;
            }
        }
        
        // Logar apenas se a quantidade mudou ou se houve ação
        if (totalAtendimentos !== ultimoLogVerificacao.quantidade || houveAcao) {
            console.log(`${formatarDataHora()} 🔄 Verificando ${totalAtendimentos} atendimento(s)`);
            ultimoLogVerificacao.quantidade = totalAtendimentos;
            ultimoLogVerificacao.timestamp = agora;
        }
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao verificar timeouts:`, error);
    }
}

// ⚠️ MENU PRINCIPAL
async function enviarMenuPrincipal(sock, usuario, texto = '') {
    try {
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        const pushName = usuario?.pushName || '';
        const numeroCliente = usuario?.numero;
        
        const menuText = 
`Olá! 👋  ${pushName ? pushName + ' ' : ''}

Bem-vindo ao atendimento da *${config.empresa}*

 1️⃣ Baixar Fatura PIX
 2️⃣ Falar com Atendente

Digite o número da opção desejada:`;

        // Verificar se é um usuário temporário (de lista/grupo)
        let jidDestino = null;
        
        if (usuario?.temporario && usuario?.jidOriginal) {
            // Para usuários temporários, usar o JID original da lista/grupo
            jidDestino = usuario.jidOriginal;
            console.log(`${formatarDataHora()} 📨 Enviando menu para JID original (lista/grupo): ${jidDestino}`);
        } else {
            // Para usuários normais, converter número para JID individual
            jidDestino = getJID(numeroCliente);
        }
        
        if (jidDestino) {
            await sock.sendMessage(jidDestino, { text: menuText });
            console.log(`${formatarDataHora()} ✅ Menu enviado para ${pushName || numeroCliente} em ${jidDestino}`);
        } else {
            console.error(`${formatarDataHora()} ❌ Não foi possível enviar menu: JID inválido para ${numeroCliente}`);
            
            // Tentar extrair número do texto se disponível
            if (texto) {
                const match = texto.match(/\d{10,}/g);
                if (match && match.length > 0) {
                    const num = match[0].replace(/\D/g, '');
                    if (num.length >= 10) {
                        const numeroFormatado = num.startsWith('55') ? num : `55${num}`;
                        const jidAlternativo = getJID(numeroFormatado);
                        if (jidAlternativo) {
                            await sock.sendMessage(jidAlternativo, { text: menuText });
                            console.log(`${formatarDataHora()} ✅ Menu enviado via número extraído para ${numeroFormatado}`);
                        }
                    }
                }
            }
        }
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao enviar menu:`, error);
    }
}

// Função auxiliar para enviar mensagem para usuário
async function enviarMensagemParaUsuario(sock, usuario, mensagem) {
    console.log(`${formatarDataHora()} 📤 [ENVIAR] Iniciando envio para: ${usuario.numero}`);
    console.log(`${formatarDataHora()} 📤 [ENVIAR] Usuário temporário? ${usuario?.temporario || 'não'}`);
    console.log(`${formatarDataHora()} 📤 [ENVIAR] JID original: ${usuario?.jidOriginal || 'não tem'}`);
    
    try {
        // Verificar se é um usuário temporário (de lista/grupo)
        let jidDestino = null;
        
        if (usuario?.temporario && usuario?.jidOriginal) {
            // Para usuários temporários, usar o JID original da lista/grupo
            jidDestino = usuario.jidOriginal;
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Usando JID original (lista/grupo): ${jidDestino}`);
        } else {
            // Para usuários normais, converter número para JID individual
            jidDestino = getJID(usuario.numero);
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Convertendo número para JID: ${usuario.numero} -> ${jidDestino}`);
        }
        
        if (jidDestino) {
            console.log(`${formatarDataHora()} 📤 [ENVIAR] JID final: ${jidDestino}`);
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Mensagem (primeiros 50 chars): ${mensagem.substring(0, 50)}...`);
            
            // ⚠️ TESTE: Verificar se sock está disponível
            if (!sock || !sock.sendMessage) {
                console.error(`${formatarDataHora()} 📤 [ENVIAR] ❌ sock ou sendMessage não disponível!`);
                return false;
            }
            
            console.log(`${formatarDataHora()} 📤 [ENVIAR] Chamando sock.sendMessage...`);
            await sock.sendMessage(jidDestino, { text: mensagem });
            
            console.log(`${formatarDataHora()} 📤 [ENVIAR] ✅ Mensagem enviada para ${usuario.pushName || usuario.numero}`);
            return true;
        } else {
            console.error(`${formatarDataHora()} 📤 [ENVIAR] ❌ JID inválido para:`, usuario.numero);
            console.error(`${formatarDataHora()} 📤 [ENVIAR] Detalhes usuário:`, JSON.stringify(usuario, null, 2));
            return false;
        }
    } catch (error) {
        console.error(`${formatarDataHora()} 📤 [ENVIAR] ❌ ERRO CRÍTICO ao enviar mensagem:`, error);
        console.error(`${formatarDataHora()} 📤 [ENVIAR] Stack trace:`, error.stack);
        return false;
    }
}

async function startBot() {
    // ⚠️ CARREGAR USUÁRIOS
    carregarUsuarios();
    
    // ⚠️ LIMPAR USUÁRIOS INATIVOS AO INICIAR
    limparUsuariosInativos();
    
    // ⚠️ AGENDAR LIMPEZA DIÁRIA (uma vez por dia às 2h)
    setInterval(() => {
        const agora = new Date();
        if (agora.getHours() === 2 && agora.getMinutes() === 0) {
            limparUsuariosInativos();
        }
    }, 60000); // Verificar a cada minuto

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

    const sock = makeWASocket({
        auth: state,
        logger: P({ level: 'silent' })
    });

    sockInstance = sock;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', ({ connection, lastDisconnect, qr }) => {
        if (qr) {
            fs.writeFileSync(QR_PATH, qr);
            setStatus('qr');
            console.log(`${formatarDataHora()} 📱 QR Code gerado. Escaneie com o WhatsApp.`);
        }

        if (connection === 'open') {
            fs.writeFileSync(QR_PATH, '');
            setStatus('online');
            console.log(`${formatarDataHora()} ✅ WhatsApp conectado - COM CONTROLE DE FERIADOS`);
            console.log(`${formatarDataHora()} 👥 ${Object.keys(usuarioMap).length} usuário(s)`);
            console.log(`${formatarDataHora()} 🕐 Horário comercial: ${dentroHorarioComercial() ? 'ABERTO' : 'FECHADO'}`);
            console.log(`${formatarDataHora()} 🎯 Feriados ativos: ${ehFeriado(new Date()) ? 'SIM (hoje é feriado)' : 'VERIFICAR CONFIG'}`);
            
            // ⚠️ INICIAR TIMEOUT
            setInterval(verificarTimeouts, 30000);
            console.log(`${formatarDataHora()} ⏱️ Sistema de timeout ativo`);
        }

        if (connection === 'close') {
            setStatus('offline');
            if (lastDisconnect?.error?.output?.statusCode !== DisconnectReason.loggedOut) {
                console.log(`${formatarDataHora()} 🔄 Reconectando...`);
                startBot();
            }
        }
    });

    sock.ev.on('messages.upsert', async ({ messages }) => {
        if (!messages || !Array.isArray(messages) || messages.length === 0) {
            return;
        }

        const msg = messages[0];
        
        // Verificar se a mensagem é do próprio bot
        if (msg.key.fromMe) {
            console.log(`${formatarDataHora()} 🤖 Ignorando mensagem do próprio bot`);
            return;
        }
        
        if (!msg.message) {
            console.log(`${formatarDataHora()} 📭 Mensagem sem conteúdo`);
            return;
        }

        // Obter JID do remetente
        const jidRemetente = msg.key.remoteJid;
        if (!jidRemetente) {
            console.error(`${formatarDataHora()} ❌ Não foi possível obter JID do remetente`);
            return;
        }
        
        // Obter texto da mensagem
        const texto = (
            msg.message.conversation ||
            msg.message.extendedTextMessage?.text ||
            ''
        ).trim();

        const pushName = msg.pushName || 'Cliente';
        
        console.log(`\n${formatarDataHora()} 📨 MENSAGEM DE: ${pushName} (${jidRemetente}) - "${texto}"`);

        // ⚠️ IDENTIFICAR USUÁRIO com cuidado para não confundir CPF/CNPJ com telefone
        // Primeiro precisamos saber o contexto atual do usuário
        let contextoAtualParaIdentificacao = 'menu';
        
        // Tentar encontrar o usuário temporário primeiro
        let usuarioTemporario = null;
        for (const [chave, user] of Object.entries(usuarioMap)) {
            if (user.jidOriginal === jidRemetente && user.temporario) {
                usuarioTemporario = user;
                contextoAtualParaIdentificacao = contextos[user.numero] || 'menu';
                break;
            }
        }
        
        const ignorarExtracaoNumero = (contextoAtualParaIdentificacao === 'aguardando_cpf');
        console.log(`${formatarDataHora()} 🔍 Identificando usuário (ignorarExtracao: ${ignorarExtracaoNumero}, contexto: ${contextoAtualParaIdentificacao})`);

        const usuario = identificarUsuario(jidRemetente, pushName, texto, ignorarExtracaoNumero);
        
        if (!usuario) {
            console.log(`${formatarDataHora()} ❌ Usuário não identificado`);
            return;
        }

        const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
        const isAtendente = usuario.tipo === 'atendente';
        const numeroCliente = usuario.numero;

        console.log(`${formatarDataHora()} 🔢 ${pushName} -> ${numeroCliente} (${usuario.tipo})`);

        // ⚠️ ENCERRAMENTO PELO ATENDENTE
        if (isAtendente) {
            if (texto === '#FECHAR') {
                console.log(`${formatarDataHora()} 🚪 Atendente encerrando tudo`);
                
                const clientesAtivos = Object.keys(atendimentos);
                console.log(`${formatarDataHora()} 👥 ${clientesAtivos.length} cliente(s)`);
                
                for (const clienteNum of clientesAtivos) {
                    const clienteInfo = usuarioMap[clienteNum];
                    const nomeCliente = clienteInfo?.pushName || 'Cliente';
                    
                    await encerrarAtendimento(clienteNum, nomeCliente, config, "atendente");
                }
                
                // Enviar mensagem para o atendente
                const jidAtendente = getJID(usuario.numero);
                if (jidAtendente) {
                    await sock.sendMessage(jidAtendente, {
                        text: `👨‍💼 *ATENDENTE:* Todos os atendimentos encerrados.\n\nA *${config.empresa}* agradece!`
                    });
                }
                return;
            }
            
            console.log(`${formatarDataHora()} 💬 Atendente falando - enviando para clientes em atendimento`);
            return;
        }

        // ⚠️ SE FOR CLIENTE
        if (!isAtendente) {
            const contextoAtual = contextos[numeroCliente] || 'menu';
            
            console.log(`${formatarDataHora()} 📊 Contexto atual: ${contextoAtual}`);

            // ⚠️ OPÇÃO 0 - ENCERRAR
            if (texto === '0' && (contextoAtual === 'pos_pix' || contextoAtual === 'em_atendimento')) {
                console.log(`${formatarDataHora()} 🔄 Cliente encerrando`);
                await encerrarAtendimento(numeroCliente, pushName, config, "cliente");
                return;
            }

            // ⚠️ OPÇÃO 9 - NOVO ATENDIMENTO
            if (texto === '9' && (contextoAtual === 'menu' || contextoAtual === 'pos_pix')) {
                contextos[numeroCliente] = 'menu';
                await enviarMenuPrincipal(sock, usuario, texto);
                return;
            }

            // ⚠️ CLIENTE EM ATENDIMENTO HUMANO
            if (atendimentos[numeroCliente]?.tipo === 'humano') {
                console.log(`${formatarDataHora()} 🤐 Cliente em atendimento humano - mensagem será encaminhada ao atendente`);
                
                if (atendimentos[numeroCliente]) {
                    const tempoTimeout = (config.tempo_atendimento_humano || 5) * 60 * 1000;
                    atendimentos[numeroCliente].timeout = Date.now() + tempoTimeout;
                    console.log(`${formatarDataHora()} ⏰ Timeout renovado para ${pushName}`);
                }
                return;
            }

            // ⚠️ MENU PRINCIPAL
            if (contextoAtual === 'menu') {
                if (texto === '1') {
                    console.log(`${formatarDataHora()} 💠 Cliente escolheu PIX`);
                    contextos[numeroCliente] = 'aguardando_cpf';
                    atendimentos[numeroCliente] = {
                        tipo: 'aguardando_cpf',
                        inicio: Date.now(),
                        timeout: null
                    };
                    
                    await enviarMensagemParaUsuario(sock, usuario, `🔐 Informe seu CPF ou CNPJ:`);
                    return;
                    
                } else if (texto === '2') {
                    console.log(`${formatarDataHora()} 👨‍💼 Cliente escolheu atendimento`);
                    
                    // ⚠️ VERIFICAR HORÁRIO COMERCIAL COM FERIADOS
                    if (!dentroHorarioComercial()) {
                        console.log(`${formatarDataHora()} ⏰ Fora do horário comercial ou feriado`);
                        
                        // Verificar se é feriado específico
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
                    
                    // Criar atendimento
                    const tempoTimeout = config.tempo_atendimento_humano || 5;
                    atendimentos[numeroCliente] = {
                        tipo: 'humano',
                        inicio: Date.now(),
                        timeout: Date.now() + (tempoTimeout * 60 * 1000)
                    };
                    contextos[numeroCliente] = 'em_atendimento';
                    
                    console.log(`${formatarDataHora()} ⏱️ Atendimento iniciado (${tempoTimeout}min)`);
                    
                    await enviarMensagemParaUsuario(sock, usuario, 
                        `👨‍💼 *ATENDIMENTO INICIADO*\n\n*${pushName}*, um atendente falará com você em instantes, aguarde...\n\n⏱️ Duração: ${tempoTimeout} minutos\n\n 0️⃣ Encerrar Atendimento`
                    );
                    return;
                    
                } else {
                    // Qualquer outra mensagem no menu, reenviar o menu
                    await enviarMenuPrincipal(sock, usuario, texto);
                    return;
                }
            }

            // ⚠️ AGUARDANDO CPF (CORRIGIDO - NÃO CONFUNDE COM TELEFONE)
            if (contextoAtual === 'aguardando_cpf') {
                console.log(`${formatarDataHora()} 📄 [DEBUG] Contexto aguardando_cpf ATIVADO`);
                console.log(`${formatarDataHora()} 📄 [DEBUG] Texto recebido: "${texto}"`);
                console.log(`${formatarDataHora()} 📄 [DEBUG] Usuário: ${pushName} (${numeroCliente})`);
                
                if (atendimentos[numeroCliente]) {
                    atendimentos[numeroCliente].inicio = Date.now();
                    console.log(`${formatarDataHora()} 📄 [DEBUG] Atendimento atualizado`);
                }
                
                // Se digitar comando
                if (texto === '0' || texto === '9' || texto === '1' || texto === '2') {
                    console.log(`${formatarDataHora()} 📄 [DEBUG] Comando detectado: ${texto}`);
                    delete atendimentos[numeroCliente];
                    contextos[numeroCliente] = 'menu';
                    await enviarMenuPrincipal(sock, usuario, texto);
                    return;
                }
                
                // ⚠️ LOG DETALHADO DO PROCESSAMENTO
                console.log(`${formatarDataHora()} 📄 [DEBUG] Iniciando processamento do documento...`);
                const doc = limparDoc(texto);
                console.log(`${formatarDataHora()} 📄 [DEBUG] Documento após limpar: "${doc}"`);
                console.log(`${formatarDataHora()} 📄 [DEBUG] Tamanho do documento: ${doc.length} dígitos`);
                
                // Testar regex
                const temApenasNumeros = /^\d+$/.test(doc);
                console.log(`${formatarDataHora()} 📄 [DEBUG] Tem apenas números? ${temApenasNumeros}`);
                
                // Validar CPF (11 dígitos)
                if (doc.length === 11 && temApenasNumeros) {
                    console.log(`${formatarDataHora()} 📄 [DEBUG] ✅ CPF VÁLIDO DETECTADO!`);
                    console.log(`${formatarDataHora()} 📄 [DEBUG] CPF: ${doc}`);
                    
                    try {
                        console.log(`${formatarDataHora()} 📄 [DEBUG] Tentando enviar mensagem com link PIX...`);
                        
                        const mensagemPix = `💠 *Pagamento via PIX*\n\nclique no link abaixo para acessar sua fatura:\n🔗 ${config.boleto_url}?doc=${doc}\n\n0️⃣  Encerrar  |  9️⃣  Retornar ao Menu`;
                        
                        // Chamar função de envio DIRETAMENTE para debug
                        console.log(`${formatarDataHora()} 📄 [DEBUG] Chamando enviarMensagemParaUsuario...`);
                        const resultado = await enviarMensagemParaUsuario(sock, usuario, mensagemPix);
                        
                        if (resultado) {
                            console.log(`${formatarDataHora()} 📄 [DEBUG] ✅ Mensagem enviada com sucesso!`);
                            delete atendimentos[numeroCliente];
                            contextos[numeroCliente] = 'pos_pix';
                            console.log(`${formatarDataHora()} 📄 [DEBUG] Contexto alterado para: pos_pix`);
                        } else {
                            console.log(`${formatarDataHora()} 📄 [DEBUG] ❌ Falha ao enviar mensagem!`);
                            // Tentar enviar mensagem de erro
                            await enviarMensagemParaUsuario(sock, usuario, 
                                `❌ Ocorreu um erro ao processar. Tente novamente.`
                            );
                        }
                        
                    } catch (error) {
                        console.error(`${formatarDataHora()} 📄 [DEBUG] ❌ ERRO no try/catch:`, error);
                        console.error(`${formatarDataHora()} 📄 [DEBUG] Stack trace:`, error.stack);
                    }
                    return;
                    
                // Validar CNPJ (14 dígitos)
                } else if (doc.length === 14 && temApenasNumeros) {
                    console.log(`${formatarDataHora()} 📄 [DEBUG] ✅ CNPJ VÁLIDO DETECTADO!`);
                    console.log(`${formatarDataHora()} 📄 [DEBUG] CNPJ: ${doc}`);
                    
                    try {
                        const mensagemPix = `💠 *Pagamento via PIX*\n\nclique no link abaixo para acessar sua fatura:\n🔗 ${config.boleto_url}?doc=${doc}\n\n0️⃣  Encerrar  |  9️⃣  Retornar ao Menu`;
                        
                        const resultado = await enviarMensagemParaUsuario(sock, usuario, mensagemPix);
                        
                        if (resultado) {
                            console.log(`${formatarDataHora()} 📄 [DEBUG] ✅ Mensagem CNPJ enviada!`);
                            delete atendimentos[numeroCliente];
                            contextos[numeroCliente] = 'pos_pix';
                        } else {
                            console.log(`${formatarDataHora()} 📄 [DEBUG] ❌ Falha ao enviar CNPJ!`);
                        }
                        
                    } catch (error) {
                        console.error(`${formatarDataHora()} 📄 [DEBUG] ❌ ERRO CNPJ:`, error);
                    }
                    return;
                    
                } else {
                    // Documento inválido
                    console.log(`${formatarDataHora()} 📄 [DEBUG] ❌ DOCUMENTO INVÁLIDO`);
                    console.log(`${formatarDataHora()} 📄 [DEBUG] Razão: length=${doc.length}, apenasNumeros=${temApenasNumeros}`);
                    
                    try {
                        let mensagemErro = `❌ ${pushName}, formato inválido.\n\n`;
                        
                        if (doc.length > 0 && !temApenasNumeros) {
                            mensagemErro += `⚠️ Contém caracteres inválidos.\n`;
                        }
                        
                        mensagemErro += `\n📋 *Formatos aceitos:*\n`;
                        mensagemErro += `• CPF: 11 dígitos (ex: 12345678901)\n`;
                        mensagemErro += `• CNPJ: 14 dígitos (ex: 12345678000199)\n\n`;
                        mensagemErro += `Digite novamente:`;
                        
                        console.log(`${formatarDataHora()} 📄 [DEBUG] Enviando mensagem de erro...`);
                        await enviarMensagemParaUsuario(sock, usuario, mensagemErro);
                        
                    } catch (error) {
                        console.error(`${formatarDataHora()} 📄 [DEBUG] ❌ ERRO ao enviar mensagem de erro:`, error);
                    }
                }
                
                console.log(`${formatarDataHora()} 📄 [DEBUG] Fim do processamento aguardando_cpf`);
                return;
            }

            // ⚠️ CONTEXTO PÓS-PIX
            if (contextoAtual === 'pos_pix') {
                await enviarMensagemParaUsuario(sock, usuario, 
                    `PIX já gerado.\n\n0️⃣  Encerrar  |  9️⃣  Retornar ao Menu`
                );
                return;
            }
            
            // Se chegou aqui e não é um contexto conhecido, enviar menu
            if (!['menu', 'aguardando_cpf', 'pos_pix', 'em_atendimento'].includes(contextoAtual)) {
                await enviarMenuPrincipal(sock, usuario, texto);
            }
        }
    });
}

startBot();
