<?php
/**
 * http服务器
 *
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */

namespace PG\MSF;

use League\Plates\Engine;
use PG\MSF\Helpers\Context;
use PG\MSF\Base\Input;
use PG\MSF\Base\Output;
use PG\MSF\Base\AOPFactory;

/**
 * Class HttpServer
 * @package PG\MSF
 */
abstract class HttpServer extends Server
{
    /**
     * @var string HTTP服务监听地址如: 0.0.0.0
     */
    public $httpSocketName;

    /**
     * @var integer HTTP服务监听端口
     */
    public $httpPort;

    /**
     * @var bool 是否启用HTTP服务
     */
    public $httpEnable;

    /**
     * @var Engine 内置模板引擎
     */
    public $templateEngine;

    /**
     * HttpServer constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $view_dir = APP_DIR . '/Views';
        if (!is_dir($view_dir)) {
            writeln('App directory does not exist Views directory, please create.');
            exit();
        }
    }

    /**
     * 设置并解析配置
     *
     * @return $this
     */
    public function setConfig()
    {
        parent::setConfig();
        $this->httpEnable     = $this->config->get('http_server.enable', true);
        $this->httpSocketName = $this->config['http_server']['socket'];
        $this->httpPort       = $this->config['http_server']['port'];
        return $this;
    }

    /**
     * 启动服务
     *
     * @return $this
     */
    public function start()
    {
        if (!$this->httpEnable) {
            parent::start();
            return $this;
        }

        if (static::mode == 'console') {
            $this->beforeSwooleStart();
            $this->onWorkerStart(null, null);
        } else {
            //开启一个http服务器
            $this->server = new \swoole_http_server($this->httpSocketName, $this->httpPort);
            $this->server->on('Start', [$this, 'onStart']);
            $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
            $this->server->on('WorkerStop', [$this, 'onWorkerStop']);
            $this->server->on('Task', [$this, 'onTask']);
            $this->server->on('Finish', [$this, 'onFinish']);
            $this->server->on('PipeMessage', [$this, 'onPipeMessage']);
            $this->server->on('WorkerError', [$this, 'onWorkerError']);
            $this->server->on('ManagerStart', [$this, 'onManagerStart']);
            $this->server->on('ManagerStop', [$this, 'onManagerStop']);
            $this->server->on('request', [$this, 'onRequest']);
            $set = $this->setServerSet();
            $set['daemonize'] = self::$daemonize ? 1 : 0;
            $this->server->set($set);
            $this->beforeSwooleStart();
            $this->server->start();
        }

        return $this;
    }

    /**
     * Swoole Worker进程启动回调
     *
     * @param \swoole_server $serv server实例
     * @param int $workerId worker id
     */
    public function onWorkerStart($serv, $workerId)
    {
        parent::onWorkerStart($serv, $workerId);
        $this->setTemplateEngine();
    }

    /**
     * 设置模板引擎
     *
     * @return $this
     */
    public function setTemplateEngine()
    {
        $this->templateEngine = new Engine();
        return $this;
    }

    /**
     * HTTP请求回调
     *
     * @param \swoole_http_request $request 请求对象
     * @param \swoole_http_response $response 响应对象
     */
    public function onRequest($request, $response)
    {
        $this->requestId++;
        $error              = '';
        $httpCode           = 500;
        $logId              = $this->genLogId($request);
        $instance           = null;
        $this->route->handleHttpRequest($request);

        // 构造请求日志对象
        $PGLog            = clone getInstance()->log;
        $PGLog->accessRecord['beginTime'] = microtime(true);
        $PGLog->accessRecord['uri']       = $this->route->getPath() ?: '/';
        $PGLog->logId     = $logId;
        defined('SYSTEM_NAME') && $PGLog->channel = SYSTEM_NAME;
        $PGLog->init();
        $PGLog->pushLog('verb', $this->route->getVerb());
        $PGLog->pushLog('user-agent', $request->header['user-agent'] ?? 'unknown');
        $PGLog->pushLog('remote-addr', self::getRemoteAddr($request));

        do {
            if ($this->route->getPath() == '') {
                $indexFile = $this->config['http']['domain'][$this->route->getHost()]['index'] ?? null;
                $response->header('X-Ngx-LogId', $PGLog->logId);
                $httpCode  = $this->sendFile($indexFile, $request, $response);
                $PGLog->pushLog('http-code', $httpCode);
                $PGLog->appendNoticeLog();
                return;
            }

            if ($this->route->getFile()) {
                $response->header('X-Ngx-LogId', $PGLog->logId);
                $httpCode  = $this->sendFile($this->route->getFile(), $request, $response);
                $PGLog->pushLog('http-code', $httpCode);
                $PGLog->appendNoticeLog();
                return;
            }

            $controllerName      = $this->route->getControllerName();
            $controllerClassName = $this->route->getControllerClassName();
            if ($controllerClassName == '') {
                $error    = 'Api not found controller(' . $controllerName . ')';
                $httpCode = 404;
                break;
            }

            $methodPrefix = $this->config->get('http.method_prefix', 'action');
            $methodName   = $methodPrefix . $this->route->getMethodName();

            try {
                /**
                 * @var \PG\MSF\Controllers\Controller $instance
                 */
                $instance = $this->objectPool->get($controllerClassName, [$controllerName, $methodName]);
                $instance->__useCount++;
                if (empty($instance->getObjectPool())) {
                    $instance->setObjectPool(AOPFactory::getObjectPool(getInstance()->objectPool, $instance));
                }

                if (!method_exists($instance, $methodName)) {
                    $error    = 'Api not found method(' . $methodName . ')';
                    $httpCode = 404;
                    break;
                }

                $instance->context = $instance->getObjectPool()->get(Context::class, [$this->requestId]);
                // 初始化控制器
                $instance->requestStartTime = microtime(true);

                // 构造请求上下文成员
                $instance->context->setLogId($PGLog->logId);
                $instance->context->setLog($PGLog);
                $instance->context->setObjectPool($instance->getObjectPool());

                /**
                 * @var $input Input
                 */
                $input    = $instance->context->getObjectPool()->get(Input::class);
                $input->set($request);
                /**
                 * @var $output Output
                 */
                $output   = $instance->context->getObjectPool()->get(Output::class, [$instance]);
                $output->set($request, $response);

                $instance->context->setInput($input);
                $instance->context->setOutput($output);
                $instance->context->setControllerName($controllerName);
                $instance->context->setActionName($methodName);
                $instance->setRequestType(Marco::HTTP_REQUEST);
                $init = $instance->__construct($controllerName, $methodName);

                if ($init instanceof \Generator) {
                    $this->scheduler->start(
                        $init,
                        $instance,
                        function () use ($instance, $methodName) {
                            $generator = $instance->$methodName(...array_values($this->route->getParams()));
                            if ($generator instanceof \Generator) {
                                $this->scheduler->taskMap[$instance->context->getRequestId()]->resetRoutine($generator);
                                $this->scheduler->schedule(
                                    $this->scheduler->taskMap[$instance->context->getRequestId()],
                                    function () use ($instance) {
                                        if (!$instance->getContext()->getOutput()->__isEnd) {
                                            $instance->getContext()->getOutput()->output('Not Implemented', 501);
                                        }
                                        $instance->destroy();
                                    }
                                );
                            } else {
                                if (!$instance->getContext()->getOutput()->__isEnd) {
                                    $instance->getContext()->getOutput()->output('Not Implemented', 501);
                                }
                                $instance->destroy();
                            }
                        }
                    );
                } else {
                    $generator = $instance->$methodName(...array_values($this->route->getParams()));
                    if ($generator instanceof \Generator) {
                        $this->scheduler->start(
                            $generator, $instance,
                            function () use ($instance) {
                                if (!$instance->getContext()->getOutput()->__isEnd) {
                                    $instance->getContext()->getOutput()->output('Not Implemented', 501);
                                }
                                $instance->destroy();
                            }
                        );
                    } else {
                        if (!$instance->getContext()->getOutput()->__isEnd) {
                            $instance->getContext()->getOutput()->output('Not Implemented', 501);
                        }
                        $instance->destroy();
                    }
                }

                if ($this->route->getEnableCache() && !$this->route->getRouteCache($this->route->getPath())) {
                    $this->route->setRouteCache(
                        $this->route->getPath(),
                        [$controllerName, $this->route->getMethodName(), $controllerClassName]
                    );
                }
                break;
            } catch (\Throwable $e) {
                $instance->onExceptionHandle($e);
                $instance->destroy();
            }
        } while (0);

        if ($error !== '') {
            if ($instance != null) {
                $instance->destroy();
            }

            $PGLog->pushLog('http-code', $httpCode);
            $PGLog->appendNoticeLog();
            $response->status($httpCode);
            $response->end($error);
        }
    }

    /**
     * 获取远程客户端IP
     *
     * @param \swoole_http_request $request 请求对象
     * @return string
     */
    public static function getRemoteAddr($request)
    {
        $ip = $request->header['http_client_ip']       ??
              $request->header['x-real-ip']            ??
              $request->header['remote_addr']          ??
              $request->server['remote_addr']          ??
              "";

        if ($ip) {
            return $ip;
        }

        $ip = $request->header['x-forwarded-for']      ??
              $request->header['http_x_forwarded_for'] ??
              $request->header['http_forwarded']       ??
              $request->header['http_forwarded_for']   ??
              "";

        if ($ip) {
            $ip = explode(',', $ip);
            $ip = trim($ip[0]);
        }

        return $ip;
    }

    /**
     * 产生日志ID
     *
     * @param \swoole_http_request $request 请求对象
     * @return string
     */
    public function genLogId($request)
    {
        static $i = 0;
        $i || $i = mt_rand(1, 0x7FFFFF);

        $logId = $request->header['x-ngx-logid'] ?? $request->header['log_id'] ?? '' ;

        if (!$logId) {
            $logId = sprintf("%08x%06x%04x%06x",
                time() & 0xFFFFFFFF,
                crc32(substr((string)gethostname(), 0, 256)) >> 8 & 0xFFFFFF,
                getmypid() & 0xFFFF,
                $i = $i > 0xFFFFFE ? 1 : $i + 1
            );
        }

        return $logId;
    }

    /**
     * 直接响应静态文件
     *
     * @param string $path
     * @param \swoole_http_request $request 请求对象
     * @param \swoole_http_response $response 响应对象
     * @return int
     */
    public function sendFile($path, $request, $response)
    {
        if (empty($path)) {
            $path = __DIR__ . '/Views/index.html';
            if (!$response->sendfile($path)) {
                swoole_async_readfile($path, function ($filename, $content) use ($response) {
                    $response->end($content);
                });
            }

            return Marco::SEND_FILE_200;
        }

        $path = realpath(urldecode($path));
        $root = $this->config['http']['domain'][$this->route->getHost()]['root'] ?? null;

        // 判断文件是否存在
        if (!file_exists($path)) {
            $response->status(404);
            $response->end('');
            return Marco::SEND_FILE_404;
        }

        // 判断文件是否有权限（非root目录不能访问）
        if (empty($root) || strpos($path, $root) === false) {
            $response->status(403);
            $response->end('');
            return Marco::SEND_FILE_403;
        }

        $info      = pathinfo($path);
        $extension = strtolower($info['extension'] ?? '');

        // 判断缓存
        $lastModified = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';
        if (isset($request->header['if-modified-since']) && $request->header['if-modified-since'] == $lastModified) {
            $response->status(304);
            $response->end('');
            return Marco::SEND_FILE_304;
        }

        $normalHeaders = getInstance()->config->get("fileHeader.normal", ['Content-Type: application/octet-stream']);
        $headers       = getInstance()->config->get("fileHeader.$extension", $normalHeaders);

        foreach ($headers as $value) {
            list($hk, $hv) = explode(': ', $value);
            $response->header($hk, $hv);
        }

        $response->header('Last-Modified', $lastModified);
        if (!$response->sendfile($path)) {
            swoole_async_readfile($path, function ($filename, $content) use ($response) {
                $response->end($content);
            });
        }

        return Marco::SEND_FILE_200;
    }
}
