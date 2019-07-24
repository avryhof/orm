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

        if (isinstance($message, 'string') && $dump !== true) {
            error_log(get_class($this) . ': ' . $message);
        }

        if ($this->debug) {
            if ($pretty) {
                $message = print_r($message, true);
            }

            if (gettype($message) == 'string' && $dump !== true) {
                $log_message = date("Y-m-d H:i:s") . ' - ' . get_class($this) . ': ' . $message;
                echo "<pre>$log_message</pre>\n";
            } else {
                $log_message = date("Y-m-d H:i:s") . ' - ' . get_class($this);
                echo "<pre>$log_message\n";
                var_dump($message);
                echo "</pre>\n";
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