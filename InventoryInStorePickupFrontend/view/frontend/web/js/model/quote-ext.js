/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define([
    'ko',
    'Magento_InventoryInStorePickupFrontend/js/model/pickup-address-converter'
], function (ko, pickupAddressConverter) {
    'use strict';

    return function (quote) {
        var shippingAddress = quote.shippingAddress;

        /**
         * Subscribe to shipping method before it is resolved in checkout-data-resolver.js
         */
        quote.shippingMethod.subscribe(
            function () {
                var shippingMethod = quote.shippingMethod(),
                    pickUpAddress,
                    isStorePickup = shippingMethod !== null &&
                        shippingMethod['carrier_code'] === 'instore' &&
                        shippingMethod['method_code'] === 'pickup';

                if (quote.shippingAddress() &&
                    quote.shippingAddress().getType() !== 'store-pickup-address' &&
                    isStorePickup
                ) {
                    pickUpAddress = pickupAddressConverter.formatAddressToPickupAddress(quote.shippingAddress());

                    if (quote.shippingAddress() !== pickUpAddress) {
                        quote.shippingAddress(pickUpAddress);
                    }
                }
            }
        );

        /**
         * Makes sure that shipping address gets appropriate type when it points
         * to a store pickup location.
         */
        quote.shippingAddress = ko.pureComputed({
            /**
             * Return quote shipping address
             */
            read: function () {
                return shippingAddress();
            },

            /**
             * Set quote shipping address
             */
            write: function (address) {
                shippingAddress(
                    pickupAddressConverter.formatAddressToPickupAddress(address)
                );
            }
        });

        return quote;
    };
});
