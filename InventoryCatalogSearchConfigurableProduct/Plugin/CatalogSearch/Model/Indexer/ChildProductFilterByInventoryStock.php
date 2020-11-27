<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryCatalogSearchConfigurableProduct\Plugin\CatalogSearch\Model\Indexer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Action\DataProvider;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventoryIndexer\Model\StockIndexTableNameResolverInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Filter configurable products by stock status.
 */
class ChildProductFilterByInventoryStock
{
    /**
     * @var StockConfigurationInterface
     */
    private $stockConfiguration;

    /**
     * @var StockIndexTableNameResolverInterface
     */
    private $stockIndexTableNameResolver;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private $stockByWebsiteIdResolver;

    /**
     * @var DefaultStockProviderInterface
     */
    private $defaultStockProvider;

    /**
     * @var StoreRepositoryInterface
     */
    private $storeRepository;

    /**
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * @var Config
     */
    private $eavConfig;

    /**
     * @param StockConfigurationInterface $stockConfiguration
     * @param StockIndexTableNameResolverInterface $stockIndexTableNameResolver
     * @param ResourceConnection $resourceConnection
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param DefaultStockProviderInterface $defaultStockProvider
     * @param StoreRepositoryInterface $storeRepository
     * @param MetadataPool $metadataPool
     * @param Config $eavConfig
     */
    public function __construct(
        StockConfigurationInterface $stockConfiguration,
        StockIndexTableNameResolverInterface $stockIndexTableNameResolver,
        ResourceConnection $resourceConnection,
        StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        DefaultStockProviderInterface $defaultStockProvider,
        StoreRepositoryInterface $storeRepository,
        MetadataPool $metadataPool,
        Config $eavConfig
    ) {
        $this->stockConfiguration = $stockConfiguration;
        $this->stockIndexTableNameResolver = $stockIndexTableNameResolver;
        $this->resourceConnection = $resourceConnection;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;
        $this->defaultStockProvider = $defaultStockProvider;
        $this->storeRepository = $storeRepository;
        $this->metadataPool = $metadataPool;
        $this->eavConfig = $eavConfig;
    }

    /**
     * Filter out of stock options for configurable product.
     *
     * @param DataProvider $dataProvider
     * @param array $indexData
     * @param array $productData
     * @param int $storeId
     * @return array|void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforePrepareProductIndex(
        DataProvider $dataProvider,
        $indexData,
        $productData,
        $storeId
    ) {
        if ($this->stockConfiguration->isShowOutOfStock($storeId)) {
            return;
        }

        $store = $this->storeRepository->getById($storeId);
        $stock = $this->stockByWebsiteIdResolver->execute((int)$store->getWebsiteId());
        $stockId = $stock->getStockId();
        if ($this->defaultStockProvider->getId() !== $stockId) {
            $productIds = array_keys($indexData);
            $stockTable = $this->stockIndexTableNameResolver->execute($stockId);
            $stockStatuses = $this->getStockStatusesFromCustomStock($productIds, $stockTable);
            $indexData = array_intersect_key($indexData, $stockStatuses);
            return [
                $indexData,
                $productData,
                $storeId,
            ];
        }
    }

    /**
     * Get product stock statuses on custom stock.
     *
     * @param array $productIds
     * @param string $stockTable
     * @return array
     */
    private function getStockStatusesFromCustomStock(array $productIds, string $stockTable): array
    {
        $connection = $this->resourceConnection->getConnection();
        if (!$connection->isTableExists($stockTable)) {
            return [];
        }

        $select = $connection->select();
        $select->from(
            ['product' => $this->resourceConnection->getTableName('catalog_product_entity')],
            ['entity_id']
        );
        $select->joinInner(
            ['stock' => $stockTable],
            'product.sku = stock.sku',
            ['is_salable']
        );
        $select->where('product.entity_id IN (?)', $productIds);
        $select->where('stock.is_salable = ?', 1);
        $this->addStockFilterByChildProducts($select, $stockTable);
        return $connection->fetchAssoc($select);
    }

    /**
     * Filtering by stock status of configurable child products to select
     *
     * @param Select $select
     * @param string $stockTable
     * @return void
     */
    private function addStockFilterByChildProducts(Select $select, string $stockTable)
    {
        $connection = $this->resourceConnection->getConnection();
        $metadata = $this->metadataPool->getMetadata(ProductInterface::class);
        $linkField = $metadata->getLinkField();
        $existsSelect = $connection->select()->from(
            ['product_link_configurable' => $this->resourceConnection->getTableName('catalog_product_super_link')]
        );
        $existsSelect->join(
            ['product_child' => $this->resourceConnection->getTableName('catalog_product_entity')],
            'product_child.entity_id = product_link_configurable.product_id'
        );
        $existsSelect->join(
            ['child_product_status' => $this->resourceConnection->getTableName('catalog_product_entity_int')],
            "product_child.{$linkField} = child_product_status.{$linkField}"
        )->where('child_product_status.attribute_id = ?', $this->getAttribute('status')->getId())
            ->where('child_product_status.value = 1')
            ->where('stock_status_index_child.is_salable = 1');
        $existsSelect->join(
            ['stock_status_index_child' => $stockTable],
            'product_child.sku = stock_status_index_child.sku'
        )->where(
            'product_link_configurable.parent_id = product.entity_id'
        );
        $typeConfigurable = Configurable::TYPE_CODE;
        $select->where(
            "product.type_id != '{$typeConfigurable}' OR EXISTS ({$existsSelect->assemble()})"
        );
    }

    /**
     * Retrieve catalog_product attribute instance by attribute code
     *
     * @param string $attributeCode
     * @return Attribute
     */
    private function getAttribute($attributeCode)
    {
        return $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
    }
}
