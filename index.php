<?php
/*
Original design by Heymind:
https://github.com/heymind/OneDrive-Index-Cloudflare-Worker
Transplanted by Somebottle.
Based on MIT LICENSE.
*/
error_reporting(E_ALL & ~E_NOTICE);
$config=array(
    "refresh_token"=>"",
    "client_id"=>"",
    "client_secret"=> "",
    "redirect_uri"=> "https://heymind.github.io/tools/microsoft-graph-api-auth", 
    'base'=>"/",
	'rewrite'=>false,
	'sitepath'=>'',
    "cache"=>array(
        'enable'=>true
    ),
    'thumbnail'=>true,
    'useProxy'=>true
);
function valueinarr($v,$a){/*判断数组中是否有一个值*/
	foreach($a as $val){
		if(stripos($val,strval($v))!==false){
			return true;
		}
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
    $rqcontent='';
    if($method=='POST'){
       $rqcontent=http_build_query($q);
	}
    $opts = array(
        'http' => array(
            'method' => $method,
            'header' => $head,
            'timeout' => 15 * 60, // 超时时间（单位:s）
			'content'=>$rqcontent
        )
    );
	if($headerget){
	    stream_context_set_default($opts);
		$hd=@get_headers($u,1);
		if(valueinarr(401,$hd)&&!$retry){/*accesstoken需要刷新*/
			$newtoken=getAccessToken(true);
			$head=modifyarr($head,'Authorization','Authorization: bearer '.$newtoken);/*获得新token重新请求*/
		    return request($u,$q,$method,$head,$headerget,true);
		}
		return $hd;
	}else{
        $ct = stream_context_create($opts);
        $result = @file_get_contents($u, false, $ct);
		$backhead=$http_response_header;/*获得返回头*/
		if(valueinarr(401,$backhead)&&!$retry){/*accesstoken需要刷新*/
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
	return str_ireplace($config['sitepath'],'',$up);
}
function getAccessToken($update=false){/*获得AccessToken*/
	global $config;
	if($update||!file_exists('./token.php')){
	$resp=request('https://login.microsoftonline.com/common/oauth2/v2.0/token',array(
	    'client_id'=>$config['client_id'],
		'redirect_uri'=>$config['redirect_uri'],
		'client_secret'=>$config['client_secret'],
		'refresh_token'=>$config['refresh_token'],
		'grant_type'=>'refresh_token'
	));
	$data=json_decode($resp,true);
	if(isset($data['access_token'])){
		file_put_contents('./token.php','<?php $token="'.$data['access_token'].'";?>');
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
	$prs=parse_url($url)['query'];
	$prs=explode('&',$prs);
	$arr=array();
	foreach($prs as $v){
		$each=explode('=',$v);
		if(isset($each[1])){
		    $arr[$each[0]]=$each[1];
		}else{
			$arr[$each[0]]='';
		}
	}
	if(isset($arr[$param])){
	    return $arr[$param];
	}else{
		return '';
	}
}
function handleRequest($url,$returnurl=false){
	global $config;
	$thumbnail=false;
	$error='';
	$accessToken=getAccessToken();/*获得accesstoken*/
	$path=parsepath($url);
	if($path=='/'){/*特殊处理*/
		$path='';
	}
	if($config['thumbnail']){
		$thumb=getParam($url,'thumbnail');/*获得缩略图尺寸*/
		if(!empty($thumb)){
			$thumbnail=$thumb;
		}
	}
	if($thumbnail){/*如果是请求缩略图*/
		$rq='https://graph.microsoft.com/v1.0/me/drive/root:'.$config['base'].$path.':/thumbnails/0/'.$thumbnail.'/content';
		$resp=request($rq,'','GET',array(
		   'Content-type: application/x-www-form-urlencoded',
		   'Authorization: bearer '.$accessToken
		),true);
		if($resp){
		return handleFile($resp['Location']);
		}
	}
	/*Normally request*/
	$rq='https://graph.microsoft.com/v1.0/me/drive/root:'.$config['base'].$path.'?select=name,eTag,size,id,folder,file,%40microsoft.graph.downloadUrl&expand=children(select%3Dname,eTag,size,id,folder,file)';
	$resp=request($rq,'','GET',array(
	    'Content-type: application/x-www-form-urlencoded',
		'Authorization: bearer '.$accessToken
    ));
	if($resp){
	$data=json_decode($resp,true);
	if(isset($data['file'])){/*返回的是文件*/
	    if($returnurl){
			return $data["@microsoft.graph.downloadUrl"];
		}
		if($data['name']=='.password'){/*阻止密码被获取到*/
			die('Access denied');
		}
		handleFile($data["@microsoft.graph.downloadUrl"]);
	}else if(isset($data['folder'])){/*返回的是目录*/
		$render=renderFolderIndex($data['children'],parsepath($url));
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
	if(!$href){
		$href=$filename;
	}
	if($size){
		$sizeitem='size="'.$size.'"';
	}else{
		$sizeitem='';
	}
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
	if(!$config['rewrite']){
		return '?/'.$pr.$hf;
	}
	return $hf;
}
function trimall($str){/*去除空格和换行*/
    $arr=array(" ","　","\t","\n","\r");
    return str_replace($arr, '', $str);  
}
function passwordform($fmd5){
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
    if(!isset($_SESSION['passwd'])){
		$_SESSION['passwd']=array();
	}
	$nav='<nav><a class="brand">Bottle Od</a></nav>';
	$itemrender='';
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
					break;
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
      <script src="https://cdn.jsdelivr.net/gh/SomeBottle/othumb.js@latest/othumb.m.js"></script>
    </body>
  </html>';
}
function handleFile($url){
	global $config;
	if($config['useProxy']){
		$url=$config['sitepath'].'/odproxy.php?'.substr($url,6);
		header('Location: '.$url);
	}else{
	    header('Location: '.substr($url,6));
	}
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
echo handleRequest($requesturl);
?>