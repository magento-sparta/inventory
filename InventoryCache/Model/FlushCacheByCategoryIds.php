<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryCache\Model;

use Magento\Framework\EntityManager\EventManager;
use Magento\Framework\Indexer\CacheContextFactory;
use Magento\Framework\App\CacheInterface;

/**
 * Clean cache for given category ids.
 */
class FlushCacheByCategoryIds
{
    /**
     * @var CacheContextFactory
     */
    private $cacheContextFactory;

    /**
     * @var EventManager
     */
    private $eventManager;

    /**
     * @var string
     */
    private $categoryCacheTag;

    /**
     * @var CacheInterface
     */
    private $appCache;

    /**
     * @param CacheContextFactory $cacheContextFactory
     * @param EventManager $eventManager
     * @param string $categoryCacheTag
     * @param CacheInterface $appCache
     */
    public function __construct(
        CacheContextFactory $cacheContextFactory,
        EventManager $eventManager,
        string $categoryCacheTag,
        CacheInterface $appCache
    ) {
        $this->cacheContextFactory = $cacheContextFactory;
        $this->eventManager = $eventManager;
        $this->categoryCacheTag = $categoryCacheTag;
        $this->appCache = $appCache;
    }

    /**
     * Clean cache for given category ids.
     *
     * @param array $categoryIds
     * @return void
     */
    public function execute(array $categoryIds): void
    {
        if ($categoryIds) {
            $cacheContext = $this->cacheContextFactory->create();
            $cacheContext->registerEntities($this->categoryCacheTag, $categoryIds);
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $cacheContext]);
            $this->appCache->clean($cacheContext->getIdentities());
        }
    }
}