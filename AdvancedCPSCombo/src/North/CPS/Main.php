<?php

declare(strict_types=1);

namespace North\CPS\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;

class Main extends PluginBase implements Listener {

    /** @var array */
    private $clickData = [];
    /** @var array */
    private $comboData = [];
    /** @var array */
    private $lastHitTime = [];
    /** @var Config */
    private $config;
    /** @var array */
    private $flaggedPlayers = [];
    /** @var array */
    private $stats = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        if (!file_exists($this->getDataFolder() . "logs")) {
            mkdir($this->getDataFolder() . "logs", 0777, true);
        }

        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends \pocketmine\scheduler\Task {
            private $plugin;

            public function __construct(Main $plugin) {
                $this->plugin = $plugin;
            }

            public function onRun(): void {
                $this->plugin->monitorClicks();
            }
        }, 20);
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->clickData[$player->getName()] = [
            'clicks' => [],
            'currentCPS' => 0,
            'averageCPS' => 0,
            'maxCPS' => 0,
            'constantPattern' => false,
            'lastCPS' => 0
        ];

        $this->comboData[$player->getName()] = [
            'currentCombo' => 0,
            'maxCombo' => 0,
            'averageCombo' => 0,
            'totalCombos' => 0,
            'comboCount' => 0,
            'hitDelays' => [],
            'lastHitTime' => 0
        ];

        $this->stats[$player->getName()] = [
            'totalClicks' => 0,
            'totalHits' => 0,
            'sessions' => 0
        ];
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $playerName = $event->getPlayer()->getName();
        unset($this->clickData[$playerName]);
        unset($this->comboData[$playerName]);
        unset($this->lastHitTime[$playerName]);
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();
        if ($player === null) return;
        if ($packet instanceof InventoryTransactionPacket && $packet->trData instanceof UseItemOnEntityTransactionData) {
            $this->handleClick($player);
        }
    }

    private function handleClick(Player $player): void {
        $name = $player->getName();
        $now = microtime(true);
        if (!isset($this->clickData[$name])) {
            return;
        }

        $this->clickData[$name]['clicks'][] = $now;
        $this->stats[$name]['totalClicks']++;
        $this->cleanOldClicks($name);
        $this->clickData[$name]['currentCPS'] = count($this->clickData[$name]['clicks']);
        if ($this->clickData[$name]['currentCPS'] > $this->clickData[$name]['maxCPS']) {
            $this->clickData[$name]['maxCPS'] = $this->clickData[$name]['currentCPS'];
        }
    }

    private function cleanOldClicks(string $name): void {
        $now = microtime(true);
        $clicks = &$this->clickData[$name]['clicks'];
        foreach ($clicks as $key => $time) {
            if ($now - $time > 1.0) {
                unset($clicks[$key]);
            } else {
                break;
            }
        }

        $clicks = array_values($clicks);
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if ($damager instanceof Player) {
                $this->handleHit($damager);
            }
        }
    }

    private function handleHit(Player $player): void {
        $name = $player->getName();
        $now = microtime(true);
        if (!isset($this->comboData[$name])) {
            return;
        }

        $this->stats[$name]['totalHits']++;
        if ($this->comboData[$name]['lastHitTime'] > 0) {
            $delay = $now - $this->comboData[$name]['lastHitTime'];
            if ($delay < 2.0) {
                $this->comboData[$name]['currentCombo']++;
                $this->comboData[$name]['hitDelays'][] = $delay;
                if ($this->comboData[$name]['currentCombo'] > $this->comboData[$name]['maxCombo']) {
                    $this->comboData[$name]['maxCombo'] = $this->comboData[$name]['currentCombo'];
                }
            } else {
                if ($this->comboData[$name]['currentCombo'] > 0) {
                    $this->comboData[$name]['totalCombos']++;
                    $this->comboData[$name]['comboCount'] += $this->comboData[$name]['currentCombo'];
                    $this->comboData[$name]['currentCombo'] = 0;
                    $this->comboData[$name]['hitDelays'] = [];
                }
            }
        }

        $this->comboData[$name]['lastHitTime'] = $now;
    }

    public function monitorClicks(): void {
        foreach ($this->clickData as $name => $data) {
            $this->calculateAverageCPS($name);
            $this->detectSuspiciousPatterns($name);
            if ($this->clickData[$name]['currentCPS'] >= $this->config->getNested('cps.warn', 13)) {
                $this->logCPS($name);
            }
        }
    }

    private function calculateAverageCPS(string $name): void {
        $now = microtime(true);
        $count = 0;

        foreach ($this->clickData[$name]['clicks'] as $time) {
            if ($now - $time <= 10.0) {
                $count++;
            }
        }

        $this->clickData[$name]['averageCPS'] = $count / 10.0;
    }

    private function detectSuspiciousPatterns(string $name): void {
        $currentCPS = $this->clickData[$name]['currentCPS'];
        $lastCPS = $this->clickData[$name]['lastCPS'];
        $maxCPS = $this->config->getNested('cps.max', 16);
        if ($currentCPS > $maxCPS) {
            $this->flagPlayer($name, "CPS trop élevé: $currentCPS (max: $maxCPS)");
        }

        if (abs($currentCPS - $lastCPS) < 0.1 && $currentCPS > 10) {
            $this->clickData[$name]['constantPattern'] = true;
            if ($this->config->getNested('cps.auto_flag_constant_cps', true)) {
                $this->flagPlayer($name, "CPS constant suspect: $currentCPS");
            }
        } else {
            $this->clickData[$name]['constantPattern'] = false;
        }

        $this->clickData[$name]['lastCPS'] = $currentCPS;
        $combo = $this->comboData[$name]['currentCombo'];
        $maxCombo = $this->config->getNested('combo.max', 20);
        if ($combo > $maxCombo) {
            $this->flagPlayer($name, "Combo trop long: $combo (max: $maxCombo)");
        }
    }

    private function flagPlayer(string $name, string $reason): void {
        $player = $this->getServer()->getPlayerExact($name);
        if ($player === null) return;
        $this->flaggedPlayers[$name] = $reason;
        $this->logToFile($name, $reason);
        $this->alertModerators($player, $reason);
        if ($this->config->getNested('cps.kick_on_cps_limit', true)) {
            $player->kick(TextFormat::RED . "Détection anti-triche: $reason");
        }
    }

    private function logToFile(string $name, string $reason): void {
        $logFile = $this->getDataFolder() . "logs/" . date("Y-m-d") . ".log";
        $logMessage = "[" . date("H:i:s") . "] $name - $reason\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    private function alertModerators(Player $player, string $reason): void {
        $message = TextFormat::RED . "[ANTI-CHEAT] " . TextFormat::YELLOW . $player->getName() .
            TextFormat::RED . " suspecté de triche: " . TextFormat::WHITE . $reason;

        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            if ($onlinePlayer->hasPermission("cpscore.alerts")) {
                $onlinePlayer->sendMessage($message);
            }
        }

        $this->getLogger()->warning($message);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch (strtolower($command->getName())) {
            case "cpsstats":
                if (count($args) > 0 && $sender->hasPermission("cpscore.admin")) {
                    return $this->showPlayerStats($sender, $args[0]);
                } else {
                    return $this->showSelfStats($sender);
                }

            case "cpscore":
                if ($sender->hasPermission("cpscore.admin")) {
                    return $this->showCoreInfo($sender);
                }
                break;
        }

        return false;
    }

    private function showSelfStats(CommandSender $sender): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "Cette commande ne peut être utilisée que par un joueur.");
            return false;
        }

        $name = $sender->getName();

        if (!isset($this->clickData[$name])) {
            $sender->sendMessage(TextFormat::RED . "Aucune donnée disponible.");
            return false;
        }

        $sender->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "----- Vos Stats PvP -----");
        $sender->sendMessage(TextFormat::GREEN . "CPS actuel: " . TextFormat::WHITE . round($this->clickData[$name]['currentCPS'], 1));
        $sender->sendMessage(TextFormat::GREEN . "CPS max: " . TextFormat::WHITE . round($this->clickData[$name]['maxCPS'], 1));
        $sender->sendMessage(TextFormat::GREEN . "CPS moyen (10s): " . TextFormat::WHITE . round($this->clickData[$name]['averageCPS'], 1));

        if ($this->comboData[$name]['maxCombo'] > 0) {
            $avgDelay = array_sum($this->comboData[$name]['hitDelays']) / count($this->comboData[$name]['hitDelays']);
            $sender->sendMessage(TextFormat::GREEN . "Combo max: " . TextFormat::WHITE . $this->comboData[$name]['maxCombo']);
            $sender->sendMessage(TextFormat::GREEN . "Délai moyen entre hits: " . TextFormat::WHITE . round($avgDelay * 1000) . "ms");
        }

        return true;
    }

    private function showPlayerStats(CommandSender $sender, string $playerName): bool {
        $player = $this->getServer()->getPlayerExact($playerName);

        if ($player === null) {
            $sender->sendMessage(TextFormat::RED . "Joueur non trouvé ou hors ligne.");
            return false;
        }

        $name = $player->getName();

        $sender->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "----- Stats de " . $name . " -----");
        $sender->sendMessage(TextFormat::GREEN . "CPS actuel: " . TextFormat::WHITE . round($this->clickData[$name]['currentCPS'], 1));
        $sender->sendMessage(TextFormat::GREEN . "CPS max: " . TextFormat::WHITE . round($this->clickData[$name]['maxCPS'], 1));
        $sender->sendMessage(TextFormat::GREEN . "CPS moyen (10s): " . TextFormat::WHITE . round($this->clickData[$name]['averageCPS'], 1));

        if ($this->comboData[$name]['maxCombo'] > 0) {
            $avgCombo = $this->comboData[$name]['comboCount'] / max(1, $this->comboData[$name]['totalCombos']);
            $avgDelay = array_sum($this->comboData[$name]['hitDelays']) / count($this->comboData[$name]['hitDelays']);

            $sender->sendMessage(TextFormat::GREEN . "Combo max: " . TextFormat::WHITE . $this->comboData[$name]['maxCombo']);
            $sender->sendMessage(TextFormat::GREEN . "Combo moyen: " . TextFormat::WHITE . round($avgCombo, 1));
            $sender->sendMessage(TextFormat::GREEN . "Délai moyen entre hits: " . TextFormat::WHITE . round($avgDelay * 1000) . "ms");
        }

        if (isset($this->flaggedPlayers[$name])) {
            $sender->sendMessage(TextFormat::RED . "⚠ Joueur flaggué: " . $this->flaggedPlayers[$name]);
        }

        return true;
    }

    private function showCoreInfo(CommandSender $sender): bool {
        $sender->sendMessage(TextFormat::BOLD . TextFormat::GOLD . "----- CPSCore Info -----");
        $sender->sendMessage(TextFormat::GREEN . "Version: " . TextFormat::WHITE . $this->getDescription()->getVersion());
        $sender->sendMessage(TextFormat::GREEN . "Joueurs surveillés: " . TextFormat::WHITE . count($this->clickData));
        $sender->sendMessage(TextFormat::GREEN . "Joueurs flaggués: " . TextFormat::WHITE . count($this->flaggedPlayers));
        $sender->sendMessage(TextFormat::GREEN . "CPS max autorisé: " . TextFormat::WHITE . $this->config->getNested('cps.max', 16));
        $sender->sendMessage(TextFormat::GREEN . "Combo max autorisé: " . TextFormat::WHITE . $this->config->getNested('combo.max', 20));

        return true;
    }

    private function logCPS(string $name): void {
        $currentCPS = $this->clickData[$name]['currentCPS'];
        $logFile = $this->getDataFolder() . "logs/cps_" . date("Y-m-d") . ".log";

        $pattern = $this->clickData[$name]['constantPattern'] ? "constant" : "normal";
        $logMessage = "[" . date("H:i:s") . "] $name - " . round($currentCPS, 1) . " CPS ($pattern)\n";

        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}