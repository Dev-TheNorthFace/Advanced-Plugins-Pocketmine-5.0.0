<?php

declare(strict_types=1);

namespace North\AntiAirJump\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\block\Block;

class Main extends PluginBase implements Listener {

    private array $playerData = [];
    private Config $config;

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "max_air_ticks" => 12,
            "max_y_increase_without_support" => 1.5,
            "min_gravity_speed" => -0.08,
            "allow_jump_boost" => true,
            "freeze_on_flag" => true,
            "exempt_when_flying" => true,
            "warn_threshold" => 3,
            "kick_threshold" => 5,
            "ban_threshold" => 10
        ]);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("AntiAirJump activé!");
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->initPlayerData($player);
    }

    public function onQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        unset($this->playerData[$player->getName()]);
    }

    private function initPlayerData(Player $player): void {
        $this->playerData[$player->getName()] = [
            "air_ticks" => 0,
            "last_on_ground" => true,
            "last_y" => $player->getPosition()->getY(),
            "y_increase_without_support" => 0,
            "violations" => 0,
            "gravity_checks" => [],
            "has_block_under" => false,
            "is_frozen" => false
        ];
    }

    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();
        $from = $event->getFrom();
        $to = $event->getTo();
        if ($this->config->get("exempt_when_flying", true) && $player->isFlying()) {
            return;
        }

        if (!isset($this->playerData[$name])) {
            $this->initPlayerData($player);
        }

        $data = &$this->playerData[$name];
        if ($data["is_frozen"]) {
            $event->cancel();
            return;
        }

        $deltaY = $to->getY() - $from->getY();
        $onGround = $this->isOnGround($player);
        if ($onGround) {
            $data["air_ticks"] = 0;
            $data["y_increase_without_support"] = 0;
            $data["last_on_ground"] = true;
        } else {
            $data["air_ticks"]++;
            $data["last_on_ground"] = false;
        }

        $data["has_block_under"] = $this->hasSolidBlockUnder($player);
        if ($deltaY > 0 && !$data["has_block_under"]) {
            $data["y_increase_without_support"] += $deltaY;
        } else {
            $data["y_increase_without_support"] = 0;
        }

        if ($deltaY < 0) {
            $this->checkGravity($player, $deltaY);
        }

        $this->checkViolations($player);
        $data["last_y"] = $to->getY();
    }

    private function isOnGround(Player $player): bool {
        $pos = $player->getPosition();
        $world = $player->getWorld();
        $blockUnder = $world->getBlock($pos->subtract(0, 0.1, 0));
        return $blockUnder->isSolid();
    }

    private function hasSolidBlockUnder(Player $player): bool {
        $pos = $player->getPosition();
        $world = $player->getWorld();
        for ($i = 1; $i <= 2; $i++) {
            $checkPos = $pos->subtract(0, $i, 0);
            $block = $world->getBlock($checkPos);
            if ($block->isSolid()) {
                return true;
            }
        }

        return false;
    }

    private function checkGravity(Player $player, float $deltaY): void {
        $name = $player->getName();
        $data = &$this->playerData[$name];
        $data["gravity_checks"][] = $deltaY;
        if (count($data["gravity_checks"]) > 10) {
            array_shift($data["gravity_checks"]);
        }

        $avg = array_sum($data["gravity_checks"]) / count($data["gravity_checks"]);
        $minGravity = $this->config->get("min_gravity_speed", -0.08);

        if ($avg > $minGravity) {
            $data["violations"] += 1;
            $this->flagPlayer($player, "Glide/Fly Hack (gravité anormale: " . round($avg, 3) . ")");
        }
    }

    private function checkViolations(Player $player): void {
        $name = $player->getName();
        $data = &$this->playerData[$name];
        $maxAirTicks = $this->config->get("max_air_ticks", 12);
        if ($data["air_ticks"] > $maxAirTicks) {
            $data["violations"] += 1;
            $this->flagPlayer($player, "AirJump (temps en l'air trop long: " . $data["air_ticks"] . " ticks)");
        }

        $maxYIncrease = $this->config->get("max_y_increase_without_support", 1.5);
        if ($data["y_increase_without_support"] > $maxYIncrease) {
            $data["violations"] += 1;
            $this->flagPlayer($player, "AirClimb (montée sans support: " . round($data["y_increase_without_support"], 2) . " blocs)");
        }

        $this->handleViolationActions($player);
    }

    private function flagPlayer(Player $player, string $reason): void {
        $name = $player->getName();
        $data = &$this->playerData[$name];

        $this->getLogger()->warning("[Flag] $name - $reason (Violations: " . $data["violations"] . ")");
        if ($this->config->get("freeze_on_flag", true)) {
            $data["is_frozen"] = true;
            $player->sendMessage("§cVous avez été gelé pour suspicion de triche. Contactez un staff.");
        }

        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            if ($onlinePlayer->hasPermission("antiairjump.alerts")) {
                $onlinePlayer->sendMessage("§4[AntiCheat] §c$name §7a été flag pour §f$reason");
            }
        }
    }

    private function handleViolationActions(Player $player): void {
        $name = $player->getName();
        $data = &$this->playerData[$name];
        $violations = $data["violations"];

        $warnThreshold = $this->config->get("warn_threshold", 3);
        $kickThreshold = $this->config->get("kick_threshold", 5);
        $banThreshold = $this->config->get("ban_threshold", 10);

        if ($violations >= $banThreshold) {
            $this->getServer()->getNameBans()->addBan($name, "Triche détectée (AirJump)", null, "AntiAirJump");
            $player->kick("§cVous avez été banni pour triche (AirJump)");
        } elseif ($violations >= $kickThreshold) {
            $player->kick("§cVous avez été expulsé pour suspicion de triche (AirJump)");
            $data["violations"] = 0; // Réinitialisation après kick
        } elseif ($violations >= $warnThreshold) {
            $player->sendMessage("§cAttention! Comportement anormal détecté. Arrêtez ou vous serez expulsé.");
        }
    }

    public function getPlayerStats(Player $player): string {
        $name = $player->getName();
        if (!isset($this->playerData[$name])) {
            return "§cAucune donnée pour ce joueur";
        }

        $data = $this->playerData[$name];
        $gravityChecks = count($data["gravity_checks"]) > 0 ?
            round(array_sum($data["gravity_checks"]) / count($data["gravity_checks"]), 3) : 0;

        $stats = "§6Statistiques AntiAirJump pour §e$name\n";
        $stats .= "§bMontées sans sol: §f" . round($data["y_increase_without_support"], 2) . " blocs\n";
        $stats .= "§bTemps en l'air: §f" . $data["air_ticks"] . " ticks\n";
        $stats .= "§bGravité moyenne: §f" . $gravityChecks . " ";
        $stats .= ($gravityChecks < $this->config->get("min_gravity_speed", -0.08)) ? "§a✔" : "§c✖ (suspect)";
        $stats .= "\n§bBloc sous joueur: §f" . ($data["has_block_under"] ? "§aOui" : "§cNon") . "\n";
        $stats .= "§bViolations: §f" . $data["violations"] . "\n";
        $stats .= "§bStatut: §f" . ($data["is_frozen"] ? "§cGelé" : "§aNormal");

        return $stats;
    }
}