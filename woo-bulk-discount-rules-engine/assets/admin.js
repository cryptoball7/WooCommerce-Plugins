jQuery(function ($) {
    const rulesList = $("#wbdre-rules-list");
    const saveResult = $("#wbdre-save-result");

    // Add new rule row
    $("#wbdre-add-rule").on("click", function (e) {
        e.preventDefault();
        const newRow = `
            <tr>
                <td><input type="checkbox" class="wbdre-enabled" checked></td>
                <td>
                    <select class="wbdre-scope">
                        <option value="all">All Products</option>
                        <option value="specific">Specific Products</option>
                    </select>
                </td>
                <td><input class="widefat wbdre-products"></td>
                <td><input type="number" min="1" class="wbdre-min-qty" value="1"></td>
                <td>
                    <select class="wbdre-type">
                        <option value="percent">Percent</option>
                        <option value="fixed">Fixed Amount</option>
                    </select>
                </td>
                <td><input type="text" class="wbdre-value" value="10"></td>
                <td><button class="button wbdre-remove">Remove</button></td>
            </tr>
        `;
        rulesList.append(newRow);
    });

    // Remove rule row
    rulesList.on("click", ".wbdre-remove", function (e) {
        e.preventDefault();
        $(this).closest("tr").remove();
    });

    // Save rules
    $("#wbdre-save").on("click", function (e) {
        e.preventDefault();

        const rules = [];
        $("#wbdre-rules-list tr").each(function () {
            const row = $(this);
            rules.push({
                enabled: row.find(".wbdre-enabled").is(":checked") ? 1 : 0,
                scope: row.find(".wbdre-scope").val(),
                product_ids: row.find(".wbdre-products").val(),
                min_qty: row.find(".wbdre-min-qty").val(),
                type: row.find(".wbdre-type").val(),
                value: row.find(".wbdre-value").val(),
            });
        });

        $.post(wbdre_admin.ajax_url, {
            action: "wbdre_save_rules",
            nonce: wbdre_admin.nonce,
            rules: rules,
        }).done(function (resp) {
            if (resp.success) {
                saveResult.text("Rules saved successfully.").css("color", "green");
            } else {
                saveResult.text("Error: " + resp.data).css("color", "red");
            }
        }).fail(function () {
            saveResult.text("AJAX error while saving.").css("color", "red");
        });
    });
});
