<?php

namespace Windcave\Payments\Api;

interface ApplePayManagementInterface
{
    /**
     * Validate the merchant and return the validationdata as string
     *
     * @param string $validationUrl.
     * @param string $domainName
     * @return string result
     */
    public function performValidation($validationUrl, $domainName);

    /**
     * Send the payment to the gateway and return the status
     *
     * @param string cartId
     * @param string token
     * @param \Magento\Quote\Api\Data\PaymentInterface method
     * @param string $billingAddress
     * @return string result
     */
    public function performPayment(
        $cartId,
        $token,
        \Magento\Quote\Api\Data\PaymentInterface $method,
        $billingAddress = null
    );
}
