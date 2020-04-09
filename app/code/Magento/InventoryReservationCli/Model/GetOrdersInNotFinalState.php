<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryReservationCli\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Traversable;

/**
 * Get list of orders, which are not in any of the final states (Complete, Closed, Canceled).
 */
class GetOrdersInNotFinalState
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var GetCompleteOrderStatusList
     */
    private $getCompleteOrderStatusList;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param GetCompleteOrderStatusList $getCompleteOrderStatusList
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        GetCompleteOrderStatusList $getCompleteOrderStatusList
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->getCompleteOrderStatusList = $getCompleteOrderStatusList;
    }

    /**
     * Get list of orders
     *
     * @param int $bunchSize
     * @param int $page
     * @return Traversable|OrderInterface[]
     */
    public function execute(int $bunchSize = 50, int $page = 1): Traversable
    {
        /** @var SearchCriteriaInterface $filter */
        $filter = $this->searchCriteriaBuilder
            ->addFilter('state', $this->getCompleteOrderStatusList->execute(), 'nin')
            ->setPageSize($bunchSize)
            ->setCurrentPage($page)
            ->create();

        $orderSearchResult = $this->orderRepository->getList($filter);

        foreach ($orderSearchResult->getItems() as $item) {
            yield $item->getEntityId() => $item;
        }

        gc_collect_cycles();
    }
}
