(function($){
    $(function(){

        function parseSelected($root){
            var selected = {};
            $root.find('.dpb-qty-input').each(function(){
                var $inp = $(this);
                var qty = parseInt($inp.val(), 10) || 0;
                var pid = $inp.attr('name').match(/\d+/);
                if (pid) pid = pid[0];
                if (qty > 0) selected[pid] = qty;
            });
            return selected;
        }

        function updateTotal($root) {
            var selected = parseSelected($root);
            var discount_type = $root.data('discount-type') || 'fixed';
            var discount_value = $root.data('discount-value') || 0;
            $('.dpb-total-value', $root).text( DPB.i18n.calculating );
            $.post( DPB.ajax_url, {
                action: 'dpb_calculate_price',
                nonce: DPB.nonce,
                selected: JSON.stringify(selected),
                discount_type: discount_type,
                discount_value: discount_value
            }, function(res){
                if (!res || !res.success) {
                    $('.dpb-total-value', $root).text( res && res.data && res.data.message ? res.data.message : '—' );
                    return;
                }
                $('.dpb-total-value', $root).text( res.data.formatted );
                if (res.data.out_of_stock) {
                    // warn user
                    if (!$root.find('.dpb-out-of-stock').length) {
                        $root.prepend('<div class="dpb-out-of-stock">' + DPB.i18n.out_of_stock + '</div>');
                    }
                } else {
                    $root.find('.dpb-out-of-stock').remove();
                }
            }, 'json');
        }

        $(document).on('click', '.dpb-calc', function(e){
            e.preventDefault();
            var $root = $(this).closest('.dpb-builder');
            updateTotal($root);
        });

        // live update when changing qty
        $(document).on('input change', '.dpb-qty-input', function(){
            var $root = $(this).closest('.dpb-builder');
            updateTotal($root);
        });

        // Add to cart
        $(document).on('click', '.dpb-add-to-cart', function(e){
            e.preventDefault();
            var $root = $(this).closest('.dpb-builder');
            var selected = parseSelected($root);

            // Ensure at least one selected
            var any = false;
            $.each(selected, function(k,v){ if (v && v>0) any = true; });
            if (!any) {
                alert('Please add at least one item to the bundle.');
                return;
            }

            // Set hidden input
            $root.find('.dpb-selected-json').val( JSON.stringify(selected) );

            // Create a synthetic form post to add to cart. We will post to the site's add-to-cart with dpb meta.
            // Use AJAX add to cart; we will post to /?add-to-cart=ID (choose first product as parent placeholder)
            // For simplicity, we'll use the first product ID from the table as the product used to add the cart item. Woo will accept custom cart data to attach bundle meta.
            var firstPid = $root.find('tr[data-product-id]').first().data('product-id');

            var data = {
                'add-to-cart': firstPid,
                'dpb_selected': JSON.stringify(selected),
                'dpb_builder_nonce': $root.find('input[name="dpb_builder_nonce"]').val(),
                'dpb_discount_type': $root.data('discount-type'),
                'dpb_discount_value': $root.data('discount-value')
            };

            // AJAX add-to-cart route
            $.post( DPB.ajax_url, $.extend( { action: 'dpb_add_to_cart_proxy' }, data ), function(response){
                // We'll use a fallback: perform normal form submit if AJAX fails.
                if (response && response.success) {
                    // Update mini-cart if present
                    $(document.body).trigger('added_to_cart', [ response.data.fragments, response.data.cart_hash, null ]);
                } else {
                    // fallback: build form and submit
                    var $f = $('<form method="post" style="display:none"></form>');
                    $f.attr('action', window.location.href);
                    $.each(data, function(k,v){ $f.append('<input type="hidden" name="'+k+'" value="'+v+'">'); });
                    $('body').append($f);
                    $f.submit();
                }
            }, 'json');
        });

        // Provide an admin-agnostic AJAX proxy to add to cart that triggers WC add-to-cart handlers.
        // We'll bind it only if not already bound (server will also accept standard add-to-cart).
    });
})(jQuery);
