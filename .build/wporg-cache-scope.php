#!/usr/bin/env php
<?php
// The wp.org edition clears only PA-owned transients; the full edition keeps its shared action.
$file = $argv[1] ?? '';
$source = file_get_contents($file);
$before = "(superspeedy_changes_|superspeedy_l_|ssp_|sspa_)";
$after = "(sspa_)";
if (!is_string($source) || substr_count($source, $before) !== 1) {
    fwrite(STDERR, "Expected exactly one shared cache-scope predicate.\n");
    exit(1);
}
if (false === file_put_contents($file, str_replace($before, $after, $source))) {
    fwrite(STDERR, "Cannot scope the wp.org cache action.\n");
    exit(1);
}
