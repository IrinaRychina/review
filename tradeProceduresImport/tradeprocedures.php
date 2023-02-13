<?php

namespace Integration\Import;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Integration\Entity\TradeProceduresTable;
use Integration\Traits\AgentImport;
use Integration\Api;
use Core;

/**
 * Class TradeProcedures
 * @package Integration\Import
 */
class TradeProcedures extends AbstractImport
{
    use AgentImport;

    /**
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    protected static function import()
    {
        if (self::saveToDB()) { // если в БД произошли изменения, грузим в инфоблок Закупки
            self::load();
        }
    }

    /**
     * @return bool
     * @throws \Exception
     */
    protected static function saveToDB()
    {
        $curDateFrom = Option::get('integration', 'b2b_integration_interval_date_from');
        $curDateTo = Option::get('integration', 'b2b_integration_interval_date_to');
        $tradeProceduresList = Api::getTradeProceduresList(strtotime($curDateFrom), strtotime($curDateTo));
        //определить новое начало и конец периода выборки изменившихся процедур
        $now = time();
        $newDateTo = strtotime('+604800 seconds', strtotime($curDateTo));
        if ($newDateTo > $now) {
            $newDateTo = $now;
        }
        $newDateFrom = strtotime('-604800 seconds', $newDateTo);
        //установить новое начало и конец периода выборки изменившихся процедур
        Option::set('integration', 'b2b_integration_interval_date_from', date('Y-m-d\TH:i', $newDateFrom));
        Option::set('integration', 'b2b_integration_interval_date_to', date('Y-m-d\TH:i', $newDateTo));
        if (!empty($tradeProceduresList)) {
            if (self::$logId) { // Стадия загрузки
                self::toEvent('Загрузка в БД');
            }
            foreach ($tradeProceduresList as $procedureListItem) {
                $tradeProcedure = Api::getTradeFullData($procedureListItem->id);
                try {
                    $isActive = 'Y';
                    $status = Api::getTradeStatus($procedureListItem->id);
                    if ($status == 'canceled') {
                        $isActive = 'N';
                    }
                    $result = TradeProceduresTable::add(
                        [
                            'EXTERNAL_ID' => (string)$tradeProcedure->id,
                            'ETP_name' => (string)$tradeProcedure->name,
                            'ACTIVE' => $isActive,
                            'DATE_END' => (int)$tradeProcedure->date_end,
                            'DATE_TRADE_END' => (int)$tradeProcedure->date_trade_end,
                            'URL' => (string)$tradeProcedure->url,
                            'PRICE' => (float)$tradeProcedure->price,
                            'PRICE_NO_TAX' => (float)$tradeProcedure->price_no_tax,
                            'PUBLISH_TRADE' => (int)$tradeProcedure->publish_date,
                            'STATUS' => $status,
                        ]
                    );
                    if (!$result->isSuccess()) {
                        self::toLog($result->getErrorMessages(), TradeProceduresTable::class, 'Закупки, БД', $tradeProcedure->id, __FILE__, __LINE__);
                    }
                } catch (\Exception $exception) {
                    self::toLog($exception->getMessage(), TradeProceduresTable::class, 'Закупки, БД', $tradeProcedure->id, __FILE__, __LINE__);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * * Сохраняем тороговые процедуры в инфоблок Закупки
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function load()
    {
        self::toEvent('Загрузка в ИБ');
        $core = Core::getInstance();
        $b2bCenterIblockId = $core->getIblockId($core::IBLOCK_CODE_B2B_CENTER);
        $lastTimeStamp = Option::get('integration', 'dateUpdateTradeProcedures');
        if ($lastTimeStamp) {
            $filterNewTradeProcedures['>=TIMESTAMP_X'] = DateTime::createFromTimestamp($lastTimeStamp);
        } else {
            $filterNewTradeProcedures = ['*'];
        }
        $tradeProceduresQuery = TradeProceduresTable::getList(
            [
                'filter' => $filterNewTradeProcedures,
                'select' => [
                    'TIMESTAMP_X',
                    'EXTERNAL_ID',
                    'ETP_name',
                    'ACTIVE',
                    'PRICE',
                    'PRICE_NO_TAX',
                    'PUBLISH_TRADE',
                    'DATE_END',
                    'DATE_TRADE_END',
                    'URL',
                    'STATUS'],
            ]
        );
        while ($tradeProcedure = $tradeProceduresQuery->fetch()) {
            $statusPropertyId = \CIBlockPropertyEnum::GetList(
                [],
                array('IBLOCK_ID' => $b2bCenterIblockId, 'CODE' => 'STATUS', 'XML_ID' => $tradeProcedure['STATUS'])
            )->GetNext()['ID'];
            if (!$tradeProcedure['DATE_END']) {
                $dateEnd = $tradeProcedure['DATE_TRADE_END'];
            } else {
                $dateEnd = $tradeProcedure['DATE_END'];
            }
            if ($isExistBxTradeProcedure = \Bitrix\Iblock\ElementTable::getList(
                [
                    'filter' => [
                        '=IBLOCK_ID' => $b2bCenterIblockId,
                        '=XML_ID' => $tradeProcedure['EXTERNAL_ID']
                    ],
                    'select' => ['ID']
                ]
            )->fetch()) {
                //Обновляем поля процедуры
                $elementClassObject = new \CIBlockElement;
                $updateResult = $elementClassObject->Update(
                    $isExistBxTradeProcedure['ID'],
                    [
                        'XML_ID' => $tradeProcedure['EXTERNAL_ID'],
                        'NAME' => $tradeProcedure['ETP_name'],
                        'ACTIVE' => $tradeProcedure['ACTIVE'],
                        'PROPERTY_VALUES' => [
                            'PRICE' => $tradeProcedure['PRICE'],
                            'PRICE_NO_TAX' => $tradeProcedure['PRICE_NO_TAX'],
                            'PUBLISH_TRADE' => ConvertTimeStamp($tradeProcedure['PUBLISH_TRADE'], "FULL"),
                            'DATE_TRADE_END' => ConvertTimeStamp($dateEnd, "FULL"),
                            'URL' => $tradeProcedure['URL'],
                            'STATUS' => (!empty($statusPropertyId)) ? ['VALUE' => $statusPropertyId] : false
                        ],
                    ]
                );
                if (!$updateResult) {
                    self::toLog($elementClassObject->LAST_ERROR, TradeProceduresTable::class, 'Закупки, ИБ', $tradeProcedure['EXTERNAL_ID'], __FILE__, __LINE__);
                }
            } else {
                //Добавляем процедуру
                $elementClassObject = new \CIBlockElement;
                $addResult = $elementClassObject->Add(
                    [
                        'IBLOCK_ID' => $b2bCenterIblockId,
                        'IBLOCK_SECTION_ID' => false,
                        'NAME' => $tradeProcedure['ETP_name'],
                        'XML_ID' => $tradeProcedure['EXTERNAL_ID'],
                        'ACTIVE' => $tradeProcedure['ACTIVE'],
                        'PROPERTY_VALUES' => [
                            'PRICE' => $tradeProcedure['PRICE'],
                            'PRICE_NO_TAX' => $tradeProcedure['PRICE_NO_TAX'],
                            'PUBLISH_TRADE' => ConvertTimeStamp($tradeProcedure['PUBLISH_TRADE'], "FULL"),
                            'DATE_TRADE_END' => ConvertTimeStamp($dateEnd, "FULL"),
                            'URL' => $tradeProcedure['URL'],
                            'STATUS' => (!empty($statusPropertyId)) ? ['VALUE' => $statusPropertyId] : false
                        ],
                    ]
                );
                if (!$addResult) {
                    self::toLog($elementClassObject->LAST_ERROR, TradeProceduresTable::class, 'Закупки, ИБ', $tradeProcedure['EXTERNAL_ID'], __FILE__, __LINE__);
                }
            }
        }
        /**
         * записываем дату последней выгрузки
         */
        Option::set('integration', 'dateUpdateTradeProcedures', (new DateTime())->getTimestamp());
    }

    /**
     * Очищам старые данные
     */
    public static function clear()
    {
        self::clearOld(TradeProceduresTable::getConnectionName(), TradeProceduresTable::getTableName(), 'time_clear_TradeProcedures');
    }
}
