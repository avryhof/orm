<?php

class BaseClass
{
    var $debug = false;
    var $debug_stdout = false;

    var $init_time = null;

    function _debug_handler($message, $kwargs = [])
    {
        $pretty = $this->get_arg($kwargs, 'pretty', false);
        $dump = $this->get_arg($kwargs, 'dump', false);

        if ($pretty) {
            $message = print_r($message, true);
        }

        $log_message = date("Y-m-d H:i:s") . ' - ' . get_class($this) . ': ' . $message;

        if ($this->debug) {
            error_log($log_message);
        }

        if ($this->debug_stdout) {
            echo "$log_message\n";
            if ($dump) {
                var_dump($message);
            }
        }

    }

    function get_arg($kwargs=[], $arg_name, $default=null, $delete=false) {
        if ($kwargs !== null && array_key_exists($arg_name, $kwargs)) {
            $value = $kwargs[$arg_name];
            if ($delete) {
                unset($kwargs[$arg_name]);
            }
            return $value;
        } else {
            return $default;
        }
    }

    function _timer()
    {
        if (!$this->init_time) {
            $this->init_time = time();
            $this->_debug_handler('Class ' . get_class($this) . ' initiated.');
        } else {
            $this->_debug_handler('Class ' . get_class($this) . ' completed.');

            $complete_time = time();
            $command_total_seconds = ($complete_time - $this->init_time);
            $command_minutes = floor($command_total_seconds / 60);
            $command_seconds = $command_total_seconds - ($command_minutes * 60);

            $this->_debug_handler('Class ' . get_class($this) . ' was active for ' . $command_minutes . ' minutes and ' . $command_seconds . ' seconds to run.');
        }
    }

    function __construct($kwargs = [])
    {
        $this->debug = $this->get_arg($kwargs, 'debug', false);
        $this->debug_stdout = $this->get_arg($kwargs, 'debug_stdout', false);

        if ($this->debug) {
            $this->_timer();
            $this->_debug_handler('Debugging enabled.');
        }
    }

    function __destruct()
    {
        $this->_timer();
    }
}