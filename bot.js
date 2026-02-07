/*************************************************
 * BOT WHATSAPP - VERSÃO COMPLETA COM FERIADOS
 * Controle de feriados via painel web
 * CORRIGIDO: Suporte para mensagens individuais e grupos
 * ADICIONADO: Data/hora nos logs + Limpeza automática de usuários
 * CORRIGIDO: Bug CPF/CNPJ apenas números (não confundir com telefone)
 * ATUALIZADO: Identificação automática do atendente via conexão QR Code
 * CORRIGIDO: Captura correta do número do WhatsApp conectado (com formato :sessao)
 * CORRIGIDO: Prevenção de duplicação atendente/cliente
 * CORRIGIDO: Ignorar mensagens de sistema/sincronização
 * ADICIONADO: Atualização automática do número do atendente no config.json
 * ADICIONADO: Limpeza automática da pasta auth_info ao detectar desconexão (loggedOut)
 * CORRIGIDO: Comando #FECHAR do atendente agora funciona corretamente
 * ADICIONADO: Timeout automático para tela PIX (10 minutos)
 * ADICIONADO: Comandos #FECHAR [número] e #FECHAR [nome] para encerrar individualmente
 * ADICIONADO: Comando #CLIENTES para listar atendimentos ativos
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

// ⚠️ FUNÇÃO PARA LIMPAR AUTH_INFO
function limparAuthInfo() {
    try {
        if (fs.existsSync(AUTH_DIR)) {
            console.log(`${formatarDataHora()} 🗑️ Limpando pasta auth_info...`);
            
            // Remover todos os arquivos da pasta
            const files = fs.readdirSync(AUTH_DIR);
            for (const file of files) {
                fs.unlinkSync(path.join(AUTH_DIR, file));
                console.log(`${formatarDataHora()} 🗑️ Removido: ${file}`);
            }
            
            // Remover a pasta
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
        let numero = jid.split('@')[0];
        
        // ⚠️ CORREÇÃO: Se tiver ":" (como "558382341576:27"), pegar apenas a parte antes dos ":"
        if (numero.includes(':')) {
            numero = numero.split(':')[0];
        }
        
        // Garantir que comece com 55
        if (numero && numero.length >= 10) {
            // ⚠️ CORREÇÃO: Remover caracteres não numéricos
            const numeroLimpo = numero.replace(/\D/g, '');
            return numeroLimpo.startsWith('55') ? numeroLimpo : `55${numeroLimpo}`;
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

// ⚠️ ATUALIZAR NÚMERO DO ATENDENTE NO CONFIG.JSON
function atualizarAtendenteNoConfig(numeroAtendente) {
    try {
        console.log(`${formatarDataHora()} ⚙️ Atualizando número do atendente no config.json: ${numeroAtendente}`);
        
        // Ler o arquivo config.json atual
        const configAtual = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
        
        // Registrar o número anterior para log
        const numeroAnterior = configAtual.atendente_numero || 'não definido';
        
        // Atualizar apenas o campo atendente_numero
        configAtual.atendente_numero = numeroAtendente;
        
        // Salvar de volta no arquivo
        fs.writeFileSync(CONFIG_PATH, JSON.stringify(configAtual, null, 2));
        
        console.log(`${formatarDataHora()} ✅ Número do atendente atualizado: ${numeroAnterior} → ${numeroAtendente}`);
        
        // ⚠️ LOG DETALHADO PARA DEBUG
        console.log(`${formatarDataHora()} 📋 Config.json atualizado:`);
        console.log(JSON.stringify(configAtual, null, 2));
        
        return true;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao atualizar config.json:`, error);
        console.error(`${formatarDataHora()} ❌ Detalhes do erro:`, error.stack);
        return false;
    }
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
            
            // Verificar se há algum atendente registrado
            const atendentes = Object.values(usuarioMap).filter(u => u.tipo === 'atendente');
            console.log(`${formatarDataHora()} 👨‍💼 ${atendentes.length} atendente(s) registrado(s)`);
            
            // ⚠️ VERIFICAR SE HÁ ATENDENTE E ATUALIZAR CONFIG.JSON SE NECESSÁRIO
            if (atendentes.length > 0) {
                // Pegar o primeiro atendente (deveria ter apenas um)
                const primeiroAtendente = atendentes[0];
                console.log(`${formatarDataHora()} 🔄 Verificando consistência: atendente ${primeiroAtendente.numero} encontrado`);
                
                try {
                    // Verificar se o config.json tem o número correto
                    const configAtual = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
                    if (configAtual.atendente_numero !== primeiroAtendente.numero) {
                        console.log(`${formatarDataHora()} ⚠️ Número no config.json (${configAtual.atendente_numero}) difere do atendente (${primeiroAtendente.numero})`);
                        
                        // Atualizar automaticamente para manter consistência
                        atualizarAtendenteNoConfig(primeiroAtendente.numero);
                    }
                } catch (error) {
                    console.error(`${formatarDataHora()} ❌ Erro ao verificar config.json:`, error);
                }
            }
            
        } else {
            // Arquivo não existe - criar estrutura vazia
            // O atendente será registrado quando o WhatsApp se conectar
            usuarioMap = {};
            console.log(`${formatarDataHora()} 📂 Mapa de usuários inicializado (vazio)`);
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

// ⚠️ LIMPAR NÚMEROS DUPLICADOS E INCONSISTÊNCIAS
function limparInconsistenciasUsuarios() {
    try {
        console.log(`${formatarDataHora()} 🧹 Verificando inconsistências nos usuários...`);
        
        const numerosVistos = new Set();
        const chavesParaRemover = [];
        let inconsistencias = 0;
        
        for (const [chave, usuario] of Object.entries(usuarioMap)) {
            // Verificar se o número já foi visto
            if (numerosVistos.has(usuario.numero)) {
                console.log(`${formatarDataHora()} ⚠️ Número duplicado encontrado: ${usuario.numero} (${usuario.tipo})`);
                chavesParaRemover.push(chave);
                inconsistencias++;
            } else {
                numerosVistos.add(usuario.numero);
            }
            
            // Verificar se número tem caracteres inválidos
            if (usuario.numero.includes(':') || /\D/.test(usuario.numero.replace('55', ''))) {
                console.log(`${formatarDataHora()} ⚠️ Número com formato inválido: ${usuario.numero}`);
                chavesParaRemover.push(chave);
                inconsistencias++;
            }
            
            // Verificar se número tem comprimento muito longo (mais de 13 dígitos)
            if (usuario.numero.length > 13) {
                console.log(`${formatarDataHora()} ⚠️ Número muito longo: ${usuario.numero} (${usuario.numero.length} dígitos)`);
                chavesParaRemover.push(chave);
                inconsistencias++;
            }
        }
        
        // Remover duplicatas (mantendo a primeira ocorrência)
        for (const chave of chavesParaRemover) {
            console.log(`${formatarDataHora()} 🗑️ Removendo entrada inconsistente: ${chave}`);
            delete usuarioMap[chave];
        }
        
        if (inconsistencias > 0) {
            salvarUsuarios();
            console.log(`${formatarDataHora()} ✅ ${inconsistencias} inconsistência(s) corrigida(s)`);
        }
        
        return inconsistencias;
        
    } catch (error) {
        console.error(`${formatarDataHora()} ❌ Erro ao limpar inconsistências:`, error);
        return 0;
    }
}

// ⚠️ LIMPAR USUÁRIOS INATIVOS
function limparUsuariosInativos() {
    try {
        const agora = new Date();
        let removidos = 0;
        const usuariosParaManter = {};
        
        for (const [chave, usuario] of Object.entries(usuarioMap)) {
            // SEMPRE manter o(s) atendente(s)
            if (usuario.tipo === 'atendente') {
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
    
    // ⚠️ CORREÇÃO: PRIMEIRO verificar se já é atendente registrado
    // Verificar se existe algum atendente com este número
    for (const [chave, usuario] of Object.entries(usuarioMap)) {
        if (usuario.numero === numero && usuario.tipo === 'atendente') {
            console.log(`${formatarDataHora()} ✅ Este número já é atendente: ${pushName} -> ${numero}`);
            
            // Atualizar pushName se necessário
            if (pushName && pushName !== usuario.pushName) {
                usuarioMap[chave].pushName = pushName;
                salvarUsuarios();
            }
            
            return usuarioMap[chave];
        }
    }
    
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
    
    // 2. NOVO CLIENTE
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
            
            // ⚠️ CORREÇÃO ADICIONADA: Timeout para tela PIX (10 minutos)
            if (atendimento.tipo === 'pos_pix' && atendimento.inicio && 
                (agora - atendimento.inicio) > (10 * 60 * 1000)) {
                console.log(`${formatarDataHora()} ⏰ Timeout PIX expirado para ${pushName}`);
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
    
    // ⚠️ LIMPAR INCONSISTÊNCIAS NOS USUÁRIOS
    limparInconsistenciasUsuarios();
    
    // ⚠️ LIMPAR USUÁRIOS INATIVOS AO INICIAR
    limparUsuariosInativos();
    
    // ⚠️ AGENDAR LIMPEZA DIÁRIA (uma vez por dia às 2h)
    setInterval(() => {
        const agora = new Date();
        if (agora.getHours() === 2 && agora.getMinutes() === 0) {
            limparUsuariosInativos();
        }
    }, 60000); // Verificar a cada minuto

    // Verificar se a pasta auth_info existe antes de tentar usar
    if (!fs.existsSync(AUTH_DIR)) {
        console.log(`${formatarDataHora()} ℹ️ Pasta auth_info não existe - será criada ao gerar QR Code`);
    }

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

    const sock = makeWASocket({
        auth: state,
        logger: P({ level: 'silent' })
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
            
            // ⚠️ CAPTURAR CREDENCIAIS DO WHATSAPP CONECTADO (VERSÃO CORRIGIDA PARA FORMATO COM ":sessao")
            try {
                const user = sock.user;
                if (user && user.id) {
                    console.log(`${formatarDataHora()} 🔍 Dados do usuário conectado:`, JSON.stringify(user, null, 2));
                    
                    // Extrair número do ID (removendo @s.whatsapp.net)
                    // ⚠️ CORREÇÃO: Lidar com formato como "558382341576:27@s.whatsapp.net"
                    let numero = user.id.split('@')[0];
                    
                    console.log(`${formatarDataHora()} 🔍 Número bruto extraído: ${numero}`);
                    
                    // ⚠️ CORREÇÃO CRÍTICA: Se tiver ":" (como "558382341576:27"), pegar apenas a parte antes dos ":"
                    if (numero.includes(':')) {
                        console.log(`${formatarDataHora()} ⚠️ Número contém ':', separando...`);
                        numero = numero.split(':')[0];
                        console.log(`${formatarDataHora()} 🔍 Número após separar ':': ${numero}`);
                    }
                    
                    // ⚠️ CORREÇÃO: Remover todos os caracteres não numéricos
                    numero = numero.replace(/\D/g, '');
                    
                    console.log(`${formatarDataHora()} 🔍 Número após limpeza: ${numero} (${numero.length} dígitos)`);
                    
                    // ⚠️ CORREÇÃO: Verificar se tem comprimento válido (10-13 dígitos para Brasil com código país)
                    if (numero.length >= 10 && numero.length <= 13) {
                        // Garantir que comece com 55
                        if (!numero.startsWith('55')) {
                            numero = '55' + numero;
                            console.log(`${formatarDataHora()} 🔍 Número após adicionar 55: ${numero}`);
                        }
                        
                        // ⚠️ VERIFICAÇÃO FINAL: Garantir que tenha comprimento correto
                        if (numero.length >= 12 && numero.length <= 13) {
                            const pushName = user.name || 'Atendente WhatsApp';
                            
                            console.log(`${formatarDataHora()} 🔐 WhatsApp conectado como: ${pushName} (${numero})`);
                            
                            // ⚠️ CORREÇÃO: Limpar atendentes antigos ANTES de adicionar o novo
                            // E também remover qualquer entrada CLIENTE com esse mesmo número
                            const chavesParaRemover = [];
                            
                            for (const [chave, usuario] of Object.entries(usuarioMap)) {
                                // Remover todos os atendentes existentes
                                if (usuario.tipo === 'atendente') {
                                    console.log(`${formatarDataHora()} 🗑️ Removendo atendente antigo: ${usuario.pushName} (${usuario.numero})`);
                                    chavesParaRemover.push(chave);
                                }
                                // ⚠️ TAMBÉM remover cliente com mesmo número (se houver)
                                else if (usuario.numero === numero) {
                                    console.log(`${formatarDataHora()} 🗑️ Removendo cliente com mesmo número: ${usuario.pushName} (${usuario.numero})`);
                                    chavesParaRemover.push(chave);
                                }
                            }
                            
                            // Remover as chaves identificadas
                            for (const chave of chavesParaRemover) {
                                delete usuarioMap[chave];
                            }
                            
                            // Atualizar/registrar como atendente no arquivo usuarios.json
                            usuarioMap[numero] = {
                                numero: numero,
                                tipo: 'atendente',
                                pushName: pushName,
                                cadastradoEm: new Date().toISOString()
                            };
                            
                            // Salvar no arquivo
                            salvarUsuarios();
                            
                            console.log(`${formatarDataHora()} ✅ Atendente registrado/atualizado: ${pushName} (${numero})`);
                            console.log(`${formatarDataHora()} 📊 Total de usuários: ${Object.keys(usuarioMap).length}`);
                            
                            // ⚠️ ATUALIZAR NÚMERO DO ATENDENTE NO CONFIG.JSON
                            atualizarAtendenteNoConfig(numero);
                            
                            // ⚠️ IMPORTANTE: ENVIAR MENSAGEM PARA O ATENDENTE CONFIRMANDO
                            try {
                                const jidAtendente = getJID(numero);
                                if (jidAtendente) {
                                    const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
                                    await sock.sendMessage(jidAtendente, {
                                        text: `👨‍💼 *ATENDENTE CONFIGURADO*\n\nOlá ${pushName}! Você foi configurado como atendente do bot da *${config.empresa}*.\n\n*Comandos disponíveis:*\n• #FECHAR - Encerra todos os atendimentos\n• #FECHAR [número] - Encerra cliente específico\n• #FECHAR [nome] - Encerra por nome\n• #CLIENTES - Lista clientes ativos`
                                    });
                                    console.log(`${formatarDataHora()} 📨 Mensagem de confirmação enviada para o atendente`);
                                }
                            } catch (error) {
                                console.error(`${formatarDataHora()} ❌ Erro ao enviar mensagem para atendente:`, error);
                            }
                        } else {
                            console.error(`${formatarDataHora()} ❌ Número com comprimento inválido após formatação: ${numero} (${numero.length} dígitos)`);
                        }
                    } else {
                        console.error(`${formatarDataHora()} ❌ Número inválido: ${numero} (${numero.length} dígitos) - Esperado 10-13 dígitos`);
                        console.error(`${formatarDataHora()} ❌ ID original: ${user.id}`);
                    }
                } else {
                    console.error(`${formatarDataHora()} ❌ Não foi possível obter dados do usuário`);
                }
            } catch (error) {
                console.error(`${formatarDataHora()} ❌ Erro ao capturar credenciais:`, error);
                console.error(`${formatarDataHora()} ❌ Detalhes do erro:`, error.stack);
            }
            
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
            
            // ⚠️ DETECTAR SE FOI DESCONEXÃO FORÇADA (loggedOut)
            if (lastDisconnect?.error?.output?.statusCode === DisconnectReason.loggedOut) {
                console.log(`${formatarDataHora()} 🔐 WhatsApp desconectado pelo usuário (loggedOut)`);
                
                // ⚠️ LIMPAR AUTH_INFO PARA GERAR NOVO QR CODE
                const limpezaRealizada = limparAuthInfo();
                
                if (limpezaRealizada) {
                    console.log(`${formatarDataHora()} 🔄 Aguardando nova conexão com QR Code...`);
                    
                    // Aguardar 2 segundos antes de reiniciar
                    setTimeout(() => {
                        console.log(`${formatarDataHora()} 🔄 Reiniciando bot...`);
                        startBot();
                    }, 2000);
                } else {
                    // Se não conseguiu limpar, tentar reconectar normalmente
                    console.log(`${formatarDataHora()} 🔄 Tentando reconectar...`);
                    startBot();
                }
            } else {
                // Para outras desconexões, reconectar normalmente
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
        
        // ⚠️ CORREÇÃO CRÍTICA: Processar comandos do atendente ANTES de qualquer outra coisa
        // Isso evita que os comandos sejam tratados como mensagem de cliente
        
        const texto = (
            msg.message.conversation ||
            msg.message.extendedTextMessage?.text ||
            ''
        ).trim();
        
        const jidRemetente = msg.key.remoteJid;
        
        // ⚠️ DETECTAR COMANDOS DO ATENDENTE (mesmo em grupos/listas)
        if (texto.startsWith('#FECHAR') || texto === '#CLIENTES') {
            console.log(`${formatarDataHora()} 🔍 Comando do atendente detectado: ${texto}`);
            
            try {
                // Carregar configuração
                const config = JSON.parse(fs.readFileSync(CONFIG_PATH));
                
                // Buscar número do atendente no config.json
                const numeroAtendenteConfig = config.atendente_numero;
                
                // Verificar se quem enviou é o atendente
                let ehAtendente = false;
                
                // 1. Verificar se é mensagem "fromMe" (atendente enviando da conta conectada)
                if (msg.key.fromMe) {
                    ehAtendente = true;
                    console.log(`${formatarDataHora()} ✅ Comando do atendente (fromMe)`);
                } 
                // 2. Verificar se vem do número configurado como atendente
                else if (jidRemetente && numeroAtendenteConfig) {
                    // Extrair número do JID para comparar
                    const numeroRemetente = extrairNumeroDoJID(jidRemetente);
                    if (numeroRemetente === numeroAtendenteConfig) {
                        ehAtendente = true;
                        console.log(`${formatarDataHora()} ✅ Comando do atendente configurado: ${numeroAtendenteConfig}`);
                    }
                }
                // 3. Verificar se pushName corresponde ao atendente conhecido (para listas/grupos)
                else {
                    const pushName = msg.pushName || '';
                    // Buscar atendente no usuarioMap
                    for (const [chave, usuario] of Object.entries(usuarioMap)) {
                        if (usuario.tipo === 'atendente' && pushName.includes(usuario.pushName)) {
                            ehAtendente = true;
                            console.log(`${formatarDataHora()} ✅ Comando do atendente por pushName: ${usuario.pushName}`);
                            break;
                        }
                    }
                }
                
                if (ehAtendente) {
                    // ⚠️ VERIFICAR QUAL COMANDO FOI ENVIADO
                    
                    // 1. COMANDO: #CLIENTES - Listar clientes ativos
                    if (texto === '#CLIENTES') {
                        console.log(`${formatarDataHora()} 📋 Atendente solicitou lista de clientes`);
                        
                        const clientesAtivos = Object.keys(atendimentos);
                        
                        try {
                            // Buscar número do atendente no usuarioMap
                            let numeroAtendente = null;
                            for (const [chave, usuario] of Object.entries(usuarioMap)) {
                                if (usuario.tipo === 'atendente') {
                                    numeroAtendente = usuario.numero;
                                    break;
                                }
                            }
                            
                            if (numeroAtendente) {
                                const jidAtendente = getJID(numeroAtendente);
                                if (jidAtendente) {
                                    let mensagemClientes = `👥 *ATENDENTE - CLIENTES ATIVOS*\n\n`;
                                    
                                    if (clientesAtivos.length > 0) {
                                        mensagemClientes += `*Total:* ${clientesAtivos.length} cliente(s)\n\n`;
                                        
                                        clientesAtivos.forEach((clienteNum, index) => {
                                            const clienteInfo = usuarioMap[clienteNum];
                                            const nome = clienteInfo?.pushName || 'Cliente';
                                            const contexto = contextos[clienteNum] || 'desconhecido';
                                            const atendimento = atendimentos[clienteNum];
                                            
                                            // Formatar número para exibição (remover 55 se tiver)
                                            let numExibicao = clienteNum;
                                            if (numExibicao.startsWith('55')) {
                                                numExibicao = numExibicao.substring(2);
                                            }
                                            
                                            // Determinar status do atendimento
                                            let status = '';
                                            let tempoRestante = '';
                                            
                                            if (atendimento) {
                                                if (atendimento.tipo === 'humano') {
                                                    status = '👨‍💼 Atendimento humano';
                                                    if (atendimento.timeout) {
                                                        const tempo = Math.max(0, atendimento.timeout - Date.now());
                                                        const minutos = Math.floor(tempo / 60000);
                                                        tempoRestante = ` (${minutos}min restantes)`;
                                                    }
                                                } else if (atendimento.tipo === 'aguardando_cpf') {
                                                    status = '🔐 Aguardando CPF';
                                                    if (atendimento.inicio) {
                                                        const tempo = Date.now() - atendimento.inicio;
                                                        const minutos = Math.floor(tempo / 60000);
                                                        tempoRestante = ` (${minutos}min)`;
                                                    }
                                                } else if (atendimento.tipo === 'pos_pix') {
                                                    status = '💠 PIX gerado';
                                                    if (atendimento.inicio) {
                                                        const tempo = Date.now() - atendimento.inicio;
                                                        const minutos = Math.floor(tempo / 60000);
                                                        tempoRestante = ` (${minutos}min)`;
                                                    }
                                                }
                                            }
                                            
                                            mensagemClientes += `${index + 1}. *${nome}*\n`;
                                            mensagemClientes += `   📱: ${numExibicao}\n`;
                                            mensagemClientes += `   📊: ${contexto}${tempoRestante}\n`;
                                            mensagemClientes += `   🔧: #FECHAR ${numExibicao}\n\n`;
                                        });
                                        
                                        mensagemClientes += `*Comandos:*\n`;
                                        mensagemClientes += `• #FECHAR [número] - Encerra cliente\n`;
                                        mensagemClientes += `• #FECHAR [nome] - Encerra por nome\n`;
                                        mensagemClientes += `• #FECHAR - Encerra todos\n`;
                                    } else {
                                        mensagemClientes += `📭 Nenhum cliente ativo no momento.`;
                                    }
                                    
                                    await sock.sendMessage(jidAtendente, { text: mensagemClientes });
                                    console.log(`${formatarDataHora()} 📨 Lista de clientes enviada para atendente`);
                                }
                            }
                        } catch (error) {
                            console.error(`${formatarDataHora()} ❌ Erro ao enviar lista de clientes:`, error);
                        }
                        
                        // ⚠️ IMPORTANTE: RETORNAR AQUI - não processar como mensagem normal
                        return;
                    }
                    
                    // 2. COMANDO: #FECHAR - Encerrar atendimentos
                    else if (texto.startsWith('#FECHAR')) {
                        // ⚠️ VERIFICAR SE É FECHAR TODOS OU FECHAR ESPECÍFICO
                        if (texto === '#FECHAR') {
                            // FECHAR TODOS OS ATENDIMENTOS
                            const clientesAtivos = Object.keys(atendimentos);
                            console.log(`${formatarDataHora()} 🚪 Atendente encerrando TODOS os ${clientesAtivos.length} atendimento(s)`);
                            
                            for (const clienteNum of clientesAtivos) {
                                const clienteInfo = usuarioMap[clienteNum];
                                const nomeCliente = clienteInfo?.pushName || 'Cliente';
                                
                                await encerrarAtendimento(clienteNum, nomeCliente, config, "atendente");
                            }
                            
                            // Enviar confirmação apenas para o atendente (não para o grupo)
                            try {
                                // Buscar número do atendente no usuarioMap
                                let numeroAtendente = null;
                                for (const [chave, usuario] of Object.entries(usuarioMap)) {
                                    if (usuario.tipo === 'atendente') {
                                        numeroAtendente = usuario.numero;
                                        break;
                                    }
                                }
                                
                                if (numeroAtendente) {
                                    const jidAtendente = getJID(numeroAtendente);
                                    if (jidAtendente) {
                                        await sock.sendMessage(jidAtendente, {
                                            text: `👨‍💼 *ATENDENTE:* Todos os ${clientesAtivos.length} atendimento(s) encerrados.\n\nA *${config.empresa}* agradece!`
                                        });
                                        console.log(`${formatarDataHora()} 📨 Confirmação enviada para atendente individualmente`);
                                    }
                                }
                            } catch (error) {
                                console.error(`${formatarDataHora()} ❌ Erro ao enviar confirmação:`, error);
                            }
                            
                        } else if (texto.startsWith('#FECHAR ')) {
                            // ⚠️ NOVO: FECHAR ATENDIMENTO ESPECÍFICO
                            // Formato: #FECHAR [número] ou #FECHAR [nome]
                            const partes = texto.split(' ');
                            if (partes.length >= 2) {
                                const parametro = partes.slice(1).join(' ').trim();
                                console.log(`${formatarDataHora()} 🔍 Tentando encerrar atendimento específico: "${parametro}"`);
                                
                                let clienteEncontrado = null;
                                let numeroCliente = null;
                                let nomeCliente = null;
                                
                                // Buscar cliente por número ou nome
                                for (const [clienteNum, clienteInfo] of Object.entries(usuarioMap)) {
                                    if (clienteInfo.tipo === 'cliente' && atendimentos[clienteNum]) {
                                        // Verificar se o parâmetro é o número (com ou sem 55)
                                        let numeroBusca = parametro.replace(/\D/g, '');
                                        if (!numeroBusca.startsWith('55') && numeroBusca.length >= 10) {
                                            numeroBusca = '55' + numeroBusca;
                                        }
                                        
                                        if (clienteNum === numeroBusca || 
                                            clienteNum === parametro ||
                                            clienteInfo.numero === numeroBusca ||
                                            clienteInfo.numero === parametro ||
                                            (clienteInfo.pushName && clienteInfo.pushName.toLowerCase().includes(parametro.toLowerCase()))) {
                                            
                                            clienteEncontrado = clienteInfo;
                                            numeroCliente = clienteNum;
                                            nomeCliente = clienteInfo.pushName || 'Cliente';
                                            break;
                                        }
                                    }
                                }
                                
                                if (clienteEncontrado && numeroCliente) {
                                    console.log(`${formatarDataHora()} ✅ Cliente encontrado: ${nomeCliente} (${numeroCliente})`);
                                    
                                    await encerrarAtendimento(numeroCliente, nomeCliente, config, "atendente");
                                    
                                    // Enviar confirmação para o atendente
                                    try {
                                        let numeroAtendente = null;
                                        for (const [chave, usuario] of Object.entries(usuarioMap)) {
                                            if (usuario.tipo === 'atendente') {
                                                numeroAtendente = usuario.numero;
                                                break;
                                            }
                                        }
                                        
                                        if (numeroAtendente) {
                                            const jidAtendente = getJID(numeroAtendente);
                                            if (jidAtendente) {
                                                await sock.sendMessage(jidAtendente, {
                                                    text: `👨‍💼 *ATENDENTE:* Atendimento de *${nomeCliente}* (${numeroCliente}) encerrado.\n\nA *${config.empresa}* agradece!`
                                                });
                                                console.log(`${formatarDataHora()} 📨 Confirmação de encerramento individual enviada`);
                                            }
                                        }
                                    } catch (error) {
                                        console.error(`${formatarDataHora()} ❌ Erro ao enviar confirmação:`, error);
                                    }
                                    
                                } else {
                                    // Cliente não encontrado - enviar lista de clientes ativos
                                    console.log(`${formatarDataHora()} ⚠️ Cliente não encontrado: ${parametro}`);
                                    
                                    try {
                                        let numeroAtendente = null;
                                        for (const [chave, usuario] of Object.entries(usuarioMap)) {
                                            if (usuario.tipo === 'atendente') {
                                                numeroAtendente = usuario.numero;
                                                break;
                                            }
                                        }
                                        
                                        if (numeroAtendente) {
                                            const jidAtendente = getJID(numeroAtendente);
                                            if (jidAtendente) {
                                                const clientesAtivos = Object.keys(atendimentos);
                                                let mensagemErro = `❌ *ATENDENTE:* Cliente "${parametro}" não encontrado.\n\n`;
                                                mensagemErro += `*Clientes ativos (${clientesAtivos.length}):*\n`;
                                                
                                                if (clientesAtivos.length > 0) {
                                                    clientesAtivos.forEach((clienteNum, index) => {
                                                        const clienteInfo = usuarioMap[clienteNum];
                                                        const nome = clienteInfo?.pushName || 'Cliente';
                                                        // Formatar número para exibição (remover 55 se tiver)
                                                        let numExibicao = clienteNum;
                                                        if (numExibicao.startsWith('55')) {
                                                            numExibicao = numExibicao.substring(2);
                                                        }
                                                        mensagemErro += `${index + 1}. ${nome} (${numExibicao})\n`;
                                                    });
                                                    mensagemErro += `\nUse: #FECHAR [número] ou #FECHAR [nome]`;
                                                } else {
                                                    mensagemErro += `Nenhum cliente ativo no momento.`;
                                                }
                                                
                                                await sock.sendMessage(jidAtendente, { text: mensagemErro });
                                            }
                                        }
                                    } catch (error) {
                                        console.error(`${formatarDataHora()} ❌ Erro ao enviar lista de clientes:`, error);
                                    }
                                }
                            } else {
                                console.log(`${formatarDataHora()} ⚠️ Comando #FECHAR inválido - formato: #FECHAR [número/nome]`);
                                
                                // Enviar ajuda para o atendente
                                try {
                                    let numeroAtendente = null;
                                    for (const [chave, usuario] of Object.entries(usuarioMap)) {
                                        if (usuario.tipo === 'atendente') {
                                            numeroAtendente = usuario.numero;
                                            break;
                                        }
                                    }
                                    
                                    if (numeroAtendente) {
                                        const jidAtendente = getJID(numeroAtendente);
                                        if (jidAtendente) {
                                            await sock.sendMessage(jidAtendente, {
                                                text: `❌ *ATENDENTE:* Comando inválido.\n\n*Formatos válidos:*\n• #FECHAR - Encerra todos\n• #FECHAR [número] - Encerra específico\n• #FECHAR [nome] - Encerra por nome\n• #CLIENTES - Lista clientes ativos\n\nEx: #FECHAR 83982345678\nEx: #FECHAR João`
                                            });
                                        }
                                    }
                                } catch (error) {
                                    console.error(`${formatarDataHora()} ❌ Erro ao enviar ajuda:`, error);
                                }
                            }
                        }
                    }
                    
                    // ⚠️ IMPORTANTE: RETORNAR AQUI - não processar como mensagem normal
                    return;
                } else {
                    console.log(`${formatarDataHora()} ⚠️ Comando do atendente ignorado - não é do atendente`);
                }
            } catch (error) {
                console.error(`${formatarDataHora()} ❌ Erro ao processar comando do atendente:`, error);
            }
        }
        
        // ⚠️ Ignorar mensagens do próprio bot (exceto comandos já processados acima)
        if (msg.key.fromMe) {
            console.log(`${formatarDataHora()} 🤖 Ignorando mensagem do próprio bot`);
            return;
        }
        
        // ⚠️ CORREÇÃO: Ignorar mensagens vazias ou de status
        if (!msg.message || msg.message.protocolMessage || msg.message.senderKeyDistributionMessage) {
            return;
        }
        
        // ⚠️ CORREÇÃO: Ignorar mensagens de conexão inicial (sincronização)
        const messageTimestamp = msg.messageTimestamp;
        const agora = Date.now() / 1000; // Converter para segundos
        const cincoMinutosAtras = agora - 300; // 5 minutos em segundos
        
        if (messageTimestamp && messageTimestamp < cincoMinutosAtras) {
            console.log(`${formatarDataHora()} ⏳ Ignorando mensagem antiga (${new Date(messageTimestamp * 1000).toISOString()})`);
            return;
        }
        
        // Obter JID do remetente
        if (!jidRemetente) {
            console.error(`${formatarDataHora()} ❌ Não foi possível obter JID do remetente`);
            return;
        }
        
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

        // ⚠️ ENCERRAMENTO PELO ATENDENTE (já processado no início, mas mantido para consistência)
        if (isAtendente) {
            if (texto === '#FECHAR' || texto === '#CLIENTES' || texto.startsWith('#FECHAR ')) {
                // Já processado no início, mas mantém lógica de backup
                console.log(`${formatarDataHora()} 🔄 Comando do atendente processado (backup): ${texto}`);
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
                            
                            // ⚠️ CORREÇÃO ADICIONADA: Configurar timeout para tela PIX
                            atendimentos[numeroCliente] = {
                                tipo: 'pos_pix',
                                inicio: Date.now(),
                                timeout: Date.now() + (10 * 60 * 1000) // 10 minutos
                            };
                            
                            contextos[numeroCliente] = 'pos_pix';
                            console.log(`${formatarDataHora()} 📄 [DEBUG] Contexto alterado para: pos_pix com timeout de 10min`);
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
                            
                            // ⚠️ CORREÇÃO ADICIONADA: Configurar timeout para tela PIX
                            atendimentos[numeroCliente] = {
                                tipo: 'pos_pix',
                                inicio: Date.now(),
                                timeout: Date.now() + (10 * 60 * 1000) // 10 minutos
                            };
                            
                            contextos[numeroCliente] = 'pos_pix';
                            console.log(`${formatarDataHora()} 📄 [DEBUG] Contexto CNPJ alterado para: pos_pix com timeout de 10min`);
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
