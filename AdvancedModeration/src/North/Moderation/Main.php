<?php

declare(strict_types=1);

namespace North\Moderation\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;

class Main extends PluginBase implements Listener {

    private $mutedPlayers = [];
    private $bannedPlayers = [];
    private $frozenPlayers = [];
    private $warnings = [];
    private $reports = [];
    private $config;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->loadData();
    }

    private function loadData(): void {
        if(file_exists($this->getDataFolder() . "muted_players.json")) {
            $this->mutedPlayers = json_decode(file_get_contents($this->getDataFolder() . "muted_players.json"), true);
        }
        if(file_exists($this->getDataFolder() . "banned_players.json")) {
            $this->bannedPlayers = json_decode(file_get_contents($this->getDataFolder() . "banned_players.json"), true);
        }
        if(file_exists($this->getDataFolder() . "warnings.json")) {
            $this->warnings = json_decode(file_get_contents($this->getDataFolder() . "warnings.json"), true);
        }
        if(file_exists($this->getDataFolder() . "reports.json")) {
            $this->reports = json_decode(file_get_contents($this->getDataFolder() . "reports.json"), true);
        }
    }

    private function saveData(): void {
        file_put_contents($this->getDataFolder() . "muted_players.json", json_encode($this->mutedPlayers));
        file_put_contents($this->getDataFolder() . "banned_players.json", json_encode($this->bannedPlayers));
        file_put_contents($this->getDataFolder() . "warnings.json", json_encode($this->warnings));
        file_put_contents($this->getDataFolder() . "reports.json", json_encode($this->reports));
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch(strtolower($command->getName())) {
            case "ban":
                return $this->banCommand($sender, $args);
            case "tempban":
                return $this->tempBanCommand($sender, $args);
            case "unban":
                return $this->unbanCommand($sender, $args);
            case "mute":
                return $this->muteCommand($sender, $args);
            case "tempmute":
                return $this->tempMuteCommand($sender, $args);
            case "unmute":
                return $this->unmuteCommand($sender, $args);
            case "kick":
                return $this->kickCommand($sender, $args);
            case "warn":
                return $this->warnCommand($sender, $args);
            case "history":
                return $this->historyCommand($sender, $args);
            case "check":
                return $this->checkCommand($sender, $args);
            case "invsee":
                return $this->invseeCommand($sender, $args);
            case "freeze":
                return $this->freezeCommand($sender, $args);
            case "ss":
                return $this->screenshareCommand($sender, $args);
            case "report":
                return $this->reportCommand($sender, $args);
            default:
                return false;
        }
    }

    private function banCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.ban")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 2) {
            $sender->sendMessage(TextFormat::RED . "Usage: /ban <joueur> <raison>");
            return true;
        }
        $playerName = $args[0];
        $reason = implode(" ", array_slice($args, 1));
        $this->bannedPlayers[$playerName] = [
            "reason" => $reason,
            "staff" => $sender->getName(),
            "date" => date("Y-m-d H:i:s"),
            "type" => "permanent"
        ];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player !== null) {
            $player->kick(TextFormat::RED . "Vous avez été banni!\nRaison: " . $reason . "\nPar: " . $sender->getName());
        }
        $this->getServer()->broadcastMessage(TextFormat::RED . $playerName . " a été banni par " . $sender->getName() . " pour: " . $reason);
        $this->saveData();
        return true;
    }

    private function tempBanCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.tempban")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 3) {
            $sender->sendMessage(TextFormat::RED . "Usage: /tempban <joueur> <durée> <raison>");
            return true;
        }
        $playerName = $args[0];
        $duration = $args[1];
        $reason = implode(" ", array_slice($args, 2));
        $this->bannedPlayers[$playerName] = [
            "reason" => $reason,
            "staff" => $sender->getName(),
            "date" => date("Y-m-d H:i:s"),
            "type" => "temporary",
            "duration" => $duration,
            "expires" => strtotime("+$duration")
        ];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player !== null) {
            $player->kick(TextFormat::RED . "Vous avez été banni temporairement!\nRaison: " . $reason . "\nPar: " . $sender->getName() . "\nDurée: " . $duration);
        }
        $this->getServer()->broadcastMessage(TextFormat::RED . $playerName . " a été banni temporairement par " . $sender->getName() . " pour: " . $reason . " (Durée: " . $duration . ")");
        $this->saveData();
        return true;
    }

    private function unbanCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.unban")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /unban <joueur>");
            return true;
        }
        $playerName = $args[0];
        if(!isset($this->bannedPlayers[$playerName])) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur n'est pas banni.");
            return true;
        }
        unset($this->bannedPlayers[$playerName]);
        $sender->sendMessage(TextFormat::GREEN . $playerName . " a été débanni avec succès.");
        $this->saveData();
        return true;
    }

    private function muteCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.mute")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 2) {
            $sender->sendMessage(TextFormat::RED . "Usage: /mute <joueur> <raison>");
            return true;
        }
        $playerName = $args[0];
        $reason = implode(" ", array_slice($args, 1));
        $this->mutedPlayers[$playerName] = [
            "reason" => $reason,
            "staff" => $sender->getName(),
            "date" => date("Y-m-d H:i:s"),
            "type" => "permanent"
        ];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player !== null) {
            $player->sendMessage(TextFormat::RED . "Vous avez été mute!\nRaison: " . $reason . "\nPar: " . $sender->getName());
        }
        $this->getServer()->broadcastMessage(TextFormat::RED . $playerName . " a été mute par " . $sender->getName() . " pour: " . $reason);
        $this->saveData();
        return true;
    }

    private function tempMuteCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.tempmute")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 3) {
            $sender->sendMessage(TextFormat::RED . "Usage: /tempmute <joueur> <durée> <raison>");
            return true;
        }
        $playerName = $args[0];
        $duration = $args[1];
        $reason = implode(" ", array_slice($args, 2));
        $this->mutedPlayers[$playerName] = [
            "reason" => $reason,
            "staff" => $sender->getName(),
            "date" => date("Y-m-d H:i:s"),
            "type" => "temporary",
            "duration" => $duration,
            "expires" => strtotime("+$duration")
        ];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player !== null) {
            $player->sendMessage(TextFormat::RED . "Vous avez été mute temporairement!\nRaison: " . $reason . "\nPar: " . $sender->getName() . "\nDurée: " . $duration);
        }
        $this->getServer()->broadcastMessage(TextFormat::RED . $playerName . " a été mute temporairement par " . $sender->getName() . " pour: " . $reason . " (Durée: " . $duration . ")");
        $this->saveData();
        return true;
    }

    private function unmuteCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.unmute")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /unmute <joueur>");
            return true;
        }
        $playerName = $args[0];
        if(!isset($this->mutedPlayers[$playerName])) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur n'est pas mute.");
            return true;
        }
        unset($this->mutedPlayers[$playerName]);
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player !== null) {
            $player->sendMessage(TextFormat::GREEN . "Vous avez été unmute par " . $sender->getName());
        }
        $sender->sendMessage(TextFormat::GREEN . $playerName . " a été unmute avec succès.");
        $this->saveData();
        return true;
    }

    private function kickCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.kick")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 2) {
            $sender->sendMessage(TextFormat::RED . "Usage: /kick <joueur> <raison>");
            return true;
        }
        $playerName = $args[0];
        $reason = implode(" ", array_slice($args, 1));
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player === null) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur n'est pas connecté.");
            return true;
        }
        $player->kick(TextFormat::RED . "Vous avez été kick!\nRaison: " . $reason . "\nPar: " . $sender->getName());
        $this->getServer()->broadcastMessage(TextFormat::RED . $playerName . " a été kick par " . $sender->getName() . " pour: " . $reason);
        return true;
    }

    private function warnCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.warn")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 2) {
            $sender->sendMessage(TextFormat::RED . "Usage: /warn <joueur> <raison>");
            return true;
        }
        $playerName = $args[0];
        $reason = implode(" ", array_slice($args, 1));
        if(!isset($this->warnings[$playerName])) {
            $this->warnings[$playerName] = [];
        }
        $this->warnings[$playerName][] = [
            "reason" => $reason,
            "staff" => $sender->getName(),
            "date" => date("Y-m-d H:i:s")
        ];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player !== null) {
            $player->sendMessage(TextFormat::RED . "Vous avez reçu un avertissement!\nRaison: " . $reason . "\nPar: " . $sender->getName());
        }
        $sender->sendMessage(TextFormat::GREEN . "Avertissement envoyé à " . $playerName . " pour: " . $reason);
        $this->saveData();
        return true;
    }

    private function historyCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.history")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /history <joueur>");
            return true;
        }
        $playerName = $args[0];
        $history = [];
        if(isset($this->warnings[$playerName])) {
            $history["warnings"] = $this->warnings[$playerName];
        }
        if(isset($this->mutedPlayers[$playerName])) {
            $history["mutes"] = $this->mutedPlayers[$playerName];
        }
        if(isset($this->bannedPlayers[$playerName])) {
            $history["bans"] = $this->bannedPlayers[$playerName];
        }
        if(empty($history)) {
            $sender->sendMessage(TextFormat::YELLOW . "Aucun historique trouvé pour " . $playerName);
            return true;
        }
        $sender->sendMessage(TextFormat::GOLD . "---- Historique de " . $playerName . " ----");
        foreach($history as $type => $entries) {
            $sender->sendMessage(TextFormat::BOLD . TextFormat::BLUE . ucfirst($type) . ":");
            if($type === "warnings") {
                foreach($entries as $index => $warning) {
                    $sender->sendMessage(TextFormat::WHITE . ($index + 1) . ". " . $warning["date"] . " - Par " . $warning["staff"] . ": " . $warning["reason"]);
                }
            } else {
                $sender->sendMessage(TextFormat::WHITE . "Date: " . $entries["date"]);
                $sender->sendMessage(TextFormat::WHITE . "Staff: " . $entries["staff"]);
                $sender->sendMessage(TextFormat::WHITE . "Raison: " . $entries["reason"]);
                if($entries["type"] === "temporary") {
                    $sender->sendMessage(TextFormat::WHITE . "Durée: " . $entries["duration"]);
                }
            }
        }
        return true;
    }

    private function checkCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.check")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /check <joueur>");
            return true;
        }
        $playerName = $args[0];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player === null) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur n'est pas connecté.");
            return true;
        }
        $sender->sendMessage(TextFormat::GOLD . "---- Informations de " . $playerName . " ----");
        $sender->sendMessage(TextFormat::WHITE . "IP: " . $player->getNetworkSession()->getIp());
        $sender->sendMessage(TextFormat::WHITE . "Ping: " . $player->getNetworkSession()->getPing() . "ms");
        $sender->sendMessage(TextFormat::WHITE . "Position: " . round($player->getPosition()->getX()) . ", " . round($player->getPosition()->getY()) . ", " . round($player->getPosition()->getZ()));
        if(isset($this->warnings[$playerName])) {
            $sender->sendMessage(TextFormat::WHITE . "Avertissements: " . count($this->warnings[$playerName]));
        } else {
            $sender->sendMessage(TextFormat::WHITE . "Avertissements: 0");
        }
        if(isset($this->mutedPlayers[$playerName])) {
            $sender->sendMessage(TextFormat::RED . "Statut: Mute");
        } else {
            $sender->sendMessage(TextFormat::GREEN . "Statut: Non mute");
        }
        return true;
    }

    private function invseeCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.invsee")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(!($sender instanceof Player)) {
            $sender->sendMessage(TextFormat::RED . "Cette commande doit être utilisée en jeu.");
            return true;
        }
        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /invsee <joueur>");
            return true;
        }
        $playerName = $args[0];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player === null) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur n'est pas connecté.");
            return true;
        }
        $sender->sendMessage(TextFormat::GREEN . "Ouverture de l'inventaire de " . $playerName);
        $sender->setCurrentWindow($player->getInventory());
        return true;
    }

    private function freezeCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.freeze")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /freeze <joueur>");
            return true;
        }
        $playerName = $args[0];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player === null) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur n'est pas connecté.");
            return true;
        }
        if(isset($this->frozenPlayers[$playerName])) {
            unset($this->frozenPlayers[$playerName]);
            $player->sendMessage(TextFormat::GREEN . "Vous avez été unfreeze par " . $sender->getName());
            $sender->sendMessage(TextFormat::GREEN . $playerName . " a été unfreeze.");
        } else {
            $this->frozenPlayers[$playerName] = true;
            $player->sendMessage(TextFormat::RED . "Vous avez été freeze par " . $sender->getName() . ". Vous ne pouvez plus bouger.");
            $sender->sendMessage(TextFormat::GREEN . $playerName . " a été freeze.");
        }
        return true;
    }

    private function screenshareCommand(CommandSender $sender, array $args): bool {
        if(!$sender->hasPermission("moderation.screenshare")) {
            $sender->sendMessage(TextFormat::RED . "Vous n'avez pas la permission d'utiliser cette commande.");
            return true;
        }
        if(count($args) < 1) {
            $sender->sendMessage(TextFormat::RED . "Usage: /ss <joueur>");
            return true;
        }
        $playerName = $args[0];
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player === null) {
            $sender->sendMessage(TextFormat::RED . "Ce joueur n'est pas connecté.");
            return true;
        }
        $this->freezeCommand($sender, $args);
        $sender->sendMessage(TextFormat::GOLD . "Début du ScreenShare avec " . $playerName);
        $sender->sendMessage(TextFormat::GOLD . "Commandes disponibles:");
        $sender->sendMessage(TextFormat::WHITE . "/ss invsee - Voir son inventaire");
        $sender->sendMessage(TextFormat::WHITE . "/ss end - Terminer le ScreenShare");
        return true;
    }

    private function reportCommand(CommandSender $sender, array $args): bool {
        if(!($sender instanceof Player)) {
            $sender->sendMessage(TextFormat::RED . "Cette commande doit être utilisée en jeu.");
            return true;
        }
        if(count($args) < 2) {
            $sender->sendMessage(TextFormat::RED . "Usage: /report <joueur> <raison>");
            return true;
        }
        $playerName = $args[0];
        $reason = implode(" ", array_slice($args, 1));
        $reporter = $sender->getName();
        $this->reports[] = [
            "player" => $playerName,
            "reporter" => $reporter,
            "reason" => $reason,
            "date" => date("Y-m-d H:i:s"),
            "status" => "pending"
        ];
        $sender->sendMessage(TextFormat::GREEN . "Votre report contre " . $playerName . " a été envoyé avec succès.");
        foreach($this->getServer()->getOnlinePlayers() as $staff) {
            if($staff->hasPermission("moderation.report.view")) {
                $staff->sendMessage(TextFormat::RED . "[REPORT] " . $reporter . " a report " . $playerName . " pour: " . $reason);
            }
        }
        $this->saveData();
        return true;
    }

    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        if(isset($this->mutedPlayers[$playerName])) {
            $muteData = $this->mutedPlayers[$playerName];
            if($muteData["type"] === "temporary" && time() > $muteData["expires"]) {
                unset($this->mutedPlayers[$playerName]);
                $this->saveData();
            } else {
                $player->sendMessage(TextFormat::RED . "Vous êtes mute! Raison: " . $muteData["reason"]);
                $event->cancel();
            }
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        if(isset($this->frozenPlayers[$playerName])) {
            $from = $event->getFrom();
            $to = $event->getTo();
            if($from->getX() !== $to->getX() || $from->getZ() !== $to->getZ()) {
                $event->cancel();
                $player->sendMessage(TextFormat::RED . "Vous êtes freeze et ne pouvez pas bouger!");
            }
        }
    }

    public function onDisable(): void {
        $this->saveData();
    }
}