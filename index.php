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
$config=array(
    "refresh_token"=>"", 
    "client_id"=>"", 
    "client_secret"=> "", 
    "api_url"=> "https://graph.microsoft.com/v1.0", 
    "oauth_url"=>"https://login.microsoftonline.com/common/oauth2/v2.0", 
    "redirect_uri"=> "http://localhost", 
    'base'=>'/',
	'datapath'=>'data',
	'rewrite'=>false,
	'sitepath'=>'',
    "cache"=>array(
        'smart'=>true,
		'expire'=>1800 /*In seconds*/
    ),
	'queue'=>array(
	    'start'=>true,/*防并发请求队列*/
		'maxnum'=>15,/*队列中允许停留的最多请求数，其他请求直接返回服务繁忙*/
		'lastfor'=>2700 /*In seconds*/
	),
	'servicebusy'=>'https://cdn.jsdelivr.net/gh/SomeBottle/odindex/assets/unavailable.png',/*队列过多时返回的“服务繁忙”图片url*/
    'thumbnail'=>true,
	'preview'=>true,
	'previewsuffix'=>['ogg','mp3','wav','m4a','mp4','webm','jpg','jpeg','png','gif','webp'],/*可预览的类型*/
    'useProxy'=>false,
	'proxyPath'=>false /*代理程序url，false则用本目录下的*/
);
/*Initialization*/
function p($p){/*转换为绝对路径*/
	return __DIR__ .'/'.$p;
}
function writeConfig($file,$arr){/*写入配置*/
	global $config;
	if(is_array($arr)) file_put_contents(p($config['datapath'].'/'.$file),json_encode($arr,true));
}
function getConfig($file){/*获得配置*/
    global $config;
	$path=p($config['datapath'].'/'.$file);
	if(!file_exists($path)) return false;/*配置文件不存在直接返回false*/
	return json_decode(file_get_contents($path),true);
}
/*smartCache*/
if(!is_dir(p($config['datapath']))) mkdir(p($config['datapath']));
if(!is_dir(p($config['datapath'].'/cache'))) mkdir(p($config['datapath'].'/cache'));/*如果没有cache目录就创建cache目录*/
$cacheInitialization=array(/*Initialize conf初始化缓存配置记录文件*/
	'requests'=>0,
    'lastcount'=>time(),
	'periods'=>array(),
	'cachestart'=>false
);
if(!getConfig('cache.json')) writeConfig('cache.json',$cacheInitialization);
/*Queue*/
$queueInitialization=array(
    'performing'=>'',/*正在执行的请求id(无实际用途，仅作标记*/
	'requesting'=>0,/*在队列中的任务数*/
	'start'=>false
);
if(!getConfig('queue.json')) writeConfig('queue.json',$queueInitialization);
/*InitializationFinished*/
function valueinarr($v,$a){/*判断数组中是否有一个值*/
    $str=join(' ',$a);/*将数组并入字串符，直接判断字串符里面是否有对应值*/
	if(stripos($str,strval($v))!==false){
		return true;
	}
	return false;
}
function modifyarr($arr,$search,$v){/*搜素含有对应值的键并通过键编辑数组*/
	foreach($arr as $k=>$val){
		if(stripos($val,strval($search))!==false){
			$arr[$k]=$v;
			return $arr;
		}
	}
}
function request($u,$q,$method='POST',$head=array('Content-type:application/x-www-form-urlencoded'),$headerget=false,$retry=false) {/*poster*/
	$rqcontent= ($method=='POST') ? http_build_query($q) : '';
    $opts = array(
        'http' => array(
            'method' => $method,
            'header' => $head,
            'timeout' => 15 * 60, // 超时时间（单位:s）
			'content'=>$rqcontent
        )
    );
	smartCache();/*智能缓存*/
	$queueid=queueChecker('add');/*增加请求到队列*/
	if($headerget){/*仅请求头部模式*/
	    stream_context_set_default($opts);
		$hd=@get_headers($u,1);
		queueChecker('del',true,$queueid);/*请求完毕，移除队列*/
		if(valueinarr('401 Unauthorized',$hd)&&!$retry){/*accesstoken需要刷新*/
			$newtoken=getAccessToken(true);
			$head=modifyarr($head,'Authorization','Authorization: bearer '.$newtoken);/*获得新token重新请求*/
		    return request($u,$q,$method,$head,$headerget,true);
		}
		return $hd;
	}else{/*正常请求模式*/
        $ct = stream_context_create($opts);
        $result = @file_get_contents($u, false, $ct);
		queueChecker('del',true,$queueid);/*请求完毕，移除队列*/
		$backhead=$http_response_header;/*获得返回头*/
		if(valueinarr('401 Unauthorized',$backhead)&&!$retry){/*accesstoken需要刷新*/
			$newtoken=getAccessToken(true);
			$head=modifyarr($head,'Authorization','Authorization: bearer '.$newtoken);/*获得新token重新请求*/
		    return request($u,$q,$method,$head,$headerget,true);
		}
	    if(!empty($result)){
            return $result;
	    }else{
		    return '';
	    }
	}
}
function encodeurl($u){/*处理URL转义，保留斜杠*/
	return str_ireplace('%2F','/',urlencode($u));
}
function parsepath($u){/*得出处理后的路径*/
	global $config;
	$up=parse_url($u)['path'];
	$rt=str_ireplace($config['sitepath'],'',$up);
	if($config['rewrite']){
		$rt=explode('&',$rt)[0];/*rewrite开启后服务器程序会自动把?转换为&*/
	}
	return $rt;
}
function getRefreshToken(){/*从token文件中或本脚本中取refreshtoken*/
    global $config;
	if(file_exists(p('token.php'))){
		require p('token.php');
		return isset($refresh) ? $refresh : $config['refresh_token'];
	}else{
		return $config['refresh_token'];
	}
}
function getAccessToken($update=false){/*获得AccessToken*/
	global $config;
	if($update||!file_exists(p('token.php'))){
	    $resp=request($config['oauth_url'].'/token',array(
	        'client_id'=>$config['client_id'],
		    'redirect_uri'=>$config['redirect_uri'],
		    'client_secret'=>$config['client_secret'],
		    'refresh_token'=>getRefreshToken(),
		    'grant_type'=>'refresh_token'
	    ));
	    $data=json_decode($resp,true);
	    if(isset($data['access_token'])){/*存入到token文件*/
		    file_put_contents(p('token.php'),'<?php $token="'.$data['access_token'].'";$refresh="'.$data['refresh_token'].'";?>');
		    return $data['access_token'];
	    }else{
		    file_exists(p('token.php')) ? unlink(p('token.php')) : die('Failed to get accesstoken. Maybe refresh_token expired.');/*refreshtoken过期*/
			return getAccessToken($update);
	    }
	}else{
		require p('token.php');
		return $token;
	}
}
function suffix($f){/*从文件名后取出后缀名*/
	$sp=explode('.',$f);
	return $sp[count($sp)-1];
}
function getParam($url,$param){/*获得url中的参数*/
    global $config;
    $prs=parse_url($url)['query'];
    if($config['rewrite']){/*rewrite开启后服务器程序会自动把?转换为&*/
		$prs=$url;
	}
	$prs=explode('&',$prs);
	$arr=array();
	foreach($prs as $v){
		$each=explode('=',$v);
		isset($each[1]) ? ($arr[$each[0]]=$each[1]) : ($arr[$each[0]]='');/*参数输入数组*/
	}
	if(isset($arr[$param])){
	    return $arr[$param];
	}else{
		return '';
	}
}
function wrapPath($p){/*包装请求url*/
	global $config;
	$wrapped=encodeurl($config['base']).$p;
	return ($wrapped=='/'||$wrapped=='') ? '' : ':'.$wrapped;
}
function handleRequest($url,$returnurl=false){
	global $config;
	$error='';
	$accessToken=getAccessToken();/*获得accesstoken*/
	$path=parsepath($url);
	$path=='/' ? $path='' : $path;/*根目录特殊处理*/
	if($config['thumbnail']){
		$thumb=getParam($url,'thumbnail');/*获得缩略图尺寸*/
		$thumbnail=empty($thumb) ? false : $thumb;/*判断请求的是不是缩略图*/
	}
	if($config['preview']){
		$prev=getParam($url,'p');/*获得preview请求*/
		$preview=empty($prev) ? false : $prev;
	}
	if($thumbnail){/*如果是请求缩略图*/
		$rq=$config['api_url'].'/me/drive/root:'.encodeurl($config['base']).$path.':/thumbnails';
		$resp=request($rq,'','GET',array(
		   'Content-type: application/x-www-form-urlencoded',
		   'Authorization: bearer '.$accessToken
		));
		$resp=json_decode($resp,true);
		$rurl=$resp['value'][0][$thumbnail]['url'];
		if($rurl){return handleFile($rurl,true);}else{return false;}/*强制不用代理*/
	}
	/*Normally request*/
	$rq=$config['api_url'].'/me/drive/root'.wrapPath($path).'?select=name,eTag,size,id,folder,file,%40microsoft.graph.downloadUrl&expand=children(select%3Dname,eTag,size,id,folder,file)';
	$cache=cacheControl('read',$path);/*请求的内容是否被缓存*/
	empty($cache[0]) ?: $queueid=queueChecker('add');/*如果有缓存也要加入队列计算*/
	$resp=(ifCacheStart()&&!empty($cache[0])) ? $cache[0] : request($rq,'','GET',array(
	    'Content-type: application/x-www-form-urlencoded',
		'Authorization: bearer '.$accessToken
    ));
	empty($cache[0]) ?: queueChecker('del',true,$queueid);/*如果有缓存也要移除队列计算*/
	if($resp){
	    $data=json_decode($resp,true);
	    if(isset($data['file'])){/*返回的是文件*/
	        if($returnurl) return $data["@microsoft.graph.downloadUrl"];/*直接返回Url，用于取得.password文件*/
		    if($data['name']=='.password') die('Access denied');/*阻止密码被获取到*/
			if(ifCacheStart()&&!$cache) cacheControl('write',$path,array($resp));/*只有下载链接储存缓存*/
			/*构建文件下载链接缓存*/
			if($preview=='t'){/*预览模式*/
				return handlePreview($data["@microsoft.graph.downloadUrl"],suffix($data['name']));/*渲染预览*/
			}else{
		        handleFile($data["@microsoft.graph.downloadUrl"]);
			}
	    }else if(isset($data['folder'])){/*返回的是目录*/
		    $render=renderFolderIndex($data['children'],parsepath($url));/*渲染目录*/
		    return $render;
	    }else{
		    return 'Error response:'.var_export($resp,true);
	    }
	}else{
		return '<!--NotFound:'.urldecode($path).'-->';
	}
}
function el($tag,$attrs,$content){
	$attrs=join(' ',$attrs);
	return '<'.$tag.' '.$attrs.'>'.$content.'</'.$tag.'>';
}
function div($className,$content){
	return el('div',array('class='.$className),$content);
}
function item($icon,$filename,$size=false,$href=false){
	$href=$href ?: $href=$filename;/*没有指定链接自动指定文件名*/
	$sizeitem=$size ? ('size="'.$size.'"') : '';/*在标签附上文件大小*/
	return el('a',array('href="'.$href.'"','class="item"',$sizeitem),el('i',array('class="material-icons"'),$icon).$filename);
}
function mime2icon($t){
	if(stripos($t,'image')!==false){
		return 'image';
	}else if(stripos($t,'audio')!==false){
		return 'audiotrack';
	}else if(stripos($t,'video')!==false){
		return 'video_label';
	}else{
		return 'description';
	}
}
function processhref($hf){/*根据伪静态是否开启处理href*/
	global $config,$pr;
	if(!$config['rewrite']) return '?/'.$pr.$hf;/*没开伪静态，请求处理*/
	return $hf;
}
function trimall($str){/*去除空格和换行*/
    $arr=array(" ","　","\t","\n","\r");
    return str_replace($arr, '', $str);  
}
function passwordform($fmd5){/*密码输入模板*/
	return '<div class="items">
	<div style="min-width:600px">
	<h2 style="text-align:center;color:#6E6E6E">你需要输入密码来浏览目录</h2>
    <form action="#" method="post" style="text-align:center;margin-bottom:20px">
          <input type="password" name="password" placeholder="password"/>
		  <input type="hidden" name="requestfolder" value="'.$fmd5.'"/>
    </form>
    </div>
	</div>';
}
function renderFolderIndex($items,$isIndex){/*渲染目录列表*/
    global $config,$pr;
    @session_start();
    if(!isset($_SESSION['passwd'])) $_SESSION['passwd']=array();/*密码是否存在*/
	$nav='<nav><a class="brand">Bottle Od</a></nav>';/*导航栏模板*/
	$itemrender='';/*文件列表渲染变量*/
	$backhref='..';
	if(!$config['rewrite']){/*回到上一目录*/
		$hfarr=array_filter(explode('/',$pr));
		array_pop($hfarr);
		$backhref='?/';
		foreach($hfarr as $v){
			$backhref.=$v.'/';
		}
	}
	if($isIndex!=='/'){
		$itemrender=item("folder", "..",false,$backhref);/*在根目录*/
	}
	foreach($items as $v){
		if(isset($v['folder'])){/*是目录*/
			$itemrender.=item("folder", $v['name'], $v['size'],processhref($v['name'].'/'));
		} else if (isset($v['file'])) {/*是文件*/
		    $namemd5=md5($pr);/*获得文件所在目录路径md5，用于密码识别*/
			$requesturl='http://request.yes/'.$pr.'.password';/*密码文件*/
			if($v['name']=='.password'&&!isset($_SESSION['passwd'][$namemd5])){/*未提交过密码*/
				$itemrender=passwordform($namemd5);
				break;
			}else if($v['name']=='.password'){/*提交过密码*/
				$downurl=handleRequest($requesturl,true);/*获得密码文件下载链接*/
				$password=request($downurl,'','GET',array());/*请求到文件*/
				if(trimall($password)!==$_SESSION['passwd'][$namemd5]){/*密码错误*/
					$itemrender=passwordform($namemd5);
					break;/*阻止文件列表加载*/
				}
			}else{
				$hf=$config['preview'] ? $v['name'].'?p=t' : $v['name'];/*如果开了预览，所有文件都加上预览请求*/
			    $itemrender.=item(mime2icon($v['file']['mimeType']), $v['name'], $v['size'],processhref($hf));
			}
		}
	}
	@session_write_close();
	return renderHTML($nav.div('container',div('items',el('div',array('style="min-width:600px"'),$itemrender))));
}
function renderHTML($body){
	return '<!DOCTYPE html>
  <html lang="en">
    <head>
      <meta charset="utf-8" />
      <meta http-equiv="x-ua-compatible" content="ie=edge, chrome=1" />
      <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
      <title>Bottle Od</title>
      <link href=\'https://fonts.loli.net/icon?family=Material+Icons\' rel=\'stylesheet\'>
      <link href=\'https://cdn.jsdelivr.net/gh/SomeBottle/OdIndex/assets/material.css\' rel=\'stylesheet\'>
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/prismjs@1.17.1/themes/prism-solarizedlight.css">
	  <script>var readmefile="'.processhref('readme.md').'";</script>
      <script type="module" src=\'https://cdn.jsdelivr.net/gh/SomeBottle/OdIndex/assets/script.js\'></script>
    </head>
    <body>
      '.$body.'
      <div style="flex-grow:1"></div>
      <footer><p>Originally designed by <a href="https://github.com/heymind/OneDrive-Index-Cloudflare-Worker">Heymind</a>.</p></footer>
      <script src="https://cdn.jsdelivr.net/npm/prismjs@1.17.1/prism.min.js" data-manual></script>
      <script src="https://cdn.jsdelivr.net/npm/prismjs@1.17.1/plugins/autoloader/prism-autoloader.min.js"></script>
      <script src="https://cdn.jsdelivr.net/gh/SomeBottle/othumb.js@0.8/othumb.m.js"></script>
    </body>
  </html>';
}
function handleFile($url,$forceorigin=false){/*forceorigin为true时强制不用代理，这里用于缩略图*/
	global $config;
	if($config['useProxy']&&!$forceorigin){
		$url=($config['proxyPath'] ? $config['proxyPath'] : $config['sitepath'].'/odproxy.php').'?'.urlencode(substr($url,6));
		header('Location: '.$url);
	}else{
	    header('Location: '.substr($url,6));
	}
}
function handlePreview($url,$suffix){/*预览渲染器(文件直链，后缀)*/
    global $config;
	$suffix=strtolower($suffix);
	if(in_array($suffix,$config['previewsuffix'])){
		$template=file_get_contents(p('preview.html'));
		$template=str_ireplace('{{url}}',$url,$template);
		$template=str_ireplace('{{suffix}}',$suffix,$template);
		return $template;
	}else{
	   return handleFile($url);/*文件格式不支持预览，直接传递给文件下载*/
	}
}
function cacheControl($mode,$path,$requestArr=false){/*缓存控制*/
    global $config;
	$cacheConf=getConfig('cache.json');
	$rt=true;
	$starttime=$cacheConf['cachestart'];
	if($starttime&&(time()-$starttime)>=$config['cache']['expire']){/*超出缓存时间*/
		$cacheConf['cachestart']=false;
		cacheClear();
		$rt=false;
	}else if(ifCacheStart()){/*缓存模式开启*/
		$file='cache/'.md5($path).'.json';
		if($mode=='write'){/*路径存在，写入缓存*/
		    writeConfig($file,$requestArr);
		}else if($mode=='read'){/*路径存在，读缓存*/
			$rt=getConfig($file);/*缓存不存在会返回false*/
		}else{/*缓存不存在*/
			$rt=false;
		}
	}else{
		$rt=false;
	}
	writeConfig('cache.json',$cacheConf);
	return $rt;
}
function cacheClear(){/*缓存清除*/
    global $config;
	$cfile=scandir(p($config['datapath'].'/cache'));
	foreach($cfile as $v){
		if($v!=='.'&&$v!=='..') unlink(p($config['datapath'].'/cache/'.$v));
	}
}
function ifCacheStart(){/*缓存是否开启了*/
    global $config;
	$cacheStatus=getConfig('cache.json');
	return ($cacheStatus['cachestart']>0&&$config['cache']['smart']);
}
function smartCache(){/*处理缓存(还包括队列)*/
    global $config;
	if(!$config['cache']['smart']) return false;/*未开启缓存直接返回*/
	$cacheNow=getConfig('cache.json');
	$queueconf=getConfig('queue.json');
	$lag=time()-$cacheNow['lastcount'];
	if($lag >= 30){
		$velo=round($cacheNow['requests']/$lag,2);/*获得速度,至少统计30秒*/
		$cacheNow['lastcount']=time();
		array_push($cacheNow['periods'],$velo);
	    if(count($cacheNow['periods'])>10) array_shift($cacheNow['periods']);
		$cacheNow['requests']=0;
	}else{
		$cacheNow['requests']+=1;
		$velo=false;
	}
	$periodsnum=count($cacheNow['periods']);
	$average=empty($periodsnum) ? 0 : @array_sum($cacheNow['periods'])/count($cacheNow['periods']);
	if(!$cacheNow['cachestart']&&($velo&&$velo>=0.9||$average>0.5)) $cacheNow['cachestart']=time();/*开启智能缓存*/
	if(!$queueconf['start']&&($velo&&$velo>=1.5||$average>1.2)) $queueconf['start']=time();/*开启队列*/
	writeConfig('cache.json',$cacheNow);
	writeConfig('queue.json',$queueconf);
}
function queueChecker($statu,$waiting=false,$id=false){/*处理队列*/
	global $config;
	$returnid=md5(time());
	$queueConf=getConfig('queue.json');/*拿到队列的记录文件*/
	if(!$config['queue']['start']||!$queueConf['start']) return false;/*未开启队列直接返回*/
	$lag=isset($queueConf['start']) ? time()-intval($queueConf['start']) : 0 ;/*计算自开始队列之后过去多久了*/
	usleep(500000);/*进来先等0.5秒*/
	if(intval($queueConf['requesting'])>=$config['queue']['maxnum']&&!$waiting&&$queueConf['performing']!==$id){/*请求的量过大*/
		header('Location: '.$config['servicebusy']);/*返回服务繁忙的图片*/
		exit();
	}
	while(!empty($queueConf['performing'])){/*有请求正在执行，阻塞*/
	    $queueConf['requesting']=!$waiting ? intval($queueConf['requesting'])+1 : $queueConf['requesting'];/*增加请求*/
		usleep(500000);/*等0.5s*/
		if($queueConf['performing']==$id) break;/*如果是正在执行的请求，不阻塞*/
		return queueChecker($statu,true,$id);/*waiting标记为true，不会被当成新请求返回服务繁忙*/
	}
	switch($statu){
		case "add":/*请求添加到队列*/
		  $queueConf['performing']=$returnid;
		  break;
		case "del":
		  $returnid=$id;
		  $queueConf['performing']='';
		  $queueConf['requesting']>0 ? $queueConf['requesting']-=1 : $queueConf['requesting']=0;/*请求执行完了*/
		  break;
	}
	if($lag>=$config['queue']['lastfor']){/*超过了持续时间，关闭队列*/
		$queueConf['start']=false;
	    $queueConf['performing']='';
		$queueConf['requesting']=0;
	}
	writeConfig('queue.json',$queueConf);/*储存配置*/
	return $returnid;/*把执行的id传回去*/
}

/*Password Processor*/
@session_start();
$passrq=@$_POST['requestfolder'];
$passwd=@$_POST['password'];
if(!empty($passrq)){
	$_SESSION['passwd'][$passrq]=md5($passwd);/*提交并储存密码*/
}
@session_write_close();
/*Request Processor*/
$pr=preg_replace('~/~','',$_SERVER['QUERY_STRING'],1);/*Get query*/
if(empty($pr)){
	$pr='';
}
$self=substr($_SERVER['PHP_SELF'],strrpos($_SERVER['PHP_SELF'],'/')+1);
$pr=str_ireplace($self,'',$pr);
$requesturl='http://request.yes/'.$pr;
/*Cache Processor*/
@ob_start();/*缓冲区打开*/
$cache=cacheControl('read',$pr);
if(ifCacheStart()&&!empty($cache[0])&&empty($passrq)){
	$output=$cache[0];
}else{
    echo handleRequest($requesturl);
    $output=ob_get_contents();
	if(ifCacheStart()) cacheControl('write',$pr,array($output));
}
@ob_end_clean();
echo $output;
?>
