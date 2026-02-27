#!/bin/bash

echo "========================================="
echo "🔍 DIAGNÓSTICO DO BOT WHATSAPP"
echo "========================================="
echo "Data: $(date)"
echo ""

# Versão atual
echo "📱 Versão configurada: [2, 3000, 1033927531]"
echo ""

# Status do bot
if pgrep -f "node bot.js" > /dev/null; then
    echo "✅ BOT: Rodando"
    echo "   PID: $(pgrep -f node | head -1)"
else
    echo "❌ BOT: Parado"
fi
echo ""

# Arquivos importantes
echo "📁 Arquivos:"
for arquivo in qrcode.txt auth_info/ config.json usuarios.json versoes.log ultima_versao.json; do
    if [ -e "$arquivo" ]; then
        if [ "$arquivo" = "auth_info/" ]; then
            echo "   ✅ auth_info/ $(ls -1 auth_info/ 2>/dev/null | wc -l) arquivos"
        else
            echo "   ✅ $arquivo $(stat -c "%y" $arquivo 2>/dev/null | cut -d. -f1)"
        fi
    else
        echo "   ❌ $arquivo (ausente)"
    fi
done
echo ""

# Logs recentes
echo "📋 Últimas linhas do log de versão:"
tail -5 versoes.log 2>/dev/null || echo "   Sem logs de versão"
echo ""

echo "========================================="
echo "✅ Diagnóstico concluído"
echo "========================================="
