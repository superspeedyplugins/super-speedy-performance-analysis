#!/usr/bin/env bash
# Overhead of the per-query context, on the query-context site: spot analyses of the same pages
# with SSPA_QUERY_CONTEXT false (wp-config.php) and with the capture on, alternating the order
# each round, then the median generation time and query count per page and mode. Leaves the
# constant removed (capture on). Usage: measure-overhead.sh [rounds] [page keys...]
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-query-context}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
sync_plugin || exit 1
rounds="${1:-6}"; shift || true
pages="${*:-home shop product-single admin-dashboard}"
out="$PLUGIN_DIR/.data/query-context/overhead-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$out"
echo "Test site: $SSPA_SITE_URL; rounds $rounds; pages $pages; evidence $out"
for round in $(seq 1 "$rounds"); do
    if [ $((round % 2)) -eq 1 ]; then order="off on"; else order="on off"; fi
    for mode in $order; do
        if [ "$mode" = off ]; then cli config set SSPA_QUERY_CONTEXT false --raw --anchor="if ( ! defined('ABSPATH')" --placement=before --quiet || exit 1; else cli config delete SSPA_QUERY_CONTEXT --quiet 2>/dev/null; fi
        sleep 3 # php-fpm opcache revalidation of wp-config.php
        cli eval "
            \$run = SSPA_Run_Controller::start(array('type' => 'spot', 'trigger' => 'manual', 'user_id' => 1, 'page_keys' => explode(' ', '$pages')));
            if (is_wp_error(\$run)) { fwrite(STDERR, \$run->get_error_message()); exit(1); }
            \$deadline = time() + 600;
            do { SSPA_Run_Controller::process_batch(\$run); \$s = SSPA_Run_Controller::status(\$run); } while (\$s && in_array(\$s['status'], array('queued','crawling','analysing'), true) && time() < \$deadline);
            global \$wpdb;
            foreach (\$wpdb->get_results(\$wpdb->prepare(\"SELECT id, page_key, page_gen_ms, sql_count FROM %i WHERE run_id = %d AND plugin_set_hash = ''\", SSPA_Schema::table('profiles'), \$run), ARRAY_A) as \$p) {
                \$c = SSPA_Profile_Panel::capture(SSPA_Profile_Panel::profile_row(\$p['id']));
                echo '$round $mode ' . \$p['page_key'] . ' ' . \$p['page_gen_ms'] . ' ' . \$p['sql_count'] . ' ' . (isset(\$c['sql']['context']) && null !== \$c['sql']['context'] ? 'context' : 'none') . ' ' . \$run . PHP_EOL;
            }
        " | tee -a "$out/samples.txt"
    done
done
cli config delete SSPA_QUERY_CONTEXT --quiet 2>/dev/null
python3 - "$out/samples.txt" <<'PY' | tee "$out/summary.txt"
import sys, statistics, collections
d = collections.defaultdict(list); q = collections.defaultdict(list); bad = 0
for line in open(sys.argv[1]):
    r, mode, page, gen, count, ctx, run = line.split()
    if (mode == 'on') != (ctx == 'context'): bad += 1
    d[(page, mode)].append(float(gen)); q[(page, mode)].append(int(count))
print('page | median gen off | median gen on | delta | queries | samples')
for page in sorted({k[0] for k in d}):
    off, on = statistics.median(d[(page, 'off')]), statistics.median(d[(page, 'on')])
    print(f"{page} | {off:.1f} ms | {on:.1f} ms | {on - off:+.1f} ms ({(on - off) / off * 100:+.1f}%) | {statistics.median(q[(page, 'on')]):.0f} | {len(d[(page, 'off')])}/{len(d[(page, 'on')])}")
print('mode/capture mismatches:', bad)
PY
