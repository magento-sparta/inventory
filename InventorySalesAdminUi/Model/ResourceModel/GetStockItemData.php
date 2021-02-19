<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventorySalesAdminUi\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventorySalesApi\Model\GetStockItemDataInterface;
use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;

/**
 * Retrieve stock item data to show salable qty in the admin area
 */
class GetStockItemData implements GetStockItemDataInterface
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var GetProductIdsBySkusInterface
     */
    private $getProductIdsBySkus;

    /**
     * @var DefaultStockProviderInterface
     */
    private $defaultStockProvider;

    /**
     * @var GetStockItemDataInterface
     */
    private $getCustomStockItemData;

    /**
     * @param ResourceConnection $resource
     * @param DefaultStockProviderInterface $defaultStockProvider
     * @param GetProductIdsBySkusInterface $getProductIdsBySkus
     * @param GetStockItemDataInterface $getCustomStockItemData
     */
    public function __construct(
        ResourceConnection $resource,
        DefaultStockProviderInterface $defaultStockProvider,
        GetProductIdsBySkusInterface $getProductIdsBySkus,
        GetStockItemDataInterface $getCustomStockItemData
    ) {
        $this->resource = $resource;
        $this->defaultStockProvider = $defaultStockProvider;
        $this->getProductIdsBySkus = $getProductIdsBySkus;
        $this->getCustomStockItemData = $getCustomStockItemData;
    }

    /**
     * @inheritdoc
     */
    public function execute(string $sku, int $stockId): ?array
    {
        if ($this->defaultStockProvider->getId() !== $stockId) {
            return $this->getCustomStockItemData->execute($sku, $stockId);
        }

        $productId = current($this->getProductIdsBySkus->execute([$sku]));
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('cataloginventory_stock_item'),
                [
                    GetStockItemDataInterface::QUANTITY => 'qty',
                    GetStockItemDataInterface::IS_SALABLE => 'is_in_stock',
                ]
            )->where(
                'product_id = ?',
                $productId
            );

        try {
            return $connection->fetchRow($select) ?: null;
        } catch (\Exception $e) {
            throw new LocalizedException(__('Could not receive Stock Item data'), $e);
        }
    }
}
