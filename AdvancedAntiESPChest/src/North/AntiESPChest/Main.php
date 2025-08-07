<?php

declare(strict_types=1);

namespace North\AntiESPChest\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\{PlayerMoveEvent, PlayerInteractEvent, PlayerJoinEvent, PlayerQuitEvent};
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\block\{Chest, Barrel, Furnace, Dispenser, MonsterSpawner};
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\{Config, TextFormat as TF};
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use North\AntiESPChest\Commands\StatsCommand;
use North\AntiESPChest\Tasks\GhostChestTask;
use North\AntiESPChest\Utils\{RaycastUtils, TrajectoryAnalyzer};

class Main extends PluginBase implements Listener {

    private const DATA_VERSION = 1;
    private const GHOST_CHEST_COUNT = 10;

    private array $targetBlocks = [];
    private array $playerData = [];
    private array $ghostChests = [];
    private Config $config;
    private RaycastUtils $raycast;
    private TrajectoryAnalyzer $trajectory;

    public function onEnable(): void {
        $this->initConfig();
        $this->initSystems();
        $this->registerEvents();
        $this->registerCommands();
        $this->scheduleTasks();

        $this->getLogger()->info(TF::GREEN . "AntiESPChest activé! Version " . self::DATA_VERSION);
    }

    private function initConfig(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "detection" => [
                "trajectory_analysis" => true,
                "line_of_sight_check" => true,
                "ghost_chest_traps" => true,
                "min_discovery_time" => 3,
                "max_hidden_distance" => 6
            ],
            "actions" => [
                "warn_player" => true,
                "freeze_on_detection" => true,
                "kick_threshold" => 3,
                "ban_threshold" => 5
            ],
            "messages" => [
                "warn" => "§cAttention! Comportement suspect détecté.",
                "freeze" => "§cVous avez été gelé pour suspicion de triche.",
                "kick" => "§cVous avez été expulsé pour triche (ESP)",
                "ban" => "§cVous avez été banni pour triche (ESP)",
                "staff_alert" => "§4[AC] §c{player} §7flag pour §f{reason}"
            ]
        ]);
    }

    private function initSystems(): void {
        $this->raycast = new RaycastUtils($this);
        $this->trajectory = new TrajectoryAnalyzer($this);

        if ($this->config->getNested("detection.ghost_chest_traps")) {
            $this->generateGhostChests();
        }
    }

    private function registerEvents(): void {
        $events = [
            PlayerMoveEvent::class,
            PlayerInteractEvent::class,
            PlayerJoinEvent::class,
            PlayerQuitEvent::class,
            BlockBreakEvent::class,
            ChunkLoadEvent::class
        ];

        foreach ($events as $event) {
            $this->getServer()->getPluginManager()->registerEvent(
                $event,
                fn($event) => $this->handleEvent($event),
                EventPriority::NORMAL,
                $this
            );
        }
    }

    private function handleEvent($event): void {
        switch (get_class($event)) {
            case PlayerMoveEvent::class:
                $this->onMove($event);
                break;
            case PlayerInteractEvent::class:
                $this->onInteract($event);
                break;
            case PlayerJoinEvent::class:
                $this->onJoin($event);
                break;
            case PlayerQuitEvent::class:
                $this->onQuit($event);
                break;
            case ChunkLoadEvent::class:
                $this->onChunkLoad($event);
                break;
        }
    }

    private function registerCommands(): void {
        $this->getServer()->getCommandMap()->registerAll("antiespchest", [
            new StatsCommand($this),
            new CheatTestCommand($this)
        ]);
    }

    private function scheduleTasks(): void {
        $this->getScheduler()->scheduleRepeatingTask(
            new GhostChestTask($this),
            20 * 60 * 5
        );
    }

    private function generateGhostChests(): void {
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            for ($i = 0; $i < self::GHOST_CHEST_COUNT; $i++) {
                $x = mt_rand(-1000, 1000);
                $z = mt_rand(-1000, 1000);
                $y = $world->getHighestBlockAt($x, $z) + 1;

                $this->ghostChests[$this->posToKey($x, $y, $z, $world->getFolderName())] = true;
            }
        }
        $this->saveGhostChests();
    }

    private function saveGhostChests(): void {
        file_put_contents(
            $this->getDataFolder() . "ghost_chests.json",
            json_encode($this->ghostChests, JSON_PRETTY_PRINT)
        );
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->playerData[$player->getName()] = [
            "movements" => [],
            "interactions" => [],
            "hidden_interactions" => 0,
            "ghost_interactions" => 0,
            "flags" => 0,
            "last_chest_found" => 0
        ];
    }

    public function onQuit(PlayerQuitEvent $event): void {
        unset($this->playerData[$event->getPlayer()->getName()]);
    }

    public function onChunkLoad(ChunkLoadEvent $event): void {
        foreach ($event->getChunk()->getTiles() as $tile) {
            $pos = $tile->getPosition();
            $block = $pos->getWorld()->getBlock($pos);

            if ($this->isTargetBlock($block)) {
                $this->targetBlocks[$this->posToKey($pos)] = [
                    "position" => $pos,
                    "discovered" => [],
                    "generation_time" => time()
                ];
            }
        }
    }

    public function onMove(PlayerMoveEvent $event): void {
        if (!$this->config->getNested("detection.trajectory_analysis")) {
            return;
        }

        $player = $event->getPlayer();
        $data = &$this->playerData[$player->getName()];
        $data["movements"][] = [
            "time" => microtime(true),
            "position" => $event->getTo(),
            "direction" => $player->getDirectionVector()
        ];

        if (count($data["movements"]) > 30) {
            array_shift($data["movements"]);
        }

        $this->trajectory->analyze($player, $data);
    }

    public function onInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if (!$this->isTargetBlock($block)) {
            return;
        }

        $posKey = $this->posToKey($block->getPosition());
        $data = &$this->playerData[$player->getName()];
        if (isset($this->ghostChests[$posKey])) {
            $this->handleGhostChest($player, $data);
            return;
        }

        $data["interactions"][$posKey] = time();
        if ($this->config->getNested("detection.line_of_sight_check") &&
            !$this->raycast->hasLineOfSight($player, $block->getPosition())) {
            $this->handleHiddenInteraction($player, $data, $block);
        }

        if (isset($this->targetBlocks[$posKey])) {
            $genTime = $this->targetBlocks[$posKey]["generation_time"];
            if ((time() - $genTime) < $this->config->getNested("detection.min_discovery_time")) {
                $this->flagPlayer($player, "Temps de découverte anormalement rapide");
            }
        }
    }

    private function handleGhostChest(Player $player, array &$data): void {
        $data["ghost_interactions"]++;

        $this->getLogger()->warning(
            "Ghost chest interaction detected from " . $player->getName() .
            " (Total: " . $data["ghost_interactions"] . ")"
        );

        $this->getServer()->broadcastMessage(
            TF::RED . "[AC] " . $player->getName() . " a été pris en flagrant délit de triche!"
        );

        $player->kick(
            $this->config->getNested("messages.kick"),
            "ESP Detection"
        );
    }

    private function handleHiddenInteraction(Player $player, array &$data, Block $block): void {
        $data["hidden_interactions"]++;

        // Calcul du taux d'interactions cachées
        $hiddenRate = count($data["interactions"]) > 0 ?
            ($data["hidden_interactions"] / count($data["interactions"]) * 100) : 0;

        if ($hiddenRate > 50 || $data["hidden_interactions"] > 2) {
            $this->flagPlayer(
                $player,
                "Interaction avec coffre caché (Taux: " . round($hiddenRate, 1) . "%)"
            );
        }
    }

    private function flagPlayer(Player $player, string $reason): void {
        $data = &$this->playerData[$player->getName()];
        $data["flags"]++;

        $this->logDetection($player, $reason, $data["flags"]);
        if ($data["flags"] >= $this->config->getNested("actions.ban_threshold")) {
            $this->banPlayer($player);
        } elseif ($data["flags"] >= $this->config->getNested("actions.kick_threshold")) {
            $this->kickPlayer($player);
        } elseif ($this->config->getNested("actions.warn_player")) {
            $player->sendMessage($this->config->getNested("messages.warn"));
        }

        if ($this->config->getNested("actions.freeze_on_detection")) {
            $player->setImmobile(true);
            $player->sendMessage($this->config->getNested("messages.freeze"));
        }
    }

    private function logDetection(Player $player, string $reason, int $flags): void {
        $log = sprintf(
            "[%s] %s - %s (Flags: %d)",
            date("Y-m-d H:i:s"),
            $player->getName(),
            $reason,
            $flags
        );

        $this->getLogger()->warning($log);
        file_put_contents(
            $this->getDataFolder() . "detections.log",
            $log . PHP_EOL,
            FILE_APPEND
        );

        $message = str_replace(
            ["{player}", "{reason}"],
            [$player->getName(), $reason],
            $this->config->getNested("messages.staff_alert")
        );

        foreach ($this->getServer()->getOnlinePlayers() as $p) {
            if ($p->hasPermission("antiespchest.alerts")) {
                $p->sendMessage($message);
            }
        }
    }

    private function kickPlayer(Player $player): void {
        $player->kick($this->config->getNested("messages.kick"));
    }

    private function banPlayer(Player $player): void {
        $player->getNetworkSession()->disconnect(
            $this->config->getNested("messages.ban")
        );
        $this->getServer()->getNameBans()->addBan(
            $player->getName(),
            "Triche détectée (ESP Chest)",
            null,
            "AntiESPChest"
        );
    }

    private function isTargetBlock(Block $block): bool {
        return $block instanceof Chest ||
            $block instanceof Barrel ||
            $block instanceof Furnace ||
            $block instanceof Dispenser ||
            $block instanceof MonsterSpawner;
    }

    private function posToKey(Position|int $x, ?int $y = null, ?int $z = null, ?string $world = null): string {
        if ($x instanceof Position) {
            return $x->getWorld()->getFolderName() . ":" . $x->getFloorX() . ":" . $x->getFloorY() . ":" . $x->getFloorZ();
        }
        return $world . ":" . $x . ":" . $y . ":" . $z;
    }

    public function getPlayerStats(Player $player): string {
        $name = $player->getName();
        if (!isset($this->playerData[$name])) {
            return TF::RED . "Aucune donnée pour ce joueur";
        }

        $data = $this->playerData[$name];
        $interactions = count($data["interactions"]);
        $hiddenRate = $interactions > 0 ? ($data["hidden_interactions"] / $interactions * 100) : 0;

        $stats = TF::GOLD . "Statistiques AntiESPChest pour " . TF::YELLOW . $name . "\n";
        $stats .= TF::AQUA . "Interactions: " . TF::WHITE . $interactions . "\n";
        $stats .= TF::AQUA . "Coffres cachés: " . TF::WHITE . $data["hidden_interactions"] . " (" . round($hiddenRate, 1) . "%)\n";
        $stats .= TF::AQUA . "Ghost chests: " . TF::WHITE . $data["ghost_interactions"] . "\n";
        $stats .= TF::AQUA . "Flags: " . TF::WHITE . $data["flags"] . "\n";
        $stats .= TF::AQUA . "Statut: " . ($player->isImmobile() ? TF::RED . "Gelé" : TF::GREEN . "Normal");

        return $stats;
    }

    public function getConfig(): Config {
        return $this->config;
    }

    public function getGhostChests(): array {
        return $this->ghostChests;
    }

    public function getTargetBlocks(): array {
        return $this->targetBlocks;
    }
}