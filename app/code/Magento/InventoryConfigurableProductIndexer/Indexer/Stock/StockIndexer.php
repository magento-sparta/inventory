<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryConfigurableProductIndexer\Indexer\Stock;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Indexer\SaveHandler\Batch;
use Magento\InventoryMultiDimensionalIndexerApi\Model\Alias;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexHandlerInterface;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexNameBuilder;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexStructureInterface;
use Magento\InventoryMultiDimensionalIndexerApi\Model\IndexTableSwitcherInterface;
use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventoryIndexer\Indexer\InventoryIndexer;
use Magento\InventoryIndexer\Indexer\Stock\GetAllStockIds;
use ArrayIterator;

class StockIndexer
{
    /**
     * Default batch size
     */
    private const BATCH_SIZE = 100;

    /**
     * @var GetAllStockIds
     */
    private $getAllStockIds;

    /**
     * @var IndexStructureInterface
     */
    private $indexStructure;

    /**
     * @var IndexHandlerInterface
     */
    private $indexHandler;

    /**
     * @var IndexNameBuilder
     */
    private $indexNameBuilder;

    /**
     * @var IndexDataByStockIdProvider
     */
    private $indexDataByStockIdProvider;

    /**
     * @var IndexTableSwitcherInterface
     */
    private $indexTableSwitcher;

    /**
     * @var DefaultStockProviderInterface
     */
    private $defaultStockProvider;

    /**
     * @var int
     */
    private $batchSize;

    /**
     * @var Batch
     */
    private $batch;

    /**
     * $indexStructure is reserved name for construct variable in index internal mechanism
     *
     * @param GetAllStockIds $getAllStockIds
     * @param IndexStructureInterface $indexStructure
     * @param IndexHandlerInterface $indexHandler
     * @param IndexNameBuilder $indexNameBuilder
     * @param IndexDataByStockIdProvider $indexDataByStockIdProvider
     * @param IndexTableSwitcherInterface $indexTableSwitcher
     * @param DefaultStockProviderInterface $defaultStockProvider
     * @param Batch|null $batch
     * @param int|null $batchSize
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) All parameters are needed for backward compatibility
     */
    public function __construct(
        GetAllStockIds $getAllStockIds,
        IndexStructureInterface $indexStructure,
        IndexHandlerInterface $indexHandler,
        IndexNameBuilder $indexNameBuilder,
        IndexDataByStockIdProvider $indexDataByStockIdProvider,
        IndexTableSwitcherInterface $indexTableSwitcher,
        DefaultStockProviderInterface $defaultStockProvider,
        ?PrepareIndexDataForClearingIndex $prepareIndexDataForClearingIndex = null,
        ?Batch $batch = null,
        ?int $batchSize = null
    ) {
        $this->getAllStockIds = $getAllStockIds;
        $this->indexStructure = $indexStructure;
        $this->indexHandler = $indexHandler;
        $this->indexNameBuilder = $indexNameBuilder;
        $this->indexDataByStockIdProvider = $indexDataByStockIdProvider;
        $this->indexTableSwitcher = $indexTableSwitcher;
        $this->defaultStockProvider = $defaultStockProvider;
        $this->batch = $batch ?: ObjectManager::getInstance()->get(Batch::class);
        $this->batchSize = $batchSize ?? self::BATCH_SIZE;
    }

    /**
     * @return void
     * @throws StateException
     */
    public function executeFull()
    {
        $stockIds = $this->getAllStockIds->execute();
        $this->executeList($stockIds);
    }

    /**
     * @param int $stockId
     * @return void
     * @throws StateException
     */
    public function executeRow(int $stockId)
    {
        $this->executeList([$stockId]);
    }

    /**
     * @param array $stockIds
     * @return void
     * @throws StateException
     */
    public function executeList(array $stockIds)
    {
        foreach ($stockIds as $stockId) {
            if ($this->defaultStockProvider->getId() === $stockId) {
                continue;
            }

            $mainIndexName = $this->indexNameBuilder
                ->setIndexId(InventoryIndexer::INDEXER_ID)
                ->addDimension('stock_', (string)$stockId)
                ->setAlias(Alias::ALIAS_MAIN)
                ->build();

            if (!$this->indexStructure->isExist($mainIndexName, ResourceConnection::DEFAULT_CONNECTION)) {
                $this->indexStructure->create($mainIndexName, ResourceConnection::DEFAULT_CONNECTION);
            }

            $indexData = $this->indexDataByStockIdProvider->execute((int)$stockId);

            foreach ($this->batch->getItems($indexData, $this->batchSize) as $batchData) {
                $batchIndexData = new ArrayIterator($batchData);
                $this->indexHandler->cleanIndex(
                    $mainIndexName,
                    $this->prepareIndexDataForClearingIndex->execute($batchIndexData),
                    ResourceConnection::DEFAULT_CONNECTION
                );

                $this->indexHandler->saveIndex(
                    $mainIndexName,
                    $batchIndexData,
                    ResourceConnection::DEFAULT_CONNECTION
                );
            }
        }
    }
}
