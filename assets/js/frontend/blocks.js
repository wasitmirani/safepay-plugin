var coreSettings = null;
if (wcSettings.hasOwnProperty('paymentMethodData')) {
    const methodSettings = wcSettings.paymentMethodData;
    if (methodSettings.hasOwnProperty('safepay_gateway')) {
        var coreSettings = wcSettings.paymentMethodData.safepay_gateway;
    }
}
var settings = coreSettings ? wcSettings.paymentMethodData.safepay_gateway : window.wc.wcSettings.getSetting('safepay_gateway', {});
const safepayLabel = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('safepay', 'safepay_gateway');
const safepayContent = () => {
    return window.wp.htmlEntities.decodeEntities(settings.description || 'Credit/Debit Cards, UPI, Account/Wallets and RAAST');
};
const safepay_Block_Gateway = {
    name: 'safepay_gateway',
    label: safepayLabel,
    content: Object(window.wp.element.createElement)(safepayContent, null),
    edit: Object(window.wp.element.createElement)(safepayContent, null),
    canMakePayment: () => true,
    ariaLabel: safepayLabel,
    supports: {
        features: settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(safepay_Block_Gateway);