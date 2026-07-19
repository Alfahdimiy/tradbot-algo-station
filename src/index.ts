import { fetchLiveMarketData, calculateEMA } from './engine.js';
import { WebSocketServer, WebSocket } from 'ws';

async function runLiveTickingBot() {
    console.log("🛡️ Unblocked Yahoo Financial Live Ticking Server Booting...\n");

    // 1. Initialize WebSocket Server on Port 8080
    const wss = new WebSocketServer({ port: 8080 });
    let activeClients: WebSocket[] = [];
    let candles: any[] = [];
    
    // Global tracking of whether the trading bot strategy is actively running
    let isBotTradingEnabled = false;

    wss.on('connection', (ws) => {
        console.log("🔌 Visual HTML Dashboard linked to market feed.");
        activeClients.push(ws);

        // INSTANT HANDSHAKE: Send historical candles the split-second a browser connects
        if (candles.length > 0) {
            ws.send(JSON.stringify({ type: 'INIT_HISTORY', data: candles }));
            // Send current Bot activation status so dashboard buttons render correctly
            ws.send(JSON.stringify({ type: 'BOT_STATE_SYNC', enabled: isBotTradingEnabled }));
        } else {
            console.log("⚠️ Client connected before baseline candles finished loading.");
        }

        // Listen for dashboard control events (e.g. Start/Stop Bot commands)
        ws.on('message', (message) => {
            try {
                const payload = JSON.parse(message.toString());
                if (payload.type === 'TOGGLE_BOT') {
                    isBotTradingEnabled = payload.enabled;
                    console.log(`🤖 Trading Bot Strategy toggled to: ${isBotTradingEnabled ? 'RUNNING' : 'PAUSED'}`);
                    
                    // Broadcast state sync back to the browser immediately
                    broadcastToDashboard('BOT_STATE_SYNC', { enabled: isBotTradingEnabled });
                }
            } catch (e) {
                console.error("Failed to parse incoming WS control text:", e);
            }
        });

        ws.on('close', () => {
            activeClients = activeClients.filter(client => client !== ws);
        });
    });

    const broadcastToDashboard = (type: string, data: any) => {
        const payload = JSON.stringify({ type, data });
        activeClients.forEach(client => {
            if (client.readyState === WebSocket.OPEN) {
                client.send(payload);
            }
        });
    };

    const INITIAL_CAPITAL = 1000;
    const TICKER = 'BTC-USD';
    const POLL_INTERVAL_MS = 5000; // Refreshes price every 5 seconds

    console.log(`⏳ Pre-loading 300 historical candles for ${TICKER}...`);
    candles = await fetchLiveMarketData(TICKER, 300);

    if (candles.length === 0) {
        console.log("❌ Error: Could not load initial baseline metrics.");
        return;
    }

    console.log(`✅ Base candles loaded successfully! Broadcasting to active clients...`);
    broadcastToDashboard('INIT_HISTORY', candles);

    let mockBalanceUSD = INITIAL_CAPITAL;
    let mockPositionAsset = 0;
    let entryPrice = 0;
    let stopLossPrice = 0;
    let takeProfitPrice = 0;

    console.log(`\n🚀 WebSocket Server Active on Port 8080. Awaiting dashboard connections...\n`);

    // 2. Continuous Active Polling Loop
    setInterval(async () => {
        const freshCandles = await fetchLiveMarketData(TICKER, 300);
        if (freshCandles.length === 0) return;

        candles = freshCandles;

        const currentCandle = candles[candles.length - 1];
        const prevCandle = candles[candles.length - 2];

        const ema9 = calculateEMA(candles, 9);
        const ema50 = calculateEMA(candles, 50);

        const currentEma9 = ema9[ema9.length - 1];
        const prevEma9 = ema9[ema9.length - 2];
        const currentEma50 = ema50[ema50.length - 1];

        const timestampStr = new Date(currentCandle.timestamp).toLocaleTimeString();
        console.log(`⏱️ [${timestampStr}] TICK: $${currentCandle.close.toFixed(2)} | Bot State: ${isBotTradingEnabled ? 'ACTIVE' : 'IDLE'}`);

        // Always broadcast live candle price wiggles so the chart moves on open
        broadcastToDashboard('LIVE_TICK', {
            candle: currentCandle,
            ema9: currentEma9,
            ema50: currentEma50
        });

        // Halt strategy checks if the bot is set to IDLE
        if (!isBotTradingEnabled) return;

        // RISK MANAGEMENT & ORDER STATE EXECUTION CHECKS
        if (mockPositionAsset > 0) {
            // Stop Loss Check
            if (currentCandle.low <= stopLossPrice) {
                mockBalanceUSD = mockPositionAsset * stopLossPrice;
                mockPositionAsset = 0;
                broadcastToDashboard('TRADE_LOG', {
                    side: 'STOP_LOSS',
                    price: stopLossPrice,
                    balance: mockBalanceUSD,
                    message: `🛡️ [STOP LOSS] Hit at $${stopLossPrice.toFixed(2)}`
                });
                return;
            }

            // Take Profit Check
            if (currentCandle.high >= takeProfitPrice) {
                mockBalanceUSD = mockPositionAsset * takeProfitPrice;
                mockPositionAsset = 0;
                broadcastToDashboard('TRADE_LOG', {
                    side: 'TAKE_PROFIT',
                    price: takeProfitPrice,
                    balance: mockBalanceUSD,
                    message: `🎯 [TAKE PROFIT] Hit at $${takeProfitPrice.toFixed(2)}`
                });
                return;
            }

            // EMA Cross-under Exit Check
            if (prevCandle.close >= prevEma9 && currentCandle.close < currentEma9) {
                mockBalanceUSD = mockPositionAsset * currentCandle.close;
                mockPositionAsset = 0;
                broadcastToDashboard('TRADE_LOG', {
                    side: 'EMA_EXIT',
                    price: currentCandle.close,
                    balance: mockBalanceUSD,
                    message: `🔴 [EMA EXIT] Closed at $${currentCandle.close.toFixed(2)}`
                });
            }
        }

        // ENTRY CHECK (Wait for Cross Up above 9 EMA & Trend above 50 EMA)
        if (mockBalanceUSD > 0) {
            const crossedUp = prevCandle.close <= prevEma9 && currentCandle.close > currentEma9;
            const isAboveMacroTrend = currentCandle.close > currentEma50;

            if (crossedUp && isAboveMacroTrend) {
                entryPrice = currentCandle.close;
                stopLossPrice = entryPrice * 0.99;  // 1% hard stop loss floor
                takeProfitPrice = entryPrice * 1.02; // 2% take profit target
                mockPositionAsset = mockBalanceUSD / entryPrice;
                mockBalanceUSD = 0;

                broadcastToDashboard('TRADE_LOG', {
                    side: 'BUY',
                    price: entryPrice,
                    balance: 0,
                    message: `🟢 [BUY ENTER] Executed at $${entryPrice.toFixed(2)}`
                });
            }
        }
    }, POLL_INTERVAL_MS);
}

runLiveTickingBot();