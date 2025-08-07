<?php

declare(strict_types=1);

namespace North\Friends\Main;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\Config;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ToastRequestPacket;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener {

    private $friendRequests;
    private $friendsData;
    private $settings;
    private $messages;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveResource("config.yml");
        $this->saveResource("messages.yml");

        $this->friendRequests = new Config($this->getDataFolder() . "friendRequests.yml", Config::YAML);
        $this->friendsData = new Config($this->getDataFolder() . "friendsData.yml", Config::YAML);
        $this->settings = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->messages = new Config($this->getDataFolder() . "messages.yml", Config::YAML);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if(!$sender instanceof Player) {
            $sender->sendMessage($this->getMessage("console-cant-use"));
            return false;
        }

        switch(strtolower($command->getName())) {
            case "friend":
                if(count($args) < 1) {
                    $this->sendFriendGUI($sender);
                    return true;
                }

                $subCommand = strtolower($args[0]);
                switch($subCommand) {
                    case "add":
                        if(!isset($args[1])) {
                            $sender->sendMessage($this->getMessage("friend-add-usage"));
                            return false;
                        }
                        $target = $this->getServer()->getPlayerExact($args[1]);
                        if($target === null) {
                            $sender->sendMessage($this->getMessage("player-not-online"));
                            return false;
                        }
                        $this->sendFriendRequest($sender, $target);
                        return true;

                    case "accept":
                        if(!isset($args[1])) {
                            $sender->sendMessage($this->getMessage("friend-accept-usage"));
                            return false;
                        }
                        $this->acceptFriendRequest($sender, $args[1]);
                        return true;

                    case "deny":
                        if(!isset($args[1])) {
                            $sender->sendMessage($this->getMessage("friend-deny-usage"));
                            return false;
                        }
                        $this->denyFriendRequest($sender, $args[1]);
                        return true;

                    case "list":
                        $this->showFriendList($sender);
                        return true;

                    case "remove":
                        if(!isset($args[1])) {
                            $sender->sendMessage($this->getMessage("friend-remove-usage"));
                            return false;
                        }
                        $this->removeFriend($sender, $args[1]);
                        return true;

                    case "requests":
                        $this->showFriendRequests($sender);
                        return true;

                    case "tp":
                        if(!isset($args[1])) {
                            $sender->sendMessage($this->getMessage("friend-tp-usage"));
                            return false;
                        }
                        $this->teleportToFriend($sender, $args[1]);
                        return true;

                    case "block":
                        if(!isset($args[1])) {
                            $sender->sendMessage($this->getMessage("friend-block-usage"));
                            return false;
                        }
                        $this->blockPlayer($sender, $args[1]);
                        return true;

                    case "gift":
                        if(!isset($args[1])) {
                            $sender->sendMessage($this->getMessage("friend-gift-usage"));
                            return false;
                        }
                        $this->sendGift($sender, $args[1]);
                        return true;

                    default:
                        $sender->sendMessage($this->getMessage("friend-usage"));
                        return false;
                }

            case "msg":
                if(count($args) < 2) {
                    $sender->sendMessage($this->getMessage("msg-usage"));
                    return false;
                }
                $target = $args[0];
                unset($args[0]);
                $message = implode(" ", $args);
                $this->sendPrivateMessage($sender, $target, $message);
                return true;
        }
        return false;
    }

    private function sendFriendRequest(Player $sender, Player $target): void {
        $senderName = strtolower($sender->getName());
        $targetName = strtolower($target->getName());

        if($this->isBlocked($targetName, $senderName)) {
            $sender->sendMessage($this->getMessage("player-blocked-you"));
            return;
        }

        if($this->areFriends($senderName, $targetName)) {
            $sender->sendMessage($this->getMessage("already-friends"));
            return;
        }

        $requests = $this->friendRequests->get($targetName, []);
        if(in_array($senderName, $requests)) {
            $sender->sendMessage($this->getMessage("request-already-sent"));
            return;
        }

        $requests[] = $senderName;
        $this->friendRequests->set($targetName, $requests);
        $this->friendRequests->save();

        $sender->sendMessage(str_replace("{player}", $target->getName(), $this->getMessage("request-sent")));
        $target->sendMessage(str_replace("{player}", $sender->getName(), $this->getMessage("request-received")));

        $this->sendToast($target, $this->getMessage("toast-title"), str_replace("{player}", $sender->getName(), $this->getMessage("toast-new-request")));
    }

    private function acceptFriendRequest(Player $sender, string $requesterName): void {
        $senderName = strtolower($sender->getName());
        $requesterName = strtolower($requesterName);

        $requests = $this->friendRequests->get($senderName, []);
        if(!in_array($requesterName, $requests)) {
            $sender->sendMessage($this->getMessage("no-request-from-player"));
            return;
        }

        unset($requests[array_search($requesterName, $requests)]);
        $this->friendRequests->set($senderName, $requests);
        $this->friendRequests->save();

        $this->addFriend($senderName, $requesterName);

        $sender->sendMessage(str_replace("{player}", $requesterName, $this->getMessage("request-accepted")));
        $requester = $this->getServer()->getPlayerExact($requesterName);
        if($requester !== null) {
            $requester->sendMessage(str_replace("{player}", $sender->getName(), $this->getMessage("request-accepted-other")));
            $this->sendToast($requester, $this->getMessage("toast-title"), str_replace("{player}", $sender->getName(), $this->getMessage("toast-friend-added")));
        }
    }

    private function denyFriendRequest(Player $sender, string $requesterName): void {
        $senderName = strtolower($sender->getName());
        $requesterName = strtolower($requesterName);

        $requests = $this->friendRequests->get($senderName, []);
        if(!in_array($requesterName, $requests)) {
            $sender->sendMessage($this->getMessage("no-request-from-player"));
            return;
        }

        unset($requests[array_search($requesterName, $requests)]);
        $this->friendRequests->set($senderName, $requests);
        $this->friendRequests->save();

        $sender->sendMessage(str_replace("{player}", $requesterName, $this->getMessage("request-denied")));
        $requester = $this->getServer()->getPlayerExact($requesterName);
        if($requester !== null) {
            $requester->sendMessage(str_replace("{player}", $sender->getName(), $this->getMessage("request-denied-other")));
        }
    }

    private function showFriendList(Player $player): void {
        $playerName = strtolower($player->getName());
        $friends = $this->friendsData->getNested("$playerName.friends", []);

        $onlineFriends = [];
        $offlineFriends = [];

        foreach($friends as $friendName => $data) {
            if($this->getServer()->getPlayerExact($friendName) !== null) {
                $onlineFriends[] = $friendName;
            } else {
                $offlineFriends[] = $friendName;
            }
        }

        $message = $this->getMessage("friend-list-header") . "\n";
        $message .= $this->getMessage("friend-list-online") . implode(", ", $onlineFriends) . "\n";
        $message .= $this->getMessage("friend-list-offline") . implode(", ", $offlineFriends);

        $player->sendMessage($message);
    }

    private function removeFriend(Player $player, string $friendName): void {
        $playerName = strtolower($player->getName());
        $friendName = strtolower($friendName);

        if(!$this->areFriends($playerName, $friendName)) {
            $player->sendMessage($this->getMessage("not-friends"));
            return;
        }

        $playerFriends = $this->friendsData->getNested("$playerName.friends", []);
        unset($playerFriends[$friendName]);
        $this->friendsData->setNested("$playerName.friends", $playerFriends);

        $friendFriends = $this->friendsData->getNested("$friendName.friends", []);
        unset($friendFriends[$playerName]);
        $this->friendsData->setNested("$friendName.friends", $friendFriends);

        $this->friendsData->save();

        $player->sendMessage(str_replace("{player}", $friendName, $this->getMessage("friend-removed")));
        $friend = $this->getServer()->getPlayerExact($friendName);
        if($friend !== null) {
            $friend->sendMessage(str_replace("{player}", $player->getName(), $this->getMessage("friend-removed-other")));
        }
    }

    private function showFriendRequests(Player $player): void {
        $playerName = strtolower($player->getName());
        $requests = $this->friendRequests->get($playerName, []);

        if(empty($requests)) {
            $player->sendMessage($this->getMessage("no-pending-requests"));
            return;
        }

        $message = $this->getMessage("pending-requests-header") . "\n";
        foreach($requests as $request) {
            $message .= "- " . $request . "\n";
        }
        $message .= $this->getMessage("pending-requests-footer");

        $player->sendMessage($message);
    }

    private function teleportToFriend(Player $player, string $friendName): void {
        $playerName = strtolower($player->getName());
        $friendName = strtolower($friendName);

        if(!$this->areFriends($playerName, $friendName)) {
            $player->sendMessage($this->getMessage("not-friends"));
            return;
        }

        $friend = $this->getServer()->getPlayerExact($friendName);
        if($friend === null) {
            $player->sendMessage($this->getMessage("player-not-online"));
            return;
        }

        $settings = $this->friendsData->getNested("$friendName.settings", []);
        if(isset($settings["allow-teleport"]) && !$settings["allow-teleport"]) {
            $player->sendMessage($this->getMessage("teleport-disabled"));
            return;
        }

        $player->teleport($friend->getPosition());
        $player->sendMessage(str_replace("{player}", $friendName, $this->getMessage("teleported-to-friend")));
        $friend->sendMessage(str_replace("{player}", $player->getName(), $this->getMessage("friend-teleported-to-you")));
    }

    private function blockPlayer(Player $player, string $targetName): void {
        $playerName = strtolower($player->getName());
        $targetName = strtolower($targetName);

        if($this->isBlocked($playerName, $targetName)) {
            $player->sendMessage($this->getMessage("already-blocked"));
            return;
        }

        $blocked = $this->friendsData->getNested("$playerName.blocked", []);
        $blocked[] = $targetName;
        $this->friendsData->setNested("$playerName.blocked", $blocked);
        $this->friendsData->save();

        $player->sendMessage(str_replace("{player}", $targetName, $this->getMessage("player-blocked")));

        $this->removeFriend($player, $targetName);
    }

    private function sendGift(Player $player, string $friendName): void {
        $playerName = strtolower($player->getName());
        $friendName = strtolower($friendName);

        if(!$this->areFriends($playerName, $friendName)) {
            $player->sendMessage($this->getMessage("not-friends"));
            return;
        }

        $friend = $this->getServer()->getPlayerExact($friendName);
        if($friend === null) {
            $player->sendMessage($this->getMessage("player-not-online"));
            return;
        }

        $settings = $this->friendsData->getNested("$friendName.settings", []);
        if(isset($settings["allow-gifts"]) && !$settings["allow-gifts"]) {
            $player->sendMessage($this->getMessage("gifts-disabled"));
            return;
        }

        $item = $player->getInventory()->getItemInHand();
        if($item->isNull()) {
            $player->sendMessage($this->getMessage("empty-hand"));
            return;
        }

        $player->getInventory()->removeItem($item);
        $friend->getInventory()->addItem($item);

        $player->sendMessage(str_replace(["{player}", "{item}"], [$friendName, $item->getName()], $this->getMessage("gift-sent")));
        $friend->sendMessage(str_replace(["{player}", "{item}"], [$player->getName(), $item->getName()], $this->getMessage("gift-received")));
        $this->sendToast($friend, $this->getMessage("toast-title"), str_replace("{player}", $player->getName(), $this->getMessage("toast-gift-received")));
    }

    private function sendPrivateMessage(Player $sender, string $targetName, string $message): void {
        $senderName = strtolower($sender->getName());
        $targetName = strtolower($targetName);

        if(!$this->areFriends($senderName, $targetName)) {
            $sender->sendMessage($this->getMessage("not-friends"));
            return;
        }

        $target = $this->getServer()->getPlayerExact($targetName);
        if($target === null) {
            $sender->sendMessage($this->getMessage("player-not-online"));
            return;
        }

        $settings = $this->friendsData->getNested("$targetName.settings", []);
        if(isset($settings["allow-messages"]) && !$settings["allow-messages"]) {
            $sender->sendMessage($this->getMessage("messages-disabled"));
            return;
        }

        $sender->sendMessage(str_replace(["{player}", "{message}"], ["To " . $targetName, $message], $this->getMessage("msg-format-sender")));
        $target->sendMessage(str_replace(["{player}", "{message}"], ["From " . $sender->getName(), $message], $this->getMessage("msg-format-receiver")));
    }

    private function addFriend(string $player1, string $player2): void {
        $friends1 = $this->friendsData->getNested("$player1.friends", []);
        $friends1[$player2] = ["since" => time(), "points" => 0];
        $this->friendsData->setNested("$player1.friends", $friends1);

        $friends2 = $this->friendsData->getNested("$player2.friends", []);
        $friends2[$player1] = ["since" => time(), "points" => 0];
        $this->friendsData->setNested("$player2.friends", $friends2);

        $this->friendsData->save();
    }

    private function areFriends(string $player1, string $player2): bool {
        $friends1 = $this->friendsData->getNested("$player1.friends", []);
        return isset($friends1[$player2]);
    }

    private function isBlocked(string $player, string $target): bool {
        $blocked = $this->friendsData->getNested("$player.blocked", []);
        return in_array($target, $blocked);
    }

    private function sendToast(Player $player, string $title, string $message): void {
        $pk = new ToastRequestPacket();
        $pk->title = $title;
        $pk->message = $message;
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    private function sendFriendGUI(Player $player): void {
        $form = new SimpleForm(function(Player $player, $data) {
            if($data === null) return;

            switch($data) {
                case 0:
                    $player->chat("/friend list");
                    break;
                case 1:
                    $player->chat("/friend requests");
                    break;
                case 2:
                    $this->sendAddFriendGUI($player);
                    break;
                case 3:
                    $this->sendFriendSettingsGUI($player);
                    break;
            }
        });

        $form->setTitle($this->getMessage("gui-main-title"));
        $form->setContent($this->getMessage("gui-main-content"));
        $form->addButton($this->getMessage("gui-main-button-list"));
        $form->addButton($this->getMessage("gui-main-button-requests"));
        $form->addButton($this->getMessage("gui-main-button-add"));
        $form->addButton($this->getMessage("gui-main-button-settings"));
        $player->sendForm($form);
    }

    private function sendAddFriendGUI(Player $player): void {
        $onlinePlayers = [];
        foreach($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            if($onlinePlayer !== $player && !$this->areFriends(strtolower($player->getName()), strtolower($onlinePlayer->getName()))) {
                $onlinePlayers[] = $onlinePlayer->getName();
            }
        }

        if(empty($onlinePlayers)) {
            $player->sendMessage($this->getMessage("no-players-to-add"));
            return;
        }

        $form = new SimpleForm(function(Player $player, $data) use ($onlinePlayers) {
            if($data === null) return;
            $player->chat("/friend add " . $onlinePlayers[$data]);
        });

        $form->setTitle($this->getMessage("gui-add-title"));
        $form->setContent($this->getMessage("gui-add-content"));

        foreach($onlinePlayers as $onlinePlayer) {
            $form->addButton($onlinePlayer);
        }

        $player->sendForm($form);
    }

    private function sendFriendSettingsGUI(Player $player): void {
        $playerName = strtolower($player->getName());
        $settings = $this->friendsData->getNested("$playerName.settings", [
            "allow-teleport" => true,
            "allow-gifts" => true,
            "allow-messages" => true,
            "auto-deny-requests" => false
        ]);

        $form = new CustomForm(function(Player $player, $data) use ($settings) {
            if($data === null) return;

            $playerName = strtolower($player->getName());
            $newSettings = [
                "allow-teleport" => (bool)$data[1],
                "allow-gifts" => (bool)$data[2],
                "allow-messages" => (bool)$data[3],
                "auto-deny-requests" => (bool)$data[4]
            ];

            $this->friendsData->setNested("$playerName.settings", $newSettings);
            $this->friendsData->save();
            $player->sendMessage($this->getMessage("settings-updated")));
        });

        $form->setTitle($this->getMessage("gui-settings-title"));
        $form->addLabel($this->getMessage("gui-settings-content"));
        $form->addToggle($this->getMessage("gui-settings-allow-tp"), $settings["allow-teleport"]);
        $form->addToggle($this->getMessage("gui-settings-allow-gifts"), $settings["allow-gifts"]);
        $form->addToggle($this->getMessage("gui-settings-allow-msg"), $settings["allow-messages"]);
        $form->addToggle($this->getMessage("gui-settings-auto-deny"), $settings["auto-deny-requests"]);
        $player->sendForm($form);
    }

    private function getMessage(string $key): string {
        return $this->messages->get($key, $key);
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $playerName = strtolower($player->getName());

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $playerName): void {
            $friends = $this->friendsData->getNested("$playerName.friends", []);
            foreach($friends as $friendName => $data) {
                $friend = $this->getServer()->getPlayerExact($friendName);
                if($friend !== null) {
                    $friend->sendMessage(str_replace("{player}", $player->getName(), $this->getMessage("friend-joined")));
                    $this->sendToast($friend, $this->getMessage("toast-title"), str_replace("{player}", $player->getName(), $this->getMessage("toast-friend-online")));
                }
            }
        }), 20 * 3);
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = strtolower($player->getName());

        $friends = $this->friendsData->getNested("$playerName.friends", []);
        foreach($friends as $friendName => $data) {
            $friend = $this->getServer()->getPlayerExact($friendName);
            if($friend !== null) {
                $friend->sendMessage(str_replace("{player}", $player->getName(), $this->getMessage("friend-left")));
            }
        }
    }
}