<?php

declare(strict_types=1);

namespace North\AntiBadPacket\Main;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\{
    MovePlayerPacket,
    InteractPacket,
    InventoryTransactionPacket,
    LoginPacket,
    SetTitlePacket,
    ModalFormResponsePacket,
    ClientToServerHandshakePacket,
    TextPacket,
    Packet,
    types\InventoryTransaction
};
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\{
    Config,
    TextFormat as TF,
    Internet
};
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {

    private array $packetStats = [];
    private array $violations = [];
    private Config $config;
    private array $whitelist = [];
    private bool $underAttack = false;
    private int $globalPacketCount = 0;

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $this->saveResource("whitelist.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, $this->getDefaultConfig());
        $this->whitelist = (new Config($this->getDataFolder() . "whitelist.yml", Config::YAML, []))->getAll();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getNetwork()->setPacketLimit($this->config->get("global_packet_limit", 1000));

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->resetPacketCounters();
            $this->checkServerHealth();
        }), 20);

        $this->getLogger()->info(TF::GREEN . "AntiBadPacket enabled with " . count($this->whitelist) . " whitelisted packets");
    }

    private function getDefaultConfig(): array {
        return [
            "enable" => true,
            "debug" => false,
            "global_packet_limit" => 1000,
            "protection_level" => 2,
            "action_threshold" => 3,
            "packet_limits" => [
                "MovePlayer" => 50,
                "Interact" => 15,
                "InventoryTransaction" => 20,
                "Text" => 10
            ],
            "position_checks" => [
                "min_y" => -64,
                "max_y" => 320,
                "max_distance" => 100
            ],
            "text_limits" => [
                "max_length" => 256,
                "max_title_length" => 64
            ],
            "actions" => [
                "warn" => true,
                "kick" => true,
                "ban" => false,
                "ban_duration" => "1 hour",
                "notify_staff" => true,
                "log_to_file" => true,
                "enable_webhook" => false,
                "webhook_url" => ""
            ],
            "advanced" => [
                "check_checksum" => true,
                "validate_entities" => true,
                "deep_packet_inspection" => false,
                "enable_geoip" => false,
                "block_vpns" => false
            ]
        ];
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        if(!$this->config->get("enable", true)) return;

        $player = $event->getOrigin()->getPlayer();
        $packet = $event->getPacket();

        if($player === null) return;

        $this->globalPacketCount++;
        $this->trackPacket($player, $packet);

        if($this->isWhitelisted($packet)) return;
        if($this->isExempted($player)) return;

        $this->validatePacket($player, $packet);
        $this->checkPacketFlood($player, $packet);
        $this->checkAnomalies($player, $packet);
    }

    private function trackPacket(Player $player, Packet $packet): void {
        $playerName = $player->getName();
        $packetName = $packet->getName();
        $currentTick = $this->getServer()->getTick();

        if(!isset($this->packetStats[$playerName])) {
            $this->packetStats[$playerName] = [
                "total" => 0,
                "types" => [],
                "last_tick" => $currentTick,
                "last_packet" => null
            ];
        }

        $this->packetStats[$playerName]["total"]++;

        if(!isset($this->packetStats[$playerName]["types"][$packetName])) {
            $this->packetStats[$playerName]["types"][$packetName] = 0;
        }

        $this->packetStats[$playerName]["types"][$packetName]++;
        $this->packetStats[$playerName]["last_packet"] = $packetName;
        $this->packetStats[$playerName]["last_tick"] = $currentTick;
    }

    private function validatePacket(Player $player, Packet $packet): void {
        try {
            switch(true) {
                case $packet instanceof MovePlayerPacket:
                    $this->validateMovement($player, $packet);
                    break;

                case $packet instanceof InteractPacket:
                    $this->validateInteraction($player, $packet);
                    break;

                case $packet instanceof InventoryTransactionPacket:
                    $this->validateTransaction($player, $packet);
                    break;

                case $packet instanceof LoginPacket:
                    $this->validateLogin($player, $packet);
                    break;

                case $packet instanceof SetTitlePacket:
                case $packet instanceof TextPacket:
                    $this->validateTextContent($player, $packet);
                    break;

                case $packet instanceof ModalFormResponsePacket:
                    $this->validateFormResponse($player, $packet);
                    break;

                case $packet instanceof ClientToServerHandshakePacket:
                    $this->validateHandshake($player, $packet);
                    break;

                default:
                    $this->genericPacketCheck($player, $packet);
            }
        } catch(\Exception $e) {
            $this->flagViolation($player, "PacketValidationError", "Exception: " . $e->getMessage());
        }
    }

    private function validateMovement(Player $player, MovePlayerPacket $packet): void {
        $position = $packet->position;
        $config = $this->config->get("position_checks");

        if($position->y < $config["min_y"] || $position->y > $config["max_y"]) {
            $this->flagViolation($player, "InvalidPositionY", "Y: " . $position->y);
            $event->cancel();
            return;
        }

        $playerPos = $player->getPosition();
        $distance = $playerPos->distance($position);

        if($distance > $config["max_distance"]) {
            $this->flagViolation($player, "MoveDistanceExceeded", "Distance: " . $distance);
            $event->cancel();
        }

        if(abs($packet->pitch) > 90 || abs($packet->headYaw) > 360 || abs($packet->yaw) > 360) {
            $this->flagViolation($player, "InvalidRotation", "Pitch: {$packet->pitch}, Yaw: {$packet->yaw}");
            $event->cancel();
        }
    }

    private function validateInteraction(Player $player, InteractPacket $packet): void {
        if($packet->targetActorRuntimeId === 0 && $packet->action !== InteractPacket::ACTION_LEAVE_VEHICLE) {
            $this->flagViolation($player, "NullInteraction", "Action: " . $packet->action);
            $event->cancel();
        }

        if($this->config->get("advanced")["validate_entities"]) {
            $entity = $player->getWorld()->getEntity($packet->targetActorRuntimeId);
            if($entity === null && $packet->targetActorRuntimeId !== 0) {
                $this->flagViolation($player, "GhostInteraction", "EntityID: " . $packet->targetActorRuntimeId);
                $event->cancel();
            }
        }
    }

    private function validateTransaction(Player $player, InventoryTransactionPacket $packet): void {
        if(count($packet->actions) > 50) {
            $this->flagViolation($player, "TransactionOverflow", "Actions: " . count($packet->actions));
            $event->cancel();
            return;
        }

        foreach($packet->actions as $action) {
            if($action->getItem()->isNull() && !$action->getItem()->equals($action->getSourceItem())) {
                $this->flagViolation($player, "InvalidTransactionItem", "Item: " . $action->getItem()->getName());
                $event->cancel();
                break;
            }

            if($action->getSlot() > 100 || $action->getSlot() < 0) {
                $this->flagViolation($player, "InvalidTransactionSlot", "Slot: " . $action->getSlot());
                $event->cancel();
                break;
            }
        }
    }

    private function validateLogin(Player $player, LoginPacket $packet): void {
        if(empty($packet->username) || strlen($packet->username) > 16 || !preg_match('/^[a-zA-Z0-9_]+$/', $packet->username)) {
            $this->flagViolation($player, "InvalidUsername", "Name: " . $packet->username);
            $event->cancel();
            return;
        }

        if($this->config->get("advanced")["enable_geoip"] && $this->config->get("advanced")["block_vpns"]) {
            $ip = $player->getNetworkSession()->getIp();
            $result = Internet::getURL("https://proxycheck.io/v2/{$ip}?key=111111-222222-333333-444444&vpn=1");

            if($result !== null && isset(json_decode($result->getBody(), true)["status"]) && json_decode($result->getBody(), true)["status"] === "warning") {
                $this->flagViolation($player, "VPNProxyDetected", "IP: " . $ip);
                $event->cancel();
            }
        }
    }

    private function checkPacketFlood(Player $player, Packet $packet): void {
        $playerName = $player->getName();
        $packetName = $packet->getName();
        $limits = $this->config->get("packet_limits");

        if(!isset($limits[$packetName])) return;

        $currentCount = $this->packetStats[$playerName]["types"][$packetName] ?? 0;
        $maxAllowed = $limits[$packetName];

        if($currentCount > $maxAllowed) {
            $this->flagViolation($player, "PacketFlood", "{$packetName}: {$currentCount}/{$maxAllowed}");
            $event->cancel();
        }
    }

    private function flagViolation(Player $player, string $type, string $details = ""): void {
        $playerName = $player->getName();

        if(!isset($this->violations[$playerName])) {
            $this->violations[$playerName] = [];
        }

        $this->violations[$playerName][] = [
            "type" => $type,
            "details" => $details,
            "time" => time()
        ];

        $this->takeAction($player, $type);
    }

    private function takeAction(Player $player, string $violationType): void {
        $playerName = $player->getName();
        $violationCount = count($this->violations[$playerName] ?? []);
        $threshold = $this->config->get("action_threshold", 3);

        if($this->config->get("actions")["warn"] && $violationCount <= $threshold) {
            $player->sendMessage(TF::RED . "Warning: Suspicious activity detected (" . $violationType . ")");
        }

        if($violationCount >= $threshold) {
            if($this->config->get("actions")["notify_staff"]) {
                $this->notifyStaff($playerName, $violationType);
            }

            if($this->config->get("actions")["kick"]) {
                $player->kick(TF::RED . "AntiCheat: Suspicious activity detected");
            }

            if($this->config->get("actions")["ban"]) {
                $banTime = $this->parseDuration($this->config->get("actions")["ban_duration"]);
                $this->getServer()->getNameBans()->addBan($playerName, "AntiCheat: Bad Packet Detected", $banTime, "UltimateAntiBadPacket");
            }

            if($this->config->get("actions")["log_to_file"]) {
                $this->logToFile($playerName, $violationType);
            }

            if($this->config->get("actions")["enable_webhook"] && !empty($this->config->get("actions")["webhook_url"])) {
                $this->sendWebhook($playerName, $violationType);
            }
        }
    }

    private function checkServerHealth(): void {
        $packetRate = $this->globalPacketCount / 20;
        $maxRate = $this->config->get("global_packet_limit", 1000);

        if($packetRate > $maxRate * 0.8) {
            $this->underAttack = true;
            $this->getLogger()->warning("High packet rate detected: " . $packetRate . " packets/sec");

            if($packetRate > $maxRate) {
                $this->getServer()->getNetwork()->setPacketLimit((int)($maxRate * 0.8));
            }
        } else {
            $this->underAttack = false;
        }

        $this->globalPacketCount = 0;
    }

    private function resetPacketCounters(): void {
        foreach($this->packetStats as $playerName => $data) {
            $this->packetStats[$playerName]["total"] = 0;
            foreach($this->packetStats[$playerName]["types"] as $packetName => $count) {
                $this->packetStats[$playerName]["types"][$packetName] = 0;
            }
        }
    }

    private function isWhitelisted(Packet $packet): bool {
        return in_array($packet->getName(), $this->whitelist);
    }

    private function isExempted(Player $player): bool {
        return $player->hasPermission("antibadpacket.bypass");
    }
}