<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Metric\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;
use Hyperf\Metric\Contract\MetricFactoryInterface;
use Hyperf\Metric\Event\MetricFactoryReady;
use Hyperf\Metric\MetricSetter;
use Hyperf\Retry\Retry;
use Hyperf\Utils\Coroutine;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Server;
use Swoole\Timer;
use function gc_status;
use function getrusage;
use function memory_get_peak_usage;
use function memory_get_usage;

/**
 * Collect and handle metrics before worker start.
 */
class OnWorkerStart implements ListenerInterface
{
    use MetricSetter;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var MetricFactoryInterface
     */
    protected $factory;

    /**
     * @var ConfigInterface
     */
    private $config;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->config = $container->get(ConfigInterface::class);
        $this->factory = $container->get(MetricFactoryInterface::class);
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array
    {
        return [
            BeforeWorkerStart::class,
        ];
    }

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process(object $event)
    {
        $workerId = $event->workerId;

        if ($workerId === null) {
            return;
        }

        /*
         * If no standalone process is started, we have to handle metrics on worker.
         */
        if (! $this->config->get('metric.use_standalone_process', true)) {
            $this->spawnHandle();
        }

        /*
         * Allow user to hook up their own metrics logic
         */
        if ($this->shouldFireMetricFactoryReadyEvent($workerId)) {
            $eventDispatcher = $this->container->get(EventDispatcherInterface::class);
            $eventDispatcher->dispatch(new MetricFactoryReady($this->factory));
        }

        if (! $this->config->get('metric.enable_default_metric', false)) {
            return;
        }

        // The following metrics MUST be collected in worker.
        $metrics = $this->factoryMetrics(
            ['worker' => (string) $workerId],
            'worker_request_count',
            'worker_dispatch_count',
            'memory_usage',
            'memory_peak_usage',
            'gc_runs',
            'gc_collected',
            'gc_threshold',
            'gc_roots',
            'ru_oublock',
            'ru_inblock',
            'ru_msgsnd',
            'ru_msgrcv',
            'ru_maxrss',
            'ru_ixrss',
            'ru_idrss',
            'ru_minflt',
            'ru_majflt',
            'ru_nsignals',
            'ru_nvcsw',
            'ru_nivcsw',
            'ru_nswap',
            'ru_utime_tv_usec',
            'ru_utime_tv_sec',
            'ru_stime_tv_usec',
            'ru_stime_tv_sec'
        );

        $server = $this->container->get(Server::class);
        $timerInterval = $this->config->get('metric.default_metric_interval', 5);
        Timer::tick($timerInterval * 1000, function () use ($metrics, $server) {
            $serverStats = $server->stats();
            if (function_exists('gc_status')) {
                $this->trySet('gc_', $metrics, gc_status());
            }
            $this->trySet('', $metrics, getrusage());
            $metrics['worker_request_count']->set($serverStats['worker_request_count']);
            $metrics['worker_dispatch_count']->set($serverStats['worker_dispatch_count']);
            $metrics['memory_usage']->set(memory_get_usage());
            $metrics['memory_peak_usage']->set(memory_get_peak_usage());
        });
    }

    private function shouldFireMetricFactoryReadyEvent(int $workerId): bool
    {
        return (! $this->config->get('metric.use_standalone_process'))
            && $workerId == 0;
    }

    private function spawnHandle()
    {
        Coroutine::create(function () {
            if (class_exists(Retry::class)) {
                Retry::whenThrows()->backoff(100)->call(function () {
                    $this->factory->handle();
                });
            } else {
                retry(PHP_INT_MAX, function () {
                    $this->factory->handle();
                }, 100);
            }
        });
    }
}
