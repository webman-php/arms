<?php

namespace Webman\Arms;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use Zipkin\Propagation\TraceContext;
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
        $b3TraceId = $request->header('X-B3-TraceId', '');
        $b3SpanId = $request->header('X-B3-SpanId', '');
        if (!empty($b3TraceId) && !empty($b3SpanId)) {
            try {
                $b3Sampled = $request->header('X-B3-Sampled', '');
                $sampled = $b3Sampled === '1' ? true : ($b3Sampled === '0' ? false : null);
                $parentContext = TraceContext::create(
                    $b3TraceId,
                    \Zipkin\Propagation\Id\generateNextId(),
                    $b3SpanId,
                    $sampled,
                );
                // newTrace() always generates a fresh trace id; newChild() keeps the
                // upstream trace id and links this span under the caller's span
                $rootSpan = $tracer->newChild($parentContext);
            } catch (\Throwable $e) {
                // Fall back to a new trace on invalid B3 headers, business is unaffected
                $rootSpan = $tracer->newTrace();
            }
        }
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

        if ($config['enable_request_params'] ?? false) {
            //记录入参
            $paramsSpan = $tracer->newChild($rootSpan->getContext());
            $paramsSpan->setName("Request:Params");
            $paramsSpan->start();
            $paramsSpan->tag('request.params', json_encode($request->all(), JSON_UNESCAPED_UNICODE));
            $paramsSpan->finish();
        }

        if ($config['enable_request_headers'] ?? false) {
            // Record request headers; mask configured sensitive values before recording
            $headers = $request->header();
            $maskHeaders = array_map('strtolower', $config['mask_headers'] ?? []);
            foreach ($headers as $headerName => $headerValue) {
                if (in_array(strtolower($headerName), $maskHeaders, true)) {
                    $headers[$headerName] = '***';
                }
            }
            $headersSpan = $tracer->newChild($rootSpan->getContext());
            $headersSpan->setName("Request:Headers");
            $headersSpan->start();
            $headersSpan->tag('request.headers', json_encode($headers, JSON_UNESCAPED_UNICODE));
            $headersSpan->finish();
        }

        if ($config['enable_response_body'] ?? false) {
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
