<?php

declare(strict_types=1);

namespace North\Nickname\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\utils\Config;
use libpmquery\PMQuery;
use libpmquery\PmQueryException;

class Main extends PluginBase implements Listener {

    private $nicks = [];
    private $nickChanges = [];
    private $config;
    private $bannedWords = [];
    private $lastChange = [];

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->bannedWords = $this->config->get("banned-words", []);
        $this->getLogger()->info("NickSystem enabled!");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch(strtolower($command->getName())) {
            case "nickname":
                if(!$sender instanceof Player) {
                    $sender->sendMessage(TextFormat::RED . "This command can only be used in-game!");
                    return true;
                }
                if(!isset($args[0])) {
                    $sender->sendMessage(TextFormat::RED . "Usage: /nickname <name>");
                    return true;
                }
                $this->setNickname($sender, implode(" ", $args));
                return true;

            case "unnick":
                if(!$sender instanceof Player) {
                    $sender->sendMessage(TextFormat::RED . "This command can only be used in-game!");
                    return true;
                }
                $this->removeNickname($sender);
                return true;

            case "nickinfo":
                if(!$sender->hasPermission("nicksystem.admin")) {
                    $sender->sendMessage(TextFormat::RED . "You don't have permission to use this command!");
                    return true;
                }
                if(!isset($args[0])) {
                    $sender->sendMessage(TextFormat::RED . "Usage: /nickinfo <player>");
                    return true;
                }
                $this->getNickInfo($sender, $args[0]);
                return true;

            case "randomnick":
                if(!$sender instanceof Player) {
                    $sender->sendMessage(TextFormat::RED . "This command can only be used in-game!");
                    return true;
                }
                $this->setRandomNickname($sender);
                return true;

            case "forceunnick":
                if(!$sender->hasPermission("nicksystem.admin")) {
                    $sender->sendMessage(TextFormat::RED . "You don't have permission to use this command!");
                    return true;
                }
                if(!isset($args[0])) {
                    $sender->sendMessage(TextFormat::RED . "Usage: /forceunnick <player>");
                    return true;
                }
                $this->forceUnnick($sender, $args[0]);
                return true;
        }
        return false;
    }

    private function setNickname(Player $player, string $nick): void {
        $uuid = $player->getUniqueId()->toString();
        $currentTime = time();

        if(isset($this->lastChange[$uuid]) {
            $timeDiff = $currentTime - $this->lastChange[$uuid];
            $cooldown = $this->config->get("change-cooldown", 3600);
            if($timeDiff < $cooldown) {
                $remaining = $cooldown - $timeDiff;
                $player->sendMessage(TextFormat::RED . "You must wait " . ceil($remaining/60) . " minutes before changing your nickname again!");
                return;
            }
        }

        if($this->isBannedWord($nick)) {
            $player->sendMessage(TextFormat::RED . "This nickname contains banned words!");
            return;
        }

        if($this->isRealPlayer($nick)) {
            $player->sendMessage(TextFormat::RED . "You can't use a real player's name as your nickname!");
            return;
        }

        $maxLength = $this->config->get("max-length", 16);
        if(strlen($nick) > $maxLength) {
            $player->sendMessage(TextFormat::RED . "Nickname is too long! Max " . $maxLength . " characters.");
            return;
        }

        $this->nicks[$uuid] = $nick;
        $this->nickChanges[$uuid] = ($this->nickChanges[$uuid] ?? 0) + 1;
        $this->lastChange[$uuid] = $currentTime;

        $player->setDisplayName($nick);
        $player->sendMessage(TextFormat::GREEN . "Your nickname has been set to: " . $nick);

        $this->updateTabList($player);
        $this->logNickChange($player->getName(), $nick);
    }

    private function removeNickname(Player $player): void {
        $uuid = $player->getUniqueId()->toString();
        if(!isset($this->nicks[$uuid])) {
            $player->sendMessage(TextFormat::RED . "You don't have a nickname set!");
            return;
        }

        $oldNick = $this->nicks[$uuid];
        unset($this->nicks[$uuid]);
        $player->setDisplayName($player->getName());
        $player->sendMessage(TextFormat::GREEN . "Your nickname has been removed!");
        $this->updateTabList($player);
        $this->logNickChange($player->getName(), $player->getName(), true);
    }

    private function getNickInfo(CommandSender $sender, string $playerName): void {
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player === null) {
            $sender->sendMessage(TextFormat::RED . "Player not found!");
            return;
        }

        $uuid = $player->getUniqueId()->toString();
        $realName = $player->getName();
        $nick = $this->nicks[$uuid] ?? $realName;

        $sender->sendMessage(TextFormat::GOLD . "Nickname Info for " . $realName);
        $sender->sendMessage(TextFormat::YELLOW . "Real Name: " . $realName);
        $sender->sendMessage(TextFormat::YELLOW . "Current Nick: " . $nick);
        $sender->sendMessage(TextFormat::YELLOW . "Total Changes: " . ($this->nickChanges[$uuid] ?? 0));
    }

    private function setRandomNickname(Player $player): void {
        $adjectives = ["Cool", "Epic", "Mystic", "Shadow", "Dark", "Light", "Super", "Mega", "Ultra"];
        $nouns = ["Player", "Warrior", "Mage", "Ninja", "Hunter", "Wizard", "Knight", "Assassin"];
        $colors = ["§a", "§b", "§c", "§d", "§e", "§f", "§1", "§2", "§3", "§4", "§5", "§6", "§7", "§8", "§9"];

        $nick = $colors[array_rand($colors)] . $adjectives[array_rand($adjectives)] . $nouns[array_rand($nouns)] . mt_rand(1, 99);
        $this->setNickname($player, $nick);
    }

    private function forceUnnick(CommandSender $sender, string $playerName): void {
        $player = $this->getServer()->getPlayerExact($playerName);
        if($player === null) {
            $sender->sendMessage(TextFormat::RED . "Player not found!");
            return;
        }

        $uuid = $player->getUniqueId()->toString();
        if(!isset($this->nicks[$uuid])) {
            $sender->sendMessage(TextFormat::RED . "This player doesn't have a nickname set!");
            return;
        }

        $oldNick = $this->nicks[$uuid];
        unset($this->nicks[$uuid]);
        $player->setDisplayName($player->getName());
        $sender->sendMessage(TextFormat::GREEN . "Reset " . $player->getName() . "'s nickname from " . $oldNick);
        $player->sendMessage(TextFormat::RED . "Your nickname has been reset by staff!");
        $this->updateTabList($player);
        $this->logForceUnnick($sender->getName(), $player->getName(), $oldNick);
    }

    private function isBannedWord(string $nick): bool {
        $nickLower = strtolower($nick);
        foreach($this->bannedWords as $word) {
            if(strpos($nickLower, strtolower($word)) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isRealPlayer(string $nick): bool {
        foreach($this->getServer()->getOnlinePlayers() as $player) {
            if(strtolower($player->getName()) === strtolower($nick)) {
                return true;
            }
        }
        return $this->getServer()->hasOfflinePlayerData($nick);
    }

    private function updateTabList(Player $player): void {
        $nick = $this->nicks[$player->getUniqueId()->toString()] ?? $player->getName();
        $player->setNameTag($nick);
        foreach($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            $onlinePlayer->getNetworkSession()->syncPlayerList([$player]);
        }
    }

    private function logNickChange(string $playerName, string $nick, bool $removed = false): void {
        $action = $removed ? "removed" : "changed to " . $nick;
        $log = date("[Y-m-d H:i:s]") . " " . $playerName . " " . $action . " nickname";
        file_put_contents($this->getDataFolder() . "nick_logs.txt", $log . PHP_EOL, FILE_APPEND);
    }

    private function logForceUnnick(string $staffName, string $playerName, string $oldNick): void {
        $log = date("[Y-m-d H:i:s]") . " " . $staffName . " reset " . $playerName . "'s nickname from " . $oldNick;
        file_put_contents($this->getDataFolder() . "nick_logs.txt", $log . PHP_EOL, FILE_APPEND);
    }

    public function onPlayerChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $uuid = $player->getUniqueId()->toString();

        if(isset($this->nicks[$uuid])) {
            $format = $event->getFormat();
            $format = str_replace($player->getDisplayName(), $this->nicks[$uuid], $format);
            $event->setFormat($format);
        }
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $uuid = $player->getUniqueId()->toString();

        if(isset($this->nicks[$uuid])) {
            $player->setDisplayName($this->nicks[$uuid]);
            $this->updateTabList($player);
        }
    }
}