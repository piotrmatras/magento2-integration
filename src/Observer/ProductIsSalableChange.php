<?php

namespace Synerise\Integration\Observer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Synerise\Integration\Helper\DataStorage;
use Synerise\Integration\Helper\Tracking;
use Synerise\Integration\Model\Synchronization\Product as SyncProduct;

class ProductIsSalableChange implements ObserverInterface
{
    public const EVENT = 'product_is_salable_change';

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var DataStorage
     */
    protected $data;

    /**
     * @var Tracking
     */
    protected $trackingHelper;

    /**
     * @var SyncProduct
     */
    protected $syncProduct;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger,
        DataStorage $data,
        Tracking $trackingHelper,
        SyncProduct $syncProduct
    ) {
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        $this->data = $data;
        $this->trackingHelper = $trackingHelper;
        $this->syncProduct = $syncProduct;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->trackingHelper->isEventTrackingEnabled(self::EVENT)) {
            return;
        }

        $eventName = $observer->getEvent()->getName();

        try {
            $changedProducts = [];
            if ($eventName === 'sales_order_item_save_before') {
                $salesOrderItem = $observer->getData('item');
                $this->data->setData($salesOrderItem->getSku(), $salesOrderItem->getProduct());
            } elseif ($eventName === 'sales_order_item_save_after') {
                $salesOrderItem = $observer->getData('item');
                $product = $this->productRepository->get($salesOrderItem->getSku(), false, $salesOrderItem->getStoreId(), true);
                $productFromDataStorage = $this->data->getAndUnsetData($salesOrderItem->getSku());
                if ($productFromDataStorage &&
                    $product->getData('is_salable') !== $productFromDataStorage->getData('is_salable')) {
                    $changedProducts[] = $product;
                }
            } elseif ($eventName === 'sales_order_place_after') {
                $order = $observer->getData('order');
                $orderItems = $order->getAllItems();
                foreach ($orderItems as $orderItem) {
                    $product = $orderItem->getProduct();
                    $isSalable = $product->getIsSalable();
                    if (!$isSalable) {
                        $changedProducts[] = $product;
                    }
                }
            }

            if (!empty($changedProducts)) {
                try {
                    $this->syncProduct->addItemsToQueue(
                        $changedProducts
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Failed to add products to cron queue', ['exception' => $e]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('An error occurred in the ProductIsSalableChange observer', ['exception' => $e]);
        }
    }
}
