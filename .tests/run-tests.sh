#!/usr/bin/env bash
# Run the SSPA test suite against the docker environment (starts it if needed).
# Usage: .tests/run-tests.sh [case-substring]
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/docker/env.sh"

if ! docker ps --format '{{.Names}}' | grep -qx $SSPA_WP; then
    "$PLUGIN_DIR/.tests/docker/up.sh" || exit 1
fi

sync_plugin

# Pre-flight: several cases silently degrade into failures (sector "general", tiny deep
# deltas, no write profiles) when the WooCommerce sample data has gone missing - reseed.
PRODUCTS=$(cli post list --post_type=product --post_status=publish --format=count 2>/dev/null | tr -dc '0-9')
if [ "${PRODUCTS:-0}" -lt 5 ]; then
    echo "sample products missing ($PRODUCTS) - reseeding WooCommerce sample data..."
    cli import "$CONTAINER_PLUGIN_DIR/../woocommerce/sample-data/sample_products.xml" --authors=create --quiet || true
    cli eval '
        if (count(wc_get_orders(array("limit" => -1, "return" => "ids"))) < 3) {
            foreach (get_posts(array("post_type" => "product", "numberposts" => 3)) as $prod) {
                $order = wc_create_order();
                $order->add_product(wc_get_product($prod->ID), 2);
                $order->set_address(array("first_name" => "Test", "last_name" => "Customer", "email" => "test@example.com"), "billing");
                $order->calculate_totals();
                $order->update_status("processing");
            }
        }
        echo "orders: " . count(wc_get_orders(array("limit" => -1, "return" => "ids"))) . "\n";
    '
fi

FILTER="${1:-}"
FAILED=0
RAN=0

for case_file in "$PLUGIN_DIR"/.tests/cases/*.php; do
    name=$(basename "$case_file")
    if [ -n "$FILTER" ] && [[ "$name" != *"$FILTER"* ]]; then
        continue
    fi
    RAN=$((RAN + 1))
    echo "=== $name ==="
    output=$(cli eval-file "$CONTAINER_PLUGIN_DIR/.tests/cases/$name" 2>&1)
    echo "$output"
    if echo "$output" | grep -q '^FAIL' || ! echo "$output" | grep -q '^PASS'; then
        FAILED=$((FAILED + 1))
        echo "--- $name FAILED ---"
    fi
done

echo
echo "$RAN case file(s) run, $FAILED failed"
exit $FAILED
