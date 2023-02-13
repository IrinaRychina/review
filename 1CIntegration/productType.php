<?php

namespace Integration\Import;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Integration\Entity\ProductTypeTable;
use Integration\Traits\AgentImport;
use SiteCore\Core;
use Rest\Constants;

/**
 * Class ProductType
 * @package Integration\Import
 */
class ProductType
{
    use AgentImport;

    private static array $errors = [];

    /**
     * загрузка Типа продукта в интеграционную таблицу
     *
     * @param $arType
     * @return array
     */
    public static function SaveType($arType)
    {
        if (!empty($arType['productType'])) {
            /**
             * записываем дату текущей выгрузки
             */
            Option::set('integration', 'lastDateUpdateProductTypes', (new DateTime())->getTimestamp());
            $responseResult = [];
            foreach ($arType['productType'] as $type) {
                try {
                    $typeParameters = [
                        'EXTERNAL_ID' => $type['id-type'],
                        'NAME' => $type['type']
                    ];
                    $addResult = ProductTypeTable::add($typeParameters);
                    $isSaveToHlBlock = self::saveTypeToHLBlock($typeParameters);
                    if (!$addResult->isSuccess() || !$isSaveToHlBlock) {
                        if (!empty($addResult->getErrorMessages())) {
                            self::$errors[] = $addResult->getErrorMessages();
                        }
                        $errorText = '';
                        foreach (self::$errors as $error) {
                            if (is_array($error)) {
                                foreach ($error as $errorArray)
                                    $errorText .= implode(' ,', $errorArray);
                            }
                            $errorText .= $error;
                        }
                        self::logToFile($errorText, ProductTypeTable::class, 'Каталог. Тип продукта', $type['id-type'], __FILE__, __LINE__);
                        $responseResult[] = [
                            'id-type' => (string)$type['id-type'],
                            'download-status' => 'Fail',
                            'error-text' => $errorText
                        ];
                    } else {
                        $responseResult[] = [
                            'id-type' => (string)$type['id-type'],
                            'download-status' => 'OK'
                        ];
                    }
                    //обнулить массив ошибок для каждого элемента
                    self::$errors = [];
                } catch (\Exception $exception) {
                    self::logToFile($exception->getMessage(), ProductTypeTable::class, 'Каталог. Тип продукта', $type['id-type'], __FILE__, __LINE__);
                }
            }
            $isFullExchange = (boolean)$arType['is-full-upload'];
            $isEndExchange = (boolean)$arType['is-end-upload'];
            $dateStart = (string)$arType['date-start'];
            $dateEnd = (string)$arType['date-end'];
            $totalCount = (int)$arType['total-count'];
            self::deleteOldFromHLBlock();
            if ($isEndExchange && $isFullExchange) {
                self::addToLogAllCount($dateStart, $dateEnd, ProductType::class, ProductTypeTable::class, $totalCount);
            }
            return $responseResult;
        }
    }

    /**
     * загрузка Типа продукта в справочник
     *
     * @param $type
     * @return bool
     */
    private static function saveTypeToHLBlock($type)
    {
        $core = Core::getInstance();
        $hlblockProductTypeTable = $core->getHlEntity(Constants::HLBLOCK_CODE_PRODUCT_TYPE);
        if (!empty($type)) {
            try {
                $hasProductType = $hlblockProductTypeTable::getList(
                    [
                        'select' => ['ID'],
                        'filter' => ['=UF_XML_ID' => $type['EXTERNAL_ID']]
                    ])->fetch()['ID'];
                if ($hasProductType) {
                    $updateResult = $hlblockProductTypeTable::update(
                        $hasProductType,
                        [
                            'UF_TIMESTAMP_X' => new \Bitrix\Main\Type\DateTime(),
                            'UF_NAME' => $type['NAME']
                        ]
                    );
                    if (!$updateResult->isSuccess()) {
                        self::$errors[] = $updateResult->getErrorMessages();
                        self::logToFile($updateResult->getErrorMessages(), $hlblockProductTypeTable, 'Каталог. Тип продукта', $type['EXTERNAL_ID'], __FILE__, __LINE__);
                        return false;
                    }
                } else {
                    $addResult = $hlblockProductTypeTable::add(
                        [
                            'UF_TIMESTAMP_X' => new \Bitrix\Main\Type\DateTime(),
                            'UF_XML_ID' => $type['EXTERNAL_ID'],
                            'UF_NAME' => $type['NAME']
                        ]
                    );
                    if (!$addResult->isSuccess()) {
                        self::$errors[] = $addResult->getErrorMessages();
                        self::logToFile($addResult->getErrorMessages(), $hlblockProductTypeTable, 'Каталог. Тип продукта', $type['EXTERNAL_ID'], __FILE__, __LINE__);
                        return false;
                    }
                }
            } catch (\Exception $exception) {
                self::logToFile($exception->getMessage(), $hlblockProductTypeTable, 'Каталог. Тип продукта', $type['EXTERNAL_ID'], __FILE__, __LINE__);
                self::$errors[] = 'Не удалось добавить или обновить highloadblock элемент';
                return false;
            }
            return true;
        }
        self::$errors[] = 'Пустой входящий массив';
        return false;
    }


    /**
     * удалить из справочника элементы из прошлой выгрузки
     *
     */
    private static function deleteOldFromHLBlock()
    {
        $lastTimeStamp = Option::get('integration', 'lastDateUpdateProductTypes');
        if ($lastTimeStamp) {
            $filterOldProductTypes['<UF_TIMESTAMP_X'] = DateTime::createFromTimestamp($lastTimeStamp);
        } else {
            return;
        }
        $core = Core::getInstance();
        $hlblockProductTypeTable = $core->getHlEntity(Constants::HLBLOCK_CODE_PRODUCT_TYPE);
        $oldProductTypes = $hlblockProductTypeTable::getList(
            [
                'select' => ['ID'],
                'filter' => $filterOldProductTypes
            ]);
        while ($productTypeId = $oldProductTypes->fetch()['ID']) {
            $deleteResult = $hlblockProductTypeTable::delete($productTypeId);
            if (!$deleteResult->isSuccess()) {
                self::logToFile($deleteResult->getErrorMessages(), $hlblockProductTypeTable, 'Каталог. Тип продукта. Удаление старой выгрузки', $productTypeId, __FILE__, __LINE__);
            }
        }
    }
}
