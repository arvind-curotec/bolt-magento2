<?php
/**
 * Bolt magento2 plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category   Bolt
 * @package    Bolt_Boltpay
 * @copyright  Copyright (c) 2017-2022 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace Bolt\Boltpay\Plugin\Magento\Catalog\Api;

use Bolt\Boltpay\Api\Data\ProductEventInterface;
use Bolt\Boltpay\Api\ProductEventManagerInterface;
use Bolt\Boltpay\Helper\Config;
use Bolt\Boltpay\Logger\Logger;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Catalog ingestion product event processor after catalog product delete
 */
class ProductRepositoryPlugin
{
    /**
     * @var ProductEventManagerInterface
     */
    private $productEventManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @param ProductEventManagerInterface $productEventManager
     * @param Config $config
     * @param Logger $logger
     */
    public function __construct(
        ProductEventManagerInterface $productEventManager,
        Config $config,
        Logger $logger
    ) {
        $this->productEventManager = $productEventManager;
        $this->config = $config;
        $this->logger = $logger;
    }


    /**
     * Publish bolt product event after product removing
     *
     * @param ProductRepositoryInterface $subject
     * @param callable $proceed
     * @param ProductInterface $product
     * @return bool
     */
    public function aroundDelete(
        ProductRepositoryInterface $subject,
        callable $proceed,
        ProductInterface $product
    ): bool {
        $websiteIds = $product->getWebsiteIds();
        $result = $proceed($product);
        foreach ($websiteIds as $websiteId) {
            if ($this->config->getIsCatalogIngestionEnabled($websiteId)) {
                try {
                    $this->productEventManager->publishProductEvent(
                        $product->getId(),
                        ProductEventInterface::TYPE_DELETE
                    );
                    //break, because product event already created and future websites check is not needed
                    break;
                } catch (\Exception $e) {
                    $this->logger->critical($e);
                }
            }
        }
        return $result;
    }
}
