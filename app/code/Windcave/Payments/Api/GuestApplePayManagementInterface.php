<?php

namespace Windcave\Payments\Api;

/**
 * Payment method management interface for guest carts.
 * @api
 */
interface GuestApplePayManagementInterface
{
     /**
      * Returns PxPay HPP link stored with the last created order.
      *
      * @param string $validationUrl.
      * @param string $domainName
      * @return string result
      */
    public function performValidation($validationUrl, $domainName);

    /**
     * Returns PxPay HPP link stored with the last created order.
     * @param string cartId
     * @param string email
     * @param string token
     * @param \Magento\Quote\Api\Data\PaymentInterface method
     * @param string billingAddress
     * @return string result
     */
    public function performPayment(
        $cartId,
        $email,
        $token,
        \Magento\Quote\Api\Data\PaymentInterface $method,
        $billingAddress = null
    );
}
