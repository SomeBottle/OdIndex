# OdIndex
SomeBottle's Onedrive Folder Index transplanted from Heymind.  

![Show](https://ae02.alicdn.com/kf/H1d8707f64dd44392ab9555961f083b5dE.png)  

最近发现Heymind写的<a href='https://github.com/heymind/OneDrive-Index-Cloudflare-Worker' target='_blank'>Cloudflare Worker</a>版的oneindex很好用，遂移植了一个php版本.（2.0进行了大更新）

> 原Heymind的Cloudflare Workers版OnedriveIndex使用**MIT协议**.  

## 前言  
这只是通过调用api实现的onedrive文件列表程序，并不提供任何账号相关的内容。如果有条件，请花钱支持一下<del>巨硬</del>微软。   

## Features & Drawbacks  
* 自动更新token(除非非常非常久没访问)  
* 可以进行转发下载（**过服务器流量的那种**[建议是国外服务器]，但能快很多）   
* [简单配置](#世纪互联测试)后可以搭配**世纪互联版Onedrive**  
* 鼠标悬停预览  
* 使用[github-markdown-css](https://github.com/sindresorhus/github-markdown-css)进行markdown的渲染    
* 使用[Prism.js](https://github.com/PrismJS/prism)来渲染简单的代码高亮  
* 支持站点非根目录  
* smartCache缓存系统  
* 支持缩略图获取  
* 支持密码保护目录**以及目录下面的文件**  
* **没有**文件上传功能  
* 支持的格式预览：```ogg```,```mp3```,```wav```,```m4a```,```mp4```,```webm```,```jpg```,```jpeg```,```png```,```gif```,```webp```,```md```,```markdown```,```txt```,```docx```,```pptx```,```xlsx```,```doc```,```ppt```,```xls```,```js```,```html```,```json```,```css```  
* 支持无目录模式  
* 支持以纯json格式返回  


## To-do list
- [x] smartCache 智能缓存（其实并不智能）  
- [x] 支持根目录文件列表  
- [x] 文件简单预览  
- [x] 文件复杂预览  
- [x] 模板系统  
- [ ] 翻页支持  

## Deployment  
1. 准备一个网站服务器，把仓库中**odproxy.php , index.php , template.html**丢进去  

2. 按照<a href='https://github.com/spencerwooo/onedrive-cf-index#deployment' target='_blank'>Beetcb</a>的方式获取refresh_token   

3. 在index.php[**设置**](#Config)相关参数  

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
最常用值有```small```,```medium```,```large```  
  
## Readme文件  
在你需要展示说明文件的目录下放入readme.md文件，会自动进行解析并展示在该目录的文件列表下.  
  
## Password  
在[**你配置的地方**](#pwdCfgPath)下创建**密码配置文件**，如图：  

![ExamplePwd](https://ae02.alicdn.com/kf/H721fdd70e4cf482b8271ebe56739e1b88.png)  

**一行**表示一个**目录保护规则**，格式是```/目录路径 32位md5加密后的密码```  

注意的是目录路径末尾**不需要```/```**。

比如我要保护```/Video/*```下的内容，密码md5是e10adc3949ba59abbe56e057f20f883e，则规则写为  
```/Video e10adc3949ba59abbe56e057f20f883e```  

PS：这个规则可以保护目录及**目录下的所有子目录和文件**，利用了目录对比。因为获取密码有了额外的资源消耗，你可以在[配置](#config)里关掉密码保护功能。

## Template.html  
这是自OdIndex2.0之后有的模板文件。  
1. 形如```{{xxx}}```的是模板提取符:  
    ```{{Body}}{{BodyEnd}}``` 之间是OdIndex主体模板  
    ```{{PathSingle}}{{PathSingleEnd}}``` 之间是目录定位链接单体模板  
    ```{{ItemSingle}}{{ItemSingleEnd}}``` 之间是单个列表中的项目的模板  
    ```{{PreviewBody}}{{PreviewBodyEnd}}``` 之间是预览的主体模板  
    ```{{ImgPreview}}{{ImgPreviewEnd}}``` 之间是图片预览的内容模板  
    ```{{AudioPreview}}{{AudioPreviewEnd}}``` 之间是音频预览的内容模板  
    ```{{VideoPreview}}{{VideoPreviewEnd}}``` 之间是视频预览的内容模板  
    ```{{TxtPreview}}{{TxtPreviewEnd}}``` 之间是文本预览的内容模板  
    ```{{MDPreview}}{{MDPreviewEnd}}``` 之间是markdown预览的内容模板  
    ```{{CodePreview}}{{CodePreviewEnd}}``` 之间是代码预览的内容模板  
    ```{{OfficePreview}}{{OfficePreviewEnd}}``` 之间是Office文档的内容模板  
    ```{{PasswordPage}}{{PasswordPageEnd}}``` 之间是密码提交页面模板  

2. 形如```{[xxx]}```的是模板替换符：  
    ```{[Path]}``` 是当前的路径，替换后形如```Video/ACG/```  
    ```{[HomePath]}``` 是主页路径  
    ```{[PathItems]}``` 和前面的```{{PathSingle}}```相搭配，替换后是组装过后的目录定位整体  
    ```{[Items]}``` 和前面的```{{ItemSingle}}```相搭配，替换后是组装后的文件列表  
    ```{[ReadmeFile]}``` 是当前目录下的readme文件的直链  
    ```{[FolderLink]},{[FolderName]}``` **仅在{{PathSingle}}中有用** , 指定目录定位链接和目录名  
    ```{[ItemLink]},{[ItemSize]},{[MimeIcon]},{[ItemName]}``` **仅在{{ItemSingle}}中有用** , 指定单个文件的链接、大小(bytes)、Mime图标标识、名字  
    ```{[FileName]}``` **仅在{{PreviewBody}}中可用** , 替换为当前预览的文件名  
    ```{[PreviewContent]}```  **仅在{{PreviewBody}}中可用** , 替换为**对应的内容模板**  
    ```{[FileRawUrl]}``` **仅在预览相关模板中可用** , 替换为文件直链  
    ```{[PreviewUrl]}``` **仅在预览Office文档时可用** , 替换为在线预览链接  
    ```{[FileContent]}``` **仅在{{TxtPreview}},{{MDPreview}},{{CodePreview}}中有用** , 替换为文件原内容  
    ```{[PrismTag]}``` **仅在{{CodePreview}}中可用** , 替换为Prism代码高亮类型tag  
    ```{[FolderMD5]}``` **仅在{{PasswordPage}}中可用** , 替换为表单目录md5  


## Config  

```php
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
	'pwdConfigUpdateInterval' => 1200 /*密码配置文件本地缓存时间(in seconds)*/
);
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

* 如果odproxy.php和index.php不是相同目录下的，需要配置**proxyPath**.例如https://xxx/odproxy.php .   

* rewrite配置项若开启，你必须配置伪静态，若关闭，你可以用请求的方式访问.例如开了伪静态，你可以访问https://xxx/Document/ ，没有开伪静态，你需要访问https://xxx/?/Document/ 来进行访问。   

* datapath配置项指的是数据的储存目录，默认配置成data，OdIndex的部分数据就会储存在data目录下  

* 当noIndex配置为true时，**除了访问文件外**一律返回**noIndexPrint**内的内容  

* 当listAsJson配置为true时，所有的返回内容都会变成**Json**形式：  

  **正常返回:**
  ```json
  {
	"success": true,
	"currentPath": "",
	"folders": [{
		"name": "\ud83d\ude04emoji",
		"size": 236237,
		"link": "\ud83d\ude04emoji\/"
	}, {
		"name": "Previews",
		"size": 88567120,
		"link": "Previews\/"
	}, {
		"name": "Protected",
		"size": 7938,
		"link": "Protected\/"
	}, {
		"name": "Videos",
		"size": 325088195,
		"link": "Videos\/"
	}],
	"files": [{
		"mimeType": "image\/png",
		"name": "Potato.png",
		"size": 314,
		"link": "Potato.png?p=t"
	}, {
		"mimeType": "application\/octet-stream",
		"name": "readme.md",
		"size": 69,
		"link": "readme.md?p=t"
	}]
  }
  ```
  **找不到文件/目录的返回：**  
  ```json
  {"success":false,"msg":"Not found: \/Potato.jpg"}
  ```  
  **Json返回模式下不支持预览：**  
  ```json
  {"success":false,"msg":"Preview not available under listAsJson Mode"}
  ```
  **访问文件时的返回：**  
  ```json
  {
	"success": true,
	"fileurl": "...."
  }
  ```

* <a id="pwdCfgPath">pwdCfgPath</a>是你的密码的配置文件路径，默认是.password，也就是列表根目录的.password文件.如果你想配置成在列表Test目录内的passwordconfig文件可以这样写：  
  ```pwdCfgPath=>'Test/passwordconfig',```  

* pwdProtect如果设置为false**会直接忽略密码配置**，放行所有请求，但是能节省一定请求资源  


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
    'expire'=>1200, /*In seconds*/
    'force' => false /*是否强制开启缓存*/
),
```

SmartCache会在你的文件目录被大量访问时**自动缓存目录**，配置项只有以上三个。  

* smart 若为true则开启smartCache  
* expire 自动缓存开启后持续的时间，这段时间过去后缓存文件会被自动删除，一切恢复正常  
* 当**force**设为true时开启强制缓存，此时用户访问的页面都会被缓存，**经过expire时间**后缓存会自动清除  

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

## Thanks  
* [LemonPerfect](https://github.com/LemonPrefect/)  提供了密码保护目录以及目录下文件的思路  
* [wangjiale1125](https://github.com/wangjiale1125)  协助测试世纪互联    
* [Micraow](https://github.com/Micraow)  提出了预览相关的意见  


## Reference  
* https://docs.microsoft.com/zh-cn/graph/api/resources/onedrive?view=graph-rest-1.0  

------------------
### UNDER MIT LICENSE. 
