<?php

namespace Rest\Controller\Pages\TradeProceduresPage;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use CHTTP;
use Rest\Picture\Webp;
use Rest\Constants;
use Fact\Api\Controller\Pages\BasePage;
use Rest\Core\Cache;
use Fact\Api\Core\Token;
use Fact\Api\Traits\HasModuleOption;
use Fact\Api\Traits\HasModules;
use Fact\Api\Traits\HasParameters;
use Fact\Api\View\Helper;
use SiteCore\Core;
use Exception;
/**
 * @OA\Get(
 *   tags={"Закупки"},
 *   path="/web/b2bcenter/",
 *   @OA\Response(
 *       response="200",
 *       description="success",
 *   )
 * )
 */
class TradeProceduresPage extends BasePage
{
    use HasParameters, HasModuleOption, HasModules;

    /** @var string ID кеша */
    const ID_CACHE = "b2bcenter_page";

    /** @var string директория хранения кеша */
    const CACHE_DIR = "/web/b2bcenter/#place#/";

    /** @var string строка для замены в пути к кешу */
    const CACHE_SEARCH_FOR_REPLACE = "#place#";

    /** @var int время кеширования */
    const CACHE_TIME = 36000000;

    /** @var int базовое количество на странице списка */
    const LIMIT_PER_PAGE = 10;

    /** @var string get параметр для фильтрации по статусу */
    const STATUS_FILTER_GET_PARAMETER = 'status';

    /** @var string get параметр для пагинации */
    const PAGE_FILTER_GET_PARAMETER = 'cur_page';


    /**
     * Подготовка префильтров
     *
     * @return \array[][]
     */
    public function configureActions()
    {
        return [
            "getTradeProceduresPage" => [
                "prefilters" => []
            ]
        ];
    }


    /**
     * Получение класса для страницы Закупки B2B Центр
     *
     * @return string
     */
    public static function getPageVersion(): string
    {
        return TradeProceduresPage::class;
    }

    /**
     * Получение страницы Закупки B2B Центр
     *
     * @return array|false
     */
    public function getTradeProceduresPageAction()
    {
        try {
            static::includeIblockModule();
            static::includeSitecoreModule();

            return [
                'meta' => [
                    'title' => (string)Option::get('core', 'title_seo_b2b_center'),
                    'description' => (string)Option::get('core', 'description_seo_b2b_center'),
                    'img_webp' => Webp::getWebpFile(\CFile::GetPath((string)Option::get('core', 'share_image'))),
                    'img' => Helper::prepareFullWebAddress(\CFile::GetPath((string)Option::get('core', 'share_image')))
                ],
                'h1' => (string)Option::get('core', 'h1_seo_b2b_center'),
                'text_block' => (string)Option::get('core', 'b2b_center_intro_text'),
                'title_instructions_list' => (string)Option::get('core', 'b2b_center_instructions_list_title'),
                'instructions_list' => $this->getInstructions(),
                'table' => [
                    'titleRow' => [
                        (string)Option::get('core', 'b2b_center_first_column'),
                        (string)Option::get('core', 'b2b_center_second_column'),
                        (string)Option::get('core', 'b2b_center_third_column'),
                        (string)Option::get('core', 'b2b_center_fourth_column'),
                    ],
                    'rows' => $this->getRows(),
                ]
            ];

        } catch (Exception $ex) {
            $this->addError(new Error($ex->getMessage()));
            return false;
        }
    }


    /**
     * Получение данных из инфоблока
     *
     * @param int $iblockId
     * @param array $filter
     * @param array $select
     * @param array $order
     * @param array $runtime
     * @param int $limit
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    protected function prepareIblockInfo(int $iblockId, array $filter = [], array $select = [], array $order = [], array $runtime = [], int $limit = 0, int $offset = 0): array
    {
        if (!is_numeric($iblockId)) {
            throw new Exception(Loc::getMessage("REST_ABOUT_PAGE_IBLOCK_NOT_FOUND", ["#IBLOCK_ID#" => $iblockId]));
        }
        return Helper::getIblockInfo(
            $iblockId,
            [
                "filter" => $filter,
                "select" => $select,
                "order" => $order,
                "runtime" => $runtime,
                "limit" => $limit,
                "offset" => $offset
            ]);
    }

    /**
     * Получение контента страницы
     *
     * @return false|mixed|null
     */
    protected function getRows()
    {
        try {
            $request = Application::getInstance()->getContext()->getRequest();
            $status = $request->get(self::STATUS_FILTER_GET_PARAMETER) ?? null;
            $curPage = $request->get(self::PAGE_FILTER_GET_PARAMETER) ?? 1;
            $B2BCenterIblockId = Core::getInstance()->getIblockId(Constants::IBLOCK_B2B_CENTER);
            return Cache::keepAlways(
                md5(self::ID_CACHE . $B2BCenterIblockId . $status . $curPage),
                $this->getCacheDir(Constants::IBLOCK_B2B_CENTER),
                function () use ($B2BCenterIblockId, $curPage, $status) {
                    $statusPropertyQuery = \CIBlockPropertyEnum::GetList([], array('IBLOCK_ID' => $B2BCenterIblockId, 'CODE' => 'STATUS'));
                    $filterStatusProperty = [];
                    $getParameters = [];
                    while ($propertyInfo = $statusPropertyQuery->GetNext()) {
                        if($propertyInfo['EXTERNAL_ID'] == 'canceled') {
                            continue;
                        }
                        $getParameters[$propertyInfo['ID']] = $propertyInfo['EXTERNAL_ID'];
                        $filterStatusProperty[$propertyInfo['EXTERNAL_ID']] = [
                            'ID' => $propertyInfo['ID'],
                            'VALUE' => $propertyInfo['VALUE']
                        ];
                    }
                    if (!empty($status)) {
                        $arFilter = array_merge($this->getDefaultFilter(), ['PROPERTY_STATUS_VALUE' => $filterStatusProperty[$status]['VALUE'], 'IBLOCK_ID' => $B2BCenterIblockId]);
                        $filterForCountAll = array_merge($this->getDefaultFilter(), ['IBLOCK_ELEMENTS_ELEMENT_B2BCENTER_STATUS_VALUE' => $filterStatusProperty[$status]['ID']]);
                    } else {
                        $arFilter = array_merge($this->getDefaultFilter(), ['IBLOCK_ID' => $B2BCenterIblockId]);
                        $filterForCountAll = $this->getDefaultFilter();
                    }
                    //через старое ядро, чтобы работала сортировка по свойству
                    $arOrder = ['PROPERTY_PUBLISH_TRADE' => 'DESC'];
                    $arNavStartParams = ['nPageSize' => self::LIMIT_PER_PAGE, 'iNumPage' => $curPage, 'checkOutOfRange' => true];
                    $arSelect = [
                        'ID',
                        'IBLOCK_ID',
                        'NAME',
                        'PROPERTY_PUBLISH_TRADE',
                        'PROPERTY_DATE_TRADE_END',
                        'PROPERTY_URL',
                        'PROPERTY_STATUS',
                        'PROPERTY_PRICE',
                    ];
                    $tabsElementsQuery = \CIBlockElement::GetList(
                        $arOrder,
                        $arFilter,
                        false,
                        $arNavStartParams,
                        $arSelect
                    );
                    $resultTabs = [];
                    while ($row = $tabsElementsQuery->GetNextElement()) {
                        $arFields = $row->GetFields();
                        $publishTradeUnix = strtotime($arFields['PROPERTY_PUBLISH_TRADE_VALUE']);
                        $dateTradeUnix = strtotime($arFields['PROPERTY_DATE_TRADE_END_VALUE']);
                        $resultTabs[] = [
                            'ID' => (string)$arFields['ID'],
                            'NAME' => (string)$arFields['NAME'],
                            'PUBLISH_TRADE_date' => date('d.m.Y', $publishTradeUnix),
                            'PUBLISH_TRADE_time' => date('H:i', $publishTradeUnix),
                            'DATE_TRADE_END_date' => date('d.m.Y', $dateTradeUnix),
                            'DATE_TRADE_END_time' => date('H:i', $dateTradeUnix),
                            'URL' => (string)$arFields['PROPERTY_URL_VALUE'],
                            'STATUS' => (string)$getParameters[$arFields['PROPERTY_STATUS_ENUM_ID']],
                            'PRICE' => (!empty((float)$arFields['PROPERTY_PRICE_VALUE']))
                                ? number_format(
                                    $arFields['PROPERTY_PRICE_VALUE'],
                                    0,
                                    '.',
                                    ' '
                                )
                                : '—',
                        ];
                    }
                    if (empty($resultTabs)) {
                        CHTTP::SetStatus("404 Not Found");
                    }
                    $countAllElements = count($this->prepareIblockInfo(
                        $B2BCenterIblockId,
                        $filterForCountAll,
                        [
                            'ID',
                            'STATUS',
                        ]
                    ));
                    $lastPage = ceil($countAllElements / self::LIMIT_PER_PAGE);

                    return [
                        'procedures' => $resultTabs,
                        'get_parameters' => $getParameters,
                        'pagination' => [
                            'page' => $curPage,
                            'last_page' => $lastPage,
                        ]
                    ];
                },
                "iblock_id_" . $B2BCenterIblockId
            );
        } catch (Exception $ex) {
            AddMessage2Log($ex->getMessage());
        }

        return false;
    }


    protected function getInstructions()
    {
        try {
            $B2BCenterInstructionsIblockId = Core::getInstance()->getIblockId(Constants::IBLOCK_B2B_CENTER_INSTRUCTIONS);
            return Cache::keepAlways(
                md5(self::ID_CACHE . $B2BCenterInstructionsIblockId),
                $this->getCacheDir(Constants::IBLOCK_B2B_CENTER_INSTRUCTIONS),
                function () use ($B2BCenterInstructionsIblockId) {
                    $list = $this->prepareIblockInfo(
                        $B2BCenterInstructionsIblockId,
                        $this->getDefaultFilter(),
                        [
                            'ID',
                            'item_list' => 'DETAIL_TEXT'
                        ],
                        ['SORT' => 'ASC']
                    );
                    return $list;
                },
                "iblock_id_" . $B2BCenterInstructionsIblockId
            );
        } catch (Exception $ex) {
            AddMessage2Log($ex->getMessage());
        }

        return false;
    }
}