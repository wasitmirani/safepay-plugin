var coreSettings = null;
if (wcSettings.hasOwnProperty('paymentMethodData')) {
    const methodSettings = wcSettings.paymentMethodData;
    if (methodSettings.hasOwnProperty('safepay_gateway')) {
        var coreSettings = wcSettings.paymentMethodData.safepay_gateway;
    }
}
var settings = coreSettings ? wcSettings.paymentMethodData.safepay_gateway : window.wc.wcSettings.getSetting('safepay_gateway', {});
const safepayLabel = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('safepay', 'safepay_gateway');
const imageUrl = settings.icon;
console.log('settings',settings)
console.log('imageUrl',imageUrl);
const safepayContent = () => {
    return window.wp.htmlEntities.decodeEntities(settings.description || 'Credit/Debit Cards, UPI, Account/Wallets and RAAST');
}
console.log(safepayContent);
console.log('settings',settings);
const safepay_Block_Gateway = {
    name: 'safepay_gateway',
    label: safepayLabel,
    content: Object(window.wp.element.createElement)(
        'div', // Container element
        null,
        Object(window.wp.element.createElement)(
            'label', // Label element
            safepayLabel,
            Object(window.wp.element.createElement)(
                'img', // Image element
                { src: imageUrl, alt: 'SafePay Image' } // Replace 'URL_TO_YOUR_IMAGE' with the actual URL of your image
            )
        ),
        Object(window.wp.element.createElement)(
            'p', // Paragraph element for description
            null,
            safepayContent()
        )
    ),
    edit: Object(window.wp.element.createElement)(
        'div', // Container element
        null,
        Object(window.wp.element.createElement)(
            'img', // Image element
            { src: imageUrl, alt: 'SafePay Image' } // Replace 'URL_TO_YOUR_IMAGE' with the actual URL of your image
        ),
        Object(window.wp.element.createElement)(
            'p', // Paragraph element for description
            null,
            safepayContent()
        )
    ),
    canMakePayment: () => true,
    ariaLabel: safepayLabel,
    supports: {
        features: settings.supports,
    },
};
console.log('safepay_Block_Gateway',safepay_Block_Gateway);
console.log('window.wc.wcBlocksRegistry', window.wc.wcBlocksRegistry);
window.wc.wcBlocksRegistry.registerPaymentMethod(safepay_Block_Gateway);