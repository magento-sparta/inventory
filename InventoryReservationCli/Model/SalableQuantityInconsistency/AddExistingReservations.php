<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryReservationCli\Model\SalableQuantityInconsistency;

use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Validation\ValidationException;
use Magento\InventoryReservationCli\Model\ResourceModel\GetOrderIncrementId;
use Magento\InventoryReservationCli\Model\ResourceModel\GetReservationsList;
use Magento\InventoryReservationsApi\Model\ReservationBuilderInterface;
use Magento\InventoryReservationsApi\Model\ReservationInterface;

/**
 * Add existing reservations
 */
class AddExistingReservations
{
    /**
     * @var GetReservationsList
     */
    private $getReservationsList;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var ReservationBuilderInterface
     */
    private $reservationBuilder;

    /**
     * @var GetOrderIncrementId
     */
    private $getOrderIncrementId;

    /**
     * @var ReservationInterface[]
     */
    private $reservationList;

    /**
     * @var string[]
     */
    private $orderIncrementIds = [];

    /**
     * @param GetReservationsList $getReservationsList
     * @param SerializerInterface $serializer
     * @param ReservationBuilderInterface $reservationBuilder
     * @param GetOrderIncrementId $getOrderIncrementId
     */
    public function __construct(
        GetReservationsList $getReservationsList,
        SerializerInterface $serializer,
        ReservationBuilderInterface $reservationBuilder,
        GetOrderIncrementId $getOrderIncrementId
    ) {
        $this->getReservationsList = $getReservationsList;
        $this->serializer = $serializer;
        $this->reservationBuilder = $reservationBuilder;
        $this->getOrderIncrementId = $getOrderIncrementId;
    }

    /**
     * Add existing reservations
     *
     * @param Collector $collector
     * @throws ValidationException
     */
    public function execute(Collector $collector): void
    {
        $reservations = $this->getFilteredReservations($collector);
        foreach ($reservations as $reservation) {
            $collector->addReservation($reservation);
        }
    }

    /**
     * Filter existing reservations by collector reservations
     *
     * @param Collector $collector
     * @return array
     */
    private function getFilteredReservations(Collector $collector): array
    {
        $result = [];
        $collectorItems = $collector->getItems();
        $isEmptyCollectorItems = empty($collector->getItems());
        $reservationList = $this->loadReservations();
        foreach ($reservationList as $key => $reservations) {
            if ($isEmptyCollectorItems || isset($collectorItems[$key])) {
                foreach ($reservations as $reservation) {
                    $result[] = $reservation;
                }
                unset($this->reservationList[$key]);
            }
        }

        return $result;
    }

    /**
     * Load existing reservations
     *
     * @return array
     */
    private function loadReservations(): array
    {
        if ($this->reservationList !== null) {
            return $this->reservationList;
        }

        $this->reservationList = [];
        $reservationList = $this->getReservationsList->execute();
        foreach ($reservationList as $reservation) {
            /** @var array $metadata */
            $metadata = $this->serializer->unserialize($reservation['metadata']);
            $orderType = $metadata['object_type'];
            if ($orderType !== 'order') {
                continue;
            }

            $this->loadOrderIncrementId($metadata);
            $stockId = (int)$reservation['stock_id'];
            $reservation = $this->reservationBuilder
                ->setMetadata($this->serializer->serialize($metadata))
                ->setStockId($stockId)
                ->setSku($reservation['sku'])
                ->setQuantity((float)$reservation['quantity'])
                ->build();

            $key = $metadata['object_increment_id'] . '-' . $stockId;
            $this->reservationList[$key][] = $reservation;
        }

        return $this->reservationList;
    }

    /**
     * Load order increment id by order id
     *
     * @param array $metadata
     * @return void
     */
    private function loadOrderIncrementId(array &$metadata): void
    {
        if (!empty($metadata['object_increment_id'])) {
            return;
        }

        $objectId = (int)$metadata['object_id'];
        if (!isset($this->orderIncrementIds[$objectId])) {
            $this->orderIncrementIds[$objectId] = $this->getOrderIncrementId->execute($objectId);
        }
        $metadata['object_increment_id'] = $this->orderIncrementIds[$objectId];
    }
}
