<?php
// Investigation: this exits 1 when the proposed wrapper breaks native header scope.
// This is a native-transparency probe, distinct from the explicitly warned opt-in diagnostic.
class SummaryStreamProbe {
 public $context;
 private $resource;
 public function stream_open($path,$mode,$options,&$opened_path) {
  stream_wrapper_restore('http');
  try { $this->resource=fopen($path,$mode,false,$this->context); }
  finally { stream_wrapper_unregister('http');stream_wrapper_register('http',self::class); }
  return is_resource($this->resource);
 }
 public function stream_read($count){return fread($this->resource,$count);}
 public function stream_eof(){return feof($this->resource);}
 public function stream_stat(){return fstat($this->resource);}
 public function stream_close(){fclose($this->resource);}
}
$url='http://127.0.0.1:8081/summary-slow-endpoint.php';
$site = getenv('SSPA_TEST_SITE_URL');
if (!$site || !preg_match('/^tests-.*\.super-speedy-performance-analysis\.localhost$/', parse_url($site, PHP_URL_HOST))) throw new RuntimeException('A dedicated native SSPA test site is required');
$context=stream_context_create(['http'=>['header'=>'Host: ' . parse_url($site, PHP_URL_HOST)]]);
$native=file_get_contents($url,false,$context);$native_headers=$http_response_header??null;
unset($http_response_header);
stream_wrapper_unregister('http');stream_wrapper_register('http',SummaryStreamProbe::class);
$wrapped=file_get_contents($url,false,$context);$wrapped_headers=$http_response_header??null;
stream_wrapper_restore('http');
echo json_encode(compact('native','wrapped','native_headers','wrapped_headers'),JSON_PRETTY_PRINT),"\n";
exit($native === $wrapped && $native_headers === $wrapped_headers ? 0 : 1);
