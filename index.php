<?php
/*
Original design by Heymind:
https://github.com/heymind/OneDrive-Index-Cloudflare-Worker
Transplanted by Somebottle.
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
	'datapath' => 'data',
	'rewrite' => false,
	'sitepath' => '',
	"cache" => array(
		'smart' => true,
		'expire' => 1800, /*In seconds*/
		'force' => false /*是否强制开启缓存*/
	),
	'queue' => array(
		'start' => true,/*防并发请求队列*/
		'maxnum' => 15,/*队列中允许停留的最多请求数，其他请求直接返回服务繁忙*/
		'lastfor' => 2700 /*In seconds*/
	),
	'servicebusy' => 'https://cdn.jsdelivr.net/gh/SomeBottle/odindex/assets/unavailable.png',/*队列过多时返回的“服务繁忙”图片url*/
	'thumbnail' => true,
	'preview' => true,
	'maxpreviewsize' => 314572, /*最大支持预览的文件大小(in bytes)*/
	'previewsuffix' => ['ogg', 'mp3', 'wav', 'm4a', 'mp4', 'webm', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'md', 'markdown', 'txt', 'docx', 'pptx', 'xlsx', 'doc', 'ppt', 'xls', 'js', 'html', 'json', 'css'],/*可预览的类型,只少不多*/
	'useProxy' => false,
	'proxyPath' => false, /*代理程序url，false则用本目录下的*/
	'noIndex' => false, /*关闭列表*/
	'noIndexPrint' => 'Static powered by OdIndex', /*关闭列表访问列表时返回什么*/
	'listAsJson' => false, /*改为返回json*/
	'pwdCfgPath' => '.password', /*密码配置文件路径*/
	'pwdProtect' => true,/*是否采用密码保护，这会稍微多占用一些程序资源*/
	'pwdConfigUpdateInterval' => 1200, /*密码配置文件本地缓存时间(in seconds)*/
	'pagination' => true, /*是否开启分页*/
	'itemsPerPage' => 10 /*每页的项目数量，用于分页*/
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
	if (is_array($arr)) file_put_contents(p($config['datapath'] . '/' . $file), json_encode($arr, true));
}
function getConfig($file)
{/*获得配置*/
	global $config;
	$path = p($config['datapath'] . '/' . $file);
	if (!file_exists($path)) return false;/*配置文件不存在直接返回false*/
	return json_decode(file_get_contents($path), true);
}
function getTp($section)
{/*获取模板中特定的section*/
	$f = file_get_contents(p("template.html"));
	$aftersec = array_filter(preg_split("/\{\{" . $section . "\}\}/i", $f))[1];
	$sec = array_merge(array_filter(preg_split("/\{\{" . $section . "End\}\}/i", $aftersec)))[0];
	return trim($sec);
}
function htmlchars($content)
{/*替换<>符*/
	return str_replace('>', '&gt;', str_replace('<', '&lt;', $content));
}
function rpTp($from, $to, $origin)
{/*替换模板标识符*/
	return preg_replace("/\\{\\[" . $from . "\\]\\}/i", $to, $origin);
}
/*smartCache*/
if (!is_dir(p($config['datapath']))) mkdir(p($config['datapath']));
if (!is_dir(p($config['datapath'] . '/cache'))) mkdir(p($config['datapath'] . '/cache'));/*如果没有cache目录就创建cache目录*/
$cacheInitialization = array(/*Initialize conf初始化缓存配置记录文件*/
	'requests' => 0,
	'lastcount' => time(),
	'periods' => array(),
	'cachestart' => false
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
	'lastupdate' => 0
);
if (!getConfig('pwdcache.json')) writeConfig('pwdcache.json', $pwdUpdaterInitialization);
/*InitializationFinished*/
function valueinarr($v, $a)
{/*判断数组中是否有一个值*/
	$str = is_array($a) ? join(' ', $a) : false;/*将数组并入字串符，直接判断字串符里面是否有对应值*/
	if (stripos($str, strval($v)) !== false) {
		return true;
	}
	return false;
}
function modifyarr($arr, $search, $v)
{/*搜素含有对应值的键并通过键编辑数组*/
	foreach ($arr as $k => $val) {
		if (stripos($val, strval($search)) !== false) {
			$arr[$k] = $v;
			return $arr;
		}
	}
}
function request($u, $q, $method = 'POST', $head = array('Content-type:application/x-www-form-urlencoded'), $headerget = false, $retry = false)
{/*poster*/
	$rqcontent = ($method == 'POST') ? http_build_query($q) : '';
	$opts = array(
		'http' => array(
			'method' => $method,
			'header' => $head,
			'timeout' => 15 * 60, // 超时时间（单位:s）
			'content' => $rqcontent
		)
	);
	smartCache();/*智能缓存*/
	$queueid = queueChecker('add');/*增加请求到队列*/
	if ($headerget) {/*仅请求头部模式*/
		stream_context_set_default($opts);
		$hd = @get_headers($u, 1);
		queueChecker('del', true, $queueid);/*请求完毕，移除队列*/
		if (valueinarr('401 Unauthorized', $hd) && !$retry) {/*accesstoken需要刷新*/
			$newtoken = getAccessToken(true);
			$head = modifyarr($head, 'Authorization', 'Authorization: bearer ' . $newtoken);/*获得新token重新请求*/
			return request($u, $q, $method, $head, $headerget, true);
		}
		return $hd;
	} else {/*正常请求模式*/
		$ct = stream_context_create($opts);
		$result = @file_get_contents($u, false, $ct);
		queueChecker('del', true, $queueid);/*请求完毕，移除队列*/
		$backhead = $http_response_header;/*获得返回头*/
		if (valueinarr('401 Unauthorized', $backhead) && !$retry) {/*accesstoken需要刷新*/
			$newtoken = getAccessToken(true);
			$head = modifyarr($head, 'Authorization', 'Authorization: bearer ' . $newtoken);/*获得新token重新请求*/
			return request($u, $q, $method, $head, $headerget, true);
		}
		if (!empty($result)) {
			return $result;
		} else {
			return '';
		}
	}
}
function encodeurl($u)
{/*处理URL转义，保留斜杠*/
	return str_ireplace('%2F', '/', urlencode($u));
}
function parsepath($u)
{/*得出处理后的路径*/
	global $config;
	$up = parse_url($u)['path'];
	$rt = str_ireplace($config['sitepath'], '', $up);
	if ($config['rewrite']) {
		$rt = explode('/', $rt);
		$ending = end($rt);
		$rt[count($rt) - 1] = explode('&', $ending)[0];/*rewrite开启后服务器程序会自动把?转换为&*/
		$rt = join('/', $rt);
	}
	return $rt;
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
	return $sp[count($sp) - 1];
}
function getParam($url, $param)
{/*获得url中的参数*/
	global $config;
	$prs = parse_url($url)['query'];
	if ($config['rewrite']) {/*rewrite开启后服务器程序会自动把?转换为&*/
		$prs = $url;
	}
	$prs = explode('&', $prs);
	$arr = array();
	foreach ($prs as $v) {
		$each = explode('=', $v);
		isset($each[1]) ? ($arr[$each[0]] = $each[1]) : ($arr[$each[0]] = '');/*参数输入数组*/
	}
	if (isset($arr[$param])) {
		return $arr[$param];
	} else {
		return '';
	}
}
function wrapPath($p)
{/*目录包装请求url*/
	global $config;
	$wrapped = encodeurl($config['base']) . $p;
	$wrapForFolder = substr($wrapped, -1) == '/' ? substr($wrapped, 0, strlen($wrapped) - 1) : $wrapped;/*请求目录内容的时候形如/test:/children,末尾是没有/的，需要去掉*/
	return ($wrapped == '/' || $wrapped == '') ? '' : ':' . $wrapForFolder;
}
function pagRequest($prurl, $rq, $accessToken, $ifRequestFolder)
{/*分页请求包装，算是一个Hook?*/
	global $config, $pagAttr;
	$nowPage = intval(getParam($prurl, 'o'));/*获得请求中的页码*/
	$requestedPage = 1;
	$totalPage = (empty($nowPage) || $nowPage < 0) ? 1 : $nowPage;/*如果没有页码默认是请求第一页*/
	$pagAttr['current'] = $totalPage;/*更新当前页码*/
	$resp = '';
	$linkForRequest = $rq;
	while ($requestedPage <= $totalPage) {
		$resp = request($linkForRequest, '', 'GET', array(
			'Content-type: application/x-www-form-urlencoded',
			'Authorization: bearer ' . $accessToken
		));
		if (empty($resp) || !$config['pagination'] || !$ifRequestFolder) break;/*请求到的一页都没有内容/没有开启分页/请求的不是目录就直接返回了*/
		$data = json_decode($resp, true);
		if ($requestedPage > 1) $pagAttr['prevExist'] = true;/*有上一页*/
		if (isset($data['@odata.nextLink'])) {
			$linkForRequest = $data['@odata.nextLink'];/*下次请求的链接是这个*/
			$pagAttr['nextExist'] = true;/*有下一页*/
		} else {/*没有nextLink了，说明到头了，直接返回*/
			$pagAttr['nextExist'] = false;
			break;
		}
		$requestedPage += 1;
	}
	if ($ifRequestFolder) $GLOBALS['pagAttr'] = $pagAttr;/*更新分页属性*/
	return $resp;
}
function handleRequest($url, $returnurl = false)
{
	global $config;
	$error = '';
	$accessToken = getAccessToken();/*获得accesstoken*/
	$path = parsepath($url);
	$ifRequestFolder = substr($path, -1) == '/' ? true : false;/*如果请求路径以/结尾就算请求的是目录*/
	$jsonarr = ['success' => false, 'msg' => ''];
	$path == '/' ? $path = '' : $path;/*根目录特殊处理*/
	if ($config['thumbnail']) {
		$thumb = getParam($url, 'thumbnail');/*获得缩略图尺寸*/
		$thumbnail = empty($thumb) ? false : $thumb;/*判断请求的是不是缩略图*/
	}
	if ($config['preview']) {
		$prev = getParam($url, 'p');/*获得preview请求*/
		$preview = empty($prev) ? false : $prev;
	}
	if ($thumbnail) {/*如果是请求缩略图*/
		$rq = $config['api_url'] . '/me/drive/root' . wrapPath($path) . ':/thumbnails';
		$resp = request($rq, '', 'GET', array(
			'Content-type: application/x-www-form-urlencoded',
			'Authorization: bearer ' . $accessToken
		));
		$resp = json_decode($resp, true);
		$rurl = $resp['value'][0][$thumbnail]['url'];
		if ($rurl) {
			return handleFile($rurl, [], true);
		} else {
			return false;
		}/*强制不用代理*/
	}
	/*Normally request*/
	$wrappedPath = wrapPath($path);
	$rq = $config['api_url'] . '/me/drive/root' . $wrappedPath . ($ifRequestFolder ? ((empty($wrappedPath) ? '' : ':') . '/children' . ($config['pagination'] ? '?$top=' . $config['itemsPerPage'] : '')) : '?select=name,eTag,createdDateTime,lastModifiedDateTime,size,id,folder,file,%40microsoft.graph.downloadUrl');/*从内而外：第一层判断是否有分页，第二层判断是请求的目录还算文件，第三层包装请求*/
	$cache = cacheControl('read', $path);/*请求的内容是否被缓存*/
	empty($cache[0]) ?: $queueid = queueChecker('add');/*如果有缓存也要加入队列计算*/
	$resp = (ifCacheStart() && !empty($cache[0])) ? $cache[0] : pagRequest($url, $rq, $accessToken, $ifRequestFolder);
	empty($cache[0]) ?: queueChecker('del', true, $queueid);/*如果有缓存也要移除队列计算*/
	if ($resp) {
		$data = json_decode($resp, true);
		if (isset($data['file'])) {/*返回的是文件*/
			if ($returnurl) return $data["@microsoft.graph.downloadUrl"];/*直接返回Url，用于取得文件内容*/
			if ($data['name'] == $config['pwdCfgPath']) die('Access denied');/*阻止密码被获取到*/
			if (ifCacheStart() && !$cache) cacheControl('write', $path, array($resp));/*只有下载链接储存缓存*/
			/*构建文件下载链接缓存*/
			if ($preview == 't') {/*预览模式*/
				return handlePreview($data["@microsoft.graph.downloadUrl"], $data);/*渲染预览*/
			} else {
				handleFile($data["@microsoft.graph.downloadUrl"], $data);
			}
		} else if (isset($data['value'])) {/*返回的是目录*/
			$render = renderFolderIndex($data['value'], parsepath($url));/*渲染目录*/
			return $render;
		} else {
			$jsonarr['msg'] = 'Error response:' . var_export($resp, true);
			return ($config['listAsJson'] ? json_encode($jsonarr, true) : 'Error response:' . var_export($resp, true));
		}
	} else {
		$jsonarr['msg'] = 'Not found: ' . urldecode($path);
		return ($config['listAsJson'] ? json_encode($jsonarr, true) : '<!--NotFound:' . urldecode($path) . '-->');
	}
}
function item($icon, $filename, $rawdata = false, $size = false, $href = false)
{
	$singleItem = getTp('itemsingle');/*获得单个项目列表的模板*/
	$href = $href ?: $href = $filename;/*没有指定链接自动指定文件名*/
	$size = $size ? $size : 0;/*在标签附上文件大小*/
	if ($rawdata) {
		$singleItem = rpTp('createddatetime', $rawdata['createdDateTime'], $singleItem);
		$singleItem = rpTp('lastmodifieddatetime', $rawdata['lastModifiedDateTime'], $singleItem);
		$singleItem = rpTp('mimetype', $rawdata['file']['mimeType'], $singleItem);
	}
	$singleItem = rpTp('itemlink', $href, $singleItem);
	$singleItem = rpTp('itemsize', $size, $singleItem);
	$singleItem = rpTp('mimeicon', $icon, $singleItem);
	$singleItem = rpTp('itemname', $filename, $singleItem);/*构造项目模板*/
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
function processhref($hf)
{/*根据伪静态是否开启处理href*/
	global $config, $pr;
	if (!$config['rewrite']) {
		$pr_parts = explode('?', $pr);
		$pr = $pr_parts[0];/*未开启伪静态要净化一下pr*/
		return '?/' . $pr . $hf;/*没开伪静态，请求处理*/
	}
	return $hf;
}
function nowPath()
{/*获得当前所在路径*/
	global $config, $pr;
	if ($config['rewrite']) {
		$pr_parts = explode('/', urldecode($pr));
		if (stripos(end($pr_parts), '&') !== false) array_pop($pr_parts);
		$pr = join('/', $pr_parts);
	}
	return $pr;
}
function trimall($str)
{/*去除空格和换行*/
	$arr = array(" ", "　", "\t", "\n", "\r");
	return str_replace($arr, '', $str);
}
function pathitems()
{/*生成当前路径模板*/
	global $config, $pr;
	$folders = explode('/', $pr);
	$lastValue = end($folders);/*观察数组最后一个值，也就是当前路径的末尾有没有请求符*/
	if ($config['rewrite'] && stripos($lastValue, '&') !== false) {/*这个问题只会在开启了伪静态的情况下出现，当目录最后一节中为请求符的时候自动忽略*/
		array_pop($folders);
	}
	$folders = array_filter($folders);/*上面一步处理完之后删除不显示的空节*/
	$singlePath = getTp('pathsingle');
	$allPath = '';
	$currentPath = $config['sitepath'] . ($config['rewrite'] ? '/' : '?/');/*兼容没有使用重定向的情况*/
	foreach ($folders as $val) {
		$currentPath .= $val . '/';
		$single = rpTp('folderlink', $currentPath, $singlePath);
		$single = rpTp('foldername', urldecode($val), $single);
		$allPath .= $single . ' / ';
	}
	return $allPath;
}
function passwordform($fmd5)/*当notinlist为true时代表不是在列表里调用的passwordform*/
{/*密码输入模板*/
	global $pr, $config;
	$passwordPage = getTp('passwordpage');
	$content = rpTp('path', nowPath(), $passwordPage);
	$content = rpTp('pathitems', pathitems(), $content);
	$content = rpTp('foldermd5', $fmd5, $content);
	$homePath = $config['rewrite'] ? '/' : '?/';/*兼容没有使用重定向的情况*/
	$content = rpTp('homepath', $homePath, $content);
	return $content;
}
function pwdConfigReader()
{/*更新配置缓存并读取密码保护配置*/
	global $config;
	$pwdCacheUpdate = getConfig('pwdcache.json');/*获取密码配置更新情况*/
	if (time() - $pwdCacheUpdate['lastupdate'] >= $config['pwdConfigUpdateInterval']) {/*该更新密码配置缓存了*/
		$requesturl = 'http://request.yes/' . $config['pwdCfgPath'];/*密码配置文件在根目录*/
		$downurl = handleRequest($requesturl, true);/*获得密码文件下载链接*/
		$remoteConfig = @request($downurl, '', 'GET', array());/*请求到文件*/
		file_put_contents(p('pwdcache.php'), '<?php $pwdcfgcache="' . base64_encode(trim($remoteConfig)) . '";?>');
		$pwdCacheUpdate['lastupdate'] = time();
		writeConfig('pwdcache.json', $pwdCacheUpdate);
	}
	require p('pwdcache.php');
	$pwdcfg = explode(PHP_EOL, base64_decode($pwdcfgcache));
	return $pwdcfg;
}
function pwdChallenge()
{
	global $pr, $config;
	if (!$config['pwdProtect']) return [true];/*没有开启密码保护一律通行*/
	$pwdcfg = pwdConfigReader();/*取得密码配置文件*/
	@session_start();
	if (!isset($_SESSION['passwd'])) $_SESSION['passwd'] = array();/*密码是否存在*/
	$currentPath = '/' . urldecode($pr);/*构造当前的路径，比如/Videos/ACG/*/
	foreach ($pwdcfg as $line) {
		$singleconfig = explode(' ', $line);
		$targetFolder = $singleconfig[0];/*获得每行配置的目标目录*/
		$targetPwd = trim($singleconfig[1]);/*获得每行目录对应的md5密码*/
		if (empty($targetFolder)) continue;/*如果配置目录为空就跳过，防止匹配bug*/
		if (stripos($currentPath, $targetFolder) === 0) {/*当前目录能匹配上目标目录，受密码保护*/
			$foldermd5 = md5($targetFolder);/*得到目标foldermd5*/
			if (!isset($_SESSION['passwd'][$foldermd5]) || $targetPwd !== $_SESSION['passwd'][$foldermd5]) {/*没有post密码或者密码错误*/
				return [false, $foldermd5];/*不通过密码检查*/
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
	global $config, $pr, $pagAttr;
	if ($config['noIndex']) return $config['noIndexPrint'];
	$jsonarr = ['success' => true, 'currentPath' => nowPath(), 'currentPage' => $pagAttr['current'], 'nextPageExist' => $pagAttr['nextExist'], 'prevPageExist' => $pagAttr['prevExist'], 'folders' => [], 'files' => []];/*初始化Json*/
	$itemrender = '';/*文件列表渲染变量*/
	$backhref = '..';
	if (!$config['rewrite']) {/*回到上一目录*/
		$hfarr = array_filter(explode('/', $pr));
		array_pop($hfarr);
		$backhref = '?/';
		foreach ($hfarr as $v) {
			$backhref .= $v . '/';
		}
	}
	if ($isIndex !== '/') {
		$itemrender = item("folder", "..", false, false, $backhref);/*在根目录*/
	}
	$passwordPage = '';/*密码页面*/
	$passwordChallenge = pwdChallenge();/*先校验当前目录有没有经过密码保护*/
	$passwordVerify = $passwordChallenge[0];/*目录是否被密码保护*/
	if (!$passwordVerify) {/*未经过密码校验*/
		$foldermd5 = $passwordChallenge[1];
		$passwordPage = passwordform($foldermd5);
		$jsonarr['success'] = false;/*请求失败状态也失败*/
		$jsonarr['msg'] = 'Password required,please post the form: requestfolder=' . $foldermd5 . '&password=<thepassword>';
	} else {
		foreach ($items as $v) {
			if (isset($v['folder'])) {/*是目录*/
				$jsonarr['folders'][] = ['createdDateTime' => $v['createdDateTime'], 'lastModifiedDateTime' => $v['lastModifiedDateTime'], 'name' => $v['name'], 'size' => $v['size'], 'link' => processhref($v['name'] . '/')];
				$itemrender .= item("folder", $v['name'], $v, $v['size'], processhref($v['name'] . '/'));
			} else if (isset($v['file'])) {/*是文件*/
				if ($v['name'] == $config['pwdCfgPath']) continue;/*不显示password配置文件*/
				$hf = $config['preview'] ? $v['name'] . '?p=t' : $v['name'];/*如果开了预览，所有文件都加上预览请求*/
				$jsonarr['files'][] = ['createdDateTime' => $v['createdDateTime'], 'lastModifiedDateTime' => $v['lastModifiedDateTime'], 'mimeType' => $v['file']['mimeType'], 'name' => $v['name'], 'size' => $v['size'], 'link' => processhref($hf)];
				$itemrender .= item(mime2icon($v['file']['mimeType']), $v['name'], $v, $v['size'], processhref($hf));
			}
		}
	}
	return $config['listAsJson'] ? json_encode($jsonarr, true) : ($passwordVerify ?  renderHTML($itemrender) : $passwordPage);
}
function renderHTML($items)
{
	global $pr, $config, $pagAttr;
	$templateBody = getTp('body');
	$templatePag = getTp('paginationsingle');
	$templatePrev = getTp('paginationprev');
	$templateNext = getTp('paginationnext');
	$currentPage = $pagAttr['current'];
	if (($pagAttr['prevExist'] || $pagAttr['nextExist']) && $config['pagination']) {/*有翻页存在*/
		$templatePrev = rpTp('prevlink', processhref('?o=' . ($currentPage - 1)), $templatePrev);
		$templateNext = rpTp('nextlink', processhref('?o=' . ($currentPage + 1)), $templateNext);
		$templatePag = $pagAttr['prevExist'] ? rpTp('prev', $templatePrev, $templatePag) : rpTp('prev', '', $templatePag);
		$templatePag = $pagAttr['nextExist'] ? rpTp('next', $templateNext, $templatePag) : rpTp('next', '', $templatePag);;
		$templateBody = rpTp('pagination', $templatePag, $templateBody);/*展示翻页*/
	} else {
		$templateBody = rpTp('pagination', '', $templateBody);/*不展示翻页*/
	}
	$construct = rpTp('path', nowPath(), $templateBody);
	$construct = rpTp('pathitems', pathitems(), $construct);
	$construct = rpTp('currentpage', $currentPage, $construct);
	$construct = rpTp('items', $items, $construct);
	$homePath = $config['rewrite'] ? '/' : '?/';/*兼容没有使用重定向的情况*/
	$construct = rpTp('homepath', $homePath, $construct);
	$construct = rpTp('readmefile', processhref('readme.md'), $construct);
	return $construct;
}
function handleFile($url, $rawdata, $forceorigin = false)
{/*forceorigin为true时强制不用代理，这里用于缩略图*/
	global $config;
	$passwordPage = '';/*密码页面*/
	$passwordChallenge = pwdChallenge();/*先校验当前目录有没有经过密码保护*/
	$passwordVerify = $passwordChallenge[0];/*目录是否被密码保护*/
	if (!$passwordVerify) {/*未经过密码校验*/
		$foldermd5 = $passwordChallenge[1];
		$passwordPage = passwordform($foldermd5, true);
		$jsonarr['success'] = false;/*请求失败状态也失败*/
		$jsonarr['msg'] = 'Password required,please post the form: requestfolder=' . $foldermd5 . '&password=<thepassword>';
		$json = json_encode($jsonarr, true);
		echo $config['listAsJson'] ? $json : $passwordPage;
		return false;
	}
	$url = substr($url, 6);
	$rdurl = ($config['useProxy'] && !$forceorigin) ? (($config['proxyPath'] ? $config['proxyPath'] : $config['sitepath'] . '/odproxy.php') . '?' . urlencode($url)) : $url;
	$json = !$forceorigin ? json_encode(['success' => true, 'fileurl' => $rdurl, 'createdDateTime' => $rawdata['createdDateTime'], 'lastModifiedDateTime' => $rawdata['lastModifiedDateTime'], 'mimeType' => $rawdata['file']['mimeType']], true) : '[]';/*初始化Json*/
	if ($config['listAsJson']) {
		echo $json;
	} else {
		header('Location: ' . $rdurl);
	}
}
function previewcontent($url, $size)
{/*预览文本类检查器（包装在request外面，用于判断文件是否超出预览大小*/
	global $config;
	if (intval($size) > $config['maxpreviewsize']) {
		$jsonarr['msg'] = 'Exceeded max preview size.';
		return 'Exceeded max preview size 文件超出了最大预览大小';
	} else {
		return request($url, '', 'GET', array());
	}
}
function handlePreview($url, $data)
{/*预览渲染器(文件直链，后缀)*/
	global $config, $pr;
	if ($config['noIndex']) return $config['noIndexPrint'];
	$suffix = suffix($data['name']);
	$filename = $data['name'];
	$size = $data['size'];
	$createdTime = $data['createdDateTime'];
	$lastModifiedTime = $data['lastModifiedDateTime'];
	$mimeType = $data['file']['mimeType'];
	$passwordPage = '';/*密码页面*/
	$passwordChallenge = pwdChallenge();/*先校验当前目录有没有经过密码保护*/
	$passwordVerify = $passwordChallenge[0];/*目录是否被密码保护*/
	if (!$passwordVerify) {/*未经过密码校验*/
		$foldermd5 = $passwordChallenge[1];
		$passwordPage = passwordform($foldermd5);
		$jsonarr['success'] = false;/*请求失败状态也失败*/
		$jsonarr['msg'] = 'Password required,please post the form: requestfolder=' . $foldermd5 . '&password=<thepassword>';
		$json = json_encode($jsonarr, true);
		return $config['listAsJson'] ? $json : $passwordPage;
	}
	$suffix = strtolower($suffix);
	$jsonarr = ['success' => false, 'msg' => 'Preview not available under listAsJson Mode'];
	if (in_array($suffix, $config['previewsuffix'])) {
		$previewBody = getTp('previewbody');
		$template = rpTp('path', nowPath(), $previewBody);
		$template = rpTp('pathitems', pathitems(), $template);
		$template = rpTp('filename', $filename, $template);
		$previewcontent = '';
		switch ($suffix) {
			case 'docx':
			case 'pptx':
			case 'xlsx':
			case 'doc':
			case 'ppt':
			case 'xls':
				$previewcontent = getTp('officepreview');
				$template = rpTp('previewurl', 'https://view.officeapps.live.com/op/view.aspx?src=' . urlencode($url), $template);
				break;
			case 'txt':
				$txtcontent = previewcontent($url, $size);/*获得文件内容*/
				$previewcontent = getTp('txtpreview');
				$previewcontent = rpTp('filecontent', trim($txtcontent), $previewcontent);
				break;
			case 'md':
			case 'markdown':
				$mdcontent = previewcontent($url, $size);/*获得文件内容*/
				$previewcontent = getTp('mdpreview');
				$previewcontent = rpTp('filecontent', trim($mdcontent), $previewcontent);
				break;
			case 'jpg':
			case 'jpeg':
			case 'png':
			case 'gif':
			case 'webp':
				$previewcontent = getTp('imgpreview');
				break;
			case 'mp4':
			case 'webm':
				$previewcontent = getTp('videopreview');
				break;
			case 'js':
			case 'html':
			case 'json':
			case 'css':
				$codecontent = previewcontent($url, $size);/*获得文件内容*/
				$previewcontent = getTp('codepreview');
				$previewcontent = rpTp('filecontent', htmlspecialchars(trim($codecontent)), $previewcontent);
				$previewcontent = rpTp('prismtag', $suffix, $previewcontent);
				break; //还差音频预览ogg', 'mp3', 'wav', 'm4a
			case 'ogg':
			case 'mp3':
			case 'wav':
			case 'm4a':
				$previewcontent = getTp('audiopreview');
				break;
			default:
				$previewcontent = 'template not exist.';
				break;
		}
		$template = rpTp('previewcontent', $previewcontent, $template);
		$homePath = $config['rewrite'] ? '/' : '?/';/*兼容没有使用重定向的情况*/
		$template = rpTp('homepath', $homePath, $template);
		$template = rpTp('filerawurl', $url, $template);
		$template = rpTp('createddatetime', $createdTime, $template);
		$template = rpTp('lastmodifieddatetime', $lastModifiedTime, $template);
		$template = rpTp('mimetype', $mimeType, $template);
		return $config['listAsJson'] ? json_encode($jsonarr, true) : $template;/*json返回模式下不支持预览*/
	} else {
		return handleFile($url, $data);/*文件格式不支持预览，直接传递给文件下载*/
	}
}
function cacheControl($mode, $path, $requestArr = false)
{/*缓存控制*/
	global $config;
	$cacheConf = getConfig('cache.json');
	$rt = true;
	$starttime = $cacheConf['cachestart'];
	if ($config['cache']['force'] && !$cacheConf['cachestart']) {/*如果强制开启了缓存*/
		$cacheConf['cachestart'] = time();
	}
	if ($starttime && ((time() - $starttime) >= $config['cache']['expire'])) {/*超出缓存时间*/
		$cacheConf['cachestart'] = false;
		cacheClear();
		$rt = false;
	} else if (ifCacheStart()) {/*缓存模式开启*/
		$file = 'cache/' . md5($path) . '.json';
		if ($mode == 'write') {/*路径存在，写入缓存*/
			writeConfig($file, $requestArr);
		} else if ($mode == 'read') {/*路径存在，读缓存*/
			$rt = getConfig($file);/*缓存不存在会返回false*/
		} else {/*缓存不存在*/
			$rt = false;
		}
	} else {
		$rt = false;
	}
	writeConfig('cache.json', $cacheConf);
	return $rt;
}
function cacheClear()
{/*缓存清除*/
	global $config;
	$cfile = scandir(p($config['datapath'] . '/cache'));
	foreach ($cfile as $v) {
		if ($v !== '.' && $v !== '..') unlink(p($config['datapath'] . '/cache/' . $v));
	}
}
function ifCacheStart()
{/*缓存是否开启了*/
	global $config;
	$cacheStatus = getConfig('cache.json');
	return ($cacheStatus['cachestart'] > 0 && $config['cache']['smart']);
}
function smartCache()
{/*处理缓存(还包括队列)*/
	global $config;
	if (!$config['cache']['smart']) return false;/*未开启缓存直接返回*/
	$cacheNow = getConfig('cache.json');
	$queueconf = getConfig('queue.json');
	$lag = time() - $cacheNow['lastcount'];
	if ($lag >= 30) {
		$velo = round($cacheNow['requests'] / $lag, 2);/*获得速度,至少统计30秒*/
		$cacheNow['lastcount'] = time();
		array_push($cacheNow['periods'], $velo);
		if (count($cacheNow['periods']) > 10) array_shift($cacheNow['periods']);
		$cacheNow['requests'] = 0;
	} else {
		$cacheNow['requests'] += 1;
		$velo = false;
	}
	$periodsnum = count($cacheNow['periods']);
	$average = empty($periodsnum) ? 0 : @array_sum($cacheNow['periods']) / count($cacheNow['periods']);
	if (!$cacheNow['cachestart'] && ($velo && $velo >= 0.9 || $average > 0.5)) $cacheNow['cachestart'] = time();/*开启智能缓存*/
	if (!$queueconf['start'] && ($velo && $velo >= 1.5 || $average > 1.2)) $queueconf['start'] = time();/*开启队列*/
	writeConfig('cache.json', $cacheNow);
	writeConfig('queue.json', $queueconf);
}
function queueChecker($statu, $waiting = false, $id = false)
{/*处理队列*/
	global $config;
	$returnid = md5(time());
	$queueConf = getConfig('queue.json');/*拿到队列的记录文件*/
	if (!$config['queue']['start'] || !$queueConf['start']) return false;/*未开启队列直接返回*/
	$lag = isset($queueConf['start']) ? time() - intval($queueConf['start']) : 0;/*计算自开始队列之后过去多久了*/
	usleep(500000);/*进来先等0.5秒*/
	if (intval($queueConf['requesting']) >= $config['queue']['maxnum'] && !$waiting && $queueConf['performing'] !== $id) {/*请求的量过大*/
		header('Location: ' . $config['servicebusy']);/*返回服务繁忙的图片*/
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
			$queueConf['performing'] = $returnid;
			break;
		case "del":
			$returnid = $id;
			$queueConf['performing'] = '';
			$queueConf['requesting'] > 0 ? $queueConf['requesting'] -= 1 : $queueConf['requesting'] = 0;/*请求执行完了*/
			break;
	}
	if ($lag >= $config['queue']['lastfor']) {/*超过了持续时间，关闭队列*/
		$queueConf['start'] = false;
		$queueConf['performing'] = '';
		$queueConf['requesting'] = 0;
	}
	writeConfig('queue.json', $queueConf);/*储存配置*/
	return $returnid;/*把执行的id传回去*/
}

/*Password Processor*/
@session_start();
$passrq = @$_POST['requestfolder'];
$passwd = @$_POST['password'];
if (!empty($passrq)) {
	$_SESSION['passwd'][$passrq] = md5($passwd);/*提交并储存密码*/
}
@session_write_close();
/*Request Processor*/
$pr = preg_replace('~/~', '', htmlchars($_SERVER['QUERY_STRING']), 1);/*Get query*/
if (empty($pr)) {
	$pr = '';
}
$self = basename($_SERVER['PHP_SELF']);
$pr = str_ireplace($self, '', $pr);
$requesturl = 'http://request.yes/' . $pr;
/*Cache Processor*/
@ob_start();/*缓冲区打开*/
$cache = cacheControl('read', $pr);
if (ifCacheStart() && !empty($cache[0]) && empty($passrq)) {
	$output = $cache[0];
} else {
	echo handleRequest($requesturl);
	$output = ob_get_contents();
	if (ifCacheStart()) cacheControl('write', $pr, array($output));
}
@ob_end_clean();
if ($config['listAsJson']) header('Content-type:text/json;charset=utf-8');/*如果以Json返回就设定头*/
echo $output;
