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
        if (!$tracing) {
            $endpoint = Endpoint::create(config('plugin.webman.arms.app.app_name'), $request->getRealIp(), null, 2555);
            $logger = new \Monolog\Logger('log');
            $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());
            $reporter = new \Zipkin\Reporters\Http([
                'endpoint_url' => config('plugin.webman.arms.app.endpoint_url')
            ]);
            $sampler = BinarySampler::createAsAlwaysSample();
            $tracing = TracingBuilder::create()
                ->havingLocalEndpoint($endpoint)
                ->havingSampler($sampler)
                ->havingReporter($reporter)
                ->build();
            $tracer = $tracing->getTracer();
            // 30秒上报一次，尽量将上报对业务的影响减少到最低
            $time_interval = config('plugin.webman.arms.app.time_interval', 30);
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
                        $sqlSpan = $tracer->newChild($rootSpan->getContext());
                        $sqlSpan->setName(SQL_QUERY . ':' . $query->connectionName);
                        $sqlSpan->start();
                        $sqlSpan->tag('db.statement', $query->sql . " /*{$query->time}ms*/");
                        $sqlSpan->finish();
                    }
                });
            }
        }

        $rootSpan = $tracer->newTrace();
        $rootSpan->setName($request->controller . "::" . $request->action);
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

        $rootSpan->finish();

        return $result;
    }
}
