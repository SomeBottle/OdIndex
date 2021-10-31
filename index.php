<?php
/*
Originally designed by Heymind:
https://github.com/heymind/OneDrive-Index-Cloudflare-Worker
OdIndex maintained by Somebottle.
Based on MIT LICENSE.
*/
set_time_limit(60);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
date_default_timezone_set("Asia/Shanghai");
$config = array(
	"refresh_token" => "",
	"client_id" => "",
	"client_secret" => "",
	"api_url" => "https://graph.microsoft.com/v1.0",
	"oauth_url" => "https://login.microsoftonline.com/common/oauth2/v2.0",
	"redirect_uri" => "http://localhost",
	'base' => '/',
	'data_path' => 'data',
	'rewrite' => false,
	'site_path' => '',
	"cache" => array(
		'smart' => true,
		'expire' => 1800, /*In seconds*/
		'force' => false /*是否强制开启缓存*/
	),
	'queue' => array(
		'start' => true,/*防并发请求队列*/
		'max_num' => 15,/*队列中允许停留的最多请求数，其他请求直接返回服务繁忙*/
		'last_for' => 2700 /*In seconds*/
	),
	'service_busy' => 'https://cdn.jsdelivr.net/gh/SomeBottle/odindex/assets/unavailable.png',/*队列过多时返回的“服务繁忙”图片url*/
	'thumbnail' => true,
	'preview' => true,
	'max_preview_size' => 314572, /*最大支持预览的文件大小(in bytes)*/
	'preview_suffix' => ['ogg', 'mp3', 'wav', 'm4a', 'mp4', 'webm', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'md', 'markdown', 'txt', 'docx', 'pptx', 'xlsx', 'doc', 'ppt', 'xls', 'js', 'html', 'json', 'css'],/*可预览的类型,只少不多*/
	'use_proxy' => false,
	'proxy_path' => false, /*代理程序url，false则用本目录下的*/
	'no_index' => false, /*关闭列表*/
	'no_index_print' => 'Static powered by OdIndex', /*关闭列表访问列表时返回什么*/
	'list_as_json' => false, /*改为返回json*/
	'pwd_cfg_path' => '.password', /*密码配置文件路径*/
	'pwd_protect' => true,/*是否采用密码保护，这会稍微多占用一些程序资源*/
	'pwd_cfg_update_interval' => 1200, /*密码配置文件本地缓存时间(in seconds)*/
	'pagination' => true, /*是否开启分页*/
	'items_per_page' => 20 /*每页的项目数量，用于分页(推荐设置为20-35)*/
);
/*Initialization*/
$pagAttr = [/*这是个全局属性，告诉大家现在在哪个页面，前后有没有页面*/
	'current' => 1,
	'prevExist' => false,
	'nextExist' => false
];
function p($p)
{/*转换为绝对路径*/
	return __DIR__ . '/' . $p;
}
function writeConfig($file, $arr)
{/*写入配置*/
	global $config;
	if (is_array($arr)) file_put_contents(p($config['data_path'] . '/' . $file), json_encode($arr, true));
}
function getConfig($file)
{/*获得配置*/
	global $config;
	$path = p($config['data_path'] . '/' . $file);
	return file_exists($path) ? json_decode(file_get_contents($path), true) : false;/*配置文件不存在直接返回false*/
}
function getTp($section)
{/*获取模板中特定的section*/
	$file = file_get_contents(p("template.html"));
	$afterSplit = array_filter(preg_split("/\{\{" . $section . "\}\}/i", $file))[1];
	$tp = array_merge(array_filter(preg_split("/\{\{" . $section . "End\}\}/i", $afterSplit)))[0];
	return trim($tp);
}
function htmlChars($content)
{/*替换<>符*/
	return str_replace('>', '&gt;', str_replace('<', '&lt;', $content));
}
function rpTp($from, $to, $origin)
{/*替换模板标识符*/
	return preg_replace("/\\{\\[" . $from . "\\]\\}/i", $to, $origin);
}
if (!is_dir(p($config['data_path']))) mkdir(p($config['data_path']));
if (!is_dir(p($config['data_path'] . '/cache'))) mkdir(p($config['data_path'] . '/cache'));/*如果没有cache目录就创建cache目录*/
/*autoCache*/
$cacheInitialization = array(/*Initialize conf初始化缓存配置记录文件*/
	'requests' => 0,
	'last_count' => time(),
	'periods' => array(),
	'cache_start' => false
);
if (!getConfig('cache.json')) writeConfig('cache.json', $cacheInitialization);
/*Queue*/
$queueInitialization = array(
	'performing' => '',/*正在执行的请求id(无实际用途，仅作标记*/
	'requesting' => 0,/*在队列中的任务数*/
	'start' => false
);
if (!getConfig('queue.json')) writeConfig('queue.json', $queueInitialization);
/*PasswordConfig Cache */
$pwdUpdaterInitialization = array(/*这个是记录密码配置文件更新时间的文件*/
	'last_update' => 0
);
if (!getConfig('pwdcache.json')) writeConfig('pwdcache.json', $pwdUpdaterInitialization);
/*InitializationFinished*/
function valueInArr($val, $arr)
{/*判断数组中是否有一个值*/
	$str = is_array($arr) ? join(' ', $arr) : false;/*将数组并入字串符，直接判断字串符里面是否有对应值*/
	return (stripos($str, strval($val)) !== false) ? true : false;
}
function modifyArr($arr, $search, $value)
{/*搜素含有对应值的键并通过键编辑数组*/
	foreach ($arr as $key => $val) {
		if (stripos($val, strval($search)) !== false) {
			$arr[$key] = $value;
			return $arr;
		}
	}
}
function request($url, $queries, $method = 'POST', $head = array('Content-type:application/x-www-form-urlencoded'), $getHeader = false, $retry = false)
{/*poster*/
	$rqContent = ($method == 'POST') ? http_build_query($queries) : '';
	$opts = array(
		'http' => array(
			'method' => $method,
			'header' => $head,
			'timeout' => 15 * 60, // 超时时间（单位:s）
			'content' => $rqContent
		),
		'ssl' => array(
			'verify_peer' => false,
			'verify_peer_name' => false,
		)
	);
	autoCache();/*智能缓存*/
	$queueID = queueChecker('add');/*增加请求到队列*/
	if ($getHeader) {/*仅请求头部模式*/
		stream_context_set_default($opts);
		$headers = @get_headers($url, 1);
		queueChecker('del', true, $queueID);/*请求完毕，移除队列*/
		if (valueInArr('401 Unauthorized', $headers) && !$retry) {/*accesstoken需要刷新*/
			$newToken = getAccessToken(true);
			$head = modifyArr($head, 'Authorization', 'Authorization: bearer ' . $newToken);/*获得新token重新请求*/
			return request($url, $queries, $method, $head, $getHeader, true);
		}
		return $headers;
	} else {/*正常请求模式*/
		$settings = stream_context_create($opts);
		$result = @file_get_contents($url, false, $settings);
		queueChecker('del', true, $queueID);/*请求完毕，移除队列*/
		$respHeader = $http_response_header;/*获得返回头*/
		if (valueInArr('401 Unauthorized', $respHeader) && !$retry) {/*accesstoken需要刷新*/
			$newToken = getAccessToken(true);
			$head = modifyArr($head, 'Authorization', 'Authorization: bearer ' . $newToken);/*获得新token重新请求*/
			return request($url, $queries, $method, $head, $getHeader, true);
		}
		return empty($result) ? '' : $result;
	}
}
function encodeUrl($u)
{/*处理URL转义，保留斜杠*/
	return str_ireplace('%2F', '/', urlencode($u));
}
function parsePath($u)
{/*得出处理后的路径*/
	global $config;
	$parsed = parse_url($u)['path'];
	$process = str_ireplace($config['site_path'], '', $parsed);
	if ($config['rewrite']) {
		$process = explode('/', $process);
		$ending = end($process);
		$process[count($process) - 1] = explode('&', $ending)[0];/*rewrite开启后服务器程序会自动把?转换为&*/
		$process = join('/', $process);
	}
	return $process;
}
function getRefreshToken()
{/*从token文件中或本脚本中取refreshtoken*/
	global $config;
	if (file_exists(p('token.php'))) {
		require p('token.php');
		return isset($refresh) ? $refresh : $config['refresh_token'];
	} else {
		return $config['refresh_token'];
	}
}
function getAccessToken($update = false)
{/*获得AccessToken*/
	global $config;
	if ($update || !file_exists(p('token.php'))) {
		$resp = request($config['oauth_url'] . '/token', array(
			'client_id' => $config['client_id'],
			'redirect_uri' => $config['redirect_uri'],
			'client_secret' => $config['client_secret'],
			'refresh_token' => getRefreshToken(),
			'grant_type' => 'refresh_token'
		));
		$data = json_decode($resp, true);
		if (isset($data['access_token'])) {/*存入到token文件*/
			file_put_contents(p('token.php'), '<?php $token="' . $data['access_token'] . '";$refresh="' . $data['refresh_token'] . '";?>');
			return $data['access_token'];
		} else {
			file_exists(p('token.php')) ? unlink(p('token.php')) : die('Failed to get accesstoken. Maybe refresh_token expired.');/*refreshtoken过期*/
			return getAccessToken($update);
		}
	} else {
		require p('token.php');
		return $token;
	}
}
function suffix($f)
{/*从文件名后取出后缀名*/
	$sp = explode('.', $f);
	return end($sp);
}
function getParam($url, $param)
{/*获得url中的参数*/
	global $config;
	$parsed = parse_url($url)['query'];
	if ($config['rewrite']) {/*rewrite开启后服务器程序会自动把?转换为&*/
		$parsed = $url;
	}
	$parsed = explode('&', $parsed);
	$arr = array();
	foreach ($parsed as $v) {
		$each = explode('=', $v);
		isset($each[1]) ? ($arr[$each[0]] = $each[1]) : ($arr[$each[0]] = '');/*参数输入数组*/
	}
	return isset($arr[$param]) ? $arr[$param] : '';
}
function wrapPath($p)
{/*目录包装请求url*/
	global $config;
	$wrapped = encodeUrl($config['base']) . $p;
	$wrapForFolder = (substr($wrapped, -1) == '/') ? substr($wrapped, 0, strlen($wrapped) - 1) : $wrapped;/*请求目录内容的时候形如/test:/children,末尾是没有/的，需要去掉*/
	return ($wrapped == '/' || $wrapped == '') ? '' : (':' . $wrapForFolder);
}
function pagRequest($requestUrl, $queryUrl, $accessToken, $requestFolder)
{/*分页请求包装，算是一个Hook?*/
	global $config, $pagAttr;
	$resp = '';
	if ($requestFolder && $config['pagination']) {/*请求的是目录并且开启了分页，作为一个Hook*/
		if ($config['no_index']) return '';/*没有开启列表就直接不用请求了*/
		$nowPage = intval(getParam($requestUrl, 'o'));/*获得请求中的页码*/
		$chunkedPage = 1;/*本地分块页数*/
		$chunkedResp = '';/*缓冲块中的Resp*/
		$chunkSize = 1;/*缓冲块当前大小，满5块后清零，进行新请求*/
		$totalPage = (empty($nowPage) || $nowPage < 0) ? 1 : $nowPage;/*如果没有页码默认是请求第一页*/
		$pagAttr['current'] = $totalPage;/*更新当前页码*/
		$chunkRequestSize = $config['items_per_page'] * 5;/*缓冲分块，在缓冲块内的分页将在本地完成*/
		$queryUrl = $queryUrl . '?$top=' . $chunkRequestSize;/*重构建请求url*/
		$linkForRequest = $queryUrl;
		while ($chunkedPage <= $totalPage) {
			$chunkedResp = ($chunkSize == 1) ? request($linkForRequest, '', 'GET', array(
				'Content-type: application/x-www-form-urlencoded',
				'Authorization: bearer ' . $accessToken
			)) : $chunkedResp;
			if (empty($chunkedResp)) break;/*请求到的一页都没有内容就直接返回了*/
			$data = json_decode($chunkedResp, true);
			$chunkItemNum = count($data['value']);/*获得总项目数*/
			$chunkSizePage = ceil($chunkItemNum / $config['items_per_page']);/*(向上取整)得出当前缓冲块有多少页，通常是我们分的四页，但是当翻到最后的时候往往可能没有四页*/
			$theChunk = array_chunk($data['value'], $config['items_per_page'])[$chunkSize - 1];/*array_chunk将数组切片，取出当前的一页*/
			$data['value'] = $theChunk;
			$resp = json_encode($data);/*导出分块数据*/
			if ($chunkedPage > 1) $pagAttr['prevExist'] = true;/*有上一页*/
			if (isset($data['@odata.nextLink'])) {
				$linkForRequest = $data['@odata.nextLink'];/*下次请求的链接是这个*/
				$pagAttr['nextExist'] = true;/*有下一页*/
			} else if ($chunkSize < $chunkSizePage) {/*缓冲区没填满也有下一页*/
				$pagAttr['nextExist'] = true;/*有下一页*/
			} else {/*没有nextLink了(或者没有开启分页)，说明到头了，直接返回*/
				$pagAttr['nextExist'] = false;
				break;
			}
			$chunkedPage += 1;
			$chunkSize = ($chunkSize >= $chunkSizePage) ? 1 : $chunkSize + 1;
		}
	} else {
		$resp = request($queryUrl, '', 'GET', array(
			'Content-type: application/x-www-form-urlencoded',
			'Authorization: bearer ' . $accessToken
		));
	}
	return $resp;
}
function handleRequest($url, $returnUrl = false, $requestForFile = false)
{
	global $config, $ifRequestFolder;
	$requestFolder = $requestForFile ? false : $ifRequestFolder;
	$accessToken = getAccessToken();/*获得accesstoken*/
	$path = parsePath($url);
	$jsonArr = ['success' => false, 'msg' => ''];
	$path = ($path == '/') ? '' : $path;/*根目录特殊处理*/
	$thumbnail = false;/*初赋值变量*/
	if ($config['thumbnail']) {
		$thumb = getParam($url, 'thumbnail');/*获得缩略图尺寸*/
		$thumbnail = empty($thumb) ? false : $thumb;/*判断请求的是不是缩略图*/
	}
	if ($config['preview']) {
		$prev = getParam($url, 'p');/*获得preview请求*/
		$preview = empty($prev) ? false : $prev;
	}
	if ($thumbnail) {/*如果是请求缩略图*/
		$rqUrl = $config['api_url'] . '/me/drive/root' . wrapPath($path) . ':/thumbnails';
		$resp = request($rqUrl, '', 'GET', array(
			'Content-type: application/x-www-form-urlencoded',
			'Authorization: bearer ' . $accessToken
		));
		$resp = json_decode($resp, true);
		$respUrl = $resp['value'][0][$thumbnail]['url'];
		if ($respUrl) {
			return handleFile($respUrl, [], true);
		} else {
			return false;
		}/*强制不用代理*/
	}
	/*Normally request*/
	$wrappedPath = wrapPath($path);
	$rqUrl = $config['api_url'] . '/me/drive/root' . $wrappedPath . ($requestFolder ? ((empty($wrappedPath) ? '' : ':') . '/children') : '?select=name,eTag,createdDateTime,lastModifiedDateTime,size,id,folder,file,%40microsoft.graph.downloadUrl');/*从内而外：第一层判断是否是根目录，第二层判断是请求的目录还算文件，第三层包装请求*/
	$cache = cacheControl('read', $url);/*请求的内容是否被缓存*/
	if (ifCacheStart() && !empty($cache[0])) {
		$resp = $cache[0];/*如果有缓存就直接抓缓存了*/
	} else {
		$queueID = queueChecker('add');/*如果没有缓存就要加入请求队列*/
		$resp = pagRequest($url, $rqUrl, $accessToken, $requestFolder);
		queueChecker('del', true, $queueID);
	}
	if (!empty($resp)) {
		$data = json_decode($resp, true);
		if (isset($data['file'])) {/*返回的是文件*/
			if ($returnUrl) return $data["@microsoft.graph.downloadUrl"];/*如果returnUrl=true就直接返回Url，用于取得文件内容*/
			if ($data['name'] == $config['pwd_cfg_path']) die('Access denied');/*阻止密码文件被获取到*/
			if (ifCacheStart() && !$cache) cacheControl('write', $url, array($resp));/*只有下载链接储存缓存*/
			if ($preview == 't') {/*预览模式*/
				return handlePreview($data["@microsoft.graph.downloadUrl"], $data);/*渲染预览*/
			} else {
				handleFile($data["@microsoft.graph.downloadUrl"], $data);/*下载文件*/
			}
		} else if (isset($data['value'])) {/*返回的是目录*/
			$render = renderFolderIndex($data['value'], parsePath($url));/*渲染目录*/
			return $render;
		} else {
			$jsonArr['msg'] = 'Error response:' . var_export($resp, true);
			return ($config['list_as_json'] ? json_encode($jsonArr, true) : 'Error response:' . var_export($resp, true));
		}
	} else if ($config['no_index']) {
		return $config['no_index_print'];
	} else {
		$jsonArr['msg'] = 'Not found: ' . urldecode($path);
		echo ($config['list_as_json'] ? json_encode($jsonArr, true) : '<!--NotFound:' . urldecode($path) . '-->');
		return '';/*当文件或目录不存在的时候返回空，以免缓存记录*/
	}
}
function item($icon, $fileName, $rawData = false, $size = false, $href = false)
{
	$singleItem = getTp('itemsingle');/*获得单个项目列表的模板*/
	$href = $href ? $href : $fileName;/*没有指定链接自动指定文件名*/
	$size = $size ? $size : 0;/*在标签附上文件大小*/
	if ($rawData) {
		$singleItem = rpTp('createddatetime', $rawData['createdDateTime'], $singleItem);
		$singleItem = rpTp('lastmodifieddatetime', $rawData['lastModifiedDateTime'], $singleItem);
		$singleItem = rpTp('mimetype', $rawData['file']['mimeType'], $singleItem);
	}
	$singleItem = rpTp('itemlink', $href, $singleItem);
	$singleItem = rpTp('itemsize', $size, $singleItem);
	$singleItem = rpTp('mimeicon', $icon, $singleItem);
	$singleItem = rpTp('itemname', $fileName, $singleItem);/*构造项目模板*/
	return $singleItem;
}
function mime2icon($t)
{
	if (stripos($t, 'image') !== false) {
		return 'image';
	} else if (stripos($t, 'audio') !== false) {
		return 'audiotrack';
	} else if (stripos($t, 'video') !== false) {
		return 'video_label';
	} else {
		return 'description';
	}
}
function processHref($hf)
{/*根据伪静态是否开启处理href*/
	global $config, $pathQuery;
	if (!$config['rewrite']) {
		$pqParts = explode('?', $pathQuery);
		$pathQuery = $pqParts[0];/*未开启伪静态要净化一下pathQuery*/
		return '?/' . $pathQuery . $hf;/*没开伪静态，处理一下请求Path*/
	}
	return $hf;
}
function nowPath()
{/*获得当前所在路径*/
	global $config, $pathQuery;
	if ($config['rewrite']) {
		$pqParts = explode('/', urldecode($pathQuery));
		if (stripos(end($pqParts), '&') !== false) array_pop($pqParts);
		$pathQuery = join('/', $pqParts);
	}
	return $pathQuery;
}
function pathItems()
{/*生成导航区当前路径的模板,🥔 > / Previews / Codes /这种*/
	global $config, $pathQuery;
	$folders = explode('/', $pathQuery);
	$lastValue = end($folders);/*观察数组最后一个值，也就是当前路径的末尾有没有请求符*/
	if ($config['rewrite'] && stripos($lastValue, '&') !== false) {/*这个问题只会在开启了伪静态的情况下出现，当目录最后一节中为请求符的时候自动忽略*/
		array_pop($folders);
	}
	$folders = array_filter($folders);/*上面一步处理完之后删除不显示的空节*/
	$singlePath = getTp('pathsingle');
	$allPath = '';
	$currentPath = $config['site_path'] . ($config['rewrite'] ? '/' : '?/');/*兼容没有使用重定向的情况*/
	foreach ($folders as $val) {
		$currentPath .= $val . '/';
		$single = rpTp('folderlink', $currentPath, $singlePath);
		$single = rpTp('foldername', urldecode($val), $single);
		$allPath .= $single . ' / ';
	}
	return $allPath;
}
function pwdForm($fmd5)
{/*密码输入模板*/
	global $config;
	$passwordPage = getTp('passwordpage');
	$content = rpTp('path', nowPath(), $passwordPage);
	$content = rpTp('pathitems', pathItems(), $content);
	$content = rpTp('foldermd5', $fmd5, $content);
	$homePath = $config['rewrite'] ? '/' : '?/';/*兼容没有使用重定向的情况*/
	$content = rpTp('homepath', $homePath, $content);
	return $content;
}
function pwdConfigReader()
{/*更新配置缓存并读取密码保护配置*/
	global $config;
	$pwdCacheUpdate = getConfig('pwdcache.json');/*获取密码配置更新情况*/
	if (time() - $pwdCacheUpdate['last_update'] >= $config['pwd_cfg_update_interval']) {/*该更新密码配置缓存了*/
		$requestUrl = 'http://request.yes/' . $config['pwd_cfg_path'];/*密码配置文件的路径*/
		$fileUrl = handleRequest($requestUrl, true, true);/*获得密码文件下载链接*/
		$remoteConfig = empty($fileUrl) ? '' : @request($fileUrl, '', 'GET', array());/*请求到文件*/
		file_put_contents(p('pwdcache.php'), '<?php $pwdCfgCache="' . base64_encode(trim($remoteConfig)) . '";?>');
		$pwdCacheUpdate['last_update'] = time();
		writeConfig('pwdcache.json', $pwdCacheUpdate);
	}
	require p('pwdcache.php');
	$pwdCfg = explode(PHP_EOL, base64_decode($pwdCfgCache));
	return $pwdCfg;
}
function pwdChallenge()
{
	global $pathQuery, $config;
	if (!$config['pwd_protect']) return [true];/*没有开启密码保护一律通行*/
	$pwdCfg = pwdConfigReader();/*取得密码配置文件*/
	@session_start();
	if (!isset($_SESSION['passwd'])) $_SESSION['passwd'] = array();/*密码是否存在*/
	$currentPath = '/' . urldecode($pathQuery);/*构造当前的路径，比如/Videos/ACG/*/
	foreach ($pwdCfg as $line) {
		$singleConfig = explode(' ', $line);
		$targetFolder = $singleConfig[0];/*获得每行配置的目标目录*/
		$targetPwd = trim($singleConfig[1]);/*获得每行目录对应的md5密码*/
		if (empty($targetFolder)) continue;/*如果配置目录为空就跳过，防止匹配bug*/
		if (stripos($currentPath, $targetFolder) === 0) {/*当前目录能匹配上目标目录，受密码保护*/
			$folderMd5 = md5($targetFolder);/*得到目标foldermd5*/
			if (!isset($_SESSION['passwd'][$folderMd5]) || $targetPwd !== $_SESSION['passwd'][$folderMd5]) {/*没有post密码或者密码错误*/
				return [false, $folderMd5];/*不通过密码检查*/
			} else {
				return [true];
			}
			break;
		}
	}
	return [true];
	@session_write_close();
}
function renderFolderIndex($items, $isIndex)
{/*渲染目录列表*/
	global $config, $pathQuery, $pagAttr;
	$jsonArr = ['success' => true, 'currentPath' => nowPath(), 'currentPage' => $pagAttr['current'], 'nextPageExist' => $pagAttr['nextExist'], 'prevPageExist' => $pagAttr['prevExist'], 'folders' => [], 'files' => []];/*初始化Json*/
	$itemRender = '';/*文件列表渲染变量*/
	$backHref = '..';
	if (!$config['rewrite']) {/*回到上一目录*/
		$hfArr = array_filter(explode('/', $pathQuery));
		array_pop($hfArr);
		$backHref = '?/';
		foreach ($hfArr as $v) {
			$backHref .= $v . '/';
		}
	}
	if ($isIndex !== '/') {
		$itemRender = item("folder", "..", false, false, $backHref);/*渲染列表中返回上级目录的一项*/
	}
	$passwordPage = '';/*密码页面*/
	$passwordChallenge = pwdChallenge();/*先校验当前目录有没有经过密码保护*/
	$passwordVerify = $passwordChallenge[0];/*目录是否被密码保护*/
	if (!$passwordVerify) {/*未经过密码校验*/
		$folderMd5 = $passwordChallenge[1];
		$passwordPage = pwdForm($folderMd5);
		$jsonArr['success'] = false;/*请求失败状态也失败*/
		$jsonArr['msg'] = 'Password required,please post the form: requestfolder=' . $folderMd5 . '&password=<thepassword>';
	} else {
		foreach ($items as $v) {
			if (isset($v['folder'])) {/*是目录*/
				$jsonArr['folders'][] = ['createdDateTime' => $v['createdDateTime'], 'lastModifiedDateTime' => $v['lastModifiedDateTime'], 'name' => $v['name'], 'size' => $v['size'], 'link' => processHref($v['name'] . '/')];
				$itemRender .= item("folder", $v['name'], $v, $v['size'], processHref($v['name'] . '/'));
			} else if (isset($v['file'])) {/*是文件*/
				if ($v['name'] == $config['pwd_cfg_path']) continue;/*不显示password配置文件*/
				$hf = $config['preview'] ? $v['name'] . '?p=t' : $v['name'];/*如果开了预览，所有文件都加上预览请求*/
				$jsonArr['files'][] = ['createdDateTime' => $v['createdDateTime'], 'lastModifiedDateTime' => $v['lastModifiedDateTime'], 'mimeType' => $v['file']['mimeType'], 'name' => $v['name'], 'size' => $v['size'], 'link' => processHref($hf)];
				$itemRender .= item(mime2icon($v['file']['mimeType']), $v['name'], $v, $v['size'], processHref($hf));
			}
		}
	}
	return $config['list_as_json'] ? json_encode($jsonArr, true) : ($passwordVerify ?  renderHTML($itemRender) : $passwordPage);
}
function renderHTML($items)
{
	global $config, $pagAttr;
	$templateBody = getTp('body');
	$templatePag = getTp('paginationsingle');
	$templatePrev = getTp('paginationprev');
	$templateNext = getTp('paginationnext');
	$currentPage = $pagAttr['current'];
	if (($pagAttr['prevExist'] || $pagAttr['nextExist']) && $config['pagination']) {/*有翻页存在*/
		$templatePrev = rpTp('prevlink', processHref('?o=' . ($currentPage - 1)), $templatePrev);
		$templateNext = rpTp('nextlink', processHref('?o=' . ($currentPage + 1)), $templateNext);
		$templatePag = $pagAttr['prevExist'] ? rpTp('prev', $templatePrev, $templatePag) : rpTp('prev', '', $templatePag);
		$templatePag = $pagAttr['nextExist'] ? rpTp('next', $templateNext, $templatePag) : rpTp('next', '', $templatePag);;
		$templateBody = rpTp('pagination', $templatePag, $templateBody);/*展示翻页*/
	} else {
		$templateBody = rpTp('pagination', '', $templateBody);/*不展示翻页*/
	}
	$construct = rpTp('path', nowPath(), $templateBody);
	$construct = rpTp('pathitems', pathItems(), $construct);
	$construct = rpTp('currentpage', $currentPage, $construct);
	$construct = rpTp('items', $items, $construct);
	$homePath = $config['rewrite'] ? '/' : '?/';/*兼容没有使用重定向的情况*/
	$construct = rpTp('homepath', $homePath, $construct);
	$construct = rpTp('readmefile', processHref('readme.md'), $construct);
	return $construct;
}
function handleFile($url, $rawData, $forceOrigin = false)
{/*forceorigin为true时强制不用代理，这里用于缩略图*/
	global $config;
	$passwordPage = '';/*密码页面*/
	$passwordChallenge = pwdChallenge();/*先校验当前目录有没有经过密码保护*/
	$passwordVerify = $passwordChallenge[0];/*目录是否被密码保护*/
	if (!$passwordVerify) {/*未经过密码校验*/
		$folderMd5 = $passwordChallenge[1];
		$passwordPage = pwdForm($folderMd5);
		$jsonArr['success'] = false;/*请求失败状态也失败*/
		$jsonArr['msg'] = 'Password required,please post the form: requestfolder=' . $folderMd5 . '&password=<thepassword>';
		$json = json_encode($jsonArr, true);
		echo $config['list_as_json'] ? $json : $passwordPage;
		return false;
	}
	$url = substr($url, 6);
	$redirectUrl = ($config['use_proxy'] && !$forceOrigin) ? (($config['proxy_path'] ? $config['proxy_path'] : $config['site_path'] . '/odproxy.php') . '?' . urlencode($url)) : $url;
	$json = !$forceOrigin ? json_encode(['success' => true, 'fileurl' => $redirectUrl, 'createdDateTime' => $rawData['createdDateTime'], 'lastModifiedDateTime' => $rawData['lastModifiedDateTime'], 'mimeType' => $rawData['file']['mimeType']], true) : '[]';/*初始化Json*/
	if ($config['list_as_json']) {
		echo $json;
	} else {
		header('Location: ' . $redirectUrl);
	}
}
function contentPreview($url, $size)
{/*预览文本类检查器（包装在request外面，用于判断文件是否超出预览大小*/
	global $config;
	if (intval($size) > $config['max_preview_size']) {
		$jsonArr['msg'] = 'Exceeded max preview size.';
		return 'Exceeded max preview size 文件超出了最大预览大小';
	} else {
		return request($url, '', 'GET', array());
	}
}
function handlePreview($url, $data)
{/*预览渲染器(文件直链，后缀)*/
	global $config;
	if ($config['no_index']) return $config['no_index_print'];
	$suffix = suffix($data['name']);
	$fileName = $data['name'];
	$size = $data['size'];
	$createdTime = $data['createdDateTime'];
	$lastModifiedTime = $data['lastModifiedDateTime'];
	$mimeType = $data['file']['mimeType'];
	$passwordPage = '';/*密码页面*/
	$passwordChallenge = pwdChallenge();/*先校验当前目录有没有经过密码保护*/
	$passwordVerify = $passwordChallenge[0];/*目录是否被密码保护*/
	if (!$passwordVerify) {/*未经过密码校验*/
		$folderMd5 = $passwordChallenge[1];
		$passwordPage = pwdForm($folderMd5);
		$jsonArr['success'] = false;/*请求失败状态也失败*/
		$jsonArr['msg'] = 'Password required,please post the form: requestfolder=' . $folderMd5 . '&password=<thepassword>';
		$json = json_encode($jsonArr, true);
		return $config['list_as_json'] ? $json : $passwordPage;
	}
	$suffix = strtolower($suffix);
	$jsonArr = ['success' => false, 'msg' => 'Preview not available under list_as_json Mode'];
	if (in_array($suffix, $config['preview_suffix'])) {
		$previewBody = getTp('previewbody');
		$template = rpTp('path', nowPath(), $previewBody);
		$template = rpTp('pathitems', pathItems(), $template);
		$template = rpTp('filename', $fileName, $template);
		$previewContent = '';
		switch ($suffix) {
			case 'docx':
			case 'pptx':
			case 'xlsx':
			case 'doc':
			case 'ppt':
			case 'xls':
				$previewContent = getTp('officepreview');
				$template = rpTp('previewurl', 'https://view.officeapps.live.com/op/view.aspx?src=' . urlencode($url), $template);
				break;
			case 'txt':
				$txtContent = contentPreview($url, $size);/*获得文件内容*/
				$previewContent = getTp('txtpreview');
				$previewContent = rpTp('filecontent', trim($txtContent), $previewContent);
				break;
			case 'md':
			case 'markdown':
				$mdContent = contentPreview($url, $size);/*获得文件内容*/
				$previewContent = getTp('mdpreview');
				$previewContent = rpTp('filecontent', trim($mdContent), $previewContent);
				break;
			case 'jpg':
			case 'jpeg':
			case 'png':
			case 'gif':
			case 'webp':
				$previewContent = getTp('imgpreview');
				break;
			case 'mp4':
			case 'webm':
				$previewContent = getTp('videopreview');
				break;
			case 'js':
			case 'html':
			case 'json':
			case 'css':
				$codeContent = contentPreview($url, $size);/*获得文件内容*/
				$previewContent = getTp('codepreview');
				$previewContent = rpTp('filecontent', htmlspecialchars(trim($codeContent)), $previewContent);
				$previewContent = rpTp('prismtag', $suffix, $previewContent);
				break; //还差音频预览ogg', 'mp3', 'wav', 'm4a
			case 'ogg':
			case 'mp3':
			case 'wav':
			case 'm4a':
				$previewContent = getTp('audiopreview');
				break;
			default:
				$previewContent = 'template not exist.';
				break;
		}
		$template = rpTp('previewContent', $previewContent, $template);
		$homePath = $config['rewrite'] ? '/' : '?/';/*兼容没有使用重定向的情况*/
		$template = rpTp('homepath', $homePath, $template);
		$template = rpTp('filerawurl', $url, $template);
		$template = rpTp('createddatetime', $createdTime, $template);
		$template = rpTp('lastmodifieddatetime', $lastModifiedTime, $template);
		$template = rpTp('mimetype', $mimeType, $template);
		return $config['list_as_json'] ? json_encode($jsonArr, true) : $template;/*json返回模式下不支持预览*/
	} else {
		return handleFile($url, $data);/*文件格式不支持预览，直接传递给文件下载*/
	}
}
function cacheControl($mode, $path, $requestArr = false)
{/*缓存控制*/
	global $config;
	$cacheConf = getConfig('cache.json');
	$returnValue = true;
	$startTime = $cacheConf['cache_start'];
	if ($config['cache']['force'] && !$cacheConf['cache_start']) {/*如果强制开启了缓存*/
		$cacheConf['cache_start'] = time();/*重设定缓存开始时间*/
	}
	if ($startTime && ((time() - $startTime) >= $config['cache']['expire'])) {/*超出缓存时间*/
		$cacheConf['cache_start'] = false;
		cacheClear();/*清除过期缓存*/
		$returnValue = false;
	} else if (ifCacheStart()) {/*缓存模式开启*/
		$file = 'cache/' . md5($path) . '.json';
		if ($mode == 'write') {/*路径存在，写入缓存*/
			if (!getConfig($file)) writeConfig($file, $requestArr);/*缓存不存在的情况下才写入*/
		} else if ($mode == 'read') {/*路径存在，读缓存*/
			$returnValue = getConfig($file);/*缓存不存在会返回false*/
		} else {/*缓存不存在*/
			$returnValue = false;
		}
	} else {
		$returnValue = false;
	}
	writeConfig('cache.json', $cacheConf);
	return $returnValue;
}
function cacheClear($singleCachePath = false)
{/*缓存清除*/
	global $config;
	if ($singleCachePath) {/*删除单个缓存*/
		$file = md5($singleCachePath) . '.json';
		unlink(p($config['data_path'] . '/cache/' . $file));
		return true;
	}
	$cacheFiles = scandir(p($config['data_path'] . '/cache'));
	foreach ($cacheFiles as $v) {/*没有指定就清空所有缓存*/
		if ($v !== '.' && $v !== '..') unlink(p($config['data_path'] . '/cache/' . $v));
	}
}
function ifCacheStart()
{/*缓存是否开启了*/
	global $config;
	$cacheStatus = getConfig('cache.json');
	return ($cacheStatus['cache_start'] > 0 && $config['cache']['smart']);
}
function autoCache()
{/*处理缓存(还包括队列)*/
	global $config, $cacheInitialization;
	if (!$config['cache']['smart']) return false;/*未开启缓存直接返回*/
	$keyNum = count($cacheInitialization);/*校验初始化的时候配置文件有几个键*/
	$cacheNow = getConfig('cache.json');
	if (count($cacheNow) !== $keyNum) $cacheNow = $cacheInitialization;/*如果校验发现配置丢失就重新初始化*/
	$queueConf = getConfig('queue.json');
	$lag = time() - $cacheNow['last_count'];
	if ($lag >= 30) {
		$velo = round($cacheNow['requests'] / $lag, 2);/*获得速度,至少统计30秒*/
		$cacheNow['last_count'] = time();
		array_push($cacheNow['periods'], $velo);
		if (count($cacheNow['periods']) > 10) array_shift($cacheNow['periods']);
		$cacheNow['requests'] = 0;
	} else {
		$cacheNow['requests'] += 1;
		$velo = false;
	}
	$periodsNum = count($cacheNow['periods']);
	$average = empty($periodsNum) ? 0 : @array_sum($cacheNow['periods']) / $periodsNum;
	if (!$cacheNow['cache_start'] && ($velo && $velo >= 0.9 || $average > 0.5)) $cacheNow['cache_start'] = time();/*开启智能缓存*/
	if (!$queueConf['start'] && ($velo && $velo >= 1.5 || $average > 1.2)) $queueConf['start'] = time();/*开启队列*/
	writeConfig('cache.json', $cacheNow);
	writeConfig('queue.json', $queueConf);
}
function queueTimeCheck()
{
	global $config;
	$queueConf = getConfig('queue.json');/*拿到队列的记录文件*/
	if ($config['queue']['start'] && $queueConf['start']) {
		$lag = isset($queueConf['start']) ? time() - intval($queueConf['start']) : 0;/*计算自开始队列之后过去多久了*/
		if ($lag >= $config['queue']['last_for']) {/*超过了持续时间，关闭队列*/
			$queueConf['start'] = false;
			$queueConf['performing'] = '';
			$queueConf['requesting'] = 0;
		}
		writeConfig('queue.json', $queueConf);/*储存配置*/
	}
}
function queueChecker($statu, $waiting = false, $id = false)
{/*处理队列*/
	global $config;
	$returnID = md5(time());
	$queueConf = getConfig('queue.json');/*拿到队列的记录文件*/
	if (!$config['queue']['start'] || !$queueConf['start']) return false;/*未开启队列直接返回*/
	usleep(500000);/*进来先等0.5秒*/
	if (intval($queueConf['requesting']) >= $config['queue']['max_num'] && !$waiting && $queueConf['performing'] !== $id) {/*请求的量过大*/
		header('Location: ' . $config['service_busy']);/*返回服务繁忙的图片*/
		exit();
	}
	while (!empty($queueConf['performing'])) {/*有请求正在执行，阻塞*/
		$queueConf['requesting'] = !$waiting ? intval($queueConf['requesting']) + 1 : $queueConf['requesting'];/*增加请求*/
		usleep(500000);/*等0.5s*/
		if ($queueConf['performing'] == $id) break;/*如果是正在执行的请求，不阻塞*/
		return queueChecker($statu, true, $id);/*waiting标记为true，不会被当成新请求返回服务繁忙*/
	}
	switch ($statu) {
		case "add":/*请求添加到队列*/
			$queueConf['performing'] = $returnID;
			break;
		case "del":
			$returnID = $id;
			$queueConf['performing'] = '';
			$queueConf['requesting'] > 0 ? $queueConf['requesting'] -= 1 : $queueConf['requesting'] = 0;/*请求执行完了*/
			break;
		case 'check':
			break;
	}
	queueTimeCheck();/*检查是否超时*/
	writeConfig('queue.json', $queueConf);/*储存配置*/
	return $returnID;/*把执行的id传回去*/
}

/*Password Receiver*/
@session_start();
$pwdRqFolder = @$_POST['requestfolder'];
$passwd = @$_POST['password'];
if (!empty($pwdRqFolder)) {
	$_SESSION['passwd'][$pwdRqFolder] = md5($passwd);/*提交并储存密码*/
}
@session_write_close();
/*Password Receiver End*/

/*Request Path Processor*/
$pathQuery = preg_replace('~/~', '', htmlChars($_SERVER['QUERY_STRING']), 1);/*Get query*/
if (empty($pathQuery)) {
	$pathQuery = '';
}
$self = basename($_SERVER['PHP_SELF']);
$pathQuery = str_ireplace($self, '', $pathQuery);
$requesturl = 'http://request.yes/' . $pathQuery;
/*Request Path ProcessorEnd*/

queueTimeCheck();/*检查是否超时*/

/*Cache Processor*/
$ifRequestFolder = substr(parsePath($requesturl), -1) == '/' ? true : false;/*如果请求路径以/结尾就算请求的是目录*/
$cachepath = '/' . $pathQuery;
if (!pwdChallenge()[0]) cacheClear($cachepath);/*如果没通过密码验证就删除缓存*/
$cache = cacheControl('read', $cachepath);
/*Cache Processor End*/

if (ifCacheStart() && !empty($cache[0]) && empty($pwdRqFolder)) {
	$output = $ifRequestFolder ? $cache[0] : handleRequest($requesturl);
} else {
	$output = handleRequest($requesturl);
	if (ifCacheStart() && pwdChallenge()[0]) cacheControl('write', $cachepath, array($output));
}

if ($config['list_as_json']) header('Content-type:text/json;charset=utf-8');/*如果以Json返回就设定头*/

echo $output;
