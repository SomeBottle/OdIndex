<?php
/*
Original design by Heymind:
https://github.com/heymind/OneDrive-Index-Cloudflare-Worker
Transplanted by Somebottle.
Based on MIT LICENSE.
*/
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
date_default_timezone_set("Asia/Shanghai");
$config=array(
    "refresh_token"=>"",
    "client_id"=>"",
    "client_secret"=> "",
    "redirect_uri"=> "https://heymind.github.io/tools/microsoft-graph-api-auth", 
    'base'=>"/",
	'rewrite'=>false,
	'sitepath'=>'',
    "cache"=>array(
        'smart'=>true,
		'expire'=>1200 /*In seconds*/
    ),
    'thumbnail'=>true,
    'useProxy'=>true
);
/*Initialization*/
if(!is_dir('./cache')) mkdir('./cache');
$conf=array(/*Initialize conf*/
	'requests'=>0,
    'lastcount'=>time(),
	'periods'=>array(),
	'cachestart'=>false
);
if(!file_exists('./cache.php')) file_put_contents('./cache.php','<?php $ct='.var_export($conf,true).';?>');

function valueinarr($v,$a){/*判断数组中是否有一个值*/
    $str=join(' ',$a);
	if(stripos($str,strval($v))!==false){
		return true;
	}
	return false;
}
function modifyarr($arr,$search,$v){/*搜素并编辑数组*/
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
	if($headerget){
	    stream_context_set_default($opts);
		$hd=@get_headers($u,1);
		if(valueinarr('401 Unauthorized',$hd)&&!$retry){/*accesstoken需要刷新*/
			$newtoken=getAccessToken(true);
			$head=modifyarr($head,'Authorization','Authorization: bearer '.$newtoken);/*获得新token重新请求*/
		    return request($u,$q,$method,$head,$headerget,true);
		}
		return $hd;
	}else{
        $ct = stream_context_create($opts);
        $result = @file_get_contents($u, false, $ct);
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
	if(file_exists('./token.php')){
		return isset($refresh) ? $refresh : $config['refresh_token'];
	}else{
		return $config['refresh_token'];
	}
}
function getAccessToken($update=false){/*获得AccessToken*/
	global $config;
	if($update||!file_exists('./token.php')){
	    $resp=request('https://login.microsoftonline.com/common/oauth2/v2.0/token',array(
	        'client_id'=>$config['client_id'],
		    'redirect_uri'=>$config['redirect_uri'],
		    'client_secret'=>$config['client_secret'],
		    'refresh_token'=>getRefreshToken(),
		    'grant_type'=>'refresh_token'
	    ));
	    $data=json_decode($resp,true);
	    if(isset($data['access_token'])){/*存入到token文件*/
		    file_put_contents('./token.php','<?php $token="'.$data['access_token'].'";$refresh="'.$data['refresh_token'].'";?>');
		    return $data['access_token'];
	    }else{
		    die('Failed to get accesstoken.');
	    }
	}else{
		require './token.php';
		return $token;
	}
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
function handleRequest($url,$returnurl=false){
	global $config;
	$error='';
	$accessToken=getAccessToken();/*获得accesstoken*/
	$path=parsepath($url);
	$path=='/' ? $path='' : $path;/*根目录特殊处理*/
	if($config['thumbnail']){
		$thumb=getParam($url,'thumbnail');/*获得缩略图尺寸*/
		$thumbnail=empty($thumb) ? false : $thumbnail=$thumb;/*判断请求的是不是缩略图*/
	}
	if($thumbnail){/*如果是请求缩略图*/
		$rq='https://graph.microsoft.com/v1.0/me/drive/root:'.$config['base'].$path.':/thumbnails/0/'.$thumbnail.'/content';
		$resp=request($rq,'','GET',array(
		   'Content-type: application/x-www-form-urlencoded',
		   'Authorization: bearer '.$accessToken
		),true);
		if($resp) return handleFile($resp['Location'],true);/*强制不用代理*/
	}
	/*Normally request*/
	$rq='https://graph.microsoft.com/v1.0/me/drive/root:'.$config['base'].$path.'?select=name,eTag,size,id,folder,file,%40microsoft.graph.downloadUrl&expand=children(select%3Dname,eTag,size,id,folder,file)';
	$cache=cacheControl('read',$path);/*请求的内容是否被缓存*/
	$resp=(ifCacheStart()&&!empty($cache[0])) ? $cache[0] : request($rq,'','GET',array(
	    'Content-type: application/x-www-form-urlencoded',
		'Authorization: bearer '.$accessToken
    ));
	if($resp){
	    $data=json_decode($resp,true);
	    if(isset($data['file'])){/*返回的是文件*/
	        if($returnurl) return $data["@microsoft.graph.downloadUrl"];/*直接返回Url，用于取得.password文件*/
		    if($data['name']=='.password') die('Access denied');/*阻止密码被获取到*/
			/*构建文件下载链接缓存*/
		    handleFile($data["@microsoft.graph.downloadUrl"]);
		    if(ifCacheStart()&&!$cache) cacheControl('write',$path,array($resp));/*只有下载链接储存缓存*/
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
			    $itemrender.=item(mime2icon($v['file']['mimeType']), $v['name'], $v['size'],processhref($v['name']));
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
      <footer><p>Original design by <a href="https://github.com/heymind/OneDrive-Index-Cloudflare-Worker">Heymind</a>.</p></footer>
      <script src="https://cdn.jsdelivr.net/npm/prismjs@1.17.1/prism.min.js" data-manual></script>
      <script src="https://cdn.jsdelivr.net/npm/prismjs@1.17.1/plugins/autoloader/prism-autoloader.min.js"></script>
      <script src="https://cdn.jsdelivr.net/gh/SomeBottle/othumb.js@0.4/othumb.m.js"></script>
    </body>
  </html>';
}
function handleFile($url,$forceorigin=false){/*forceorigin为true时强制不用代理，这里用于缩略图*/
	global $config;
	if($config['useProxy']&&!$forceorigin){
		$url=$config['sitepath'].'/odproxy.php?'.substr($url,6);
		header('Location: '.$url);
	}else{
	    header('Location: '.substr($url,6));
	}
}
function cacheControl($mode,$path,$arr=false){/*缓存控制*/
    global $config;
	$cf=getCache('./cache.php');
	$rt=true;
	$starttime=$cf['cachestart'];
	if($starttime&&(time()-$starttime)>=$config['cache']['expire']){/*超出缓存时间*/
		$cf['cachestart']=false;
		cacheClear();
		$rt=false;
	}else if(ifCacheStart()){/*缓存模式开启*/
		$file='./cache/'.md5($path).'.php';
		if($mode=='write'){/*路径存在，写入缓存*/
			file_put_contents($file,'<?php $ct='.var_export($arr,true).';?>');
		}else if($mode=='read'&&file_exists($file)){/*路径存在，读缓存*/
			$rt=getCache($file);
		}else{/*缓存不存在*/
			$rt=false;
		}
	}else{
		$rt=false;
	}
	file_put_contents('./cache.php','<?php $ct='.var_export($cf,true).';?>');
	return $rt;
}
function cacheClear(){/*缓存清除*/
	$cfile=scandir('./cache');
	foreach($cfile as $v){
		if($v!=='.'&&$v!=='..') unlink('./cache/'.$v);
	}
}
function ifCacheStart(){/*缓存是否开启了*/
    global $config;
	$arr=getCache('./cache.php');
	return ($arr['cachestart']>0&&$config['cache']['smart']);
}
function getCache($file){
	require $file;
	return $ct;
}
function smartCache(){/*处理缓存*/
    global $config;
	if(!$config['cache']['smart']) return false;/*未开启缓存直接返回*/
	$arr=getCache('./cache.php');
	$lag=time()-$arr['lastcount'];
	if($lag >= 30){
		$velo=round($arr['requests']/$lag,2);/*获得速度,至少统计30秒*/
		$arr['lastcount']=time();
		array_push($arr['periods'],$velo);
	    if(count($arr['periods'])>10) array_shift($arr['periods']);
		$arr['requests']=0;
	}else{
		$arr['requests']+=1;
		$velo=false;
	}
	$average=@array_sum($arr['periods'])/count($arr['periods']);
	if(!$arr['cachestart']&&($velo&&$velo>=0.9||$average>0.5)) $arr['cachestart']=time();/*开启智能缓存*/
	file_put_contents('./cache.php','<?php $ct='.var_export($arr,true).';?>');/*rate limit(concurrent)*/
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