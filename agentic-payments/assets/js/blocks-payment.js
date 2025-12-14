import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { createElement } from '@wordpress/element';

console.log('[AgenticPayments][DBG] Agentic Blocks JS loaded');

registerPaymentMethod({
    name: 'agentic', // must match your gateway ID
    label: 'Agentic (programmatic)',
    ariaLabel: 'Pay with Agentic',
    canMakePayment: () => true, // always available if enabled
    content: () => createElement('div', null, 'Pay via Agentic'),
    edit: () => createElement('div', null, 'Agentic payment method'),
});

console.log('[AgenticPayments][DBG] Agentic registerPaymentMethod called');
