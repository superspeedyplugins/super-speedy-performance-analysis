jQuery(function () {
	var hash = window.location.hash.replace('#', '');
	sspa_click_tab(hash || 'overview');
});

jQuery(document).on('click', '#sspa_main .nav-tab-wrapper .nav-tab', function (e) {
	var slug = jQuery(this).data('tab');
	window.history.pushState(null, null, '#' + slug);
	sspa_click_tab(slug);
	e.preventDefault();
	e.stopPropagation();
});

function sspa_click_tab(slug) {
	if (!jQuery('#sspa_main .nav-tab-wrapper .nav-tab[data-tab="' + slug + '"]').length) {
		slug = 'overview';
	}
	jQuery('#sspa_main .nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
	jQuery('#sspa_main .nav-tab-wrapper .nav-tab[data-tab="' + slug + '"]').addClass('nav-tab-active').focus();
	jQuery('#sspa_main div.tab-contents').css('display', 'none');
	jQuery('#sspa_main div.tab-contents[data-tab="' + slug + '"]').css('display', 'block');
}
