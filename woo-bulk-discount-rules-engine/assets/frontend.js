jQuery(function ($) {
    const cartForm = $("form.woocommerce-cart-form");

    // Listen to quantity changes
    cartForm.on("change", "input.qty", function () {
        const data = {
            action: "wbdre_update_cart",
            nonce: wbdre_front.nonce,
            cart: {},
        };

        // Collect quantities
        cartForm.find("input.qty").each(function () {
            const qty = $(this).val();
            const key = $(this).attr("name").match(/cart\\[(.*?)\\]/)[1];
            data.cart[key] = qty;
        });

        $.post(wbdre_front.ajax_url, data, function (resp) {
            if (resp.success) {
                // Replace cart totals
                $(".cart_totals").replaceWith(resp.data.totals);

                // Replace mini cart (if present)
                $(".widget_shopping_cart_content").html(resp.data.mini_cart);

                // Trigger WooCommerce events so totals update properly
                $(document.body).trigger("updated_cart_totals");
                $(document.body).trigger("wc_fragment_refresh");
            }
        });
    });
});
