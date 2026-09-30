<?php
/** Isolated browser translation fixture; active only with its test cookie. */
if (empty($_COOKIE['sspa_translation_test'])) return;
$sspa_test_translations = array(
    'Previous measurements' => 'Mesures précédentes',
    'Recent measurements' => 'Mesures récentes',
    'Current measurements' => 'Mesures actuelles',
    'Previous median' => 'Médiane précédente',
    'Current median' => 'Médiane actuelle',
    'Failed requests' => 'Requêtes échouées',
    'Comparison of every retained %s measurement for the previous and current measured setups.' => 'Comparaison des mesures %s précédentes et actuelles.',
    '%s chart loaded.' => 'Graphique %s chargé.',
    'Window name' => 'Nom de la fenêtre',
    'Compare' => 'Comparer',
    'Loading the selected comparison…' => 'Chargement de la comparaison…',
    'The points in time could not be compared. Please try Compare again.' => 'Comparaison impossible. Réessayez.',
);
add_filter('gettext', function ($translated, $text, $domain) use ($sspa_test_translations) {
    return 'super-speedy-performance-analysis' === $domain && isset($sspa_test_translations[$text]) ? $sspa_test_translations[$text] : $translated;
}, 10, 3);
add_filter('pre_load_script_translations', function ($translations, $file, $handle, $domain) use ($sspa_test_translations) {
    if ('super-speedy-performance-analysis' !== $domain) return $translations;
    $catalog = array('' => array('domain' => 'super-speedy-performance-analysis', 'lang' => 'fr', 'plural_forms' => 'nplurals=2; plural=(n > 1);'));
    foreach ($sspa_test_translations as $source => $translation) $catalog[$source] = array($translation);
    return wp_json_encode(array('locale_data' => array('messages' => $catalog)));
}, 10, 4);
