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
namespace Hyperf\ServerWatcher;

use Hyperf\Contract\ContainerInterface;
use Hyperf\Di\Annotation\AnnotationInterface;
use Hyperf\Di\Annotation\AnnotationReader;
use Hyperf\Di\Annotation\ScanConfig;
use Hyperf\Di\Aop\Ast;
use Hyperf\Di\ClassLoader;
use Hyperf\ServerWatcher\Ast\Metadata;
use Hyperf\ServerWatcher\Ast\RewriteClassNameVisitor;
use Hyperf\ServerWatcher\Driver\DriverInterface;
use Hyperf\ServerWatcher\Driver\FswatchDriver;
use Hyperf\Utils\Codec\Json;
use Hyperf\Utils\Filesystem\Filesystem;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\Adapter;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;
use Swoole\Process;
use Symfony\Component\Console\Output\OutputInterface;

class Watcher
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var Option
     */
    protected $option;

    /**
     * @var DriverInterface
     */
    protected $driver;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var ClassLoader
     */
    protected $loader;

    /**
     * @var array
     */
    protected $autoload;

    /**
     * @var BetterReflection
     */
    protected $reflection;

    /**
     * @var AnnotationReader
     */
    protected $reader;

    /**
     * @var ScanConfig
     */
    protected $config;

    /**
     * @var Ast
     */
    protected $ast;

    /**
     * @var Standard
     */
    protected $printer;

    /**
     * @var Channel
     */
    protected $channel;

    /**
     * @var string
     */
    protected $path = BASE_PATH . '/runtime/container/collectors.cache';

    public function __construct(ContainerInterface $container, Option $option, OutputInterface $output)
    {
        $this->container = $container;
        $this->option = $option;
        $this->driver = $this->getDriver();
        $this->filesystem = new Filesystem();
        $this->output = $output;
        $json = Json::decode($this->filesystem->get(BASE_PATH . '/composer.json'));
        $this->autoload = array_flip($json['autoload']['psr-4'] ?? []);
        $this->reflection = new BetterReflection();
        $this->reader = new AnnotationReader();
        $this->config = ScanConfig::instance('/');
        $this->ast = new Ast();
        $this->printer = new Standard();
        $this->channel = new Channel(1);
        $this->channel->push(true);
    }

    public function run()
    {
        $this->restart(true);

        $channel = new Channel(999);
        go(function () use ($channel) {
            $this->driver->watch($channel);
        });

        $result = [];
        while (true) {
            $file = $channel->pop(0.001);
            if ($file === false) {
                if (count($result) > 0) {
                    $result = [];
                    // 重启服务
                    $this->restart(false);
                }
            } else {
                // 重写缓存
                $meta = $this->getMetadata($file);
                if ($meta) {
                    System::exec('php reload.php ' . $meta->path . ' ' . str_replace('\\', '\\\\', $meta->toClassName()));
                }
                $result[] = $file;
            }
        }
    }

    public function collect($className, ReflectionClass $reflection)
    {
        // Parse class annotations
        $classAnnotations = $this->reader->getClassAnnotations(new Adapter\ReflectionClass($reflection));
        if (! empty($classAnnotations)) {
            foreach ($classAnnotations as $classAnnotation) {
                if ($classAnnotation instanceof AnnotationInterface) {
                    $classAnnotation->collectClass($className);
                }
            }
        }
        // Parse properties annotations
        $properties = $reflection->getImmediateProperties();
        foreach ($properties as $property) {
            $propertyAnnotations = $this->reader->getPropertyAnnotations(new Adapter\ReflectionProperty($property));
            if (! empty($propertyAnnotations)) {
                foreach ($propertyAnnotations as $propertyAnnotation) {
                    if ($propertyAnnotation instanceof AnnotationInterface) {
                        $propertyAnnotation->collectProperty($className, $property->getName());
                    }
                }
            }
        }
        // Parse methods annotations
        $methods = $reflection->getImmediateMethods();
        foreach ($methods as $method) {
            $methodAnnotations = $this->reader->getMethodAnnotations(new Adapter\ReflectionMethod($method));
            if (! empty($methodAnnotations)) {
                foreach ($methodAnnotations as $methodAnnotation) {
                    if ($methodAnnotation instanceof AnnotationInterface) {
                        $methodAnnotation->collectMethod($className, $method->getName());
                    }
                }
            }
        }
    }

    public function restart($isStart = true)
    {
        if (! $isStart) {
            $pid = $this->filesystem->get(BASE_PATH . '/runtime/hyperf.pid');
            try {
                $this->output->writeln('Stop server...');
                Process::kill((int) $pid, SIGTERM);
            } catch (\Throwable $exception) {
                $this->output->writeln('Stop server failed. Please execute `composer dump-autoload -o`');
            }
        }

        go(function () {
            $this->channel->pop();
            $this->output->writeln('Start server ...');
            $ret = System::exec('SCAN_CACHEABLE=(true) && php bin/hyperf.php start');
            $this->output->writeln('Stop server success');
            $this->channel->push($ret);
        });
    }

    protected function putCache($path, $data)
    {
        if (! $this->filesystem->isDirectory($dir = dirname($path))) {
            $this->filesystem->makeDirectory($dir, 0755, true);
        }

        $this->filesystem->put($path, $data);
    }

    protected function getMetadata(string $file): ?Metadata
    {
        $stmts = $this->ast->parse($this->filesystem->get($file));
        $meta = new Metadata();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new RewriteClassNameVisitor($meta));
        $modifiedStmts = $traverser->traverse($stmts);
        if (! $meta->isClass()) {
            return null;
        }
        $code = $this->printer->prettyPrintFile($modifiedStmts);
        $path = BASE_PATH . '/runtime/watcher/';
        if (! is_dir($path)) {
            $this->filesystem->makeDirectory($path, 0777, true);
        }
        $this->filesystem->put($path . $meta->proxyName, $code);
        $meta->path = $path . $meta->proxyName;
        return $meta;
    }

    protected function getDriver()
    {
        $driver = $this->option->getDriver();
        switch (strtolower($driver)) {
            case 'fswatch':
                return new FswatchDriver($this->option);
            default:
                throw new \InvalidArgumentException('Driver not support.');
        }
    }
}
