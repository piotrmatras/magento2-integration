<?php

namespace Synerise\Integration\Observer;

use Magento\Framework\Event\ObserverInterface;
use Synerise\ApiClient\ApiException;

class CartStatus implements ObserverInterface
{
    const EVENT = 'sales_quote_save_after';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Synerise\Integration\Helper\Catalog
     */
    protected $catalogHelper;

    /**
     * @var \Synerise\Integration\Helper\Tracking
     */
    protected $trackingHelper;

    /**
     * @var \Synerise\Integration\Helper\Queue
     */
    protected $queueHelper;

    /**
     * @var \Synerise\Integration\Helper\Event
     */
    protected $eventHelper;

    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Synerise\Integration\Helper\Catalog $catalogHelper,
        \Synerise\Integration\Helper\Tracking $trackingHelper,
        \Synerise\Integration\Helper\Queue $queueHelper,
        \Synerise\Integration\Helper\Event $eventHelper
    ) {
        $this->logger = $logger;
        $this->catalogHelper = $catalogHelper;
        $this->trackingHelper = $trackingHelper;
        $this->queueHelper = $queueHelper;
        $this->eventHelper = $eventHelper;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->trackingHelper->isLiveEventTrackingEnabled(self::EVENT)) {
            return;
        }

        if ($this->trackingHelper->isAdminStore()) {
            return;
        }

        try {
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $observer->getQuote();
            $storeId = $quote->getStoreId();

            if (!$this->trackingHelper->getClientUuid() && !$quote->getCustomerEmail()) {
                return;
            }

            $cartStatusEvent = null;

            if ($this->trackingHelper->hasItemDataChanges($quote)) {
                $cartStatusEvent = $this->eventHelper->prepareCartStatusEvent($quote, (float) $quote->getSubtotal(), (int) $quote->getItemsQty());
            } elseif ($quote->dataHasChangedFor('reserved_order_id')) {
                $cartStatusEvent = $this->eventHelper->prepareCartStatusEvent($quote, 0, 0);
            }

            if ($cartStatusEvent) {
                if ($this->queueHelper->isQueueAvailable(self::EVENT, $storeId)) {
                    $this->queueHelper->publishEvent(self::EVENT, $cartStatusEvent, $storeId);
                } else {
                    $this->eventHelper->sendEvent(self::EVENT, $cartStatusEvent, $storeId);
                }
            }
        } catch (ApiException $e) {
        } catch (\Exception $e) {
            $this->logger->error('Synerise Error', ['exception' => $e]);
        }
    }
}
