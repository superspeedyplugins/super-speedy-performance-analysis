#!/usr/bin/env bash
# Spin up the SSPA docker test site: mariadb + wordpress + WooCommerce + sample content,
# with this plugin bind-mounted and activated. Idempotent: safe to re-run.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

docker network inspect $SSPA_NET >/dev/null 2>&1 || docker network create $SSPA_NET

if ! docker ps --format '{{.Names}}' | grep -qx $SSPA_DB; then
    docker rm -f $SSPA_DB >/dev/null 2>&1 || true
    docker run -d --name $SSPA_DB --network $SSPA_NET \
        -e MARIADB_DATABASE=wordpress \
        -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp \
        -e MARIADB_ROOT_PASSWORD=root \
        $DB_IMAGE --performance-schema=ON >/dev/null
    echo "started $SSPA_DB (performance_schema ON)"
fi

if ! docker ps --format '{{.Names}}' | grep -qx $SSPA_REDIS; then
    docker rm -f $SSPA_REDIS >/dev/null 2>&1 || true
    docker run -d --name $SSPA_REDIS --network $SSPA_NET $REDIS_IMAGE >/dev/null
    echo "started $SSPA_REDIS"
fi

if ! docker ps --format '{{.Names}}' | grep -qx $SSPA_WP; then
    docker rm -f $SSPA_WP >/dev/null 2>&1 || true
    docker run -d --name $SSPA_WP --network $SSPA_NET \
        -p $SSPA_PORT:80 \
        -e WORDPRESS_DB_HOST=$SSPA_DB \
        -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp \
        -e WORDPRESS_DB_NAME=wordpress \
        $WP_IMAGE >/dev/null
    echo "started $SSPA_WP"
fi

echo "waiting for database..."
for i in $(seq 1 60); do
    # mariadb's own healthcheck: avoids the newer mariadb-client's TLS-required default.
    if docker exec $SSPA_DB healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
    sleep 2
    [ "$i" = 60 ] && { echo "db never came up"; exit 1; }
done
# The WordPress db user cannot read performance_schema by default - that denial is the
# normal case on a real site, and the Tools tab generates this exact GRANT for it. Granting
# it here is what lets the digest tests (16-digests.php) exercise the real path instead of
# only the graceful no-op.
docker exec $SSPA_DB mariadb -uroot -proot \
    -e "GRANT SELECT ON performance_schema.* TO 'wp'@'%'; FLUSH PRIVILEGES;" >/dev/null 2>&1 \
    && echo "granted wp SELECT on performance_schema" || echo "WARN: performance_schema grant failed"

# Give the wordpress entrypoint a moment to finish copying core files on first boot.
for i in $(seq 1 30); do
    if cli core version >/dev/null 2>&1; then break; fi
    sleep 2
done

if ! cli core is-installed >/dev/null 2>&1; then
    echo "installing WordPress..."
    cli core install --url="http://$SSPA_WP" --title="SSPA Test Store" \
        --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
    cli rewrite structure '/%postname%/' --hard

    echo "installing WooCommerce + sample data..."
    cli plugin install woocommerce --activate
    cli plugin install wordpress-importer --activate
    cli import "$CONTAINER_PLUGIN_DIR/../woocommerce/sample-data/sample_products.xml" --authors=create --quiet || true

    echo "seeding posts, categories and orders..."
    cli post generate --count=30 --post_type=post
    cli eval '
        $cat = wp_insert_term("News", "category");
        $cat_id = is_wp_error($cat) ? 0 : $cat["term_id"];
        $posts = get_posts(array("numberposts" => 15));
        foreach ($posts as $p) { if ($cat_id) { wp_set_post_terms($p->ID, array($cat_id), "category"); } }
        $products = get_posts(array("post_type" => "product", "numberposts" => 3));
        foreach ($products as $prod) {
            $order = wc_create_order();
            $order->add_product(wc_get_product($prod->ID), 2);
            $order->set_address(array("first_name" => "Test", "last_name" => "Customer", "email" => "test@example.com"), "billing");
            $order->calculate_totals();
            $order->update_status("processing");
        }
        echo count($products) . " orders created\n";
    '

    echo "enabling Redis object cache..."
    cli config set WP_REDIS_HOST $SSPA_REDIS
    cli plugin install redis-cache --activate
    cli redis enable

    echo "activating $PLUGIN_SLUG..."
    sync_plugin
    cli plugin activate $PLUGIN_SLUG
    cli rewrite flush --hard
fi

echo "ready: http://localhost:$SSPA_PORT (admin/admin), site url http://$SSPA_WP (container-internal)"
