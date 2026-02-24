#!/usr/bin/env node

/**
 * TESTE DE NOTIFICAÇÃO TELEGRAM
 * Simula uma mudança de versão sem alterar o bot.js
 */

const https = require('https');
const fs = require('fs');
const path = require('path');

const CONFIG_PATH = path.join(__dirname, 'config.json');

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

async function testarNotificacao() {
    console.log('\n' + '='.repeat(60));
    console.log('📱 TESTE DE NOTIFICAÇÃO TELEGRAM');
    console.log('='.repeat(60));
    console.log(`${formatarDataHora()} Iniciando teste...\n`);

    try {
        // Verifica se config.json existe
        if (!fs.existsSync(CONFIG_PATH)) {
            console.log('❌ config.json não encontrado!');
            console.log('   Caminho:', CONFIG_PATH);
            return;
        }

        // Lê configuração
        const config = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
        
        console.log('📋 Configurações encontradas:');
        console.log(`   Telegram ativado: ${config.telegram_ativado || 'Não configurado'}`);
        console.log(`   Token: ${config.telegram_token ? '✅ Configurado' : '❌ Não configurado'}`);
        console.log(`   Chat ID: ${config.telegram_chat_id ? '✅ Configurado' : '❌ Não configurado'}`);
        console.log('');

        // Verifica se Telegram está ativado
        if (config.telegram_ativado !== 'Sim') {
            console.log('❌ Telegram não está ativado no config.json');
            console.log('   Altere telegram_ativado para "Sim"');
            return;
        }

        if (!config.telegram_token || !config.telegram_chat_id) {
            console.log('❌ Token ou Chat ID não configurados');
            return;
        }

        // Dados do teste
        const versaoAntiga = '1033927531';
        const versaoNova = '9999999999'; // Versão falsa para teste
        
        const mensagem = 
`🧪 *TESTE DE NOTIFICAÇÃO - VERSÃO SIMULADA*

📱 *Versão antiga:* \`${versaoAntiga}\`
📱 *Versão nova (teste):* \`${versaoNova}\`
⏰ ${formatarDataHora()}

✅ *Este é apenas um teste!*
🔧 O sistema de monitoramento está funcionando corretamente.

*Se você recebeu esta mensagem, as notificações estão funcionando!* 🎯`;

        console.log('📤 Enviando mensagem de teste...');
        console.log('   Para:', config.telegram_chat_id);
        console.log('');

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

        return new Promise((resolve, reject) => {
            const req = https.request(options, (res) => {
                let data = '';
                res.on('data', (chunk) => { data += chunk; });
                res.on('end', () => {
                    console.log(`📡 Status HTTP: ${res.statusCode}`);
                    
                    if (res.statusCode === 200) {
                        console.log('\n✅ SUCESSO! Notificação enviada!');
                        console.log('   Verifique seu Telegram.');
                        resolve(true);
                    } else {
                        console.log('\n❌ FALHA! Resposta da API:', data);
                        try {
                            const erro = JSON.parse(data);
                            console.log('   Descrição:', erro.description);
                        } catch (e) {
                            console.log('   Resposta bruta:', data);
                        }
                        resolve(false);
                    }
                });
            });

            req.on('error', (error) => {
                console.log('\n❌ ERRO na requisição:', error.message);
                resolve(false);
            });

            req.on('timeout', () => {
                console.log('\n⏰ TIMEOUT na requisição');
                req.destroy();
                resolve(false);
            });

            console.log('⏳ Aguardando resposta da API do Telegram...');
            req.write(postData);
            req.end();
        });

    } catch (error) {
        console.log('\n❌ ERRO:', error.message);
        return false;
    }
}

// Executa o teste
testarNotificacao().then((resultado) => {
    console.log('\n' + '='.repeat(60));
    console.log(`📊 Resultado do teste: ${resultado ? '✅ SUCESSO' : '❌ FALHA'}`);
    console.log('='.repeat(60) + '\n');
});
