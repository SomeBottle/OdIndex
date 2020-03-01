# OdIndex
Onedrive index transplanted from Heymind.

最近发现Heymind写的<a href='https://github.com/heymind/OneDrive-Index-Cloudflare-Worker' target='_blank'>Cloudflare Worker</a>版的oneindex很好用，但尚存bug，发issue和邮件作者也没回答，遂移植了一个php版本.

> 原Heymind的Cloudflare Workers版OnedriveIndex使用**MIT协议**.  

## Features & Drawbacks  
* 转发下载  
* md小缩略图修复  
* accesstoken有需要时自动刷新  
* 支持站点非根目录  
* 没有移植缓存系统  
* 支持缩略图  
* 没有文件上传功能  

## Deployment  
1. 准备一个网站服务器，把仓库中两个.php玩意丢进去  
2. 按照<a href='https://github.com/SomeBottle/OdIndex/blob/master/heymind.md' target='_blank'>Heymind</a>的方式获取refresh_token  
**PS:Heymind的工具目前测试只支持个人版，Business版请转弯去Oneindex**  
3. 在index.php替换相关参数  
4. 设置伪静态:  
  
  ```
  if (!-f $request_filename){
    set $rule_0 1$rule_0;
  }
  if (!-d $request_filename){
    set $rule_0 2$rule_0;
  }
  if ($rule_0 = "21"){
    rewrite ^/(.*)$ /?q=$1 last;
  }
  ```
  如果是**非根目录**，要在伪静态规则上作相应调整。  
  
## Config  
其他配置类似Heymind的worker版，简单说一两个特殊的：  
```php
'sitepath'=>'',  
...
'useProxy'=>true  
```
sitepath配置项是为了适应**站点非根目录**的，如果你的站点类似于**https://xxx/** ，这一个配置项**留空**；如果你的站点类似于**https://xxx/onedrive/** ，那么这个配置项你就要改成：  
```php
'sitepath'=>'/onedrive',  
//末尾不要斜杠！  
```

useProxy配置项用于启动转发下载，如果为true，调用直链时会自动用odproxy.php转发下载.  

------------------
### MIT LICENSE. 
