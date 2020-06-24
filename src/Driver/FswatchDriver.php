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
namespace Hyperf\ServerWatcher\Driver;

use Hyperf\ServerWatcher\Option;
use Hyperf\Utils\Str;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;

class FswatchDriver implements DriverInterface
{
    /**
     * @var Option
     */
    protected $option;

    public function __construct(Option $option)
    {
        $this->option = $option;
        $ret = System::exec('which fswatch');
        if (empty($ret['output'])) {
            throw new \InvalidArgumentException('fswatch not exists. You can `brew install fswatch` to install it.');
        }
    }

    public function watch(Channel $channel): void
    {
        $cmd = $this->getCmd();
        while (true) {
            $ret = System::exec($cmd);
            go(function () use ($ret, $channel) {
                $files = array_filter(explode("\n", $ret['output']));
                foreach ($files as $file) {
                    if (Str::endsWith($file, $this->option->getExt())) {
                        $channel->push($file);
                    }
                }
            });
        }
    }

    protected function getCmd(): string
    {
        $dir = $this->option->getWatchDir();
        $file = $this->option->getWatchFile();

        return 'fswatch -1 ' . implode(' ', $dir) . ' ' . implode(' ', $file);
    }
}
