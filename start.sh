#!/bin/bash
# Avvia Life Manager su porta 8095 (accessibile anche da mobile in WiFi)
cd "$(dirname "$0")"
lsof -ti:8095 2>/dev/null | xargs kill -9 2>/dev/null
sleep 1

IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo "localhost")

echo "→ Avvio Life Manager"
echo "  Desktop:  http://localhost:8095"
echo "  Mobile:   http://${IP}:8095  (apri in Safari/Chrome del telefono)"
echo ""
nohup php -S 0.0.0.0:8095 > /tmp/life-manager.log 2>&1 &
sleep 1
open "http://localhost:8095/"
echo "✓ Server avviato (PID $!). Log: /tmp/life-manager.log"
