<?php
// Isolated retained test-only barrier at the actual database write boundary.
if (isset($_GET['sspa-deactivation-race']) && 'flush' === $_GET['sspa-deactivation-race']) {
    add_filter('query', static function($sql) {
        if (preg_match('/^INSERT\s+INTO\s+`?[^\s`]*sspa_traffic_events\b/i', $sql)) {
            $dir=WP_CONTENT_DIR.'/sspa-deactivation-race';
            file_put_contents($dir.'/flush.entered', (string)microtime(true));
            $deadline=microtime(true)+20;
            while (!is_file($dir.'/flush.release') && microtime(true)<$deadline) { clearstatcache(); usleep(10000); }
            if (!is_file($dir.'/flush.release')) throw new RuntimeException('Flush barrier was not released');
        }
        return $sql;
    });
}
if (isset($_GET['sspa-deactivation-race']) && 'buffered' === $_GET['sspa-deactivation-race']) {
    add_action('template_redirect',static function(){
        $dir=WP_CONTENT_DIR.'/sspa-deactivation-race';
        file_put_contents($dir.'/buffered.entered',(string)microtime(true));
        $deadline=microtime(true)+20;
        while(!is_file($dir.'/buffered.release') && microtime(true)<$deadline){clearstatcache();usleep(10000);}
        if(!is_file($dir.'/buffered.release'))throw new RuntimeException('Buffered request barrier was not released');
    });
}
