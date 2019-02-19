# LaravelMergeRequest
laravel框架 根据路由规则创建/发起虚拟请求,可以在当前请求中发起多个虚拟请求


## 安装
`composer require xaboy/laravel-merge-request`

## 说明

### 示例

路由规则
```php
//获取验证码
Route::get('captcha', 'AuthController@captcha');
 
//登录并返回用户id
//例: [user_id=>1]
Route::post('login','AuthController@login'); 

//根据用户id获取用户信息
Route::get('user/info/{id}','AuthController@userInfo'); 
```
合并上面三条路由规则
```php
use Xaboy\MergeRequest\MergeRequest;

$mergeRequest = new MergeRequest([
    'loginInfo'=>'auth/loginInfo',
    'login'=>[
        'path'=>'login',
        'method'=>'post',
        'post'=>[
            'username'=>'username',
            'password'=>'password',
        ]
    
    ],
    'userInfo'=>[
        'path'=>'user/info/{id}',
        'route'=>[
            'id'=>'${mr:login.user_id}' //模板变量
        ]
    ]
])
```
发起虚拟请求
```php
$mergeData = $mergeRequest->run();
```

### 规则

- `path` : 路由规则/请求地址/路由别名/控制器@方法
- `method` : 请求方式,默认为`GET`
- `route` : 路由参数
- `post` : POST 参数,默认为当前请求的 POST 参数
- `get` : GET 参数,默认为当前请求的 GET 参数
- `files` : FILE 参数,默认为当前请求的 FILE 参数
- `parameter` : 自定义参数,默认为当前请求的自定义参数

以上配置项都支持模板变量

### 事件

- `executing` : 发起模拟请求时触发
  
  参数: 
  - `$key`: 生成规则的 key
  - `&$mergeData` : 该请求之前已请求到的数据

- `executed` : 发起模拟请求之后触发
  
  参数: 
  - `$res` : 该请求返回的数据
  - `$key`: 生成规则的 key
  - `&$mergeData` : 该请求之前已请求到的数据

- `loaded` : 所有模拟请求结束后触发
  
  参数: 
    -  `&$mergeData` : 所有模拟请求返回的数据

示例
```php
class Observer{
    public function executing($key, $mergeData){}
    public function executed($res, $key, $mergeData){}
    public function loaded($mergeData){}
}

//绑定
$mr->observer(Observer::class);
```
### 模板变量

`${mr:key.res.data}`

参考上面的示例


## 更多示例
路由规则
```php
Route::post('user/update/{id}','AuthController@updateUser')->name('user.update');
```
规则1
```php
[
    'path'=>'user/update/{id}',
    'method'=>'post',
    'route'=>[
        'id'=>1
    ]
]
```
规则2
```php
[
    'path'=>'AuthController@updateUser',
    'method'=>'post',
    'route'=>[
        'id'=>1
    ]
]
```
规则3
```php
[
    'path'=>'user.update',
    'method'=>'post',
    'route'=>[
        'id'=>1
    ]
]
```
规则4
```php
[
    'path'=>'user/update/1',
    'method'=>'post'
]
```