<?php
/*
File: 		profile.php
Coder:		Levi Meahan
Created:	02/26/2013
Revised:	08/24/2013 by Levi Meahan
Purpose:	Functions for displaying user profile
Algorithm:	See master_plan.html
*/

/**
 * @throws Exception
 */
function userProfile() {
    global $system;
    global $player;

    // Submenu
    renderProfileSubmenu();

    // Level up/rank up checks
    $exp_needed = $player->expForNextLevel();

    // Level up
    if($player->level < $player->rank->max_level
        && $player->exp >= $exp_needed
        && ($player->level_up || $player->exp > $player->expForNextLevel(5))
    ) {
        if($player->battle_id) {
            require 'templates/level_rank_up/level_up_in_battle.php';
        }
        else {
            require("levelUp.php");
            levelUp();
            $exp_needed = $player->expForNextLevel();
        }
    }
    // Rank up
    else if($player->level >= $player->rank->max_level && $player->exp >= $exp_needed && $player->rank_num < System::SC_MAX_RANK && $player->rank_up) {
        if($player->battle_id > 0 or !$player->in_village) {
            require "templates/level_rank_up/rank_up_in_battle.php";
        }
        else {
            $rankManager = new RankManager($system);
            $rankManager->loadRanks();
            $exam_name = $rankManager->ranks[$player->rank_num + 1]->name . " Exam";
            if($player->exam_stage) {
                $prompt = "Resume $exam_name";
            }
            else {
                $prompt = "Take $exam_name";
            }
            require "templates/level_rank_up/rank_up_prompt.php";
        }
    }

    $page = $_GET['page'] ?? 'profile';
    if($player->rank_num > 1 && $page == 'send_money') {
        sendMoney($system, $player, System::CURRENCY_TYPE_MONEY);
        return;
    }
    else if($player->rank_num > 1 && $page == 'send_ak') {
        sendMoney($system, $player, System::CURRENCY_TYPE_PREMIUM_CREDITS);
        return;
    }

    require 'templates/profile.php';
}

/**
 * @throws Exception
 */
function sendMoney(System $system, User $player, string $currency_type): void {
    if($currency_type == System::CURRENCY_TYPE_MONEY) {
        $label = "Money";
        $current_amount = "&yen;" . $player->getMoney();
        $page = 'send_money';
    }
    else if($currency_type == System::CURRENCY_TYPE_PREMIUM_CREDITS) {
        $label = "Ancient Kunai";
        $current_amount = $player->getPremiumCredits();
        $page = 'send_ak';
    }
    else {
        throw new Exception("Invalid currency type!");
    }

    if(isset($_POST['send_currency'])) {
        $recipient = $system->clean($_POST['recipient']);
        $amount = (int)$_POST['amount'];

        try {
            if(strtolower($recipient) == strtolower($player->user_name)) {
                throw new Exception("You cannot send money/AK to yourself!");
            }
            if($amount <= 0 && !$player->isHeadAdmin()) {
                throw new Exception("Invalid amount!");
            }

            $result = $system->query(
                "SELECT `user_id`, `user_name`, `money`, `premium_credits` 
                        FROM `users` 
                        WHERE `user_name`='$recipient' LIMIT 1"
            );
            if(!$system->db_last_num_rows) {
                throw new Exception("Invalid user!");
            }
            $recipient = $system->db_fetch($result);

            if($currency_type == System::CURRENCY_TYPE_MONEY) {
                if($amount > $player->getMoney()) {
                    throw new Exception("You do not have that much money/AK!");
                }
                $player->subtractMoney($amount, "Sent money to {$recipient['user_name']} (#{$recipient['user_id']})");

                $system->query("UPDATE `users` SET `money`=`money` + $amount WHERE `user_id`='{$recipient['user_id']}' LIMIT 1");
                $system->currencyLog(
                    $recipient['user_id'],
                    $currency_type,
                    $recipient['money'],
                    $recipient['money'] + $amount,
                    $amount,
                    "Received money from $player->user_name (#$player->user_id)"
                );

                $system->log(
                    'money_transfer',
                    'Money Sent',
                    "{$amount} yen - #{$player->user_id} ($player->user_name) to #{$recipient['user_id']}"
                );
                
                $alert_message = $player->user_name . " has sent you &yen;$amount.";
                Inbox::sendAlert($system, Inbox::ALERT_YEN_RECEIVED, $player->user_id, $recipient['user_id'], $alert_message);
                
                $system->message("&yen;{$amount} sent to {$recipient['user_name']}!");
                
            }
            else if($currency_type == System::CURRENCY_TYPE_PREMIUM_CREDITS) {
                if($amount > $player->getPremiumCredits()) {
                    throw new Exception("You do not have that much AK!");
                }
                $player->subtractPremiumCredits($amount, "Sent AK to {$recipient['user_name']} (#{$recipient['user_id']})");

                $system->query("UPDATE `users` SET `premium_credits`=`premium_credits` + $amount WHERE `user_id`='{$recipient['user_id']}' LIMIT 1");
                $system->currencyLog(
                    $recipient['user_id'],
                    $currency_type,
                    $recipient['premium_credits'],
                    $recipient['premium_credits'] + $amount,
                    $amount,
                    "Received AK from $player->user_name (#$player->user_id)"
                );

                $system->log(
                    'premium_credit_transfer',
                    'Premium Credits Sent',
                    "{$amount} AK - #{$player->user_id} ($player->user_name) to #{$recipient['user_id']}"
                );
                
                $alert_message = $player->user_name . " has sent you $amount Ancient Kunai.";
                Inbox::sendAlert($system, Inbox::ALERT_AK_RECEIVED, $player->user_id, $recipient['user_id'], $alert_message);
                
                $system->message("{$amount} AK sent to {$recipient['user_name']}!");
            }

        } catch(Exception $e) {
            $system->message($e->getMessage());
        }
        $system->printMessage();
    }

    if($currency_type == System::CURRENCY_TYPE_MONEY) {
        $current_amount = "&yen;" . $player->getMoney();
    }
    else if($currency_type == System::CURRENCY_TYPE_PREMIUM_CREDITS) {
        $current_amount = $player->getPremiumCredits();
    }

    $recipient = $_GET['recipient'] ?? '';

    echo "<table class='table'><tr><th>Send {$label}</th></tr>
    <tr><td style='text-align:center;'>
    <form action='{$system->router->links['profile']}&page={$page}' method='post'>
    <b>Your {$label}:</b> {$current_amount}<br />
    <br />
    Send {$label} to:<br />
    <input type='text' name='recipient' value='{$recipient}' /><br />
    Amount:<br />
    <input type='text' name='amount' /><br />
    <input type='submit' name='send_currency' value='Send {$label}' />
    </form>
    </td></tr></table>";
}

function renderProfileSubmenu() {
    global $system;
    global $player;
    global $self_link;

    $submenu_links = [
        [
            'link' => $system->router->links['profile'],
            'title' => 'Character',
        ],
        [
            'link' => $system->router->links['settings'],
            'title' => 'Settings',
        ],
    ];
    if($player->rank_num > 1) {
        $submenu_links[] = [
            'link' => $system->router->links['profile'] . "&page=send_money",
            'title' => 'Send Money',
        ];
        $submenu_links[] = [
            'link' => $system->router->links['profile'] . "&page=send_ak",
            'title' => 'Send AK',
        ];
    }
    if($player->bloodline_id) {
        $submenu_links[] = [
            'link' => $system->router->links['bloodline'],
            'title' => 'Bloodline',
        ];
    }

    echo "<div class='submenu'>
    <ul class='submenu'>";
    $submenu_link_width = round(100 / count($submenu_links), 1);
    foreach($submenu_links as $link) {
        echo "<li style='width:{$submenu_link_width}%;'><a href='{$link['link']}'>{$link['title']}</a></li>";
    }
    echo "</ul>
    </div>
    <div class='submenuMargin'></div>
    ";
}
