<?php

namespace Lxj\Laravel\Zipkin\Commands;

use Elasticsearch;
use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Console\Input\InputOption;
use Zipkin\Reporters\Http\CurlFactory;

class ZipkinReporter extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zipkin:report';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Zipkin Reporter';

    private $redisOptions = [
        'queue_name' => 'queue:zipkin:span',
        'connection' => 'zipkin',
    ];

    private $esOptions = [
        'connection' => 'zipkin',
    ];

    private $endpointUrl = 'http://localhost:9411/api/v2/spans';

    private $curlTimeout = 1;

    /** @var Connection */
    private $redis;

    private $curlClient;

    /** @var Elasticsearch\Client */
    private $esClient;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->redisOptions = array_merge($this->redisOptions, config('zipkin.redis_options', []));
        $this->esOptions = array_merge($this->esOptions, config('zipkin.es_options', []));
        $this->endpointUrl = config('zipkin.endpoint_url', 'http://localhost:9411/api/v2/spans');
        $this->curlTimeout = config('zipkin.curl_timeout', 1);
    }

    protected function configure()
    {
        $this->addOption(
            'interval',
            null,
            InputOption::VALUE_OPTIONAL,
            'Consumption interval(ms)',
            5
        )->addOption(
            'freq',
            null,
            InputOption::VALUE_OPTIONAL,
            'Report frequency(int)',
            100 //Local test: 5000 ok，if curl timeout，increase curl timeout config or number of consumer
        );
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $redisClient = $this->getRedisClient();
        if (is_null($redisClient)) {
            $this->output->error('Redis client is null');
            return;
        }

        if (empty($this->redisOptions['queue_name'])) {
            $this->output->error('Redis queue name is empty');
            return;
        }

        $counter = 0;
        $aggData = [];
        while (true) {
            $spanArr = json_decode($redisClient->rpop($this->redisOptions['queue_name']), true);
            if ((!json_last_error()) && count($spanArr) > 0) {
                $aggData = array_merge($aggData, $spanArr);
            }

            ++$counter;

            //每消费100次上报一次zipkin
            if ($counter == intval($this->option('freq'))) {
                if (count($aggData) > 0) {
                    $this->report(json_encode($aggData));

                    $this->saveToEs($aggData);

                    $aggData = [];
                }

                $counter = 0;
            }

            usleep(intval(doubleval($this->option('interval')) * 1000));
        }

        return;
    }

    private function report($payload)
    {
        $client = $this->getCurlClient();
        $client($payload);
    }

    private function saveToEs($spanArr)
    {
        $esClient = $this->getEsClient();
        if (is_null($esClient)) {
            return;
        }
        try {
            $params = [];
            foreach ($spanArr as $span) {
                $createdTime = intval($span['timestamp'] / 1000000);
                $createdDate = date('Y-m-d', $createdTime);

                $index = 'zipkin:span:processed-' . $createdDate;

                $params['body'][] = [
                    'index' => [
                        '_index' => $index,
                        '_type' => $index,
                    ]
                ];

                $params['body'][] = $this->formatSpan($span);
            }

            $esClient->bulk($params);
        } catch (\Exception $e) {
            //
        }
    }

    private function formatSpan($span)
    {
        $createdTime = intval($span['timestamp'] / 1000000);
        $createdAt = date('c', $createdTime);
        $span['created_at'] = $createdAt;

        $span['is_success'] = !isset($span['tags']['error']);

        if (isset($span['tags'])) {
            $formattedTags = [];
            $tags = $span['tags'];
            unset($span['tags']);
            $dbQueryTimes = 0;
            $dbQueryDuration = 0;
            foreach ($tags as $key => $value) {
                $formattedKey = 'tag_' . str_replace('.', '_', $key);
                $formattedTags[$formattedKey] = $value;

                if (strpos($formattedKey, 'tag_db_query_times_') === 0) {
                    $dbQueryTimes += intval($value);
                }
                if (strpos($formattedKey, 'tag_db_query_total_duration_') === 0) {
                    $dbQueryDuration += doubleval(substr($value, 0, -2));
                }
            }
            $span = array_merge($span, $formattedTags);
            $span['tag_db_query_times'] = $dbQueryTimes;
            $span['tag_db_query_total_duration'] = $dbQueryDuration;
        }

        if (isset($span['tag_http_status_code'])) {
            $span['tag_http_status_code'] = intval($span['tag_http_status_code']);
        }
        if (isset($span['tag_http_request_body_size'])) {
            $span['tag_http_request_body_size'] = intval($span['tag_http_request_body_size']);
        }
        if (isset($span['tag_http_response_body_size'])) {
            $span['tag_http_response_body_size'] = intval($span['tag_http_response_body_size']);
        }
        if (isset($span['tag_runtime_memory'])) {
            $runtimeMemory = substr($span['tag_runtime_memory'], 0, -2);
            $span['tag_runtime_memory_float'] = doubleval($runtimeMemory);
        }
        if (isset($span['tag_http_request_headers'])) {
            $requestHeaders = json_decode($span['tag_http_request_headers'], true);
            if (!json_last_error()) {
                foreach ($requestHeaders as $headerName => $headerValues) {
                    if (strtolower($headerName) === 'content-type') {
                        $span['tag_http_request_content_type'] = implode(',', $headerValues);
                        break;
                    }
                }
            }
        }
        if (isset($span['tag_http_response_headers'])) {
            $responseHeaders = json_decode($span['tag_http_response_headers'], true);
            if (!json_last_error()) {
                foreach ($responseHeaders as $headerName => $headerValues) {
                    if (strtolower($headerName) === 'content-type') {
                        $span['tag_http_response_content_type'] = implode(',', $headerValues);
                        break;
                    }
                }
            }
        }

        return $span;
    }

    private function getRedisClient()
    {
        if (is_null($this->redis)) {
            if (!empty($this->redisOptions['connection'])) {
                $this->redis = Redis::connection($this->redisOptions['connection']);
            }
        }

        return $this->redis;
    }

    private function getCurlClient()
    {
        if (is_null($this->curlClient)) {
            $this->curlClient = CurlFactory::create()->build([
                'endpoint_url' => $this->endpointUrl,
                'timeout' => $this->curlTimeout,
            ]);
        }

        return $this->curlClient;
    }

    private function getEsClient()
    {
        if (is_null($this->esClient)) {
            if (!empty($this->esOptions['connection'])) {
                $this->esClient = Elasticsearch::connection($this->esOptions['connection']);
            }
        }

        return $this->esClient;
    }
}
