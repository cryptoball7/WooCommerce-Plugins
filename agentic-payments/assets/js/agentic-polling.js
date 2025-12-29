(function () {
    if (typeof AgenticOrder === 'undefined') {
        return;
    }

    const orderId = AgenticOrder.orderId;

    console.log('[AgenticPayments] Polling order', orderId);

    const poll = async () => {
        try {
            const res = await fetch(
                `/wp-json/agentic/v1/order-status/${orderId}`
            );
            const data = await res.json();

            if (data.completed || data.status === 'completed') {
                console.log('[AgenticPayments] Order completed, reloading');
                window.location.reload();
            }
        } catch (e) {
            console.warn('[AgenticPayments] Poll error', e);
        }
    };

    setInterval(poll, 3000);
})();
