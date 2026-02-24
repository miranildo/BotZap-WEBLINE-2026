const { default: makeWASocket, useMultiFileAuthState } = require('@whiskeysockets/baileys');
const P = require('pino');
const fs = require('fs');
const path = require('path');

async function testarVersaoCorreta() {
    console.log('🔍 TESTANDO COM VERSÃO CORRETA DO WHATSAPP');
    console.log('📱 Versão: [2, 3000, 1033927531]');
    
    const AUTH_DIR = path.join(__dirname, 'auth_info_test');
    
    // Remove auth antigo
    if (fs.existsSync(AUTH_DIR)) {
        fs.rmSync(AUTH_DIR, { recursive: true, force: true });
    }
    
    const { state } = await useMultiFileAuthState(AUTH_DIR);
    
    const sock = makeWASocket({
        auth: state,
        logger: P({ level: 'error' }),
        browser: ['Chrome', 'Linux', '120.0.0'],
        version: [2, 3000, 1033927531],  // VERSÃO CORRETA!
        syncFullHistory: false,
        connectTimeoutMs: 60000
    });
    
    console.log('⏳ Conectando aos servidores do WhatsApp...');
    
    sock.ev.on('connection.update', (update) => {
        if (update.qr) {
            console.log('\n✅ QR CODE GERADO COM SUCESSO!');
            console.log('📱 Tamanho:', update.qr.length, 'caracteres');
            
            // Salva em arquivo
            fs.writeFileSync('qrcode.txt', update.qr);
            console.log('💾 QR salvo em qrcode.txt');
            
            // Mostra primeiros caracteres
            console.log('📝 Primeiros 50 chars:', update.qr.substring(0, 50));
            console.log('\n🔗 Acesse o painel web para escanear!');
            
            process.exit(0);
        }
        
        if (update.connection === 'open') {
            console.log('✅ CONECTADO AO WHATSAPP!');
            process.exit(0);
        }
        
        if (update.connection === 'close') {
            console.log('❌ Conexão fechada:', update.lastDisconnect?.error?.message);
        }
        
        if (update.connection === 'connecting') {
            console.log('🔄 Conectando...');
        }
    });
    
    // Timeout de 60 segundos
    setTimeout(() => {
        console.log('\n⏰ TIMEOUT: QR Code não gerado em 60 segundos');
        process.exit(1);
    }, 60000);
}

testarVersaoCorreta().catch(console.error);
