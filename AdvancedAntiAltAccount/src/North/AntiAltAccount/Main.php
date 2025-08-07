<?php

declare(strict_types=1);

namespace North\AntiAltAccount\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\network\mcpe\protocol\types\DeviceOS;

class Main extends PluginBase implements Listener {

    private Config $playerData;
    private array $config;
    private array $captcha = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $this->config = $this->getConfig()->getAll();
        $this->playerData = new Config($this->getDataFolder() . "players.json", Config::JSON, []);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->registerPlayer($player);

        if($this->shouldCheck($player)) {
            $this->processAltCheck($player);
        }
    }

    private function registerPlayer(Player $player): void {
        $data = $this->playerData->getAll();
        $uuid = $player->getUniqueId()->toString();

        if(!isset($data[$uuid])) {
            $data[$uuid] = [
                "name" => $player->getName(),
                "ips" => [$player->getNetworkSession()->getIp()],
                "device_id" => $player->getNetworkSession()->getClientId(),
                "os" => $player->getPlayerInfo()->getExtraData()["DeviceOS"] ?? DeviceOS::UNKNOWN,
                "first_join" => time(),
                "last_join" => time(),
                "playtime" => 0,
                "verified" => false
            ];
        } else {
            $data[$uuid]["last_join"] = time();
            if(!in_array($player->getNetworkSession()->getIp(), $data[$uuid]["ips"])) {
                $data[$uuid]["ips"][] = $player->getNetworkSession()->getIp();
            }
        }

        $this->playerData->setAll($data);
        $this->playerData->save();
    }

    private function shouldCheck(Player $player): bool {
        $uuid = $player->getUniqueId()->toString();
        $data = $this->playerData->getAll()[$uuid] ?? [];

        if(empty($data)) return false;
        if($data["verified"]) return false;

        foreach($this->config["exempt_ranks"] as $rank) {
            if($player->hasPermission($rank)) return false;
        }

        return true;
    }

    private function processAltCheck(Player $player): void {
        $uuid = $player->getUniqueId()->toString();
        $playerData = $this->playerData->getAll()[$uuid];
        $allData = $this->playerData->getAll();

        $checks = [
            "ip" => $this->checkIP($player, $playerData, $allData),
            "device" => $this->checkDevice($player, $playerData, $allData),
            "age" => $this->checkAccountAge($player, $playerData),
            "behavior" => $this->checkBehavior($player)
        ];

        if(in_array(true, $checks, true)) {
            $this->takeAction($player, array_filter($checks));
        } elseif($this->config["require_captcha"] ?? false) {
            $this->initCaptcha($player);
        }
    }

    private function checkIP(Player $player, array $playerData, array $allData): bool {
        if(!$this->config["enable_ip_check"]) return false;

        $linked = [];
        foreach($allData as $uuid => $data) {
            if($uuid !== $player->getUniqueId()->toString() && array_intersect($playerData["ips"], $data["ips"])) {
                $linked[] = $data["name"];
            }
        }

        if(count($linked) >= $this->config["max_accounts_per_ip"]) {
            $this->notifyStaff("§cALT IP §f{$player->getName()} partage IP avec: ".implode(", ", $linked));
            return true;
        }

        return false;
    }

    private function checkDevice(Player $player, array $playerData, array $allData): bool {
        if(!$this->config["enable_device_id_check"] || empty($playerData["device_id"])) return false;

        $linked = [];
        foreach($allData as $uuid => $data) {
            if($uuid !== $player->getUniqueId()->toString() && $data["device_id"] === $playerData["device_id"]) {
                $linked[] = $data["name"];
            }
        }

        if(!empty($linked)) {
            $this->notifyStaff("§cALT DEVICE §f{$player->getName()} même appareil que: ".implode(", ", $linked));
            return true;
        }

        return false;
    }

    private function checkAccountAge(Player $player, array $playerData): bool {
        $minAge = $this->config["block_new_accounts_under_x_minutes"] * 60;
        $age = time() - $playerData["first_join"];

        if($age < $minAge) {
            $this->notifyStaff("§cALT AGE §f{$player->getName()} compte trop récent (".round($age/60)." minutes)");
            return true;
        }

        return false;
    }

    private function checkBehavior(Player $player): bool {
        return false;
    }

    private function takeAction(Player $player, array $reasons): void {
        $actions = [
            "ip" => fn() => $player->kick("§cTrop de comptes depuis votre IP"),
            "device" => fn() => $player->kick("§cAppareil déjà utilisé"),
            "age" => fn() => $player->kick("§cCompte trop récent"),
            "behavior" => fn() => $player->kick("§cComportement suspect")
        ];

        foreach(array_keys($reasons) as $reason) {
            if(isset($actions[$reason])) $actions[$reason]();
        }
    }

    private function initCaptcha(Player $player): void {
        $code = substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ23456789"), 0, 6);
        $this->captcha[$player->getName()] = $code;

        $player->sendMessage("§e[CAPTCHA] §fVeuillez taper: §a/captcha ".$code);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player): void {
            if(isset($this->captcha[$player->getName()])) {
                $player->kick("§cCAPTCHA non validé");
            }
        }), 20 * 60);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch($command->getName()) {
            case "alts":
                return $this->handleAltCheckCommand($sender, $args);
            case "captcha":
                return $this->handleCaptchaCommand($sender instanceof Player ? $sender : null, $args);
            default:
                return false;
        }
    }

    private function handleAltCheckCommand(CommandSender $sender, array $args): bool {
        if(!isset($args[0])) {
            $sender->sendMessage("Usage: /alts <joueur>");
            return false;
        }

        $target = $args[0];
        $allData = $this->playerData->getAll();
        $found = null;

        foreach($allData as $data) {
            if(strtolower($data["name"]) === strtolower($target)) {
                $found = $data;
                break;
            }
        }

        if($found === null) {
            $sender->sendMessage("§cJoueur introuvable");
            return false;
        }

        $linkedIPs = [];
        $linkedDevices = [];

        foreach($allData as $data) {
            if($data["name"] !== $found["name"]) {
                if(array_intersect($found["ips"], $data["ips"])) {
                    $linkedIPs[] = $data["name"];
                }
                if($found["device_id"] === $data["device_id"]) {
                    $linkedDevices[] = $data["name"];
                }
            }
        }

        $sender->sendMessage("§6=== Rapport ALT pour §e{$found["name"]}§6 ===");
        $sender->sendMessage("§bIPs utilisées: §f".implode(", ", $found["ips"]));
        $sender->sendMessage("§bAppareil: §f".$found["device_id"]);
        $sender->sendMessage("§bPremière connexion: §f".date("d/m/Y H:i", $found["first_join"]));
        $sender->sendMessage("§bComptes liés (IP): §f".(empty($linkedIPs) ? "Aucun" : implode(", ", $linkedIPs)));
        $sender->sendMessage("§bComptes liés (Appareil): §f".(empty($linkedDevices) ? "Aucun" : implode(", ", $linkedDevices)));

        return true;
    }

    private function handleCaptchaCommand(?Player $player, array $args): bool {
        if($player === null) return false;
        if(!isset($args[0])) return false;

        $name = $player->getName();
        if(!isset($this->captcha[$name])) {
            $player->sendMessage("§cAucun CAPTCHA en attente");
            return false;
        }

        if($args[0] === $this->captcha[$name]) {
            unset($this->captcha[$name]);
            $player->sendMessage("§aCAPTCHA validé!");

            $uuid = $player->getUniqueId()->toString();
            $data = $this->playerData->getAll();
            $data[$uuid]["verified"] = true;
            $this->playerData->setAll($data);
            $this->playerData->save();
        } else {
            $player->kick("§cCAPTCHA incorrect");
        }

        return true;
    }

    private function notifyStaff(string $message): void {
        if($this->config["notify_staff_on_alt_detected"]) {
            foreach($this->getServer()->getOnlinePlayers() as $staff) {
                if($staff->hasPermission("altdetector.notify")) {
                    $staff->sendMessage($message);
                }
            }
        }

        if(!empty($this->config["webhook_url"])) {
            $this->sendWebhook($message);
        }
    }

    private function sendWebhook(string $message): void {
        $webhook = $this->config["webhook_url"];
        $payload = json_encode(["content" => $message]);

        $this->getServer()->getAsyncPool()->submitTask(new ClosureTask(function() use ($webhook, $payload): void {
            $ch = curl_init($webhook);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
        }));
    }
}