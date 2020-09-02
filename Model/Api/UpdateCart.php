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
 * @copyright  Copyright (c) 2017-2020 Bolt Financial, Inc (https://www.bolt.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Bolt\Boltpay\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Exception as WebApiException;
use Bolt\Boltpay\Api\UpdateCartInterface;
use Bolt\Boltpay\Model\Api\UpdateCartCommon;
use Bolt\Boltpay\Model\Api\UpdateCartContext;
use Bolt\Boltpay\Model\Api\UpdateDiscountTrait;
use Bolt\Boltpay\Model\ErrorResponse as BoltErrorResponse;
use Bolt\Boltpay\Api\Data\CartDataInterfaceFactory;
use Bolt\Boltpay\Api\Data\UpdateCartResultInterfaceFactory;
use Bolt\Boltpay\Helper\Session as SessionHelper;

/**
 * Class UpdateCart
 * 
 * @package Bolt\Boltpay\Model\Api
 */
class UpdateCart extends UpdateCartCommon implements UpdateCartInterface
{
    use UpdateDiscountTrait { __construct as private UpdateDiscountTraitConstructor; }

    /**
     * @var CartDataInterfaceFactory
     */
    protected $cartDataFactory;

    /**
     * @var UpdateCartResultInterfaceFactory
     */
    protected $updateCartResultFactory;
    
    /**
     * @var array
     */
    protected $cartRequest;
    
    /**
     * @var SessionHelper
     */
    protected $sessionHelper;
    
    
    /**
     * UpdateCart constructor.
     *
     * @param UpdateCartContext $updateCartContext
     * @param CartDataInterfaceFactory $cartDataFactory
     * @param UpdateCartResultInterfaceFactory $updateCartResultFactory
     */
    final public function __construct(
        UpdateCartContext $updateCartContext,
        CartDataInterfaceFactory $cartDataFactory,
        UpdateCartResultInterfaceFactory $updateCartResultFactory
    ) {
        parent::__construct($updateCartContext);
        $this->UpdateDiscountTraitConstructor($updateCartContext);
        $this->cartDataFactory = $cartDataFactory;
        $this->updateCartResultFactory = $updateCartResultFactory;
        $this->sessionHelper = $updateCartContext->getSessionHelper();
    }

    /**
     * Update cart with items and discounts.
     *
     * @api
     * @param mixed $cart
     * @param mixed $add_items
     * @param mixed $remove_items
     * @param mixed $discount_codes_to_add
     * @param mixed $discount_codes_to_remove
     * @return \Bolt\Boltpay\Api\Data\UpdateCartResultInterface
     */
    public function execute($cart, $add_items = null, $remove_items = null, $discount_codes_to_add = null, $discount_codes_to_remove = null)
    {
        try {
            $this->cartRequest = $cart;
            
            // TODO the display_id will be updated in this PR https://github.com/BoltApp/bolt-magento2/pull/863, and we need to adjust per change
            list($incrementId, $immutableQuoteId) = array_pad(
                explode(' / ', $cart['display_id']),
                2,
                null
            );
            $parentQuoteId = $cart['order_reference'];
            
            $result = $this->validateQuote($parentQuoteId, $immutableQuoteId, $incrementId);
            
            if(!$result){
                // Already sent a response with error, so just return.
                return false;
            }
            
            list($parentQuote, $immutableQuote) = $result;
            
            $storeId = $parentQuote->getStoreId();
            $websiteId = $parentQuote->getStore()->getWebsiteId();

            $this->preProcessWebhook($storeId);
            
            $parentQuote->getStore()->setCurrentCurrencyCode($parentQuote->getQuoteCurrencyCode());
            
            // Load logged in customer checkout and customer sessions from cached session id.
            // Replace the quote with $parentQuote in checkout session.
            $this->sessionHelper->loadSession($parentQuote);
            
            if (!empty($cart['shipments'][0]['reference'])) {
                $this->setShipment($cart['shipments'][0], $immutableQuote);
                $this->setShipment($cart['shipments'][0], $parentQuote);
            }

            // TODO : add/remove giftcard
            // TODO : cache issue https://github.com/BoltApp/bolt-magento2/pull/833
            
            // Add discounts
            if( !empty($discount_codes_to_add) ){
                // Get the coupon code
                $discount_code = $discount_codes_to_add[0];
                $couponCode = trim($discount_code);
                
                $coupon = $this->verifyCouponCode($couponCode, $websiteId, $storeId);
                if( ! $coupon ){
                    // Already sent a response with error, so just return.
                    return false;
                }              

                $result = $this->applyDiscount($couponCode, $coupon, $parentQuote);
    
                if (!$result) {
                    // Already sent a response with error, so just return.
                    return false;
                }    
            }
  
            // Remove discounts
            if( !empty($discount_codes_to_remove) ){
                $discount_code = $discount_codes_to_remove[0];
                $couponCode = trim($discount_code);

                $discounts = $this->getQuoteDiscounts($parentQuote);

                if(empty($discounts)){
                    $this->sendErrorResponse(
                        BoltErrorResponse::ERR_CODE_INVALID,
                        'Coupon code does not exist!',
                        422,
                        $parentQuote
                    );
                    return false;
                }
                
                $discounts = array_column($discounts, 'discount_category', 'reference');
             
                $result = $this->removeDiscount($couponCode, $discounts, $parentQuote, $websiteId, $storeId);
                
                if (!$result) {
                    // Already sent a response with error, so just return.
                    return false;
                }
            }

            $this->cartHelper->replicateQuoteData($parentQuote, $immutableQuote);
           
            $result = $this->generateResult($immutableQuote);
                
            $this->sendSuccessResponse($result);
            
        } catch (WebApiException $e) {
            $this->bugsnag->notifyException($e);
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                $e->getHttpCode(),
                ($immutableQuote) ? $immutableQuote : null
            );

            return false;
        } catch (LocalizedException $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                500
            );

            return false;
        } catch (\Exception $e) {
            $this->sendErrorResponse(
                BoltErrorResponse::ERR_SERVICE,
                $e->getMessage(),
                500
            );

            return false;
        }

        return true;
    }
    
    /**
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    protected function getQuoteDiscounts($quote)
    {
        $is_has_shipment = !empty($this->cartRequest['shipments'][0]['reference']);
        list ($discounts, ,) = $this->cartHelper->collectDiscounts(0, 0, $is_has_shipment, $quote);
        return $discounts;
    }
    
    /**
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    protected function getQuoteCart($quote)
    {
        $has_shipment = !empty($this->cartRequest['shipments'][0]['reference']);
        return $this->cartHelper->getCartData($has_shipment, null, $quote);
    }
    
    /**
     * @param int        $errCode
     * @param string     $message
     * @param int        $httpStatusCode
     * @param null|Quote $quote
     *
     * @return void
     * @throws \Exception
     */
    protected function sendErrorResponse($errCode, $message, $httpStatusCode, $quote = null)
    {
        $additionalErrorResponseData = [];
        if ($quote) {
            $additionalErrorResponseData = $this->getQuoteCart($quote);
        }

        $encodeErrorResult = $this->errorResponse
            ->prepareUpdateCartErrorMessage($errCode, $message, $additionalErrorResponseData);

        $this->logHelper->addInfoLog('### sendErrorResponse');
        $this->logHelper->addInfoLog($encodeErrorResult);

        $this->response->setHttpResponseCode($httpStatusCode);
        $this->response->setBody($encodeErrorResult);
        $this->response->sendResponse();
    }

    /**
     * @param array $result
     * @param Quote $quote
     * @return array
     * @throws \Exception
     */
    protected function sendSuccessResponse($result)
    {
        $this->logHelper->addInfoLog('### sendSuccessResponse');
        $this->logHelper->addInfoLog(json_encode($result));
        $this->logHelper->addInfoLog('=== END ===');
        
        $this->response->setBody(json_encode($result));
        $this->response->sendResponse();
    }
    
    /**
     * @param Quote $quote
     * @param array $cart
     * @return UpdateCartResultInterface
     * @throws \Exception
     */
    public function generateResult($quote)
    {
        $cartData = $this->cartDataFactory->create();        
        $quoteCart = $this->getQuoteCart($quote);
      
        $cartData->setDisplayId($quoteCart['display_id']);
        $cartData->setCurrency($quoteCart['currency']);
        $cartData->setItems($quoteCart['items']);
        $cartData->setDiscounts($quoteCart['discounts']);
        $cartData->setTotalAmount($quoteCart['total_amount']);
        $cartData->setTaxAmount($quoteCart['tax_amount']);
        $cartData->setOrderReference($quoteCart['order_reference']);
        $cartData->setShipments( (!empty($quoteCart['shipments'])) ? $quoteCart['shipments'] : [] );

        $updateCartResult = $this->updateCartResultFactory->create();
        $updateCartResult->setOrderCreate($cartData);
        $updateCartResult->setOrderReference($quoteCart['order_reference']);
        $updateCartResult->setStatus('success');

        return $updateCartResult->getCartResult();
    }
    
}