// service-worker.js - Gerenciamento de sessão em segundo plano
const CACHE_NAME = 'session-cache-v1';
const SESSION_CHECK_INTERVAL = 10000; // 10 segundos
let SESSION_TIMEOUT = 1800; // Valor padrão, será atualizado pelo servidor

let sessionTimer = null;
let lastActivityTime = Date.now();
let isLoggedIn = false;
let sessionTimeLeft = SESSION_TIMEOUT;

// Instalação do Service Worker
self.addEventListener('install', event => {
    console.log('Service Worker instalado');
    self.skipWaiting(); // Ativar imediatamente
});

// Ativação do Service Worker
self.addEventListener('activate', event => {
    console.log('Service Worker ativado');
    event.waitUntil(clients.claim()); // Assumir controle de todas as abas
    startSessionMonitoring();
});

// Receber mensagens das abas
self.addEventListener('message', event => {
    const { type, data } = event.data;
    
    switch (type) {
        case 'USER_ACTIVITY':
            lastActivityTime = Date.now();
            sessionTimeLeft = SESSION_TIMEOUT;
            broadcastToClients({ type: 'ACTIVITY_RECEIVED', timeLeft: sessionTimeLeft });
            break;
            
        case 'SESSION_EXPIRED':
            broadcastToClients({ type: 'SESSION_EXPIRED' });
            isLoggedIn = false;
            stopSessionMonitoring();
            break;
            
        case 'LOGIN_SUCCESS':
            isLoggedIn = true;
            lastActivityTime = Date.now();
            sessionTimeLeft = SESSION_TIMEOUT;
            startSessionMonitoring();
            break;
            
        case 'LOGOUT':
            isLoggedIn = false;
            stopSessionMonitoring();
            broadcastToClients({ type: 'LOGOUT' });
            break;
            
        case 'TIME_UPDATED':
            // Atualizar tempo de sessão com o valor do servidor
            SESSION_TIMEOUT = data.timeOut;
            sessionTimeLeft = data.timeLeft || SESSION_TIMEOUT;
            console.log('⏰ Service Worker: tempo atualizado para', SESSION_TIMEOUT, 'segundos');
            broadcastToClients({ type: 'TIME_CHANGED', newTime: sessionTimeLeft });
            break;
    }
});

// Buscar configuração inicial do servidor
self.addEventListener('fetch', event => {
    if (event.request.url.includes('heartbeat')) {
        event.respondWith(
            fetch(event.request)
                .then(response => {
                    // Atualizar timeout baseado na resposta do servidor
                    response.clone().json().then(data => {
                        if (data.time_left && data.time_left !== SESSION_TIMEOUT) {
                            SESSION_TIMEOUT = data.time_left;
                            console.log('⏰ Service Worker: tempo sincronizado via heartbeat:', SESSION_TIMEOUT);
                        }
                    }).catch(() => {});
                    return response;
                })
                .catch(() => {
                    // Offline - usar valores locais
                    const timeSinceLastActivity = (Date.now() - lastActivityTime) / 1000;
                    const timeLeft = Math.max(0, SESSION_TIMEOUT - timeSinceLastActivity);
                    
                    return new Response(JSON.stringify({
                        success: isLoggedIn,
                        expired: timeLeft <= 0,
                        time_left: Math.floor(timeLeft),
                        offline: true
                    }), {
                        headers: { 'Content-Type': 'application/json' }
                    });
                })
        );
    }
});

// Função para iniciar monitoramento da sessão
function startSessionMonitoring() {
    if (sessionTimer) clearInterval(sessionTimer);
    
    sessionTimer = setInterval(() => {
        if (!isLoggedIn) return;
        
        const timeSinceLastActivity = (Date.now() - lastActivityTime) / 1000;
        sessionTimeLeft = Math.max(0, SESSION_TIMEOUT - timeSinceLastActivity);
        
        if (sessionTimeLeft <= 0) {
            broadcastToClients({ type: 'SESSION_EXPIRED' });
            
            fetch('index.php?auto_logout=1', {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).catch(() => {
                console.log('Offline: logout será processado quando reconectar');
            });
            
            isLoggedIn = false;
            stopSessionMonitoring();
        } else {
            checkServerSession();
        }
    }, SESSION_CHECK_INTERVAL);
}

// Parar monitoramento
function stopSessionMonitoring() {
    if (sessionTimer) {
        clearInterval(sessionTimer);
        sessionTimer = null;
    }
}

// Verificar sessão no servidor
function checkServerSession() {
    fetch('index.php?check_session_status&t=' + Date.now(), {
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'expired') {
            broadcastToClients({ type: 'SESSION_EXPIRED' });
            isLoggedIn = false;
            stopSessionMonitoring();
        } else if (data.status === 'active') {
            lastActivityTime = Date.now();
            sessionTimeLeft = SESSION_TIMEOUT;
        }
    })
    .catch(() => {
        console.log('Offline: mantendo controle local da sessão');
    });
}

// Notificar todas as abas abertas
function broadcastToClients(message) {
    clients.matchAll({ type: 'window' }).then(clientList => {
        clientList.forEach(client => {
            client.postMessage(message);
        });
    });
}

// Sincronização em background quando voltar online
self.addEventListener('sync', event => {
    if (event.tag === 'session-sync') {
        event.waitUntil(checkServerSession());
    }
});