<?php
namespace Tideways\Traces;

/**
 * Abstraction for trace spans.
 *
 * Different implementations based on support
 */
abstract class Span
{
    /**
     * Create Child span
     * @private
     */
    abstract public function createSpan($name = null);

    /**
     * @private
     * @return array
     */
    abstract public function getSpans();

    /**
     * 32/64 bit random integer.
     *
     * @return int
     */
    public abstract function getId();

    /**
     * Record start of timer in microseconds.
     *
     * If timer is already running, don't record another start.
     */
    public abstract function startTimer();

    /**
     * Record stop of timer in microseconds.
     *
     * If timer is not running, don't record.
     */
    public abstract function stopTimer();

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public abstract function annotate(array $annotations);
}

namespace Tideways\Traces;

class TwExtensionSpan extends Span
{
    /**
     * @var int
     */
    private $idx;

    public function createSpan($name = null)
    {
        return new self(tideways_span_create($name));
    }

    public function getSpans()
    {
        return tideways_get_spans();
    }

    public function __construct($idx)
    {
        $this->idx = $idx;
    }

    /**
     * 32/64 bit random integer.
     *
     * @return int
     */
    public function getId()
    {
        return $this->idx;
    }

    /**
     * Record start of timer in microseconds.
     *
     * If timer is already running, don't record another start.
     */
    public function startTimer()
    {
        tideways_span_timer_start($this->idx);
    }

    /**
     * Record stop of timer in microseconds.
     *
     * If timer is not running, don't record.
     */
    public function stopTimer()
    {
        tideways_span_timer_stop($this->idx);
    }

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public function annotate(array $annotations)
    {
        tideways_span_annotate($this->idx, $annotations);
    }
}


namespace Tideways\Profiler;
use function Tideways\logall;

class NetworkBackend{
    //const  SEND_IP='http://127.0.0.1';
    //const  SEND_URL='/probe/phpprobe';
    private $port;
    private $url;


    public function __construct($port = "4575")
    {
        $this->port = $port;
        $this->url = 'http://127.0.0.1'.':'.$port."/probe/command";
    }

    public function store(array $trace){
        if (!is_array($trace)) {
           return;
        }
        //将数据发送给Agent
        $data=array(
            'content'=>$trace,
            'header'=>array(
                'serverName'=>ini_get("yyy.servername"),
                'tid'=>ini_get("yyy.tid"),
                'pt'=>"command",
                'srid'=>"default",
            ),
        );
        $sendData=array();
        $sendData[0]=$data;
        //将数组转为json串
        $sendData = json_encode($sendData);
        logall('store','sendData',$sendData);
        //数据发送
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->url);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $sendData);
        $response = curl_exec($curl);
        curl_close($curl);
        logall('store','response',$response);
    }

}




/**
 * Convert a Backtrace to a String like {@see Exception::getTraceAsString()} would do.
 */
class BacktraceConverter
{
    static public function convertToString(array $backtrace)
    {
        $trace = '';

        foreach ($backtrace as $k => $v) {
            if (!isset($v['function'])) {
                continue;
            }

            if (!isset($v['file'])) {
                $v['file'] = '';
            }

            if (!isset($v['line'])) {
                $v['line'] = '';
            }

            $args = '';
            if (isset($v['args'])) {
                $args = implode(', ', array_map(function ($arg) {
                    return (is_object($arg)) ? get_class($arg) : gettype($arg);
                }, $v['args']));
            }

            $trace .= '#' . ($k) . ' ';
            if (isset($v['file'])) {
                $trace .= $v['file'] . '(' . $v['line'] . '): ';
            }

            if (isset($v['class'])) {
                $trace .= $v['class'] . '->';
            }

            $trace .= $v['function'] . '(' . $args .')' . "\n";
        }

        return $trace;
    }
}

namespace Tideways;
class Profiler
{
    const MODE_DISABLED  = 0;
    const MODE_NONE = 0;
    const MODE_BASIC = 1;
    const MODE_PROFILING = 2;
    const MODE_TRACING = 4;
    const MODE_FULL = 6;

    const EXTENSION_NONE = 0;
    const EXTENSION_TIDEWAYS = 2;

    const EXT_FATAL            = 1;
    const EXT_EXCEPTION        = 4;
    const EXT_TRANSACTION_NAME = 8;

    const FRAMEWORK_ZEND_FRAMEWORK1    = 'zend1';
    const FRAMEWORK_ZEND_FRAMEWORK2    = 'zend2';
    const FRAMEWORK_SYMFONY2_COMPONENT = 'symfony2c';
    const FRAMEWORK_SYMFONY2_FRAMEWORK = 'symfony2';
    const FRAMEWORK_OXID               = 'oxid';
    const FRAMEWORK_OXID6              = 'oxid6';
    const FRAMEWORK_SHOPWARE           = 'shopware';
    const FRAMEWORK_WORDPRESS          = 'wordpress';
    const FRAMEWORK_LARAVEL            = 'laravel';
    const FRAMEWORK_MAGENTO            = 'magento';
    const FRAMEWORK_MAGENTO2           = 'magento2';
    const FRAMEWORK_PRESTA16           = 'presta16';
    const FRAMEWORK_DRUPAL8            = 'drupal8';
    const FRAMEWORK_TYPO3              = 'typo3';
    const FRAMEWORK_FLOW               = 'flow';
    const FRAMEWORK_FLOW4              = 'flow4';
    const FRAMEWORK_CAKE2              = 'cake2';
    const FRAMEWORK_CAKE3              = 'cake3';
    const FRAMEWORK_YII                = 'yii';
    const FRAMEWORK_YII2               = 'yii2';

    /**
     * Default XHProf/Tideways hierachical profiling options.
     */
    private static $defaultOptions = array(
        'ignored_functions' => array(
            'call_user_func',
            'call_user_func_array',
            'array_filter',
            'array_map',
            'array_reduce',
            'array_walk',
            'array_walk_recursive',
            'Symfony\Component\DependencyInjection\Container::get',
        ),
        'transaction_function' => null,
        'exception_function' => null,
        'watches' => array(),
        'callbacks' => array(),
        'framework' => null,
    );

    private static $trace;
    private static $currentRootSpan;
    private static $shutdownRegistered = false;
    private static $error = false;
    private static $mode = self::MODE_DISABLED;
    private static $backend;
    private static $extension = self::EXTENSION_NONE;
    private static $logLevel = 0;

    public static function isStarted()
    {
        return self::$mode !== self::MODE_DISABLED;
    }
    public static function ignoreTransaction()
    {
        if (self::$mode !== self::MODE_DISABLED) {
            self::$mode = self::MODE_DISABLED;
            tideways_disable();
        }
    }

    //根据框架（$framework），自动设置对应的transaction_function和exception_function
    public static function detectFramework($framework){
        self::$defaultOptions['framework'] = $framework;
        $cli = (php_sapi_name() === 'cli');

        switch ($framework) {
            case self::FRAMEWORK_ZEND_FRAMEWORK1://zend1
                self::$defaultOptions['transaction_function'] = 'Zend_Controller_Action::dispatch';
                self::$defaultOptions['exception_function'] = 'Zend_Controller_Response_Abstract::setException';
                break;

            case self::FRAMEWORK_ZEND_FRAMEWORK2://zend2
                self::$defaultOptions['transaction_function'] = 'Zend\\Mvc\\Controller\\ControllerManager::get';
                break;

            case self::FRAMEWORK_SYMFONY2_COMPONENT://symfony2c
                self::$defaultOptions['transaction_function'] = $cli
                    ? 'Symfony\Component\Console\Application::find'
                    : 'Symfony\Component\HttpKernel\Controller\ControllerResolver::createController';
                self::$defaultOptions['exception_function'] = $cli
                    ? 'Symfony\Component\Console\Application::renderException'
                    : 'Symfony\Component\HttpKernel\HttpKernel::handleException';
                break;

            case self::FRAMEWORK_SYMFONY2_FRAMEWORK:
                self::$defaultOptions['transaction_function'] = $cli
                    ? 'Symfony\Component\Console\Application::find'
                    : 'Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver::createController';
                self::$defaultOptions['exception_function'] = $cli
                    ? 'Symfony\Component\Console\Application::renderException'
                    : 'Symfony\Component\HttpKernel\HttpKernel::handleException';
                break;

            case self::FRAMEWORK_OXID:
                self::$defaultOptions['transaction_function'] = 'oxView::setClassName';
                self::$defaultOptions['exception_function'] = 'oxShopControl::_handleBaseException';
                break;

            case self::FRAMEWORK_OXID6:
                self::$defaultOptions['transaction_function'] = 'OxidEsales\EshopCommunity\Core\Controller\BaseController::setClassKey';
                self::$defaultOptions['exception_function'] = 'OxidEsales\EshopCommunity\Core\ShopControl::logException';
                break;

            case self::FRAMEWORK_SHOPWARE:
                self::$defaultOptions['transaction_function'] = $cli
                    ? 'Symfony\Component\Console\Application::find'
                    : 'Enlight_Controller_Action::dispatch';
                self::$defaultOptions['exception_function'] = $cli
                    ? 'Symfony\Component\Console\Application::renderException'
                    : 'Zend_Controller_Response_Abstract::setException';
                break;

            case self::FRAMEWORK_WORDPRESS:
                self::$defaultOptions['transaction_function'] = 'get_query_template';
                break;

            case self::FRAMEWORK_LARAVEL:
                self::$defaultOptions['transaction_function'] = $cli
                    ? 'Symfony\Component\Console\Application::find'
                    : 'Illuminate\Routing\Controller::callAction';
                self::$defaultOptions['exception_function'] = $cli
                    ? 'Symfony\Component\Console\Application::renderException'
                    : 'Illuminate\Foundation\Http\Kernel::reportException';
                break;

            case self::FRAMEWORK_MAGENTO:
                self::$defaultOptions['transaction_function'] = 'Mage_Core_Controller_Varien_Action::dispatch';
                self::$defaultOptions['exception_function'] = 'Mage::printException';
                break;

            case self::FRAMEWORK_MAGENTO2:
                self::$defaultOptions['transaction_function'] = 'Magento\Framework\App\ActionFactory::create';
                self::$defaultOptions['exception_function'] = 'Magento\Framework\App\Http::catchException';
                break;

            case self::FRAMEWORK_PRESTA16:
                self::$defaultOptions['transaction_function'] = 'ControllerCore::getController';
                self::$defaultOptions['exception_function'] = 'PrestaShopExceptionCore::displayMessage';
                break;

            case self::FRAMEWORK_DRUPAL8:
                self::$defaultOptions['transaction_function'] = 'Drupal\Core\Controller\ControllerResolver::createController';
                self::$defaultOptions['exception_function'] = 'Symfony\Component\HttpKernel\HttpKernel::handleException';
                break;

            case self::FRAMEWORK_FLOW:
                self::$defaultOptions['transaction_function'] = 'TYPO3\Flow\Mvc\Controller\ActionController_Original::callActionMethod';
                self::$defaultOptions['exception_function'] = 'TYPO3\Flow\Error\AbstractExceptionHandler::handleException';
                break;

            case self::FRAMEWORK_FLOW4:
                self::$defaultOptions['transaction_function'] = 'Neos\Flow\Mvc\Controller\ActionController_Original::callActionMethod';
                self::$defaultOptions['exception_function'] = 'Neos\Flow\Error\AbstractExceptionHandler::handleException';
                break;

            case self::FRAMEWORK_TYPO3:
                self::$defaultOptions['transaction_function'] = 'TYPO3\CMS\Extbase\Mvc\Controller\ActionController::callActionMethod';
                self::$defaultOptions['exception_function'] = 'TYPO3\CMS\Error\AbstractExceptionHandler::handleException';
                break;

            case self::FRAMEWORK_CAKE2:
                self::$defaultOptions['transaction_function'] = 'Controller::invokeAction';
                self::$defaultOptions['exception_function'] = 'ExceptionRenderer::__construct';
                break;

            case self::FRAMEWORK_CAKE3:
                self::$defaultOptions['transaction_function'] = 'Cake\\Controller\\Controller::invokeAction';
                self::$defaultOptions['exception_function'] = 'Cake\\Error\\ExceptionRenderer::__construct';
                break;

            case self::FRAMEWORK_YII:
                self::$defaultOptions['transaction_function'] = 'CController::run';
                self::$defaultOptions['exception_function'] = 'CApplication::handleException';
                break;

            case self::FRAMEWORK_YII2:
                self::$defaultOptions['transaction_function'] = 'yii\\base\\Module::runAction';
                self::$defaultOptions['exception_function'] = 'yii\\base\\ErrorHandler::handleException';
                break;

            default:
                self::$defaultOptions['transaction_function'] = $framework;
                break;
        }
    }

    public static function generateRandomId()
    {
        return mt_rand(1, PHP_INT_MAX);
    }


    //注册请求结束方法，实例化$backend、$mode、$trace等值
    private static function init( $options)
    {
        //注册请求结束的函数
        if (self::$shutdownRegistered == false) {
            register_shutdown_function(array("Tideways\\Profiler", "shutdown"));
            self::$shutdownRegistered = true;
        }

        //创建静态变量$backend，用于数据发送给Agent
        if (self::$backend === null) {
            self::$backend = new Profiler\NetworkBackend(
                ini_get('yyy.port') ?: '4575'
            );
        }

        //根据框架名，设置对应的transaction_function和exception_function
        if ($options['framework']) {
            self::detectFramework($options['framework']);
        }

        if (function_exists('tideways_enable')) {
            self::$extension = self::EXTENSION_TIDEWAYS;
        }

        self::$trace = array(
            'txid' => self::generateRandomId(),
            'tx' => 'default',
        );
    }
    private static function convertMode($mode){
        if (!is_int($mode)) {
            $mode = self::MODE_DISABLED;
        } else if (($mode & (self::MODE_FULL|self::MODE_BASIC)) === 0) {
            $mode = self::MODE_DISABLED;
        }

        return $mode;
    }

    public static function setCustomVariable($name, $value)
    {
        if ((self::$mode & self::MODE_FULL) === 0 || !is_scalar($value)) {
            return;
        }

        if (!self::$currentRootSpan) {
            return;
        }

        self::$currentRootSpan->annotate(array($name => $value));
    }

    private static function enableProfiler($mode)
    {
        self::$mode = $mode;
        if (self::$extension === self::EXTENSION_TIDEWAYS && (self::$mode !== self::MODE_DISABLED)) {
            switch (self::$mode) {
                case self::MODE_FULL:
                    //$flags = 0; add by gaochy8
                    $flags = 0|TIDEWAYS_FLAGS_CPU|TIDEWAYS_FLAGS_MEMORY;
                    break;

                case self::MODE_PROFILING:
                    $flags = TIDEWAYS_FLAGS_NO_SPANS;
                    break;

                case self::MODE_TRACING:
                    $flags = TIDEWAYS_FLAGS_NO_HIERACHICAL;
                    break;

                default:
                    $flags = TIDEWAYS_FLAGS_NO_COMPILE | TIDEWAYS_FLAGS_NO_USERLAND | TIDEWAYS_FLAGS_NO_BUILTINS;
                    break;
            }
            //$currentRootSpan指向spans[0]，即app span
            self::$currentRootSpan = new \Tideways\Traces\TwExtensionSpan(0);
            //启动探针
            tideways_enable($flags, self::$defaultOptions);
            //为方法添加watches和callbacks
            if (($flags & TIDEWAYS_FLAGS_NO_SPANS) === 0) {
                foreach (self::$defaultOptions['watches'] as $watch => $category) {
                    tideways_span_watch($watch, $category);
                }
                foreach (self::$defaultOptions['callbacks'] as $function => $callback) {
                    tideways_span_callback($function, $callback);
                }
            }
            //self::log(2, "Starting tideways extension for " . self::$trace['apiKey'] . " with mode: " . $mode);
        }
    }

    private static function decideProfiling(array $options = array())
    {
        $collectMode = (int)$options['collectmode'];
        $collectMode = self::convertMode($collectMode);
        //通过给定的采样模式，进行采样
        self::enableProfiler($collectMode);
        //为$currentRootSpan添加注解
        self::setCustomVariable('tid', $options['tid']);
        self::setCustomVariable('servername', $options['servername']);
        //从Cookie中解析appid、bussid等字段
        self::setCustomVariableFromCookie("appid","_yyy_appid");
        self::setCustomVariableFromCookie("busiid","_yyy_busiid");

    }

    //目前的js探针只通过Cookie传递值
    private static function setCustomVariableFromCookie($name,$key){
        if(isset($_COOKIE[$key])){
            self::setCustomVariable($name,$_COOKIE[$key]);
        }
    }

    public static function start($options = array(), $sampleRate = null)
    {
        //丢弃之前数据
        self::ignoreTransaction();

        //从php.ini读取tid等字段
        $defaults = array(
            'tid' => ini_get("yyy.tid"),
            'servername' => ini_get("yyy.servername"),
            'collectmode' =>ini_get("yyy.collectmode"),
            'framework' => ini_get("yyy.framework"),
        );

        //将$defaults加入到$options
        $options = array_merge($defaults, $options);

        //初始化：注册请求结束方法，实例化$backend、$mode、$trace等值
        self::init($options);

        //确定开始收集数据的模式，开始采样
        self::decideProfiling($options);

    }

    public static function logFatal($message, $file, $line, $type = null, $trace = null)
    {
        if (self::$error === true || !self::$currentRootSpan) {
            return;
        }

        if ($type === null) {
            $type = E_USER_ERROR;
        }

        $trace = is_array($trace)
            ? \Tideways\Profiler\BacktraceConverter::convertToString($trace)
            : $trace;

        self::$error = true;
        self::$currentRootSpan->annotate(array(
            "err_msg" => $message,
            "err_source" => $file . ':' . $line,
            "err_exception" => 'EngineException', // Forward compatibility with PHP7
            "err_trace" => $trace,
        ));
    }

    public static function logException($exception)
    {
        if (is_string($exception)) {
            $exception = new \RuntimeException($exception);
        }

        if (self::$error === true || !self::$currentRootSpan || !is_object($exception)) {
            return;
        }

        // PHP 5 compatible way to check for !($exception instanceof Throwable)
        if (!($exception instanceof \Exception) && !in_array('Throwable', class_implements($exception, false))) {
            return;
        }

        // We are only interested in the original exception
        while ($previous = $exception->getPrevious()) {
            $exception = $previous;
        }

        self::$error = true;
        self::$currentRootSpan->annotate(array(
            "err_msg" => $exception->getMessage(),
            "err_source" => $exception->getFile() . ':' . $exception->getLine(),
            "err_exception" => get_class($exception),
            "err_trace" => \Tideways\Profiler\BacktraceConverter::convertToString($exception->getTrace()),
        ));
    }

    //停止数据采集，并返回数据
    public static function stop()
    {
        if (self::$mode === self::MODE_DISABLED) {
            return;
        }
        $mode = self::$mode;
        //返回transaction_name
        if (self::$trace['tx'] === 'default' && self::$extension === self::EXTENSION_TIDEWAYS) {
            self::$trace['tx'] = tideways_transaction_name() ?: 'default';
        }

        //调用tideways方法，采集异常信息
        if (function_exists('tideways_last_detected_exception') && $exception = tideways_last_detected_exception()) {
            self::logException($exception);
        } elseif (function_exists("http_response_code") && http_response_code() >= 500) {
            self::logFatal("PHP request set error HTTP response code to '" . http_response_code() . "'.", "", 0, E_USER_ERROR);
        }

        $profilingData = array();

        //调用tideways_disable方法，返回stats_count
        //如果$mode不为0，或error不空（出错），则记录
        if (($mode & self::MODE_FULL) > 0 || self::$error) {
            if (self::$extension === self::EXTENSION_TIDEWAYS) {
                $profilingData = tideways_disable();
            }

            //为$currentRootSpan添加注解
            $annotations = array('mem' => ceil(memory_get_peak_usage() / 1024));

            if (self::$extension === self::EXTENSION_TIDEWAYS) {
                $annotations['xhpv'] = phpversion('tideways');

                if (self::$defaultOptions['framework']) {
                    $annotations['framework'] = self::$defaultOptions['framework'];
                }
            }

            if (extension_loaded('xdebug')) {
                $annotations['xdebug'] = '1';
            }
            $annotations['php'] = PHP_VERSION;
            $annotations['sapi'] = php_sapi_name();

            //能否作为事务名称
            if (isset($_SERVER['REQUEST_URI'])) {
                //添加http请求信息
                $annotations['title'] = '';
                if (isset($_SERVER['REQUEST_METHOD'])) {
                    $annotations['title'] = $_SERVER["REQUEST_METHOD"] . ' ';
                }

                if (isset($_SERVER['HTTP_HOST'])) {
                    $annotations['title'] .= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . self::getRequestUri();
                } elseif(isset($_SERVER['SERVER_ADDR'])) {
                    $annotations['title'] .= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['SERVER_ADDR'] . self::getRequestUri();
                }

                if (isset($_SERVER['QUERY_STRING'])) {
                    $annotations['query'] = $_SERVER['QUERY_STRING'];
                }

            } elseif ($annotations['sapi'] === "cli") {
                //命令行模式下，获取脚本名
                $annotations['title'] = basename($_SERVER['argv'][0]);
            }
        }else {
            self::$currentRootSpan->stopTimer();
            $annotations = array('mem' => ceil(memory_get_peak_usage() / 1024));
        }

        //在currentRootSpan中记录tx和txid
        $annotations['tx']=self::$trace['tx'];
        $annotations['txid']=self::$trace['txid'];

        //释放$trace中的tx和txid
        unset(self::$trace['tx']);
        unset(self::$trace['txid']);

        self::$currentRootSpan->annotate($annotations);

        if (($mode & self::MODE_PROFILING) > 0) {
            self::$trace['profdata'] = $profilingData ?: array();
        }

        self::$mode = self::MODE_DISABLED;

        //存储spans数据
        $spans = self::$currentRootSpan->getSpans();


        if (self::$error === true || ($mode & self::MODE_FULL) > 0) {
            //出现错误，或$mode不为0 ，将整个$spans存储    
            self::$trace['spans'] = $spans;
            self::$backend->store(self::$trace);
        }


      /*  logall('stop','error',tideways_last_fatal_error());
        logall('stop','exception',tideways_last_detected_exception());
        logall('stop','$trace', self::$trace);*/

        // 释放内存
        self::$trace = null;
        self::$logLevel = 0;


    }

    protected static function getRequestUri()
    {
        return strpos($_SERVER["REQUEST_URI"], "?")
            ? substr($_SERVER["REQUEST_URI"], 0, strpos($_SERVER["REQUEST_URI"], "?"))
            : $_SERVER["REQUEST_URI"];
    }


    //请求结束调用方法
    public static function shutdown()
    {
        if (self::$mode === self::MODE_DISABLED) {
            return;
        }
        //函数返回最后发生的错误
        $lastError = error_get_last();

        if ($lastError && ($lastError["type"] === E_ERROR || $lastError["type"] === E_PARSE || $lastError["type"] === E_COMPILE_ERROR)) {
            $lastError['trace'] = function_exists('tideways_fatal_backtrace') ? tideways_fatal_backtrace() : null;

            self::logFatal($lastError['message'], $lastError['file'], $lastError['line'], $lastError['type'], $lastError['trace']);
        }
        //结束采样，记录数据
        self::stop();
    }

    public static function autoStart()
    {
            if (self::isStarted() === false) {
                        self::start();

            }
    }
}

function logall($func,$dataname,$data) {
    //数据类型检测
    if (is_array($data)) {
        $data = json_encode($data);
    }
    $filename = "/home/php_log/tideways.log";
    $info="$func::$dataname==>$data";
    $str = date("Y-m-d H:i:s")."   $info"."\n";
    file_put_contents($filename, $str, FILE_APPEND|LOCK_EX);
    return null;
}

//添加js探针
echo "<script src='yonyou-yyy.js'></script>";
//开启采样
\Tideways\Profiler::autoStart();