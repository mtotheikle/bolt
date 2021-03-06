<?php

namespace Bolt\Logger\Handler;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use Silex\Application;

/**
 * Monolog Database handler for system logging
 *
 * @author Gawain Lynch <gawain.lynch@gmail.com>
 */
class SystemHandler extends AbstractProcessingHandler
{
    /**
     * @var Application
     */
    private $app;

    /**
     * @var boolean
     */
    private $initialized = false;

    /**
     * @var string
     */
    private $tablename;

    /**
     *
     * @param Application $app
     * @param string      $logger
     * @param integer     $level
     * @param boolean     $bubble
     */
    public function __construct(Application $app, $level = Logger::DEBUG, $bubble = true)
    {
        $this->app = $app;
        parent::__construct($level, $bubble);
    }

    /**
     * Handle
     *
     * @param  array   $record
     * @return boolean
     */
    public function handle(array $record)
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        $record = $this->processRecord($record);
        $record['formatted'] = $this->getFormatter()->format($record);
        $this->write($record);

        return false === $this->bubble;
    }

    protected function write(array $record)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        if (isset($record['context']['event'])
            && $record['context']['event'] === ''
            && isset($record['context']['exception'])
            && $record['context']['exception'] instanceof \Exception) {

                $e = $record['context']['exception'] ;
                $trace = $e->getTrace();
                $source = json_encode(
                    array(
                        'file'     => $e->getFile(),
                        'line'     => $e->getLine(),
                        'class'    => $trace['class'],
                        'function' => $trace['function']
                    )
                );
        } elseif ($this->app['config']->get('general/debug')) {
            $backtrace = debug_backtrace();
            $backtrace = $backtrace[3];

            $source = json_encode(
                array(
                    'file'     => str_replace($this->app['resources']->getPath('root'), "", $backtrace['file']),
                    'line'     => $backtrace['line'],
                    'class'    => isset($backtrace['class']) ? $backtrace['class'] : '',
                    'function' => isset($backtrace['function']) ? $backtrace['function'] : ''
                )
            );
        } else {
            $source = '';
        }

        $user = $this->app['session']->get('user');

        try {
            $this->app['db']->insert(
                $this->tablename,
                array(
                    'level'      => $record['level'],
                    'date'       => $record['datetime']->format('Y-m-d H:i:s'),
                    'message'    => $record['message'],
                    'ownerid'    => isset($user['id']) ? $user['id'] : '',
                    'requesturi' => $this->app['request']->getRequestUri(),
                    'route'      => $this->app['request']->get('_route'),
                    'ip'         => $this->app['request']->getClientIp(),
                    'context'    => isset($record['context']['event']) ? $record['context']['event'] : '',
                    'source'     => $source
                )
            );
        } catch (\Exception $e) {
            // Nothing..
        }
    }

    /**
     * Initialize
     */
    private function initialize()
    {
        $this->tablename = sprintf("%s%s", $this->app['config']->get('general/database/prefix', "bolt_"), 'log_system');
        $this->initialized = true;
    }
}
