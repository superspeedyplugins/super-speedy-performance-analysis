(function ($) {
'use strict';
var saved = null, chart = null;
function request(data) {
    return $.post(ajaxurl, Object.assign({action:'sspa_ajax_profile', nonce:sspa_admin.nonce}, data)).then(function (response) {
        if (!response.success) throw new Error(typeof response.data === 'string' ? response.data : 'Request failed.');
        return response.data;
    });
}
function fail(error) { $('.sspa-ajax-status').text(error.message || 'Request failed.'); }
$(document).on('change', '.sspa-ajax-endpoint-group', function () { var group=this.value; $('.sspa-ajax-start [name="endpoints[]"] option').each(function () { this.selected=!!group && this.dataset.group===group; }); });
$(document).on('submit', '.sspa-ajax-start', function (event) {
    event.preventDefault(); var form=$(this), data={operation:'start'};
    form.serializeArray().forEach(function (item) { if (item.name === 'endpoints[]') (data.endpoints || (data.endpoints=[])).push(item.value); else data[item.name]=item.value; });
    request(data).then(function () { location.reload(); }, fail);
});
$(document).on('click', '.sspa-ajax-stop', function () { request({operation:'stop', uuid:$(this).data('uuid')}).then(function () { location.reload(); }, fail); });
function formatTime(value) { if(value===null)return 'Not measured'; return value>=1000 ? (value/1000).toFixed(2)+' s' : Number(value).toFixed(0)+' ms'; }
function headlines(doc) {
    var wrap=$('<div class="sspa-ajax-headline-list">');
    doc.pages.forEach(function(page){
        var card=$('<section class="sspa-ajax-headline">').appendTo(wrap);
        $('<h3>').text(page.label).appendTo(card);
        $('<div class="sspa-ajax-big-times">').text(formatTime(page.previous.median)+' → '+formatTime(page.current.median)).appendTo(card);
        if(page.delta.percent!==null)$('<strong class="sspa-ajax-reduction">').text(Math.abs(page.delta.percent).toFixed(1)+(page.delta.absolute>0 ? '% slower · ' : '% reduction · ')+formatTime(Math.abs(page.delta.absolute))+(page.delta.absolute>0 ? ' added' : ' saved')).appendTo(card);
        $('<p>').text('Server request median · '+page.previous.samples+' before / '+page.current.samples+' after samples · '+page.previous.errors+' / '+page.current.errors+' errors').appendTo(card);
        if(page.delta.absolute===null)$('<p>').text(page.warning).appendTo(card);
    });
    return wrap;
}
function visibleDocument() { var filter=($('.sspa-ajax-filter').val() || '').toLowerCase(); return Object.assign({},saved,{selection_filter:filter,pages:saved.pages.filter(function(p){return !filter || (p.label+' '+p.key).toLowerCase().indexOf(filter)!==-1;})}); }
function paint() {
    if (!saved) return;
    var mount=document.querySelector('.sspa-ajax-chart');
    chart=chart || window.SSPAECharts.init(mount);
    var filter=($('.sspa-ajax-filter').val() || '').toLowerCase();
    var visible=visibleDocument();
    $('.sspa-ajax-headlines').empty().append(headlines(visible));
    chart.setOption(SSPAMeasurementChart.optionFor(saved, filter), true);
    chart.off('click'); chart.on('click', function (event) { if(event.data.savedPoint) $('.sspa-ajax-point').text(JSON.stringify(event.data.savedPoint,null,2)); });
}
function summary(documentData) {
    var result=$('<div>'); $('<p>').text(documentData.metric.description).appendTo(result);
    documentData.warnings.forEach(function (warning) { $('<p>').text(warning).appendTo(result); });
    documentData.pages.forEach(function (page) {
        $('<h3>').text(page.label).appendTo(result);
        var before=page.previous, after=page.current;
        $('<p>').text('Median: '+before.median+' → '+after.median+' ms. p95: '+before.p95+' → '+after.p95+' ms. Successful samples: '+before.samples+' → '+after.samples+'. Errors: '+before.errors+' → '+after.errors+'.').appendTo(result);
        if(page.delta.absolute !== null) $('<p>').text(Math.abs(page.delta.absolute)+' ms '+(page.delta.absolute>0 ? 'added' : 'saved')+' per request'+(page.delta.percent !== null ? ' ('+Math.abs(page.delta.percent)+'% '+(page.delta.absolute>0 ? 'slower' : 'reduction')+')' : '')+'.').appendTo(result);
        $('<p>').text(page.warning).appendTo(result);
    });
    return result;
}
$(document).on('change', '.sspa-ajax-saved', function () { if (!this.value) return; var item=JSON.parse(this.value); $('.sspa-ajax-compare [name=before]').val(item.before); $('.sspa-ajax-compare [name=after]').val(item.after); $('.sspa-ajax-compare [name=comparison_name]').val(item.name); $('.sspa-ajax-compare').trigger('submit'); });
$(document).on('submit', '.sspa-ajax-compare', function(event) {
    event.preventDefault(); var data={operation:'compare'}; $(this).serializeArray().forEach(function(i){data[i.name]=i.value;});
    request(data).then(function(result) { saved=result; $('.sspa-ajax-results').prop('hidden',false); $('.sspa-ajax-summary').empty().append(summary(result)); $('.sspa-ajax-status').text(result.pages.length ? 'Saved request measurements loaded.' : 'No comparable retained requests.'); paint(); },fail);
});
$(document).on('input', '.sspa-ajax-filter', paint);
$(window).on('resize',function(){if(chart)chart.resize();});
$(document).on('click','.sspa-ajax-export',function(){
    if(!saved || !chart)return;
    var exported=visibleDocument(); var out=$('<main>'); $('<h1>').text('AJAX before/after: server request time').appendTo(out);
    $('<img>').attr('src',chart.getDataURL({type:'png',pixelRatio:2,backgroundColor:'#fff'})).attr('alt','Measured AJAX request chart').appendTo(out);
    out.append(headlines(exported)); out.append(summary(exported)); $('<pre>').text(JSON.stringify(exported,null,2)).appendTo(out);
    var blob=new Blob(['<!doctype html><meta charset="utf-8"><title>AJAX measurements</title><style>body{font:16px system-ui;max-width:1200px;margin:32px auto;padding:0 20px}img{max-width:100%}pre{white-space:pre-wrap;overflow-wrap:anywhere}.sspa-ajax-big-times{font-size:40px;font-weight:700}.sspa-ajax-headline{padding:20px;border:1px solid #ccc}.sspa-ajax-reduction{color:#135e96;font-size:20px}</style>'+out[0].outerHTML],{type:'text/html'}),url=URL.createObjectURL(blob),link=document.createElement('a');
    link.href=url;link.download='ajax-before-after.html';link.click();setTimeout(function(){URL.revokeObjectURL(url);},1000);
});
})(jQuery);
