<?php

declare(strict_types=1);

namespace North\AntiNoClip\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;
use pocketmine\block\Block;
use pocketmine\block\Slab;
use pocketmine\block\Stair;
use pocketmine\block\Trapdoor;
use pocketmine\block\Piston;
use pocketmine\block\PistonArm;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\world\particle\BlockBreakParticle;
use pocketmine\item\VanillaItems;

class Main extends PluginBase implements Listener {

    private array $violations = [];
    private array $lastPositions = [];
    private array $ghostBlocks = [];
    private array $noclipFlags = [];
    private Config $config;
    private array $exemptBlocks = [];
    private array $adminAlerts = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->initConfig();
        $this->initExemptBlocks();
        $this->registerCommands();
        $this->initGhostBlocks();
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->checkAllPlayers();
        }), 20);
    }

    private function initConfig(): void {
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "antinoclip" => [
                "check_inside_block" => true,
                "max_ticks_inside_block" => 4,
                "allow_slab_halfblock" => true,
                "boundingbox_check" => true,
                "teleport_on_detection" => true,
                "freeze_on_flag" => true,
                "exempt_op" => true,
                "ghostblock_check" => true,
                "alert_threshold" => 3,
                "ban_after_flags" => 5,
                "ban_duration" => "7d",
                "replay_length" => 100,
                "webhook_url" => ""
            ]
        ]);
    }

    private function initExemptBlocks(): void {
        $this->exemptBlocks = [
            VanillaBlocks::WATER(),
            VanillaBlocks::LAVA(),
            VanillaBlocks::COBWEB(),
            VanillaBlocks::VINES(),
            VanillaBlocks::LADDER()
        ];
    }

    private function registerCommands(): void {
        $this->getServer()->getCommandMap()->register("antinoclip", new class($this) extends Command {
            public function __construct(private AntiNoClip $plugin) {
                parent::__construct("noclipstats", "View NoClip statistics", "/noclipstats [player]");
                $this->setPermission("antinoclip.stats");
            }

            public function execute(CommandSender $sender, string $commandLabel, array $args): void {
                $target = isset($args[0]) ? $this->plugin->getServer()->getPlayerExact($args[0]) : ($sender instanceof Player ? $sender : null);

                if ($target === null) {
                    $sender->sendMessage(TextFormat::RED . "Player not found.");
                    return;
                }

                $stats = $this->plugin->getPlayerStats($target);
                $sender->sendMessage(TextFormat::GOLD . "NoClip Stats for " . $target->getName());
                $sender->sendMessage(TextFormat::YELLOW . "Position: " . TextFormat::WHITE . $stats['position']);
                $sender->sendMessage(TextFormat::YELLOW . "Current Block: " . TextFormat::WHITE . $stats['current_block']);
                $sender->sendMessage(TextFormat::YELLOW . "Ticks inside block: " . ($stats['ticks_inside'] > 0 ? TextFormat::RED : TextFormat::GREEN) . $stats['ticks_inside']);
                $sender->sendMessage(TextFormat::YELLOW . "Max speed: " . TextFormat::WHITE . $stats['max_speed'] . " blocks/tick");
                $sender->sendMessage(TextFormat::YELLOW . "Flags: " . ($stats['flags'] > 0 ? TextFormat::RED : TextFormat::GREEN) . $stats['flags']);
            }
        });
    }

    private function initGhostBlocks(): void {
        if ($this->config->getNested("antinoclip.ghostblock_check")) {
            $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
                foreach ($this->getServer()->getOnlinePlayers() as $player) {
                    $this->placeGhostBlock($player);
                }
            }), 100);
        }
    }

    private function placeGhostBlock(Player $player): void {
        $pos = $player->getPosition()->add(0, -1, 0);
        $block = VanillaBlocks::GLASS();
        $player->getWorld()->sendBlocks([$player], [$pos->floor() => $block]);
        $this->ghostBlocks[$player->getName()] = $pos->floor();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $pos): void {
            if (isset($this->ghostBlocks[$player->getName()])) {
                $realBlock = $player->getWorld()->getBlock($pos);
                $player->getWorld()->sendBlocks([$player], [$pos->floor() => $realBlock]);
                unset($this->ghostBlocks[$player->getName()]);
            }
        }), 20 * 5);
    }

    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();

        if ($this->shouldExemptPlayer($player)) {
            return;
        }

        $from = $event->getFrom();
        $to = $event->getTo();

        $this->checkInsideBlock($player, $to);
        $this->checkPath($player, $from, $to);
        $this->checkGhostBlocks($player, $to);
        $this->checkBoundingBox($player);
        $this->recordPosition($player, $to);
    }

    private function shouldExemptPlayer(Player $player): bool {
        return ($this->config->getNested("antinoclip.exempt_op") && $player->isOp()) ||
            $player->hasPermission("antinoclip.bypass") ||
            $player->isCreative() ||
            $player->isSpectator();
    }

    private function checkAllPlayers(): void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if ($this->shouldExemptPlayer($player)) {
                continue;
            }

            $this->checkInsideBlock($player, $player->getPosition());
            $this->checkBoundingBox($player);
        }
    }

    private function checkInsideBlock(Player $player, Position $position): void {
        if (!$this->config->getNested("antinoclip.check_inside_block")) return;

        $block = $player->getWorld()->getBlock($position->floor());

        if ($this->isSolidForCheck($block)) {
            $uuid = $player->getUniqueId()->toString();

            $this->violations[$uuid] = ($this->violations[$uuid] ?? 0) + 1;

            if ($this->violations[$uuid] >= $this->config->getNested("antinoclip.max_ticks_inside_block")) {
                $this->flagPlayer($player, "Inside solid block for " . $this->violations[$uuid] . " ticks");

                if ($this->config->getNested("antinoclip.teleport_on_detection")) {
                    $safePos = $this->findSafePosition($player);
                    $player->teleport($safePos);
                }

                if ($this->config->getNested("antinoclip.freeze_on_flag")) {
                    $player->setImmobile(true);
                    $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player): void {
                        $player->setImmobile(false);
                    }), 20 * 3);
                }

                $this->sendAlert($player, "NoClip detected: Inside block");
            }
        } else {
            $uuid = $player->getUniqueId()->toString();
            $this->violations[$uuid] = 0;
        }
    }

    private function checkPath(Player $player, Position $from, Position $to): void {
        $distance = $from->distance($to);
        if ($distance > 1.5) {
            $path = $this->tracePath($from, $to);
            $solidCount = 0;

            foreach ($path as $point) {
                $block = $player->getWorld()->getBlock($point);
                if ($this->isSolidForCheck($block)) {
                    $solidCount++;
                }
            }

            if ($solidCount >= 2) {
                $this->flagPlayer($player, "Moved through $solidCount solid blocks in one tick (Distance: $distance)");
                $this->sendAlert($player, "NoClip detected: Moved through $solidCount blocks");

                if ($this->config->getNested("antinoclip.teleport_on_detection")) {
                    $player->teleport($from);
                }
            }
        }
    }

    private function checkGhostBlocks(Player $player, Position $position): void {
        if (!$this->config->getNested("antinoclip.ghostblock_check")) return;
        $name = $player->getName();
        if (isset($this->ghostBlocks[$name])) {
            $ghostPos = $this->ghostBlocks[$name];
            if ($position->distance($ghostPos) < 1.5 && $position->y < $ghostPos->y + 2 && $position->y > $ghostPos->y - 1) {
                $this->flagPlayer($player, "Passed through ghost block at " . $ghostPos->__toString());
                $this->sendAlert($player, "NoClip detected: Ghost block bypass");
                $player->getWorld()->addParticle($ghostPos, new BlockBreakParticle(VanillaBlocks::GLASS()));
                if ($this->config->getNested("antinoclip.teleport_on_detection")) {
                    $player->teleport($player->getLastKnownPosition());
                }
            }
        }
    }

    private function checkBoundingBox(Player $player): void {
        if (!$this->config->getNested("antinoclip.boundingbox_check")) return;
        $pos = $player->getPosition();
        $bb = $player->getBoundingBox();
        for ($x = floor($bb->minX); $x <= floor($bb->maxX); $x++) {
            for ($y = floor($bb->minY); $y <= floor($bb->maxY); $y++) {
                for ($z = floor($bb->minZ); $z <= floor($bb->maxZ); $z++) {
                    $block = $player->getWorld()->getBlockAt($x, $y, $z);

                    if ($this->isSolidForCheck($block)) {
                        $this->flagPlayer($player, "Bounding box inside solid block at $x, $y, $z");
                        $this->sendAlert($player, "NoClip detected: Bounding box collision");

                        if ($this->config->getNested("antinoclip.teleport_on_detection")) {
                            $player->teleport($this->findSafePosition($player));
                        }
                        return;
                    }
                }
            }
        }
    }

    private function recordPosition(Player $player, Position $pos): void {
        $uuid = $player->getUniqueId()->toString();

        if (!isset($this->lastPositions[$uuid])) {
            $this->lastPositions[$uuid] = [];
        }

        array_unshift($this->lastPositions[$uuid], $pos);

        if (count($this->lastPositions[$uuid]) > $this->config->getNested("antinoclip.replay_length")) {
            array_pop($this->lastPositions[$uuid]);
        }
    }

    private function tracePath(Vector3 $start, Vector3 $end): array {
        $path = [];
        $steps = max(5, (int) ceil($start->distance($end) * 2));

        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $x = $start->x + ($end->x - $start->x) * $t;
            $y = $start->y + ($end->y - $start->y) * $t;
            $z = $start->z + ($end->z - $start->z) * $t;
            $path[] = new Vector3($x, $y, $z);
        }

        return $path;
    }

    private function isSolidForCheck(Block $block): bool {
        if (in_array($block, $this->exemptBlocks, true)) {
            return false;
        }

        if ($block instanceof Slab && $this->config->getNested("antinoclip.allow_slab_halfblock")) {
            return false;
        }

        if ($block instanceof Stair || $block instanceof Trapdoor) {
            return false;
        }

        if ($block instanceof Piston || $block instanceof PistonArm) {
            return false;
        }

        return $block->isSolid();
    }

    private function findSafePosition(Player $player): Position {
        $world = $player->getWorld();
        $pos = $player->getPosition();
        if (!$this->isSolidForCheck($world->getBlock($pos)) {
            return $pos;
    }

        for ($radius = 1; $radius <= 5; $radius++) {
            for ($y = -$radius; $y <= $radius; $y++) {
                for ($x = -$radius; $x <= $radius; $x++) {
                    for ($z = -$radius; $z <= $radius; $z++) {
                        if (abs($x) < $radius && abs($y) < $radius && abs($z) < $radius) continue;

                        $checkPos = $pos->add($x, $y, $z);
                        $block = $world->getBlock($checkPos);
                        $blockAbove = $world->getBlock($checkPos->add(0, 1, 0));

                        if (!$this->isSolidForCheck($block) && !$this->isSolidForCheck($blockAbove)) {
                            return $checkPos;
                        }
                    }
                }
            }
        }

        return $player->getWorld()->getSpawnLocation();
    }

    private function flagPlayer(Player $player, string $reason): void {
        $uuid = $player->getUniqueId()->toString();

        $this->noclipFlags[$uuid] = ($this->noclipFlags[$uuid] ?? 0) + 1;

        $this->getLogger()->warning("[AntiNoClip] Player {$player->getName()} flagged: $reason (Total flags: {$this->noclipFlags[$uuid]})");

        if ($this->noclipFlags[$uuid] >= $this->config->getNested("antinoclip.ban_after_flags")) {
            $banMessage = "You have been banned for NoClip hacking";
            $player->kick($banMessage);
            $this->getServer()->getNameBans()->addBan($player->getName(), $banMessage, null, "AntiNoClip");
            unset($this->noclipFlags[$uuid]);
        }
    }

    private function sendAlert(Player $player, string $message): void {
        $threshold = $this->config->getNested("antinoclip.alert_threshold");
        $uuid = $player->getUniqueId()->toString();

        if (($this->noclipFlags[$uuid] ?? 0) >= $threshold) {
            foreach ($this->getServer()->getOnlinePlayers() as $staff) {
                if ($staff->hasPermission("antinoclip.alerts")) {
                    $staff->sendMessage(TextFormat::RED . "[AntiNoClip] " . TextFormat::WHITE . $player->getName() . ": " . $message);
                }
            }

            $this->sendWebhookAlert($player, $message);
        }
    }

    private function sendWebhookAlert(Player $player, string $message): void {
        $webhookUrl = $this->config->getNested("antinoclip.webhook_url");
        if (empty($webhookUrl)) return;

        $data = [
            "embeds" => [
                [
                    "title" => "NoClip Detection",
                    "description" => $message,
                    "fields" => [
                        [
                            "name" => "Player",
                            "value" => $player->getName(),
                            "inline" => true
                        ],
                        [
                            "name" => "Position",
                            "value" => $player->getPosition()->__toString(),
                            "inline" => true
                        ],
                        [
                            "name" => "Total Flags",
                            "value" => $this->noclipFlags[$player->getUniqueId()->toString()] ?? 0,
                            "inline" => true
                        ]
                    ],
                    "color" => 16711680,
                    "timestamp" => date("c")
                ]
            ]
        ];

        $this->getServer()->getAsyncPool()->submitTask(new class($webhookUrl, $data) extends AsyncTask {
            public function __construct(private string $url, private array $data) {}

            public function onRun(): void {
                $ch = curl_init($this->url);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
            }
        });
    }

    public function getPlayerStats(Player $player): array {
        $uuid = $player->getUniqueId()->toString();
        $pos = $player->getPosition();
        $block = $player->getWorld()->getBlock($pos);

        return [
            'position' => $pos->__toString(),
            'current_block' => $block->getName() . " (solid: " . ($block->isSolid() ? "yes" : "no") . ")",
            'ticks_inside' => $this->violations[$uuid] ?? 0,
            'max_speed' => $this->calculateMaxSpeed($player),
            'flags' => $this->noclipFlags[$uuid] ?? 0
        ];
    }

    private function calculateMaxSpeed(Player $player): float {
        $uuid = $player->getUniqueId()->toString();
        if (empty($this->lastPositions[$uuid]) || count($this->lastPositions[$uuid]) < 2) {
            return 0.0;
        }

        $maxSpeed = 0.0;
        $lastPos = $this->lastPositions[$uuid][0];

        for ($i = 1; $i < min(5, count($this->lastPositions[$uuid])); $i++) {
            $currentPos = $this->lastPositions[$uuid][$i];
            $distance = $lastPos->distance($currentPos);
            $ticks = $i;
            $speed = $distance / $ticks;

            if ($speed > $maxSpeed) {
                $maxSpeed = $speed;
            }
        }

        return round($maxSpeed, 2);
    }

    public function getReplayData(Player $player): array {
        $uuid = $player->getUniqueId()->toString();
        return $this->lastPositions[$uuid] ?? [];
    }
}