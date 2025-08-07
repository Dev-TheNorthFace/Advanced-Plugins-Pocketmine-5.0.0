<?php

namespace North\AntiReach\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\world\Position;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener {

    private Config $config;
    private array $reachStats = [];
    private array $hitPatterns = [];
    private array $flaggedPlayers = [];
    private array $ghostEntities = [];
    private array $frozenPlayers = [];

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "antireach" => [
                "max_distance" => 3.3,
                "warn_threshold" => 3.1,
                "auto_flag_threshold" => 3.4,
                "auto_freeze_on_flag" => true,
                "exempt_ping_above" => 250,
                "enable_angle_check" => true,
                "ghost_entity_test" => true,
                "punishments" => [
                    "first_offense" => "freeze",
                    "second_offense" => "kick",
                    "third_offense" => "ban"
                ]
            ]
        ]);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->ghostEntityTest();
        }), 20 * 30);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->cleanupData();
        }), 20 * 60 * 60);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch ($command->getName()) {
            case "reachstats":
                if (!$sender->hasPermission("antireach.stats")) {
                    $sender->sendMessage(TextFormat::RED . "Permission denied.");
                    return true;
                }

                $target = $args[0] ?? ($sender instanceof Player ? $sender->getName() : "");
                if ($target === "") {
                    $sender->sendMessage(TextFormat::RED . "Usage: /reachstats <player>");
                    return true;
                }

                $player = $this->getServer()->getPlayerExact($target);
                if ($player === null) {
                    if (!isset($this->reachStats[$target])) {
                        $sender->sendMessage(TextFormat::RED . "No data available for " . $target);
                        return true;
                    }
                    $sender->sendMessage($this->getOfflineReachStats($target));
                    return true;
                }

                $sender->sendMessage($this->getReachStats($player));
                return true;

            case "antireach":
                if (!$sender->hasPermission("antireach.admin")) {
                    $sender->sendMessage(TextFormat::RED . "Permission denied.");
                    return true;
                }

                $subcmd = strtolower($args[0] ?? "");
                switch ($subcmd) {
                    case "reload":
                        $this->reloadConfig();
                        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
                        $sender->sendMessage(TextFormat::GREEN . "AntiReach config reloaded!");
                        return true;

                    case "freeze":
                        if (!isset($args[1])) {
                            $sender->sendMessage(TextFormat::RED . "Usage: /antireach freeze <player>");
                            return true;
                        }
                        $player = $this->getServer()->getPlayerExact($args[1]);
                        if ($player === null) {
                            $sender->sendMessage(TextFormat::RED . "Player not found!");
                            return true;
                        }
                        $this->freezePlayer($player);
                        $sender->sendMessage(TextFormat::GREEN . "Froze " . $player->getName());
                        return true;

                    case "unfreeze":
                        if (!isset($args[1])) {
                            $sender->sendMessage(TextFormat::RED . "Usage: /antireach unfreeze <player>");
                            return true;
                        }
                        $player = $this->getServer()->getPlayerExact($args[1]);
                        if ($player === null) {
                            $sender->sendMessage(TextFormat::RED . "Player not found!");
                            return true;
                        }
                        $this->unfreezePlayer($player);
                        $sender->sendMessage(TextFormat::GREEN . "Unfroze " . $player->getName());
                        return true;

                    default:
                        $sender->sendMessage(TextFormat::YELLOW . "AntiReach Commands:");
                        $sender->sendMessage("/antireach reload - Reload config");
                        $sender->sendMessage("/antireach freeze <player> - Freeze player");
                        $sender->sendMessage("/antireach unfreeze <player> - Unfreeze player");
                        return true;
                }
        }
        return false;
    }

    public function onPlayerHit(EntityDamageByEntityEvent $event): void {
        $damager = $event->getDamager();
        $victim = $event->getEntity();

        if (!($damager instanceof Player) || !($victim instanceof Player)) {
            return;
        }

        if (isset($this->frozenPlayers[$damager->getName()])) {
            $event->cancel();
            return;
        }

        $ping = $damager->getNetworkSession()->getPing();
        if ($ping > $this->config->getNested("antireach.exempt_ping_above", 250)) {
            return;
        }

        $distance = $damager->getPosition()->distance($victim->getPosition());
        $reach = $distance - 0.6;

        if (!isset($this->reachStats[$damager->getName()])) {
            $this->initPlayerStats($damager);
        }

        $this->recordHit($damager, $reach);

        $this->checkReachViolation($damager, $reach, $victim);

        $this->checkHitPattern($damager);

        if ($this->config->getNested("antireach.enable_angle_check", true)) {
            $this->checkAngle($damager, $victim, $reach);
        }

        $this->checkGhostHit($damager, $victim);
    }

    private function initPlayerStats(Player $player): void {
        $this->reachStats[$player->getName()] = [
            "total_hits" => 0,
            "suspect_hits" => 0,
            "max_reach" => 0,
            "avg_reach" => 0,
            "sum_reach" => 0,
            "correct_aim" => 0,
            "last_flagged" => null,
            "first_join" => time(),
            "sessions" => 1
        ];

        $this->hitPatterns[$player->getName()] = [];
    }

    private function recordHit(Player $player, float $reach): void {
        $name = $player->getName();
        $stats = &$this->reachStats[$name];

        $stats["total_hits"]++;
        $stats["sum_reach"] += $reach;
        $stats["avg_reach"] = $stats["sum_reach"] / $stats["total_hits"];

        if ($reach > $stats["max_reach"]) {
            $stats["max_reach"] = $reach;
        }

        array_push($this->hitPatterns[$name], $reach);
        if (count($this->hitPatterns[$name]) > 10) {
            array_shift($this->hitPatterns[$name]);
        }
    }

    private function checkReachViolation(Player $player, float $reach, Player $victim): void {
        $maxAllowed = $this->config->getNested("antireach.max_distance", 3.3);
        $warnThreshold = $this->config->getNested("antireach.warn_threshold", 3.1);
        $flagThreshold = $this->config->getNested("antireach.auto_flag_threshold", 3.4);

        if ($reach > $flagThreshold) {
            $this->flagPlayer($player, "Reach > " . round($reach, 2) . " blocks (max: $flagThreshold)");
            $this->reachStats[$player->getName()]["suspect_hits"]++;

            if ($this->config->getNested("antireach.auto_freeze_on_flag", true)) {
                $this->freezePlayer($player);
            }
        } elseif ($reach > $maxAllowed) {
            $this->reachStats[$player->getName()]["suspect_hits"]++;
            $this->warnPlayer($player, "Suspect reach: " . round($reach, 2) . " blocks (max: $maxAllowed)");
        } elseif ($reach > $warnThreshold) {
            $this->warnPlayer($player, "High reach: " . round($reach, 2) . " blocks (warning at: $warnThreshold)");
        }
    }

    private function checkHitPattern(Player $player): void {
        $name = $player->getName();
        $hits = $this->hitPatterns[$name];

        if (count($hits) < 5) return;

        $min = min($hits);
        $max = max($hits);
        $threshold = 3.1;

        if ($min > $threshold && $max - $min < 0.2) {
            $this->flagPlayer($player, "Pattern detection: Consistent reach ~" . round($min, 2));
        }

        $suspectCount = 0;
        foreach ($hits as $hit) {
            if ($hit > 3.1) $suspectCount++;
        }

        if ($suspectCount >= 8) {
            $this->flagPlayer($player, "Pattern detection: $suspectCount/10 hits > 3.1 blocks");
        }
    }

    private function checkAngle(Player $damager, Player $victim, float $reach): void {
        $damagerPos = $damager->getPosition();
        $victimPos = $victim->getPosition();
        $dx = $victimPos->x - $damagerPos->x;
        $dy = $victimPos->y - $damagerPos->y;
        $dz = $victimPos->z - $damagerPos->z;

        $expectedYaw = rad2deg(atan2(-$dx, $dz));
        $dist = sqrt($dx * $dx + $dz * $dz);
        $expectedPitch = rad2deg(-atan2($dy, $dist));

        $actualYaw = $damager->getLocation()->getYaw();
        $actualPitch = $damager->getLocation()->getPitch();

        $yawDiff = abs($expectedYaw - $actualYaw);
        $pitchDiff = abs($expectedPitch - $actualPitch);
        if ($yawDiff > 30 || $pitchDiff > 30) {
            $this->reachStats[$damager->getName()]["suspect_hits"]++;

            if ($reach > 3.0 || $this->reachStats[$damager->getName()]["suspect_hits"] > 5) {
                $this->flagPlayer($damager, "Angle violation (Yaw: $yawDiff°, Pitch: $pitchDiff°)");
            }
        } else {
            $this->reachStats[$damager->getName()]["correct_aim"]++;
        }
    }

    private function ghostEntityTest(): void {
        if (!$this->config->getNested("antireach.ghost_entity_test", true)) return;

        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if (isset($this->flaggedPlayers[$player->getName()])) {
                $this->spawnGhostEntity($player);
            }
        }
    }

    private function spawnGhostEntity(Player $player): void {
        $pos = $player->getPosition();
        $direction = $player->getDirectionVector();
        $ghostPos = $pos->add($direction->multiply(4.5));

        $particle = new FloatingTextParticle("", TextFormat::RED . "Ghost Entity (Anti-Cheat Test)");
        $player->getWorld()->addParticle($ghostPos, $particle);

        $this->ghostEntities[$player->getName()] = [
            "position" => $ghostPos,
            "particle" => $particle,
            "spawn_time" => time()
        ];

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $particle) {
            if (isset($this->ghostEntities[$player->getName()])) {
                $player->getWorld()->removeParticle($particle);
                unset($this->ghostEntities[$player->getName()]);
            }
        }), 20 * 10);
    }

    private function checkGhostHit(Player $damager, Player $victim): void {
        $name = $damager->getName();
        if (!isset($this->ghostEntities[$name])) return;

        $ghostPos = $this->ghostEntities[$name]["position"];
        $distance = $damager->getPosition()->distance($ghostPos);

        if ($distance < 5.0 && $victim->getName() === "Ghost Entity") {
            $this->flagPlayer($damager, "Ghost entity hit detected (Cheat confirmed)");
            $this->punishPlayer($damager, "ghost_hit");
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        if (isset($this->frozenPlayers[$player->getName()])) {
            $from = $event->getFrom();
            $to = $event->getTo();
            if ($from->x != $to->x || $from->y != $to->y || $from->z != $to->z) {
                $event->cancel();
            }
        }
    }

    private function flagPlayer(Player $player, string $reason): void {
        $name = $player->getName();

        if (!isset($this->flaggedPlayers[$name])) {
            $this->flaggedPlayers[$name] = [
                "count" => 0,
                "reasons" => [],
                "first_flag" => time(),
                "last_flag" => time()
            ];
        }

        $this->flaggedPlayers[$name]["count"]++;
        $this->flaggedPlayers[$name]["reasons"][] = $reason;
        $this->flaggedPlayers[$name]["last_flag"] = time();
        $this->reachStats[$name]["last_flagged"] = time();

        $this->getLogger()->warning("[AntiReach] Flagged $name: $reason");
        $player->sendMessage(TextFormat::RED . "[Anti-Cheat] Abnormal combat pattern detected. Staff notified.");

        $this->punishPlayer($player, "flag");
    }

    private function punishPlayer(Player $player, string $type): void {
        $name = $player->getName();
        $flagCount = $this->flaggedPlayers[$name]["count"] ?? 0;

        $punishments = $this->config->getNested("antireach.punishments", [
            "first_offense" => "freeze",
            "second_offense" => "kick",
            "third_offense" => "ban"
        ]);

        switch ($type) {
            case "flag":
                if ($flagCount === 1) {
                    $action = $punishments["first_offense"] ?? "freeze";
                } elseif ($flagCount === 2) {
                    $action = $punishments["second_offense"] ?? "kick";
                } else {
                    $action = $punishments["third_offense"] ?? "ban";
                }
                break;

            case "ghost_hit":
                $action = "ban";
                break;

            default:
                $action = "freeze";
        }

        switch ($action) {
            case "freeze":
                $this->freezePlayer($player);
                break;

            case "kick":
                $player->kick(TextFormat::RED . "Kicked by Anti-Cheat: Reach violations detected");
                break;

            case "ban":
                $this->getServer()->getNameBans()->addBan(
                    $name,
                    "Cheating detected (Reach)",
                    null,
                    "AntiReach"
                );
                $player->kick(TextFormat::RED . "Banned by Anti-Cheat: Reach violations detected");
                break;
        }
    }

    private function warnPlayer(Player $player, string $message): void {
        $player->sendMessage(TextFormat::YELLOW . "[Anti-Cheat Warning] " . TextFormat::WHITE . $message);
        $this->getLogger()->info("[AntiReach] Warning for {$player->getName()}: $message");
    }

    private function freezePlayer(Player $player): void {
        $name = $player->getName();
        $this->frozenPlayers[$name] = true;

        $player->sendTitle(
            TextFormat::RED . "FROZEN",
            TextFormat::WHITE . "Possible cheat detected",
            20, 100, 20
        );
        $player->sendMessage(TextFormat::RED . "You have been frozen by the Anti-Cheat system.");
        $player->sendMessage(TextFormat::GRAY . "Please contact staff if this is a mistake.");
    }

    private function unfreezePlayer(Player $player): void {
        $name = $player->getName();
        unset($this->frozenPlayers[$name]);
        $player->sendMessage(TextFormat::GREEN . "You have been unfrozen.");
    }

    private function getReachStats(Player $player): string {
        $name = $player->getName();
        if (!isset($this->reachStats[$name])) {
            return TextFormat::RED . "No combat data for $name";
        }

        $stats = $this->reachStats[$name];
        $aimAccuracy = $stats["total_hits"] > 0
            ? round(($stats["correct_aim"] / $stats["total_hits"]) * 100, 1)
            : 0;

        $output = TextFormat::GOLD . "Reach Stats for " . TextFormat::YELLOW . $name . "\n";
        $output .= TextFormat::GREEN . "Avg Reach: " . TextFormat::WHITE . round($stats["avg_reach"], 2) . " blocks\n";
        $output .= TextFormat::GREEN . "Max Reach: " . TextFormat::WHITE . round($stats["max_reach"], 2) . " blocks";
        $output .= $stats["max_reach"] > 3.3 ? TextFormat::RED . " (Flagged)" : "" . "\n";
        $output .= TextFormat::GREEN . "Aim Accuracy: " . TextFormat::WHITE . "$aimAccuracy%\n";
        $output .= TextFormat::GREEN . "Suspect Hits: " . TextFormat::WHITE . "{$stats["suspect_hits"]}/{$stats["total_hits"]}\n";
        $output .= TextFormat::GREEN . "Ping: " . TextFormat::WHITE . $player->getNetworkSession()->getPing() . "ms\n";

        if (isset($this->flaggedPlayers[$name])) {
            $flags = $this->flaggedPlayers[$name]["count"];
            $output .= TextFormat::RED . "Flags: " . TextFormat::WHITE . "$flags - ";
            $output .= implode(", ", array_slice($this->flaggedPlayers[$name]["reasons"], -3)) . "\n";
        } else {
            $output .= TextFormat::GREEN . "Status: " . TextFormat::DARK_GREEN . "Clean\n";
        }

        if (isset($this->frozenPlayers[$name])) {
            $output .= TextFormat::RED . "FROZEN: " . TextFormat::WHITE . "Yes\n";
        }

        return $output;
    }

    private function getOfflineReachStats(string $name): string {
        if (!isset($this->reachStats[$name])) {
            return TextFormat::RED . "No data available for $name";
        }

        $stats = $this->reachStats[$name];
        $aimAccuracy = $stats["total_hits"] > 0
            ? round(($stats["correct_aim"] / $stats["total_hits"]) * 100, 1)
            : 0;

        $output = TextFormat::GOLD . "Offline Reach Stats for " . TextFormat::YELLOW . $name . "\n";
        $output .= TextFormat::GREEN . "Avg Reach: " . TextFormat::WHITE . round($stats["avg_reach"], 2) . " blocks\n";
        $output .= TextFormat::GREEN . "Max Reach: " . TextFormat::WHITE . round($stats["max_reach"], 2) . " blocks";
        $output .= $stats["max_reach"] > 3.3 ? TextFormat::RED . " (Flagged)" : "" . "\n";
        $output .= TextFormat::GREEN . "Aim Accuracy: " . TextFormat::WHITE . "$aimAccuracy%\n";
        $output .= TextFormat::GREEN . "Suspect Hits: " . TextFormat::WHITE . "{$stats["suspect_hits"]}/{$stats["total_hits"]}\n";

        if (isset($this->flaggedPlayers[$name])) {
            $flags = $this->flaggedPlayers[$name]["count"];
            $output .= TextFormat::RED . "Flags: " . TextFormat::WHITE . "$flags - ";
            $output .= implode(", ", array_slice($this->flaggedPlayers[$name]["reasons"], -3)) . "\n";
        }

        $lastSeen = date("Y-m-d H:i", $stats["last_flagged"] ?? $stats["first_join"]);
        $output .= TextFormat::GREEN . "Last Seen: " . TextFormat::WHITE . $lastSeen . "\n";

        return $output;
    }

    private function cleanupData(): void {
        $now = time();
        $inactiveThreshold = 60 * 60 * 24 * 7;
        foreach ($this->reachStats as $name => $data) {
            $lastActive = $data["last_flagged"] ?? $data["first_join"];
            if ($now - $lastActive > $inactiveThreshold) {
                unset($this->reachStats[$name]);
                unset($this->hitPatterns[$name]);
                unset($this->flaggedPlayers[$name]);
            }
        }
    }
}