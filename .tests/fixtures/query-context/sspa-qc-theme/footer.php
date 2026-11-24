<footer>
<?php
// The footer shortcode is called straight from the template, after get_footer, as td-composer's
// footer blocks are: no action is running when its query executes.
echo do_shortcode('[sspa_qc_footer_posts]');
?>
</footer>
<?php wp_footer(); ?>
</body>
</html>
