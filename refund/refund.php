<?php

namespace Refund;

use Core;
use Entity\ShipmentRealisations\ShipmentRealisationsRepository;
use \Entity\ShipmentRealisations\ShipmentRealisationLinesRepository;
use \Entity\Refunds\RefundStatusesRepository;
use \Entity\Refunds\RefundTypesRepository;
use Tools\Repository\Entity\BaseRepository;

class Refund
{
    /**
     * @var array
     * список реализованных товаров из выгрузки из интеграционного слоя (реализации)
     */
    private array $realisations;
    /**
     * @var array
     * список товаров доступных для частичного возврата
     */
    private array $partialRefundElements;
    /**
     * @var float
     * сумма переплаты доступной для возврата
     */
    private float $overpaymentRefund;

    private bool $isPossiblePartialRefund;
    private bool $isPossibleOverpaymentRefund;
    private bool $isPossibleFullRefund;
    private static array $refundStatuses;
    private static array $refundTypes;

    /**
     * @return bool
     */
    public function isPossiblePartialRefund(): bool
    {
        if (empty($this->isPossiblePartialRefund)) {
            $issetActiveRefundRequests = (new RefundPartial())->issetActiveRefundRequests();
            if ($issetActiveRefundRequests) {
                $this->isPossibleOverpaymentRefund = false;
                return $this->isPossibleOverpaymentRefund;
            }

            $hasAtLeastOneRealisation = $this->hasAtLeastOneRealisation();
            if (!$hasAtLeastOneRealisation) {
                $this->isPossiblePartialRefund = false;
                return $this->isPossiblePartialRefund;
            }
        }
        return $this->isPossiblePartialRefund;
    }

    /**
     * @return bool
     */
    private function isPossibleOverpaymentRefund(\Bitrix\Sale\Order $order): bool
    {
        if (empty($this->isPossibleOverpaymentRefund)) {
            $orderId = $order->getId();
            $issetActiveRefundRequests = (new RefundOverpayment())->issetActiveRefundRequests($orderId, 1, 1);
            if ($issetActiveRefundRequests) {
                $this->isPossibleOverpaymentRefund = false;
                return $this->isPossibleOverpaymentRefund;
            }

            $hasAllRealisations = $this->hasAllRealisations();
            if (!$hasAllRealisations) {
                $this->isPossibleOverpaymentRefund = false;
                return $this->isPossibleOverpaymentRefund;
            }
        }

        $this->isPossibleOverpaymentRefund = true;
        return $this->isPossibleOverpaymentRefund;
    }

    /**
     * @return bool
     */
    public function isPossibleFullRefund(\Bitrix\Sale\Order $order): bool
    {
        if (empty($this->isPossibleFullRefund)) {
            $orderId = $order->getId();
            $issetActiveRefundRequests = (new RefundFull())->issetActiveRefundRequests($orderId, 1, 1);
            if ($issetActiveRefundRequests) {
                $this->isPossibleFullRefund = false;
                return $this->isPossibleFullRefund;
            }

            $hasAllRealisations = $this->hasAllRealisations();
            if (!$hasAllRealisations) {
                $this->isPossibleFullRefund = false;
                return $this->isPossibleFullRefund;
            }
        }
        return $this->isPossibleFullRefund;
    }

    /**
     * @return array
     */
    public function getRealisations(\Bitrix\Sale\Order $order): array
    {
        if (empty($this->realisations)) {
            $this->realisations = $this->getRealisedElements($order);
        }
        return $this->realisations;
    }

    /**
     * @return array
     */
    public static function getRefundStatuses(): array
    {
        if (empty(self::$refundStatuses)) {
            $refundStatusesRepository = RefundStatusesRepository::getEntity();
            $queryRefundStatuses = $refundStatusesRepository::getList(['filter' => [], 'select' => ['*']]);
            while ($refundStatus = $queryRefundStatuses->fetch()) {
                self::$refundStatuses[$refundStatus['UF_STATUS_NAME']] = $refundStatus['ID'];
            }

        }
        return self::$refundStatuses;
    }

    /**
     * @return array
     */
    public static function getRefundTypes(): array
    {
        if (empty(self::$refundTypes)) {
            $RefundTypesRepository = RefundTypesRepository::getEntity();
            $queryRefundTypes = $RefundTypesRepository::getList(['filter' => [], 'select' => ['*']]);
            while ($refundType = $queryRefundTypes->fetch()) {
                self::$refundTypes[$refundType['UF_REFUND_TYPE_NAME']] = $refundType['ID'];
            }
        }
        return self::$refundTypes;
    }

    /**
     * @return bool
     */
    public function hasAllRealisations(\Bitrix\Sale\Order $order): bool
    {
        $realisations = $this->getRealisations($order);
        if (empty($realisations['NOT_REALISED_PRODUCTS'])) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    private function hasAtLeastOneRealisation(\Bitrix\Sale\Order $order): bool
    {
        $realisations = $this->getRealisations($order);
        return !empty($realisations);
    }

    /**
     * @return array
     */
    public function getPartialRefundElements(\Bitrix\Sale\Order $order): array
    {
        $realisations = $this->getRealisations($order);

        $isPossiblePartialRefund = $this->isPossiblePartialRefund();
        if (!$isPossiblePartialRefund) {
            return [];
        }

        return $realisations;
    }

    /**
     *
     */
    private function getRealisedElements(\Bitrix\Sale\Order $order, $shipmentsId): array
    {
        $result = [];
        $realisations = (new ShipmentRealisationsRepository())->getRealisations(
            ['=UF_SHIPMENT' => $shipmentsId],
            ['UF_EXTERNALID_OTGR', 'UF_DATE']
        );
        if (empty($realisations)) {
            return [];
        }

        $realisationDates = array_column($realisations, 'UF_DATE', 'UF_EXTERNALID_OTGR');
        $realisationLines = (new ShipmentRealisationLinesRepository())->getRealisationLines(
            ['=UF_EXTERNALID_OTGR' => array_column($realisations, 'UF_EXTERNALID_OTGR')]
        );
        if (empty($realisationLines)) {
            return [];
        }

        $products = $this->getCatalogElements($order);
        if (empty($products)) {
            return [];
        }

        $refundedProducts = [];

        foreach ($realisationLines as $realisationLine) {
            $realisationIndex = $realisationLine['UF_EXTERNALID_OTGR'];
            $productIndex = $realisationLine['UF_EXTERNALID'] . $realisationLine['UF_EXTERNALID_N'];
            $result[$realisationIndex]['REALISATION_DATE'] = $realisationDates[$realisationIndex];
            $realisationLine = [
                'PRODUCT_ID' => $products[$productIndex]['ID'],
                'PRODUCT_NAME' => $products[$productIndex]['NAME'],
                'DETAIL_PAGE_URL' => $products[$productIndex]['DETAIL_PAGE_URL'],
                'PRODUCT_WEIGHT' => $realisationLine['UF_QTY'],
                'PRODUCT_PRICE' => $realisationLine['UF_TOTAL'],
                'REFUND_ID' => $realisationLine['UF_REFUND_ID'],
            ];
            $result[$realisationIndex]['REALISATION_LINES'][] = $realisationLine;
            if (!empty($realisationLine['REFUND_ID'])) {
                $refundedProducts[] = $realisationLine;
            }
            unset($products[$productIndex]);
        }

        $result['NOT_REALISED_PRODUCTS'] = $products;
        $result['REFUNDED_PRODUCTS'] = $refundedProducts;
        return $result;
    }

    private function getCatalogElements(\Bitrix\Sale\Order $order): array
    {
        $productIds = $this->getBitrixOrderProductsIds($order);
        if (empty($productIds)) {
            return [];
        }
        $products = [];
        $queryCatalogProducts = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => Core::IBLOCK_CODE_CATALOG,
                'ID' => $productIds,
            ],
            false,
            false,
            ['IBLOCK_ID', 'ID', 'NAME', 'DETAIL_PAGE_URL', 'PROPERTY_EXTERNALID', 'PROPERTY_EXTERNALID_N']
        );
        while ($catalogProduct = $queryCatalogProducts->fetch()) {
            $products[$catalogProduct['PROPERTY_EXTERNALID_VALUE'] . $catalogProduct['PROPERTY_EXTERNALID_N_VALUE']] = [
                'ID' => $catalogProduct['ID'],
                'NAME' => $catalogProduct['NAME'],
                'DETAIL_PAGE_URL' => $catalogProduct['DETAIL_PAGE_URL'],
                'PROPERTY_EXTERNALID_VALUE' => $catalogProduct['PROPERTY_EXTERNALID_VALUE'],
                'PROPERTY_EXTERNALID_N_VALUE' => $catalogProduct['PROPERTY_EXTERNALID_N_VALUE'],
            ];
        }
        return $products;
    }

    /**
     * @param \Bitrix\Sale\Order $order
     * @return array
     */
    public function getBitrixOrderProductsIds(\Bitrix\Sale\Order $order): array
    {
        $productIds = [];
        $basket = $order->getBasket();
        foreach ($basket as $basketItem) {
            $productIds[] = $basketItem->getProductId();
        }
        return $productIds;
    }
}
