( function () {
    if (
        ! window.wc ||
        ! window.wc.wcBlocksRegistry ||
        ! window.wp ||
        ! window.wp.element
    ) {
        return;
    }

    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement } = window.wp.element;

    console.log('[AgenticPayments][Blocks] JS loaded');

    registerPaymentMethod({
        name: 'agentic',
        label: 'Agentic (programmatic)',
        ariaLabel: 'Pay with Agentic',
        canMakePayment: () => true,
        content: () =>
            createElement('div', null, 'Pay via Agentic'),
        edit: () =>
            createElement('div', null, 'Agentic payment method'),
    });

    console.log('[AgenticPayments][Blocks] registerPaymentMethod executed');
} )();
