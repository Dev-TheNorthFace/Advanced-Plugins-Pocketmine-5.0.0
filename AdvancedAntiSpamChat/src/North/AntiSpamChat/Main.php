<?php

declare(strict_types=1);

namespace North\AntiSpamChat\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use function mb_strlen;
use function mb_strtoupper;
use function preg_match_all;
use function preg_replace;
use function round;
use function similar_text;
use function str_replace;
use function strtolower;
use function time;

class Main extends PluginBase implements Listener {

    private Config $config;
    private array $lastMessages = [];
    private array $spamCounts = [];
    private array $mutedPlayers = [];

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "antispamchat" => [
                "min_message_interval" => 1.5,
                "max_caps_percentage" => 70,
                "max_special_chars_percentage" => 30,
                "max_message_length" => 150,
                "block_repeated_messages" => true,
                "similarity_threshold" => 80,
                "blocked_words" => [
                    "aternos",
                    "discord.gg/",
                    "kill yourself"
                ],
                "punishments" => [
                    "warn_on_first" => true,
                    "mute_on_second" => true,
                    "mute_duration" => 300,
                    "ban_on_third" => false
                ]
            ]
        ]);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getCommandMap()->register("spamstats", new class($this) extends \pocketmine\command\Command {
            public function __construct(private Main $plugin) {
                parent::__construct("spamstats", "Voir les stats anti-spam d'un joueur");
                $this->setPermission("antispam.stats");
            }
            public function execute(\pocketmine\command\CommandSender $sender, string $commandLabel, array $args): void {
                if (isset($args[0])) {
                    $player = $this->plugin->getServer()->getPlayerExact($args[0]);
                    if ($player !== null) {
                        $sender->sendMessage(TextFormat::GREEN . "Stats anti-spam pour " . $player->getName());
                    } else {
                        $sender->sendMessage(TextFormat::RED . "Joueur non trouvé");
                    }
                } else {
                    $sender->sendMessage(TextFormat::RED . "Usage: /spamstats <joueur>");
                }
            }
        });
    }

    public function onChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $message = $event->getMessage();
        $playerName = $player->getName();
        $currentTime = time();
        $config = $this->config->get("antispamchat");

        if (isset($this->mutedPlayers[$playerName]) && $this->mutedPlayers[$playerName] > $currentTime) {
            $player->sendMessage(TextFormat::RED . "Vous êtes muté pour " . ($this->mutedPlayers[$playerName] - $currentTime) . " secondes");
            $event->cancel();
            return;
        }

        if (isset($this->lastMessages[$playerName]["time"]) && ($currentTime - $this->lastMessages[$playerName]["time"]) < $config["min_message_interval"]) {
            $this->flagSpam($player, "SPAM_TROP_RAPIDE");
            $event->cancel();
            return;
        }

        if (mb_strlen($message) > $config["max_message_length"]) {
            $this->flagSpam($player, "MESSAGE_TROP_LONG");
            $event->cancel();
            return;
        }

        $upperCount = mb_strlen(preg_replace('/[^A-ZÀÂÄÇÉÈÊËÎÏÔÖÙÛÜÝŸ]/u', '', $message));
        $upperPercentage = ($upperCount / mb_strlen($message)) * 100;
        if ($upperPercentage > $config["max_caps_percentage"]) {
            $this->flagSpam($player, "TROP_DE_MAJUSCULES");
            $event->cancel();
            return;
        }

        $specialCharsCount = preg_match_all('/[^\p{L}\p{N}\s]/u', $message);
        $specialCharsPercentage = ($specialCharsCount / mb_strlen($message)) * 100;
        if ($specialCharsPercentage > $config["max_special_chars_percentage"]) {
            $this->flagSpam($player, "TROP_DE_CARACTERES_SPECIAUX");
            $event->cancel();
            return;
        }

        foreach ($config["blocked_words"] as $blockedWord) {
            if (strpos(strtolower($message), strtolower($blockedWord)) !== false) {
                $this->flagSpam($player, "MOT_INTERDIT: " . $blockedWord);
                $event->cancel();
                return;
            }
        }

        if ($config["block_repeated_messages"] && isset($this->lastMessages[$playerName]["messages"])) {
            foreach ($this->lastMessages[$playerName]["messages"] as $lastMessage) {
                similar_text($message, $lastMessage, $similarity);
                if ($similarity >= $config["similarity_threshold"]) {
                    $this->flagSpam($player, "MESSAGE_REPETITIF");
                    $event->cancel();
                    return;
                }
            }
        }

        $this->lastMessages[$playerName] = [
            "time" => $currentTime,
            "messages" => [
                $message,
                ...($this->lastMessages[$playerName]["messages"] ?? [])
            ]
        ];
        if (count($this->lastMessages[$playerName]["messages"]) > 3) {
            array_pop($this->lastMessages[$playerName]["messages"]);
        }
    }

    private function flagSpam(\pocketmine\player\Player $player, string $reason): void {
        $playerName = $player->getName();
        $this->spamCounts[$playerName] = ($this->spamCounts[$playerName] ?? 0) + 1;
        $punishments = $this->config->get("antispamchat")["punishments"];

        switch ($this->spamCounts[$playerName]) {
            case 1:
                if ($punishments["warn_on_first"]) {
                    $player->sendMessage(TextFormat::RED . "Avertissement: Ne spammez pas le chat! (Raison: $reason)");
                }
                break;
            case 2:
                if ($punishments["mute_on_second"]) {
                    $muteDuration = $punishments["mute_duration"];
                    $this->mutedPlayers[$playerName] = time() + $muteDuration;
                    $player->sendMessage(TextFormat::RED . "Vous avez été muté pendant $muteDuration secondes pour spam (Raison: $reason)");
                }
                break;
            case 3:
                if ($punishments["ban_on_third"]) {
                    $player->kick(TextFormat::RED . "Vous avez été banni temporairement pour spam répété");
                }
                break;
            default:
                $player->kick(TextFormat::RED . "Spam détecté (Raison: $reason)");
                break;
        }
    }
}