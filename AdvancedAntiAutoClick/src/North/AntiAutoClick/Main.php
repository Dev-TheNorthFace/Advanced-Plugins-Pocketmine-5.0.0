<?php

declare(strict_types=1);

namespace North\AntiAutoClick\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\ClosureTask;
use North\AntiAutoClick\Detectors\CPSTracker;
use North\AntiAutoClick\Detectors\ClickPatternDetector;
use North\AntiAutoClick\Utils\AlertManager;
use North\AntiAutoClick\Utils\HumanScoreCalculator;
use North\AntiAutoClick\Commands\AutoClickCommand;
use North\AntiAutoClick\Commands\FlagCommand;
use North\AntiAutoClick\Tasks\AnalysisTask;

class Main extends PluginBase implements Listener {

    private Config $config;
    private CPSTracker $cpsTracker;
    private ClickPatternDetector $patternDetector;
    private AlertManager $alertManager;
    private HumanScoreCalculator $humanScoreCalculator;
    private array $clickData = [];
    private array $watchlist = [];
    private array $violations = [];

    public function onEnable(): void {
        $this->initConfig();
        $this->initComponents();
        $this->registerCommands();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->startAnalysisTask();

        $this->getLogger()->info("§aAntiAutoClickCore activé avec succès!");
    }

    private function initConfig(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "autoclick" => [
                "max_cps" => 20,
                "constancy_threshold" => 5,
                "click_delay_variance" => 5,
                "freeze_on_suspect" => true,
                "ban_on_repeat_flags" => true,
                "bypass_permission" => "anticheat.bypass"
            ],
            "notifications" => [
                "discord_webhook" => "",
                "in_game_alerts" => true,
                "alert_prefix" => "§8[§cAntiAC§8]§r"
            ]
        ]);
    }

    private function initComponents(): void {
        $this->cpsTracker = new CPSTracker();
        $this->patternDetector = new ClickPatternDetector();
        $this->alertManager = new AlertManager($this->config);
        $this->humanScoreCalculator = new HumanScoreCalculator();
    }

    private function registerCommands(): void {
        $commandMap = $this->getServer()->getCommandMap();
        $commandMap->register("anticheat", new AutoClickCommand($this, $this->cpsTracker, $this->alertManager));
        $commandMap->register("anticheat", new FlagCommand($this, $this->alertManager));
    }

    private function startAnalysisTask(): void {
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            foreach ($this->clickData as $playerName => $data) {
                $this->analyzePlayer($playerName);
            }

            $this->getServer()->getAsyncPool()->submitTask(
                new AnalysisTask($this->clickData, [
                    'max_cps' => $this->config->getNested("autoclick.max_cps"),
                    'min_human_score' => 20,
                    'click_delay_variance' => $this->config->getNested("autoclick.click_delay_variance"),
                    'min_variation' => 5
                ])
            );
        }), 20);
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();

        if ($player instanceof Player && ($packet instanceof InventoryTransactionPacket ||
                ($packet instanceof LevelSoundEventPacket && $packet->sound === LevelSoundEvent::ATTACK_NODAMAGE))) {
            $this->cpsTracker->recordClick($player);
            $this->recordClickData($player);
        }
    }

    private function recordClickData(Player $player): void {
        $name = $player->getName();
        $now = microtime(true);

        if (!isset($this->clickData[$name])) {
            $this->clickData[$name] = [
                'timestamps' => [],
                'lastClick' => 0,
                'firstClick' => $now
            ];
        }

        $this->clickData[$name]['timestamps'][] = $now;
        $this->clickData[$name]['lastClick'] = $now;

        if (count($this->clickData[$name]['timestamps']) > 100) {
            array_shift($this->clickData[$name]['timestamps']);
        }
    }

    private function analyzePlayer(string $playerName): void {
        if (!isset($this->clickData[$playerName])) {
            return;
        }

        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player === null || $player->hasPermission($this->config->getNested("autoclick.bypass_permission"))) {
            return;
        }

        $cps = $this->cpsTracker->getRecentCps($playerName, 1);
        $clickPattern = $this->patternDetector->analyze($this->clickData[$playerName]);
        $humanScore = $this->humanScoreCalculator->calculate($this->clickData[$playerName]);

        if ($cps > $this->config->getNested("autoclick.max_cps")) {
            $this->handleViolation($player, 'high_cps', $cps, $humanScore);
        }

        if ($clickPattern['type'] === 'perfect_timing') {
            $this->handleViolation($player, 'perfect_timing', $cps, $humanScore);
        }

        if ($humanScore < 20) {
            $this->handleViolation($player, 'low_human_score', $cps, $humanScore);
        }
    }

    public function handleViolation(Player $player, string $type, float $cps, int $humanScore): void {
        $this->alertManager->handleViolation($player, $type, $cps, $humanScore);

        if (!isset($this->violations[$player->getName()])) {
            $this->violations[$player->getName()] = [];
        }

        $this->violations[$player->getName()][] = [
            'type' => $type,
            'cps' => $cps,
            'score' => $humanScore,
            'time' => time()
        ];

        if ($this->config->getNested("autoclick.freeze_on_suspect")) {
            $this->alertManager->sendFreezeMessage($player);
        }
    }

    public function processAnalysisResults(array $results): void {
        foreach ($results as $playerName => $result) {
            $player = $this->getServer()->getPlayerExact($playerName);
            if ($player && $result['violation'] !== 'clean') {
                $this->handleViolation(
                    $player,
                    $result['violation'],
                    $result['cps'],
                    $result['score']
                );
            }
        }
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->clickData[$player->getName()] = [
            'timestamps' => [],
            'lastClick' => 0,
            'firstClick' => microtime(true)
        ];
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        unset($this->clickData[$event->getPlayer()->getName()]);
    }

    public function getCpsTracker(): CPSTracker {
        return $this->cpsTracker;
    }

    public function getWatchlist(): array {
        return $this->watchlist;
    }

    public function addToWatchlist(string $playerName, array $data): void {
        $this->watchlist[$playerName] = $data;
    }
}