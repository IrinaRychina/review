<?php

namespace Integration;

use Bitrix\Main\Config\Option;
use Integration\Traits\Log;

/**
 * Class Api
 * @package Integration
 */
class Api
{
    use Log;

    private const UNAUTHORIZED_SERVER_RESPONSE_CODE = 401;

    /**
     * Получение токена для получения данных из b2b-center
     *
     * @return string
     * @throws \Exception
     */
    public static function getB2bCenterToken(): string
    {
        $config = \Bitrix\Main\Config\Configuration::getInstance();
        $b2bAuthData = $config->get('b2bCenter');
        $tokenRequest = Methods::curlExec(
            $b2bAuthData['domain']. '/integration/json/User.Login/',
            [
                'login' => $b2bAuthData['login'],
                'password' => $b2bAuthData['password']
            ],
            true
        );
        $objectToken = json_decode($tokenRequest);

        if (!empty($objectToken->error)) {
            self::logToFile($objectToken->error->message, Api::class, 'Закупки, API запрос', '', __FILE__, __LINE__);
            return '';
        }

        $token = $objectToken->access_token;
        Option::set('integration', 'b2b_center_token', $objectToken->access_token);
        return $token;
    }

    /**
     * Получение торговых процедур компании
     *
     * @param int $date_from
     * @param int $date_to
     * @return array
     * @throws \Exception
     */
    public static function getTradeProceduresList(int $date_from, int $date_to): array
    {
        $token = Option::get('integration', 'b2b_center_token');
        $config = \Bitrix\Main\Config\Configuration::getInstance();
        $b2bCenterDomain = $config->get('b2bCenter')['domain'];
        $tradeProceduresList = Methods::curlExec($b2bCenterDomain . '/integration/json/TradeProcedures.GetMyList/?access_token=' . $token . '&date_from=' . $date_from . '&date_to=' . $date_to);
        if (!empty($errorObject = json_decode($tradeProceduresList)->error)) {
            if ($errorObject->code == self::UNAUTHORIZED_SERVER_RESPONSE_CODE) {
                $token = self::getB2bCenterToken();
                $tradeProceduresList = Methods::curlExec($b2bCenterDomain . '/integration/json/TradeProcedures.GetMyList/?access_token=' . $token . '&date_from=' . $date_from . '&date_to=' . $date_to);
                if (!empty($errorObject = json_decode($tradeProceduresList)->error)) {
                    self::logToFile($errorObject->message, Api::class, 'Закупки, API запрос', '',__FILE__, __LINE__);
                    return [];
                }
            } else {
                self::logToFile($errorObject->message, Api::class, 'Закупки, API запрос', '',__FILE__, __LINE__);
                return [];
            }
        }

        return json_decode($tradeProceduresList)->trade_list;
    }

    /**
     * Получение детальной информации о торговой процедуре по id
     *
     * @param string $tradeId
     * @return object
     * @throws \Exception
     */
    public static function getTradeFullData(string $tradeId): ?object
    {
        $token = Option::get('integration', 'b2b_center_token');
        $config = \Bitrix\Main\Config\Configuration::getInstance();
        $b2bCenterDomain = $config->get('b2bCenter')['domain'];
        $tradeFullData = Methods::curlExec($b2bCenterDomain . '/integration/json/TradeProcedures.GetFullTrade/?access_token=' . $token . '&id=' . $tradeId);

        $errorObject = json_decode($tradeFullData)->error;
        if (!empty($errorObject)) {
            if ($errorObject->code == self::UNAUTHORIZED_SERVER_RESPONSE_CODE) {
                $token = self::getB2bCenterToken();
                $tradeFullData = Methods::curlExec($b2bCenterDomain . '/integration/json/TradeProcedures.GetFullTrade/?access_token=' . $token . '&id=' . $tradeId);
                if (!empty($errorObject = json_decode($tradeFullData)->error)) {
                    self::toLog($errorObject->message, Api::class, 'Закупки, API запрос', '',__FILE__, __LINE__);
                    return null;
                }
            } else {
                self::toLog($errorObject->message, Api::class, 'Закупки, API запрос', '',__FILE__, __LINE__);
                return null;
            }
        }

        return json_decode($tradeFullData)->full_trade_procedure;
    }

    /**
     * Получение статуса торговой процедуре по id (в архиве или нет)
     *
     * @param string $tradeId
     * @return string
     * @throws \Exception
     */
    public static function getTradeStatus(string $tradeId): ?string
    {
        $token = Option::get('integration', 'b2b_center_token');
        $config = \Bitrix\Main\Config\Configuration::getInstance();
        $b2bCenterDomain = $config->get('b2bCenter')['domain'];
        $shortTradeData = Methods::curlExec($b2bCenterDomain . '/integration/json/TradeProcedures.GetShortTrade/?access_token=' . $token . '&id=' . $tradeId);

        $errorObject = json_decode($shortTradeData)->error;
        if (!empty($errorObject)) {
            if ($errorObject->code == self::UNAUTHORIZED_SERVER_RESPONSE_CODE) {
                $token = self::getB2bCenterToken();
                $shortTradeData = Methods::curlExec($b2bCenterDomain . '/integration/json/TradeProcedures.GetShortTrade/?access_token=' . $token . '&id=' . $tradeId);
                if (!empty($errorObject = json_decode($shortTradeData)->error)) {
                    self::toLog($errorObject->message, Api::class, 'Закупки, API запрос', '',__FILE__, __LINE__);
                    return '';
                }
            } else {
                self::toLog($errorObject->message, Api::class, 'Закупки, API запрос', '',__FILE__, __LINE__);
                return '';
            }
        }

        return json_decode($shortTradeData)->short_trade_procedure->status;
    }
}
