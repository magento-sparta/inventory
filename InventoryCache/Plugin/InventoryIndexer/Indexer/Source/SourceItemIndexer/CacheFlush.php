<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryCache\Plugin\InventoryIndexer\Indexer\Source\SourceItemIndexer;

use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventoryIndexer\Indexer\SourceItem\GetSkuListInStock;
use Magento\InventoryCache\Model\FlushCacheByProductIds;
use Magento\InventoryIndexer\Indexer\SourceItem\SourceItemIndexer;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Clean cache for corresponding products after source item reindex.
 */
class CacheFlush
{
    /**
     * @var FlushCacheByProductIds
     */
    private $flushCacheByIds;

    /**
     * @var GetSkuListInStock
     */
    private $getSkuListInStock;

    /**
     * @var DefaultStockProviderInterface
     */
    private $defaultStockProvider;

    /**
     * @var IsProductSalableInterface
     */
    private $isProductSalable;

    /**
     * @var GetProductIdsBySkusInterface
     */
    private $getGetProductIdsBySkus;

    /**
     * @param FlushCacheByProductIds $flushCacheByIds
     * @param GetSkuListInStock $getSkuListInStockToUpdate
     * @param DefaultStockProviderInterface $defaultStockProvider
     * @param IsProductSalableInterface $isProductSalable
     * @param GetProductIdsBySkusInterface $getGetProductIdsBySkus
     */
    public function __construct(
        FlushCacheByProductIds $flushCacheByIds,
        GetSkuListInStock $getSkuListInStockToUpdate,
        DefaultStockProviderInterface $defaultStockProvider,
        IsProductSalableInterface $isProductSalable,
        GetProductIdsBySkusInterface $getGetProductIdsBySkus
    ) {
        $this->flushCacheByIds = $flushCacheByIds;
        $this->getSkuListInStock = $getSkuListInStockToUpdate;
        $this->defaultStockProvider = $defaultStockProvider;
        $this->isProductSalable = $isProductSalable;
        $this->getGetProductIdsBySkus = $getGetProductIdsBySkus;
    }

    /**
     * Clean cache for specific products after source items reindex.
     *
     * @param SourceItemIndexer $subject
     * @param array $sourceItemIds
     * @param callable $proceed
     * @throws \Exception in case catalog product entity type hasn't been initialize.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecuteList(SourceItemIndexer $subject, callable $proceed, array $sourceItemIds)
    {
        $beforeSalableList = $this->getSalableStatuses($sourceItemIds);
        $proceed($sourceItemIds);
        $afterSalableList = $this->getSalableStatuses($sourceItemIds);
        $productsIdsToFlush = $this->getProductsIdsToFlush($beforeSalableList, $afterSalableList);
        if (!empty($productsIdsToFlush)) {
            $this->flushCacheByIds->execute($productsIdsToFlush);
        }
    }

    /**
     * Get salable statuses for products based on affected source items
     *
     * @param array $sourceItemIds
     * @return array
     */
    private function getSalableStatuses(array $sourceItemIds) : array
    {
        $result = [];
        $skuListInStockList = $this->getSkuListInStock->execute($sourceItemIds);
        foreach ($skuListInStockList as $skuListInStock) {
            $stockId = $skuListInStock->getStockId();
            if ($this->defaultStockProvider->getId() === $stockId) {
                continue;
            }
            $salableStatusList = $skuListInStock->getSkuList();

            foreach ($salableStatusList as $sku) {
                $isSalable = $this->isProductSalable->execute($sku, $stockId);
                $result[$sku] = [$stockId => $isSalable];
            }
        }
        return $result;
    }

    /**
     * Compares state before and after reindex, filter only products with changed state
     *
     * @param array $before
     * @param array $after
     * @return array
     */
    private function getProductsIdsToFlush(array $before, array $after) : array
    {
        $productIds = [];
        $productSkus = array_merge(
            array_diff(array_keys($before), array_keys($after)),
            array_diff(array_keys($after), array_keys($before))
        );
        foreach ($before as $sku => $salableData) {
            if (!in_array($sku, $productSkus)) {
                foreach ($salableData as $stockId => $isSalable) {
                    if (empty($after[$sku][$stockId])
                        || $before[$sku][$stockId] !== $after[$sku][$stockId]) {
                        $productSkus[] = $sku;
                    }
                }
            }
        }
        if (!empty($productSkus)) {
            $productSkus = array_unique($productSkus);
            foreach ($productSkus as $sku) {
                try {
                    $productId = $this->getGetProductIdsBySkus->execute([$sku]);
                    $productIds = array_merge($productIds, $productId);
                } catch (NoSuchEntityException $e) {
                    continue;
                }
            }
        }
        return $productIds;
    }
}
