#!/usr/bin/env node

/**
 * MONITOR DE VERSÃO DO WHATSAPP
 * CORRIGIDO: SEMPRE busca na internet antes de usar fallback
 */

const https = require('https');
const fs = require('fs');
const path = require('path');

const CONFIG_PATH = path.join(__dirname, 'config.json');
const VERSAO_LOG_PATH = path.join(__dirname, 'versoes.log');
const ULTIMA_VERSAO_PATH = path.join(__dirname, 'ultima_versao.json');
const BOT_PATH = path.join(__dirname, 'bot.js');

// Versão atual conhecida (fevereiro/2026) - SOMENTE COMO ÚLTIMO RECURSO!
const VERSAO_FALLBACK = '1033927531';

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

function registrarLog(mensagem) {
    const logEntry = `${formatarDataHora()} ${mensagem}\n`;
    fs.appendFileSync(VERSAO_LOG_PATH, logEntry, 'utf8');
    console.log(logEntry.trim());
}

// ================= MÉTODO 1: HEAD REQUEST =================
async function detectarVersaoPorHeader() {
    return new Promise((resolve) => {
        const options = {
            hostname: 'web.whatsapp.com',
            path: '/',
            method: 'HEAD',
            headers: {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8',
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache',
                'Connection': 'keep-alive'
            },
            timeout: 10000
        };

        const req = https.request(options, (res) => {
            const csp = res.headers['content-security-policy'] || '';
            const match = csp.match(/cv=(\d+)/);
            
            if (match && match[1]) {
                registrarLog(`✅ HEAD: Versão encontrada no header: ${match[1]}`);
                resolve(match[1]);
            } else {
                registrarLog(`ℹ️ HEAD: cv= não encontrado no header`);
                resolve(null);
            }
        });

        req.on('error', (err) => {
            registrarLog(`ℹ️ HEAD: Erro - ${err.message}`);
            resolve(null);
        });

        req.on('timeout', () => {
            req.destroy();
            registrarLog(`ℹ️ HEAD: Timeout`);
            resolve(null);
        });

        req.end();
    });
}

// ================= MÉTODO 2: GET REQUEST (ALTERNATIVO 1) =================
async function detectarVersaoPorGET() {
    return new Promise((resolve) => {
        const options = {
            hostname: 'web.whatsapp.com',
            path: '/',
            method: 'GET',
            headers: {
                'User-Agent': 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8'
            },
            timeout: 10000
        };

        const req = https.request(options, (res) => {
            // Primeiro tenta header (mais rápido)
            const csp = res.headers['content-security-policy'] || '';
            const match = csp.match(/cv=(\d+)/);
            
            if (match && match[1]) {
                registrarLog(`✅ GET: Versão encontrada no header: ${match[1]}`);
                resolve(match[1]);
                return;
            }

            // Se não achou no header, lê o body
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                // Procura por cv= no HTML/JS
                const matchBody = data.match(/cv=(\d+)/);
                if (matchBody && matchBody[1]) {
                    registrarLog(`✅ GET: Versão encontrada no HTML: ${matchBody[1]}`);
                    resolve(matchBody[1]);
                } else {
                    registrarLog(`ℹ️ GET: cv= não encontrado no HTML`);
                    resolve(null);
                }
            });
        });

        req.on('error', (err) => {
            registrarLog(`ℹ️ GET: Erro - ${err.message}`);
            resolve(null);
        });

        req.on('timeout', () => {
            req.destroy();
            registrarLog(`ℹ️ GET: Timeout`);
            resolve(null);
        });

        req.end();
    });
}

// ================= MÉTODO 3: VIA API WHATSAPP (ALTERNATIVO 2) =================
async function detectarVersaoPorAPI() {
    return new Promise((resolve) => {
        const options = {
            hostname: 'v.whatsapp.net',
            path: '/v2/version',
            method: 'GET',
            headers: {
                'User-Agent': 'WhatsApp/2.24.6.74',
                'Accept': 'application/json'
            },
            timeout: 10000
        };

        const req = https.request(options, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => {
                try {
                    const json = JSON.parse(data);
                    if (json.version) {
                        registrarLog(`✅ API: Versão encontrada: ${json.version}`);
                        resolve(json.version.toString());
                    } else {
                        resolve(null);
                    }
                } catch (e) {
                    resolve(null);
                }
            });
        });

        req.on('error', () => resolve(null));
        req.on('timeout', () => {
            req.destroy();
            resolve(null);
        });
        req.end();
    });
}

// ================= MÉTODO 4: DNS/MMG (ALTERNATIVO 3) =================
async function detectarVersaoPorMMG() {
    return new Promise((resolve) => {
        const options = {
            hostname: 'mmg.whatsapp.net',
            path: '/',
            method: 'HEAD',
            headers: {
                'User-Agent': 'WhatsApp/2.24.6.74'
            },
            timeout: 5000
        };

        const req = https.request(options, (res) => {
            const server = res.headers['server'] || '';
            const match = server.match(/WhatsApp\/(\d+)/);
            
            if (match && match[1]) {
                registrarLog(`✅ MMG: Versão encontrada: ${match[1]}`);
                resolve(match[1]);
            } else {
                resolve(null);
            }
        });

        req.on('error', () => resolve(null));
        req.on('timeout', () => {
            req.destroy();
            resolve(null);
        });
        req.end();
    });
}

// ================= MÉTODO 5: MÚLTIPLOS USER-AGENTS (ALTERNATIVO 4) =================
async function detectarVersaoMultiAgents() {
    const agents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1'
    ];

    for (const agent of agents) {
        const versao = await new Promise((resolve) => {
            const options = {
                hostname: 'web.whatsapp.com',
                path: '/',
                method: 'HEAD',
                headers: {
                    'User-Agent': agent,
                    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                },
                timeout: 5000
            };

            const req = https.request(options, (res) => {
                const csp = res.headers['content-security-policy'] || '';
                const match = csp.match(/cv=(\d+)/);
                resolve(match ? match[1] : null);
            });

            req.on('error', () => resolve(null));
            req.on('timeout', () => {
                req.destroy();
                resolve(null);
            });
            req.end();
        });

        if (versao) {
            registrarLog(`✅ Multi-Agent: Versão encontrada com ${agent.substring(0, 30)}...: ${versao}`);
            return versao;
        }
    }
    
    registrarLog(`ℹ️ Multi-Agent: Nenhuma versão encontrada`);
    return null;
}

// ================= FUNÇÃO PRINCIPAL DE DETECÇÃO =================
async function detectarVersaoWhatsApp() {
    registrarLog('🔍 Iniciando detecção de versão (buscando na internet)...');
    
    // Lista de TODOS os métodos que BUSCAM NA INTERNET
    const metodos = [
        { nome: 'HEAD', fn: detectarVersaoPorHeader },
        { nome: 'GET', fn: detectarVersaoPorGET },
        { nome: 'API', fn: detectarVersaoPorAPI },
        { nome: 'MMG', fn: detectarVersaoPorMMG },
        { nome: 'Multi-Agent', fn: detectarVersaoMultiAgents }
    ];
    
    // Tenta cada método em sequência
    for (const metodo of metodos) {
        registrarLog(`🔍 Tentando método: ${metodo.nome}...`);
        const versao = await metodo.fn();
        
        if (versao) {
            registrarLog(`✅ SUCESSO! Versão encontrada via ${metodo.nome}: ${versao}`);
            
            // Salva a versão detectada para referência futura
            try {
                const info = {
                    data: new Date().toISOString(),
                    metodo: metodo.nome,
                    versao: versao,
                    detectada_em: formatarDataHora()
                };
                fs.writeFileSync('/tmp/ultima_versao_real.json', JSON.stringify(info, null, 2));
            } catch (e) {}
            
            return versao;
        }
    }
    
    // Se TODOS os métodos falharam, aí sim usamos o fallback
    registrarLog(`⚠️ TODOS os métodos de detecção falharam!`);
    registrarLog(`⚠️ O WhatsApp pode estar fora do ar ou bloqueando conexões`);
    registrarLog(`📱 Usando versão fallback: ${VERSAO_FALLBACK} (pode estar desatualizada!)`);
    
    return VERSAO_FALLBACK;
}

// Função para ler versão atual do bot.js
function lerVersaoDoBot() {
    try {
        if (!fs.existsSync(BOT_PATH)) {
            registrarLog(`ℹ️ bot.js não encontrado`);
            return VERSAO_FALLBACK;
        }
        
        const conteudo = fs.readFileSync(BOT_PATH, 'utf8');
        const match = conteudo.match(/version:\s*\[\s*2\s*,\s*3000\s*,\s*(\d+)\s*\]/);
        
        if (match && match[1]) {
            registrarLog(`📱 Versão no bot.js: ${match[1]}`);
            return match[1];
        } else {
            registrarLog(`ℹ️ Padrão de versão não encontrado no bot.js`);
        }
    } catch (error) {
        registrarLog(`ℹ️ Erro ao ler bot.js: ${error.message}`);
    }
    
    return VERSAO_FALLBACK;
}

// Função para enviar notificação Telegram
async function enviarNotificacaoTelegram(mensagem) {
    try {
        if (!fs.existsSync(CONFIG_PATH)) return;
        
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
        
        if (config.telegram_ativado !== 'Sim' || !config.telegram_token || !config.telegram_chat_id) {
            return;
        }

        const postData = JSON.stringify({
            chat_id: config.telegram_chat_id,
            text: mensagem,
            parse_mode: 'Markdown'
        });

        const options = {
            hostname: 'api.telegram.org',
            port: 443,
            path: `/bot${config.telegram_token}/sendMessage`,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData)
            },
            timeout: 10000
        };

        return new Promise((resolve) => {
            const req = https.request(options, (res) => {
                resolve(res.statusCode === 200);
            });
            req.on('error', () => resolve(false));
            req.on('timeout', () => {
                req.destroy();
                resolve(false);
            });
            req.write(postData);
            req.end();
        });

    } catch (error) {
        return false;
    }
}

// Função para salvar informações
function salvarInfoVersao(versaoBot, versaoDetectada, deOndeVeio) {
    try {
        const infoVersao = {
            data: new Date().toISOString(),
            versao_bot: versaoBot,
            versao_detectada: versaoDetectada,
            detectada_em: formatarDataHora(),
            metodo_deteccao: deOndeVeio || 'fallback',
            status: versaoBot === versaoDetectada ? 'atualizada' : 'desatualizada'
        };
        fs.writeFileSync(ULTIMA_VERSAO_PATH, JSON.stringify(infoVersao, null, 2));
        registrarLog(`💾 Informações salvas em ${ULTIMA_VERSAO_PATH}`);
    } catch (error) {
        registrarLog(`ℹ️ Erro ao salvar: ${error.message}`);
    }
}

// ================= FUNÇÃO PRINCIPAL =================
async function main() {
    console.log('\n' + '='.repeat(70));
    console.log('🔍 MONITOR DE VERSÃO DO WHATSAPP');
    console.log('='.repeat(70));
    console.log(`📅 Data: ${formatarDataHora()}`);
    console.log('='.repeat(70) + '\n');
    
    // PASSO 1: DETECTAR VERSÃO NA INTERNET (sempre tenta!)
    const versaoDetectada = await detectarVersaoWhatsApp();
    
    // PASSO 2: LER VERSÃO DO BOT
    const versaoBot = lerVersaoDoBot();
    
    console.log('\n' + '-'.repeat(50));
    console.log(`📱 Versão no bot.js: ${versaoBot}`);
    console.log(`📱 Versão detectada:  ${versaoDetectada}`);
    console.log('-'.repeat(50));
    
    // PASSO 3: SALVAR INFORMAÇÕES
    const deOndeVeio = versaoDetectada === VERSAO_FALLBACK ? 'fallback' : 'internet';
    salvarInfoVersao(versaoBot, versaoDetectada, deOndeVeio);
    
    // PASSO 4: COMPARAR E NOTIFICAR
    if (versaoDetectada !== versaoBot && versaoDetectada !== VERSAO_FALLBACK) {
        console.log('\n⚠️  ' + '='.repeat(40));
        console.log('⚠️  NOVA VERSÃO DO WHATSAPP DETECTADA!');
        console.log('⚠️  ' + '='.repeat(40));
        
        const mensagem = 
`⚠️ *NOVA VERSÃO DO WHATSAPP DETECTADA*

📱 *Versão atual no bot:* \`${versaoBot}\`
📱 *Nova versão detectada:* \`${versaoDetectada}\`
🔍 *Método:* ${deOndeVeio}
⏰ ${formatarDataHora()}

🔧 *Para atualizar:*
1. Edite o \`bot.js\`
2. Altere a linha \`version:\` para:
   \`\`\`
   version: [2, 3000, ${versaoDetectada}]
   \`\`\`
3. Reinicie o bot`;

        await enviarNotificacaoTelegram(mensagem);
        console.log('\n📢 Notificação enviada ao Telegram!');
        
    } else if (versaoDetectada === versaoBot) {
        console.log('\n✅ ' + '='.repeat(40));
        console.log('✅ VERSÃO DO WHATSAPP ESTÁ ATUALIZADA!');
        console.log('✅ ' + '='.repeat(40));
    } else if (versaoDetectada === VERSAO_FALLBACK) {
        console.log('\n⚠️ ' + '='.repeat(40));
        console.log('⚠️ NÃO FOI POSSÍVEL DETECTAR VERSÃO NA INTERNET');
        console.log('⚠️ ' + '='.repeat(40));
        console.log(`\n📱 Usando versão fallback: ${VERSAO_FALLBACK}`);
        console.log(`📱 Versão no bot: ${versaoBot}`);
        console.log(`\n🔍 Possíveis causas:`);
        console.log(`   • WhatsApp fora do ar`);
        console.log(`   • Bloqueio de rede`);
        console.log(`   • Firewall bloqueando conexões`);
    }
    
    console.log('\n' + '='.repeat(70) + '\n');
}

// Executa
if (require.main === module) {
    main().catch(console.error);
}

module.exports = { detectarVersaoWhatsApp, lerVersaoDoBot };