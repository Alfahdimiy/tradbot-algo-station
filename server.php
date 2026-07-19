<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class TradingBotServer implements MessageComponentInterface {
    protected $clients;
    private $db;
    public $isBotActive = false;
    private $candles = [];

    // Virtual Portfolio State tracking
    private $mockBalanceUSD = 1000.00;
    private $mockPositionAsset = 0.0;
    private $entryPrice = 0.0;
    private $stopLossPrice = 0.0;
    private $takeProfitPrice = 0.0;

    // Execution configuration strategy attributes
    private $stopLossPct = 0.01;
    private $takeProfitPct = 0.02;
    private $fastPeriod = 9;
    private $slowPeriod = 50;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->db = new mysqli('localhost', 'root', '', 'tradbot_db');
        if ($this->db->connect_error) {
            die("Database Connection Failure: " . $this->db->connect_error);
        }
        
        // Sync bot settings and initial capital from database config
        $result = $this->db->query("SELECT * FROM bot_settings WHERE id = 1");
        if ($row = $result->fetch_assoc()) {
            $this->isBotActive = (bool)$row['is_bot_active'];
            $this->mockBalanceUSD = (float)$row['initial_capital'];
            $this->stopLossPct = (float)$row['stop_loss_pct'];
            $this->takeProfitPct = (float)$row['take_profit_pct'];
            $this->fastPeriod = (int)$row['fast_period'];
            $this->slowPeriod = (int)$row['slow_period'];
        }
        $this->generateHistoricalData();
    }

    private function generateHistoricalData() {
        $price = 64100.00;
        $time = time() - (300 * 5);
        for ($i = 0; $i < 300; $i++) {
            $change = (rand(-100, 100) / 10);
            $open = $price; $close = $price + $change;
            $this->candles[] = [
                'timestamp' => ($time + ($i * 5)) * 1000,
                'open' => $open, 'high' => max($open, $close) + 2, 'low' => min($open, $close) - 2, 'close' => $close
            ];
            $price = $close;
        }
    }

    private function calculateEMA($period) {
        $ema = []; $k = 2 / ($period + 1);
        foreach ($this->candles as $i => $candle) {
            $ema[] = ($i === 0) ? $candle['close'] : ($candle['close'] - $ema[$i - 1]) * $k + $ema[$i - 1];
        }
        return $ema;
    }

    private function simulateWeb3Settlement($actionType, $price, $amount) {
        $contractAddress = "0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D"; 
        $txHash = "0x" . bin2hex(random_bytes(32)); 
        
        echo "⛓️ Web3 Escrow Event: Executing transaction on contract $contractAddress...\n";
        echo "   Transaction Hash: $txHash\n";
        
        return [
            'contract' => $contractAddress,
            'txHash' => $txHash,
            'gasUsed' => rand(21000, 65000)
        ];
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "🔌 Dashboard linked over PHP channel! ({$conn->resourceId})\n";
        
        $settingsQuery = $this->db->query("SELECT * FROM bot_settings WHERE id = 1");
        if ($row = $settingsQuery->fetch_assoc()) {
            $settingsData = [
                'initial_capital' => (float)$row['initial_capital'],
                'stop_loss_pct' => (float)$row['stop_loss_pct'],
                'take_profit_pct' => (float)$row['take_profit_pct'],
                'fast_period' => (int)$row['fast_period'],
                'slow_period' => (int)$row['slow_period']
            ];
            $conn->send(json_encode(['type' => 'SETTINGS_SYNC', 'data' => $settingsData]));
        }

        $conn->send(json_encode(['type' => 'BOT_STATE_SYNC', 'enabled' => $this->isBotActive]));
        
        $pastTrades = [];
        $ledgerQuery = $this->db->query("SELECT * FROM execution_ledger ORDER BY executed_at DESC LIMIT 50");
        if ($ledgerQuery) {
            while ($r = $ledgerQuery->fetch_assoc()) {
                $pastTrades[] = [
                    'type' => $r['trade_type'], 'price' => (float)$r['price'],
                    'balance' => (float)$r['wallet_balance'], 'profit' => (float)$r['profit_percent'],
                    'txHash' => $r['tx_hash'], 'timestamp' => $r['executed_at']
                ];
            }
        }
        $conn->send(json_encode(['type' => 'INIT_HISTORY', 'candles' => $this->candles, 'past_trades' => array_reverse($pastTrades)]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $payload = json_decode($msg, true);
        if (!$payload) return;

        if ($payload['type'] === 'TOGGLE_BOT') {
            $this->isBotActive = (bool)$payload['enabled'];
            $stmt = $this->db->prepare("UPDATE bot_settings SET is_bot_active = ? WHERE id = 1");
            $status = $this->isBotActive ? 1 : 0;
            $stmt->bind_param("i", $status); $stmt->execute();
            $this->broadcast(['type' => 'BOT_STATE_SYNC', 'enabled' => $this->isBotActive]);
        }

        if ($payload['type'] === 'UPDATE_SETTINGS') {
            $this->mockBalanceUSD = (float)$payload['initial_capital'];
            $this->stopLossPct = (float)$payload['stop_loss_pct'];
            $this->takeProfitPct = (float)$payload['take_profit_pct'];
            $this->fastPeriod = (int)$payload['fast_period'];
            $this->slowPeriod = (int)$payload['slow_period'];

            $stmt = $this->db->prepare("UPDATE bot_settings SET initial_capital = ?, stop_loss_pct = ?, take_profit_pct = ?, fast_period = ?, slow_period = ? WHERE id = 1");
            $stmt->bind_param("dddii", $this->mockBalanceUSD, $this->stopLossPct, $this->takeProfitPct, $this->fastPeriod, $this->slowPeriod);
            $stmt->execute();
            echo "💾 MySQL Configuration Updated across sliders live.\n";
        }
    }

    public function onClose(ConnectionInterface $conn) { $this->clients->detach($conn); }
    public function onError(ConnectionInterface $conn, \Exception $e) { $conn->close(); }
    public function broadcast($data) { $msg = json_encode($data); foreach ($this->clients as $c) { $c->send($msg); } }

    private function logOrderToMySQL($type, $price, $balance, $profit = 0.0, $web3Data = null) {
        $txHash = $web3Data ? $web3Data['txHash'] : ("0x" . bin2hex(random_bytes(32))); 
        $gasUsed = $web3Data ? $web3Data['gasUsed'] : rand(21000, 60000);

        $stmt = $this->db->prepare("INSERT INTO execution_ledger (trade_type, price, wallet_balance, profit_percent, tx_hash, gas_used) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sdddsi", $type, $price, $balance, $profit, $txHash, $gasUsed);
        $stmt->execute();
        
        echo "📝 Trade Saved to Database: [$type] Hash: " . substr($txHash, 0, 10) . "...\n";

        $this->broadcast([
            'type' => 'TRADE_LOG',
            'data' => [
                'side' => $type, 'price' => $price, 'balance' => $balance, 'profit' => $profit, 'txHash' => $txHash
            ]
        ]);
    }

    public function tickMarket() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.binance.com/api/v3/ticker/price?symbol=BTCUSDT");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_USERAGENT => 'Mozilla/5.0']);
        $res = curl_exec($ch); curl_close($ch);

        if ($res) {
            $d = json_decode($res, true);
            if (isset($d['price'])) { $livePrice = (float)$d['price']; }
        }
        if (!isset($livePrice) || $livePrice <= 0) {
            $livePrice = end($this->candles)['close'] + (rand(-40, 40) / 10);
        }

        $open = !empty($this->candles) ? end($this->candles)['close'] : $livePrice;
        $newCandle = ['timestamp' => time() * 1000, 'open' => $open, 'high' => max($open, $livePrice) + 1, 'low' => min($open, $livePrice) - 1, 'close' => $livePrice];
        
        $this->candles[] = $newCandle;
        if (count($this->candles) > 300) array_shift($this->candles);

        $this->broadcast(['type' => 'LIVE_TICK', 'data' => [ 'candle' => $newCandle ]]);

        if (!$this->isBotActive) return;

        $ema9 = $this->calculateEMA($this->fastPeriod);
        $ema50 = $this->calculateEMA($this->slowPeriod);
        
        $currentEma9 = end($ema9); $prevEma9 = $ema9[count($ema9) - 2];
        $currentEma50 = end($ema50);
        
        $currentCandle = $newCandle;
        $prevCandle = $this->candles[count($this->candles) - 2];

        if ($this->mockPositionAsset > 0) {
            if ($currentCandle['low'] <= $this->stopLossPrice) {
                $this->mockBalanceUSD = $this->mockPositionAsset * $this->stopLossPrice;
                $w3 = $this->simulateWeb3Settlement('SELL', $this->stopLossPrice, $this->mockPositionAsset);
                $this->mockPositionAsset = 0.0;
                $this->logOrderToMySQL('SELL_SL', $this->stopLossPrice, $this->mockBalanceUSD, -$this->stopLossPct * 100, $w3);
                return;
            }
            if ($currentCandle['high'] >= $this->takeProfitPrice) {
                $this->mockBalanceUSD = $this->mockPositionAsset * $this->takeProfitPrice;
                $w3 = $this->simulateWeb3Settlement('SELL', $this->takeProfitPrice, $this->mockPositionAsset);
                $this->mockPositionAsset = 0.0;
                $this->logOrderToMySQL('SELL_TP', $this->takeProfitPrice, $this->mockBalanceUSD, $this->takeProfitPct * 100, $w3);
                return;
            }
            if ($prevCandle['close'] >= $prevEma9 && $currentCandle['close'] < $currentEma9) {
                $this->mockBalanceUSD = $this->mockPositionAsset * $currentCandle['close'];
                $p = (($currentCandle['close'] - $this->entryPrice) / $this->entryPrice) * 100;
                $w3 = $this->simulateWeb3Settlement('SELL', $currentCandle['close'], $this->mockPositionAsset);
                $this->mockPositionAsset = 0.0;
                $this->logOrderToMySQL('SELL_EMA', $currentCandle['close'], $this->mockBalanceUSD, $p, $w3);
            }
        }

        if ($this->mockBalanceUSD > 0) {
            if (($prevCandle['close'] <= $prevEma9 && $currentCandle['close'] > $currentEma9) && ($currentCandle['close'] > $currentEma50)) {
                $this->entryPrice = $currentCandle['close'];
                $this->stopLossPrice = $this->entryPrice * (1 - $this->stopLossPct);
                $this->takeProfitPrice = $this->entryPrice * (1 + $this->takeProfitPct);
                $this->mockPositionAsset = $this->mockBalanceUSD / $this->entryPrice;
                $w3 = $this->simulateWeb3Settlement('BUY', $this->entryPrice, $this->mockPositionAsset);
                $this->mockBalanceUSD = 0.0;
                $this->logOrderToMySQL('BUY', $this->entryPrice, 0.0, 0.0, $w3);
            }
        }
    }
}

$botComponent = new TradingBotServer();
$server = IoServer::factory(new HttpServer(new WsServer($botComponent)), 8080);
$server->loop->addPeriodicTimer(5.0, function () use ($botComponent) { $botComponent->tickMarket(); });
$server->run();