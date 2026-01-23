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

    console.log('[AgenticPayments][Blocks] JS loaded');    console.log("hi");

    const Content = createElement(
        'div',
        null,
        'Pay via Agentic'
    );

    console.log("hi");
    console.log(Content);

    const Edit = createElement(
        'div',
        null,
        'Agentic payment method'
    );

    registerPaymentMethod({
        name: 'agentic',
        label: 'Agentic (programmatic)',
        ariaLabel: 'Pay with Agentic',
        canMakePayment: () => true,
        content: Content,
        edit: Edit,
    });

    console.log('[AgenticPayments][Blocks] registerPaymentMethod executed');
} )();
