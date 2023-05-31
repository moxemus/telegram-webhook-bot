<?php

namespace src\components\telegram;

use src\components\rateApi\BaseAdaptor;
use src\components\rateApi\BaseAdaptor as RateAdaptor;
use src\components\rateApi\MessariAdaptor;
use moxemus\array\Helper as ArrayHelper;
use src\config\DB;

class Handler
{
    const SMILE_GREEN = "\xE2\x9C\x85";
    const SMILE_RED = "\xF0\x9F\x94\xBB";
    const SMILE_EXCLAMATION = "\xE2\x9D\x97";

    protected Adaptor $telegramAdaptor;
    protected BaseAdaptor $apiAdaptor;
    protected DB $db;

    public function __construct(?BaseAdaptor $apiAdaptor = null)
    {
        $this->apiAdaptor = $apiAdaptor ?? new MessariAdaptor();
        $this->telegramAdaptor = new Adaptor();
        $this->db = new DB();
    }

    protected function getAvailableCrypto(): array
    {
        return [
            RateAdaptor::BTC,
            RateAdaptor::ETH,
            RateAdaptor::DOGE
        ];
    }

    public function mail(): void
    {
        $users = DB::query("SELECT telegram_id, is_admin, last_rate from users");

        $data = array_map(
            fn($currency) => [
                'currency' => $currency,
                'value' => $this->apiAdaptor->getRate($currency)
            ],
            $this->getAvailableCrypto()
        );

        foreach ($users as $user) {
            $message = '';
            $chatId = $user['telegram_id'];

            foreach ($data as $item) {
                $currentRate = $item['value'];
                $currency = $item['currency'];
                $lastRate = $this->getLastUserRate($chatId, $currency);

                $message .= "$currency: " . $this->getRateMessage($currentRate, $lastRate) . PHP_EOL;

                $this->updateUserRate($chatId, $currentRate, $currency);
            }

            $this->telegramAdaptor->sendMessage($chatId, $message);
        }
    }

    public function notify(): void
    {
        $userAlarms = DB::query("select * from user_alarms");
        $currentRate = $this->apiAdaptor->getRate(RateAdaptor::BTC);

        foreach ($userAlarms as $alarm) {
            $userRate = $alarm['rate'];
            $isBigger = !!$alarm['is_bigger'];

            if ($userRate > $currentRate && $isBigger ||
                $userRate < $currentRate && !$isBigger) {
                $text = ($isBigger) ? 'more' : 'less';
                $message = self::SMILE_EXCLAMATION . "BTC costs is {$text} than {$userRate} now - {$currentRate}" .
                    self::SMILE_EXCLAMATION;

                $this->telegramAdaptor->sendMessage($alarm['user_id'], $message);

                $this->updateUserRate($alarm['user_id'], $currentRate);
            }
        }
    }

    public function setUserAlarm(int $userId, string $text): void
    {
        $matches = [];
        preg_match('/alarm (\w+) (\d+)/', $text, $matches);

        $sign = $matches[1] ?? null;
        $rate = $matches[2] ?? 0;

        if (!in_array($sign, ['more', 'less']) || $rate <= 0) {
            $this->sendMessage($userId, 'Please give correct info');
        } else {
            $isBigger = intval($sign == 'more');

            DB::exec("delete from user_alarms where user_id = $userId");
            DB::exec("insert into user_alarms (user_id, rate, is_bigger) values ($userId, $rate, $isBigger)");

            $this->sendMessage($userId, 'New alarm configured');
        }
    }

    public function sendCurrentRate(int $chatId): bool
    {
        $data = array_map(
            fn($currency) => [
                'currency' => $currency,
                'value' => $this->apiAdaptor->getRate($currency)
            ],
            $this->getAvailableCrypto()
        );

        $message = '';

        foreach ($data as $item) {
            $currentRate = $item['value'];
            $currency = $item['currency'];
            $lastRate = $this->getLastUserRate($chatId, $currency);

            $message .= "$currency: " . $this->getRateMessage($currentRate, $lastRate) . "\n";

            $this->updateUserRate($chatId, $currentRate, $currency);
        }

        return $this->telegramAdaptor->sendMessage($chatId, $message);
    }

    public function sendAdminMenu(int $chatId): bool
    {
        $markupParams = [
            "Show users" => Response::COMMAND_USERS,
            "Show rate" => Response::COMMAND_SHOW_RATE,
        ];

        return $this->sendMessage($chatId, 'Hello admin!', $markupParams);
    }

    public function sendUsers(?int $chatId): bool
    {
        if (is_null($chatId)) {
            return false;
        }

        $raw = DB::queryOne("select count(*) as cc from users");

        return $this->sendAnswerCallback($chatId, $raw->cc);
    }

    public function sendScheduleMenu(int $chatId): bool
    {
        $markupParams = [
            "Every day" => Response::COMMAND_SCHEDULE_EVERY_DAY,
            "Every hour" => Response::COMMAND_SCHEDULE_EVERY_HOUR,
            "Disable notifications" => Response::COMMAND_SCHEDULE_DISABLE
        ];

        return $this->sendMessage($chatId, 'Set up your notification schedule', $markupParams);
    }

    public function sendWelcome(Response $response): bool
    {
        $raw = DB::query("select id from users where telegram_id = " . $response->id);
        if (ArrayHelper::isEmpty($raw)) {
            $this->createUser($response->id, $response->userInfo);
        }

        $this->sendMessage($response->id, 'Welcome to BTC rate bot!');
        return $this->sendCurrentRate($response->id);
    }

    protected function createUser(int $chatId, array $params): void
    {
        $firstName = $params['first_name'] ?? '';
        $lastName = $params['last_name'] ?? '';
        $language = $params['language_code'] ?? '';
        $username = $params['username'] ?? '';

        DB::exec(
            "insert into users (telegram_id, first_name, last_name, username, language_code) values " .
            "($chatId, '$firstName', '$lastName', '$username', '$language')"
        );

        $currencies = $this->getAvailableCrypto();
        foreach ($currencies as $currency) {
            DB::exec(
                "insert into user_rates (user_id, currency, value) values " .
                "($chatId, '$currency', 0)"
            );
        }
    }

    protected function getLastUserRate(int $chatId, string $currency): float
    {
        $raw = DB::queryOne("select value from user_rates where user_id = $chatId and currency = '$currency'");

        return (float)($raw->value ?? 0);
    }

    public function sendAlarmInfo(int $chatId): bool
    {
        return $this->sendMessage(
            $chatId,
            "You can get notification when BTC rate will be more/lower than your price.\n" .
            "Write your alarm template, for example: alarm less 22000 \n"
        );
    }

    public function sendAnswerCallback(int $callbackId, string $text): bool
    {
        return $this->telegramAdaptor->sendAnswerCallback($callbackId, $text);
    }

    protected function sendMessage(int $chatId, string $text, array $markupParams = []): bool
    {
        return $this->telegramAdaptor->sendMessage($chatId, $text, $markupParams);
    }

    protected function getRateMessage(float $currentRate, float $lastRate): string
    {
        $smile = ($currentRate >= $lastRate) ? self::SMILE_GREEN : self::SMILE_RED;
        return $currentRate . $smile;
    }

    protected function updateUserRate(int $chatId, float $rate, string $currency): void
    {
        DB::exec("UPDATE user_rates set value = " . number_format($rate, 2, '.', '')
            ."  where user_id = {$chatId} and currency = '{$currency}'");
    }
}