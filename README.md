# OdIndex
Onedrive index transplanted from Heymind.

最近发现Heymind写的<a href='https://github.com/heymind/OneDrive-Index-Cloudflare-Worker' target='_blank'>Cloudflare Worker</a>版的oneindex很好用，遂移植了一个php版本.

> 原Heymind的Cloudflare Workers版OnedriveIndex使用**MIT协议**.  

## 前言  
这只是通过调用api实现的onedrive文件列表程序，并不提供任何账号相关的内容。如果有条件，请花钱支持一下<del>巨硬</del>微软。   

## Features & Drawbacks  
* 转发下载（要过服务器流量[建议是国外服务器]，但能快很多）   
* 鼠标悬停预览  
* md小缩略图修复  
* accesstoken有需要时自动刷新  
* 支持站点非根目录  
* smartCache缓存系统  
* 支持缩略图  
* 支持密码保护目录  
* 没有文件上传功能  

## To-do list
- [x] smartCache 智能缓存（其实并不智能）  
- [x] 支持根目录文件列表  
- [x] 文件简单预览  

## Deployment  
1. 准备一个网站服务器，把仓库中**odproxy.php , index.php , preview.html**丢进去  

2. 按照<a href='https://github.com/SomeBottle/OdIndex/blob/master/heymind/heymind.md' target='_blank'>Heymind</a>的方式获取refresh_token  
**PS:Heymind的工具目前只测试了个人版，Business版请看一下<a href='https://github.com/heymind/OneDrive-Index-Cloudflare-Worker/issues' target='_blank'>issue</a>**  

3. 在index.php替换相关参数  

4. 设置伪静态:（可选）  
  
  ```
  if (!-f $request_filename){
    set $rule_0 1$rule_0;
  }
  if (!-d $request_filename){
    set $rule_0 2$rule_0;
  }
  if ($rule_0 = "21"){
    rewrite ^/(.*)$ /?/$1 last;
  }
  ```
  如果是**非根目录**，要在伪静态规则上作相应调整。  
  
## Thumbnail缩略图  
对于图片文件，可以直接获取不同尺寸的缩略图。 比如：https://xxx/pics/loli.png?thumbnail=medium  
最常用值有**small,medium,large**  
  
## Readme文件  
在你需要展示说明文件的目录下放入readme.md文件，会自动进行解析并展示在该目录的文件列表下.  
  
## Password  
在你需要保护的目录下放入.password文件，注意在password文件内写入的密码必须是**小写32位md5加密后**的（不再用明文）.  

**密码仅仅能保护当前目录，不排除有人得知了目录下的子目录或者文件**  

## Preview.html  
这是文件预览页面的模板，其中 **{{url}}** 代表文件的直链（非代理），而 **{{suffix}}** 是预览文件的后缀名.  

## Config  
其他配置类似Heymind的worker版，简单说一两个特殊的：  

```php
'sitepath'=>'',  
'base'=>'/Share',
'rewrite'=>false,
...
'useProxy'=>true,  
'preview'=>true,  
'previewsuffix'=>[...]  
```
* base配置项用于规定展示onedrive根目录下哪个目录的内容.**例如**将你要展示列表的文件放在**onedrive根目录下的Share目录里面**，base项配置为 "**/Share**" 即可，如果你要展示**根目录的内容**，请将base项设置为 "**/**"  

* preview配置项用来配置是否开启**默认预览**，开启之后点击列表中的文件会默认进入**预览界面**.previewsuffix是支持预览的文件格式，**不建议修改**.  

* sitepath配置项是为了适应**站点非根目录**的，如果你的站点类似于**https://xxx/** ，这一个配置项**留空**；如果你的站点类似于**https://xxx/onedrive/** ，那么这个配置项你就要改成：  
```php
'sitepath'=>'/onedrive',  
//末尾不要斜杠！  
```

* **值得注意的是，rewrite=true时，sitepath要留空**   

* useProxy配置项用于启动转发下载，如果为true，调用直链时会自动用odproxy.php转发下载.  

* rewrite配置项若开启，你必须配置伪静态，若关闭，你可以用请求的方式访问.例如开了伪静态，你可以访问https://xxx/Document/ ，没有开伪静态，你需要访问https://xxx/?/Document/ 来进行访问。   

## 世纪互联（测试）  

编辑头部config中**api_url**和**oauth_url**内容为：  

```php
"api_url"=> "https://microsoftgraph.chinacloudapi.cn/v1.0", 
"oauth_url"=>"https://login.partner.microsoftonline.cn/common/oauth2/v2.0", 
```

## Smart Cache  
```php
"cache"=>array(
    'smart'=>true,
    'expire'=>1200 /*In seconds*/
),
```

SmartCache会在你的文件目录被大量访问时**自动缓存目录**，配置项只有以上两个。  

* smart 若为true则开启smartCache  
* expire 自动缓存开启后持续的时间，这段时间过去后缓存文件会被自动删除，一切恢复正常  

## Smart Queue  
```php
'queue'=>array(
     'start'=>true,/*防并发请求队列*/
     'maxnum'=>15,/*队列中允许停留的最多请求数，其他请求直接返回服务繁忙*/
     'lastfor'=>2700 /*In seconds*/
),
'servicebusy'=>'https://cdn.jsdelivr.net/gh/SomeBottle/odindex/assets/unavailable.png',
```

SmartQueue会在游客对文件造成大量请求时防止并发情况出现，可以有效防止账户被微软限制.  

* maxnum 是队列中存在的最多请求数，每请求一个未缓存页面、一个文件，在请求未完成之时全部当排队请求，而当**排队请求的量**超过了maxnum，会直接返回服务繁忙，也就是servicebusy的图片.  

* lastfor 是队列模式开启后持续的时间，按秒计算.超过这个时间后一切会恢复正常.**建议比SmartCache的设置更长一点**.  

## Notice  

* 访问目录时末尾一定要加上'/'，比如你想访问Document目录，访问https://xxx/Document/ 才是正确的，如果访问 https://xxx/Document 会出现链接bug.  

* 如果特别特别久没有访问了，显示 **Failed to get accesstoken. Maybe refresh_token expired** ，需要更换refresh_token，**删掉生成的token.php，在index.php头部修改配置为自行重新获取的refreshtoken**即可.  

------------------
### MIT LICENSE. 
