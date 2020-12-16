<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryShippingAdminUi\Model;

use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Is source inventory of certain order is manageable
 */
class IsOrderSourceManageable
{
    /**
     * @var GetSkuFromOrderItemInterface
     */
    private $getSkuFromOrderItem;

    /**
     * @var GetStockItemConfigurationInterface
     */
    private $getStockItemConfiguration;

    /**
     * @var StockRepositoryInterface
     */
    private $stockRepository;

    /**
     * @var IsSourceItemManagementAllowedForProductTypeInterface
     */
    private $isSourceItemManagementAllowedForProductType;

    /**
     * @param GetSkuFromOrderItemInterface $productRepository
     * @param GetStockItemConfigurationInterface $getStockItemConfiguration
     * @param StockRepositoryInterface $stockRepository
     * @param IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType
     */
    public function __construct(
        GetSkuFromOrderItemInterface $productRepository,
        GetStockItemConfigurationInterface $getStockItemConfiguration,
        StockRepositoryInterface $stockRepository,
        IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType
    ) {
        $this->getSkuFromOrderItem = $productRepository;
        $this->getStockItemConfiguration = $getStockItemConfiguration;
        $this->stockRepository = $stockRepository;
        $this->isSourceItemManagementAllowedForProductType = $isSourceItemManagementAllowedForProductType;
    }

    /**
     * Check if source manageable for certain order
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function execute(OrderInterface $order): bool
    {
        $stocks = $this->stockRepository->getList()->getItems();
        $orderItems = $order->getItems();
        foreach ($orderItems as $orderItem) {
            $productType = $orderItem->getProduct()->getTypeId();
            if (!$this->isSourceItemManagementAllowedForProductType->execute($productType)) {
                continue;
            }

            /** @var StockInterface $stock */
            foreach ($stocks as $stock) {
                try {
                    $inventoryConfiguration = $this->getStockItemConfiguration->execute(
                        $this->getSkuFromOrderItem->execute($orderItem),
                        $stock->getStockId()
                    );
                } catch (\Magento\InventoryConfigurationApi\Exception\SkuIsNotAssignedToStockException $exception) {
                    // it's okay if a product is not assigned to a stock
                    continue;
                }

                if ($inventoryConfiguration->isManageStock()) {
                    return true;
                }
            }
        }

        return false;
    }
}
