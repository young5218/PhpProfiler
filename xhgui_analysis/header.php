<?php
// 检查至少需要包含xhprof、uprofiler、tideways、tideways_xhprof之一
if (!extension_loaded('xhprof') && !extension_loaded('uprofiler') && !extension_loaded('tideways') && !extension_loaded('tideways_xhprof')) {
    error_log('xhgui - either extension xhprof, uprofiler or tideways must be loaded');
    return;
}

//dir表示xhgui所在目录，如/home/php/xhgui-branch
$dir = dirname(__DIR__);
//引入下述文件，目的是引入Xhgui_Config对象
require_once $dir . '/src/Xhgui/Config.php';
//将config下的配置文件，读入到Xhgui_Config的_config数组中
Xhgui_Config::load($dir . '/config/config.default.php');
if (file_exists($dir . '/config/config.php')) {
    Xhgui_Config::load($dir . '/config/config.php');
}
//销毁变量dir
unset($dir);
//根据Xhgui_Config._config数组的debug字段，运行时设置php.ini字段
if(Xhgui_Config::read('debug'))
{
    ini_set('display_errors',1);
}
//读取profiler.filter_path字段
$filterPath = Xhgui_Config::read('profiler.filter_path');
//过滤：如果当前运行脚本的根目录在filterPath中，程序直接返回。
if(is_array($filterPath)&&in_array($_SERVER['DOCUMENT_ROOT'],$filterPath)){
    return;
}
//如果未加载到mongo扩展，程序直接返回
if ((!extension_loaded('mongo') && !extension_loaded('mongodb')) && Xhgui_Config::read('save.handler') === 'mongodb') {
    error_log('xhgui - extension mongo not loaded');
    return;
}
//根据config.default.php中配置的profiler.enable，判断是否继续执行
if (!Xhgui_Config::shouldRun()) {
    return;
}
//请求开始时间戳，如果未设置，在此将其设置为当前时间（微秒级）
if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}
//根据config.default.php中配置的extension，设置扩展名
//根据对应的扩展，调用tideways中的相应导出函数，下述的常量，在tideways的PHP_MINIT_FUNCTION函数中设置
$extension = Xhgui_Config::read('extension');
if ($extension == 'uprofiler' && extension_loaded('uprofiler')) {
    uprofiler_enable(UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY);
} else if ($extension == 'tideways_xhprof' && extension_loaded('tideways_xhprof')) {
    tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_MEMORY | TIDEWAYS_XHPROF_FLAGS_MEMORY_MU | TIDEWAYS_XHPROF_FLAGS_MEMORY_PMU | TIDEWAYS_XHPROF_FLAGS_CPU);
} else if ($extension == 'tideways' && extension_loaded('tideways')) {
    //参数中的常量为long型，在php_tideways.h中定义，这里做了按位或操作
    tideways_enable(TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY);
    tideways_span_create('sql');
} else if(function_exists('xhprof_enable')){
    if (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION > 4) {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS);
    } else {
        xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
    }
}else{
    throw new Exception("Please check the extension name in config/config.default.php \r\n,you can use the 'php -m' command.", 1);
}

register_shutdown_function(
    function () {
        $extension = Xhgui_Config::read('extension');
        if ($extension == 'uprofiler' && extension_loaded('uprofiler')) {
            $data['profile'] = uprofiler_disable();
        } else if ($extension == 'tideways_xhprof' && extension_loaded('tideways_xhprof')) {
            $data['profile'] = tideways_xhprof_disable();
        } else if ($extension == 'tideways' && extension_loaded('tideways')) {
            //调用tideways导出函数，将采集的数据取出来
            $data['profile'] = tideways_disable();
            $sqlData = tideways_get_spans();
            $data['sql'] = array();
            //sql数据
            if(isset($sqlData[1])){
                foreach($sqlData as $val){
                    if(isset($val['n'])&&$val['n'] === 'sql'&&isset($val['a'])&&isset($val['a']['sql'])){
                        $_time_tmp = (isset($val['b'][0])&&isset($val['e'][0]))?($val['e'][0]-$val['b'][0]):0;
                        if(!empty($val['a']['sql'])){
                            $data['sql'][] = [
                                'time' => $_time_tmp,
                                'sql' => $val['a']['sql']
                            ];
                        }
                    }
                }
            }
        } else {
            $data['profile'] = xhprof_disable();
        }

        //忽略与用户的断开,脚本继续执行
        ignore_user_abort(true);
        //将当前为止程序的所有输出发送到用户的浏览器
        flush();
        //如果XHGUI_ROOT_DIR常量存在，将/src/bootstrap.php引入
        if (!defined('XHGUI_ROOT_DIR')) {
            require dirname(dirname(__FILE__)) . '/src/bootstrap.php';
        }
        //获取请求Uri
        $uri = array_key_exists('REQUEST_URI', $_SERVER)
            ? $_SERVER['REQUEST_URI']
            : null;
        if (empty($uri) && isset($_SERVER['argv'])) {
            $cmd = basename($_SERVER['argv'][0]);
            $uri = $cmd . ' ' . implode(' ', array_slice($_SERVER['argv'], 1));
        }
        //获取请求开始时间戳
        $time = array_key_exists('REQUEST_TIME', $_SERVER)
            ? $_SERVER['REQUEST_TIME']
            : time();
        //获取请求开始时间戳(微秒级),以点号分隔放入$requestTimeFloat
        $requestTimeFloat = explode('.', $_SERVER['REQUEST_TIME_FLOAT']);
        if (!isset($requestTimeFloat[1])) {
            $requestTimeFloat[1] = 0;
        }
        //根据save.handler选择处理方式
        if (Xhgui_Config::read('save.handler') === 'file') {
            $requestTs = array('sec' => $time, 'usec' => 0);
            $requestTsMicro = array('sec' => $requestTimeFloat[0], 'usec' => $requestTimeFloat[1]);
        } else {
            $requestTs = new MongoDate($time);
            $requestTsMicro = new MongoDate($requestTimeFloat[0], $requestTimeFloat[1]);
        }
        //存储meta数据，用于描述数据的数据
        $data['meta'] = array(
            'url' => $uri,
            'SERVER' => $_SERVER,
            'get' => $_GET,
            'env' => $_ENV,
            'simple_url' => Xhgui_Util::simpleUrl($uri),
            'request_ts' => $requestTs,
            'request_ts_micro' => $requestTsMicro,
            'request_date' => date('Y-m-d', $time),
        );
        //将数据发送至mongodb
        try {
            $config = Xhgui_Config::all();
            $config += array('db.options' => array());
            $saver = Xhgui_Saver::factory($config);
            $saver->save($data);
        } catch (Exception $e) {
            error_log('xhgui - ' . $e->getMessage());
        }
    }
);
