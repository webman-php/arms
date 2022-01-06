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

class Arms implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        static $tracing = null, $tracer = null;
        if (!$tracing) {
            $endpoint = Endpoint::create(config('plugin.arms.app.app_name'), $request->getRealIp(), null, 2555);
            $logger = new \Monolog\Logger('log');
            $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());
            $reporter = new \Zipkin\Reporters\Http([
                'endpoint_url' => config('plugin.arms.app.endpoint_url')
            ]);
            $sampler = BinarySampler::createAsAlwaysSample();
            $tracing = TracingBuilder::create()
                ->havingLocalEndpoint($endpoint)
                ->havingSampler($sampler)
                ->havingReporter($reporter)
                ->build();
            $tracer = $tracing->getTracer();
            // 30秒上报一次，尽量将上报对业务的影响减少到最低
            Timer::add(30, function () use ($tracer) {
                $tracer->flush();
            });
            register_shutdown_function(function () use ($tracer) {
                $tracer->flush();
            });

            if (class_exists('\Illuminate\Database\Events\QueryExecuted')) {
                Db::listen(function (\Illuminate\Database\Events\QueryExecuted $query) {
                    request()->rootSpan->tag('db.statement', $query->sql . " /*{$query->time}ms*/");
                });
            }
        }

        $rootSpan = $tracer->newTrace();
        $rootSpan->setName($request->controller . "::" . $request->action);
        $rootSpan->start();
        $result = $next($request);

        if (class_exists(\think\facade\Db::class)) {
            $logs = \think\facade\Db::getDbLog(true);
            if (!empty($logs['sql'])) {
                foreach ($logs['sql'] as $sql) {
                    $rootSpan->tag('db.statement', $sql);
                }
            }
        }

        $rootSpan->finish();

        return $result;
    }
}