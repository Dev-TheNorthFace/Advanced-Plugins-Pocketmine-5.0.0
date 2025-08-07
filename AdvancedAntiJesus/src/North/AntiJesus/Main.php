<?php

declare(strict_types=1);

namespace North\AntiJesus\Main;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\block\Block;
use pocketmine\block\Liquid;
use pocketmine\math\Vector3;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private array $jesusData = [];
    private Config $config;

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, [
            "antijesus" => [
                "y_variation_required" => 0.02,
                "max_ticks_on_liquid" => 10,
                "freeze_on_detected" => true,
                "exempt_with_effects" => true,
                "check_speed_on_liquid" => true,
                "allow_boats" => true
            ]
        ]);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onPlayerMove(PlayerMoveEvent $event) : void {
        $player = $event->getPlayer();
        if($player->isCreative() || $player->isSpectator()) return;

        $from = $event->getFrom();
        $to = $event->getTo();
        $position = $player->getPosition();
        $blockBelow = $player->getWorld()->getBlock($position->subtract(0, 0.1, 0));

        if(!$blockBelow instanceof Liquid) {
            unset($this->jesusData[$player->getName()]);
            return;
        }

        if(!isset($this->jesusData[$player->getName()])) {
            $this->jesusData[$player->getName()] = [
                "ticks" => 0,
                "lastY" => $to->y,
                "variation" => 0
            ];
        }

        $data = $this->jesusData[$player->getName()];
        $yVariation = abs($to->y - $data["lastY"]);

        if($yVariation < $this->config->get("antijesus")["y_variation_required"]) {
            $data["ticks"]++;
            $data["variation"] += $yVariation;
        } else {
            $data["ticks"] = 0;
            $data["variation"] = 0;
        }

        $data["lastY"] = $to->y;
        $this->jesusData[$player->getName()] = $data;

        if($data["ticks"] >= $this->config->get("antijesus")["max_ticks_on_liquid"]) {
            $this->flagJesus($player, $data);
        }
    }

    private function flagJesus(Player $player, array $data) : void {
        $player->sendMessage("§cAnti-Cheat: Suspicious movement detected (Jesus)");

        if($this->config->get("antijesus")["freeze_on_detected"]) {
            $player->setImmobile(true);
            $player->sendTitle("§cCHEAT DETECTED", "§fJesus Hack");
        }

        $this->getLogger()->warning("Jesus cheat detected for {$player->getName()}: Y variation={$data["variation"]} over {$data["ticks"]} ticks");
        unset($this->jesusData[$player->getName()]);
    }
}