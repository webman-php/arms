<?php

namespace Webman\Arms;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use Zipkin\TracingBuilder;
use Zipkin\Samplers\BinarySampler;
use Zipkin\Endpoint;
use Workerman\Timer;
use support\Db;
use const Zipkin\Tags\SQL_QUERY;

class Arms implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        static $tracing = null, $tracer = null;
        $config = config('plugin.webman.arms.app');
        if (!$tracing) {
            $endpoint = Endpoint::create($config['app_name'], $request->getRealIp(), null, 2555);
            $logger = new \Monolog\Logger('log');
            $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());
            $reporter = new \Zipkin\Reporters\Http([
                'endpoint_url' => $config['endpoint_url']
            ]);
            $sampler = BinarySampler::createAsAlwaysSample();
            $tracing = TracingBuilder::create()
                ->havingLocalEndpoint($endpoint)
                ->havingSampler($sampler)
                ->havingReporter($reporter)
                ->build();
            $tracer = $tracing->getTracer();
            // 30秒上报一次，尽量将上报对业务的影响减少到最低
            $time_interval = $config['time_interval'];
            Timer::add($time_interval, function () use ($tracer) {
                $tracer->flush();
            });
            register_shutdown_function(function () use ($tracer) {
                $tracer->flush();
            });

            if (class_exists('\Illuminate\Database\Events\QueryExecuted')) {
                Db::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($tracer) {
                    $rootSpan = request()->rootSpan ?? null;
                    if ($rootSpan && 'select 1' != trim($query->sql)) {
                        $contents = "[{$query->time} ms] " . vsprintf(str_replace('?', "'%s'", $query->sql), $query->bindings);
                        $sqlSpan = $tracer->newChild($rootSpan->getContext());
                        $sqlSpan->setName(SQL_QUERY . ':' . $query->connectionName);
                        $sqlSpan->start();
                        $sqlSpan->tag('db.statement', $contents);
                        $sqlSpan->finish();
                    }
                });
            }
        }

        $rootSpan = $tracer->newTrace();
        $rootSpan->setName($request->controller . "::" . $request->action . '(' . $request->uri() . ')');
        $rootSpan->start();
        $request->tracer = $tracer;
        $request->rootSpan = $rootSpan;
        $result = $next($request);

        if (class_exists(\think\facade\Db::class)) {
            $logs = \think\facade\Db::getDbLog(true);
            if (!empty($logs['sql'])) {
                foreach ($logs['sql'] as $sql) {
                    $sqlSpan = $tracer->newChild($rootSpan->getContext());
                    $sqlSpan->setName(SQL_QUERY);
                    $sqlSpan->start();
                    $sqlSpan->tag('db.statement', $sql);
                    $sqlSpan->finish();
                }
            }
        }

        if ($config['enable_request_params']) {
            //记录入参
            $paramsSpan = $tracer->newChild($rootSpan->getContext());
            $paramsSpan->setName("Request:Params");
            $paramsSpan->start();
            $paramsSpan->tag('request.params', json_encode($request->all(), JSON_UNESCAPED_UNICODE));
            $paramsSpan->finish();
        }

        if ($config['enable_response_body']) {
            //记录返回内容
            $responseSpan = $tracer->newChild($rootSpan->getContext());
            $responseSpan->setName("Response:body");
            $responseSpan->start();
            $responseSpan->tag('response.body', $result->rawBody());
            $responseSpan->finish();
        }


        $rootSpan->finish();

        return $result;
    }
}
