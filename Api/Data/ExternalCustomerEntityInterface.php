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

namespace Bolt\Boltpay\Api\Data;

interface ExternalCustomerEntityInterface
{
    /**
     * @return string
     */
    public function getExternalID();

    /**
     * @param string $externalID
     *
     * @return void
     */
    public function setExternalID($externalID);

    /**
     * @return int
     */
    public function getCustomerID();

    /**
     * @param int $customerID
     *
     * @return void
     */
    public function setCustomerID($customerID);
}
