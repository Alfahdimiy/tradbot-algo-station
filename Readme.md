# TRADBOT: Web3 Algo Trading Bot Simulator v2.5

An interactive, high-performance Algorithmic Trading Backtester and Live Simulator. The system utilizes an asynchronous PHP WebSocket pipeline to stream real-time price tickers directly from the global exchange layer, evaluates multi-timeframe Exponential Moving Average (EMA) crossovers, logs state-persistent transactions to a MySQL ledger, and simulates decentralized Web3 smart contract escrow settlements.

---

## 🚀 System Features

* **Live Global Exchange Streams:** Connects via native PHP cURL streams to fetch real-time, live global price data directly from the Binance exchange feed for **BTC-USD**.
* **Bi-Directional Database Synchronization:** Changing sliders on your frontend dashboard (Initial Capital, Hard Stop Loss, Take Profit targets, EMA Periods) instantly sends data across the WebSocket to update your MySQL configuration rules live.
* **Auto-Scaling Equity Curve Engine:** A secondary interactive line graph at the bottom dynamically plots your account's balance history over time, with an auto-scaling safety boundary to handle micro-profit variations cleanly.
* **Web3 Escrow Smart Contract Simulator:** Generates a simulated transaction hash (`0x...`) and trackable gas configuration for every order execution, rendering them as clickable cyan blockchain explorer badges on your ledger.
* **Advanced Risk Performance Analytics HUD:** Computes real-time trading statistics across your account history, including **Win Rate**, **Profit Factor** (Gross Profits / Gross Losses), and **Maximum Drawdown %**.

---

## 🛠️ Technical Architecture

| Layer | Component | Core Responsibility |
| :--- | :--- | :--- |
| **User Interface** | HTML5 / Tailwind CSS / Canvas API | Renders the premium dark HUD viewport, live candlestick streams, equity line graph, and transaction tables. |
| **WebSocket Stream** | Asynchronous PHP Ratchet (`Port 8080`) | Manages non-blocking server socket client connections, pushing live metric updates down to the frontend every 5 seconds. |
| **Database Layer** | XAMPP MySQL Engine (`Port 3306`) | Permanently stores active parameters into the `bot_settings` table and saves past order trade flows into the `execution_ledger` table. |
| **Web3 Cryptography** | Native PHP Hash Generators | Simulates cryptographic smart contract escrow signatures and returns mock block hashes. |

---

## 📦 Local Installation & Setup

### 1. Database Initialization
Make sure **Apache** and **MySQL** are running inside your XAMPP Control Panel. Open your browser to phpMyAdmin (`http://localhost/phpmyadmin`), create a database named `tradbot_db`, and run the following query in the **SQL** tab:

```sql
CREATE TABLE IF NOT EXISTS `bot_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `is_bot_active` TINYINT(1) DEFAULT 0,
    `initial_capital` DOUBLE(10,2) DEFAULT 1000.00,
    `stop_loss_pct` DOUBLE(5,4) DEFAULT 0.0100,
    `take_profit_pct` DOUBLE(5,4) DEFAULT 0.0200,
    `fast_period` INT DEFAULT 9,
    `slow_period` INT DEFAULT 50
);

CREATE TABLE IF NOT EXISTS `execution_ledger` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `trade_type` VARCHAR(20) NOT NULL,
    `price` DOUBLE(12,2) NOT NULL,
    `wallet_balance` DOUBLE(12,2) NOT NULL,
    `profit_percent` DOUBLE(8,4) DEFAULT 0.0000,
    `tx_hash` VARCHAR(66) NULL,
    `gas_used` INT NULL,
    `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO `bot_settings` (`id`, `is_bot_active`, `initial_capital`, `stop_loss_pct`, `take_profit_pct`, `fast_period`, `slow_period`) 
VALUES (1, 0, 1000.00, 0.01, 0.02, 9, 50);

2. Install Composer Dependencies
Open a terminal window in your project root directory and install Ratchet:

Bash
composer require ratchet/pawl cboden/ratchet
3. Start the Application
Boot up your backend WebSocket server loop:

Bash
php server.php
Finally, open your index.html file in your web browser (via Live Server or direct link), adjust your trading parameters, and click "START LIVE BOT"!