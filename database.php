<?php
require_once(__DIR__ . "/exceptions.php");
require_once(__DIR__ . "/base_class.php");


class BaseDBClass extends BaseClass
{
    /* *** *** ***
     *    This is just the utility class to handle database connection and queries.
     *    The Objects class wraps around this to map database queries and results to Model objects.
     *    If you adapt this class, (specify it as a kwarg) and the SQL in Objects.filter, it can work with just about any DBMS.
    */
    var $debug_queries = false;
    var $dsn = '';
    var $database = null;
    var $conn = null;

    var $count = null;

    var $statement;
    var $result;

    var $encap = "`";

    function __construct($kwargs = ['type' => 'mysql', 'server' => false, 'user' => false, 'password' => false, 'name' => false, 'debug_queries' => false])
    {
        parent::__construct($kwargs);

        $type = $this->get_arg($kwargs, 'type', $this->get_arg($_ENV, 'DB_TYPE'));

        $server = $this->get_arg($kwargs, 'server', $this->get_arg($_ENV, 'DB_HOST'));
        $user = $this->get_arg($kwargs, 'user', $this->get_arg($_ENV, 'DB_USER'));
        $password = $this->get_arg($kwargs, 'password',$this->get_arg($_ENV, 'DB_PASSWORD'));
        $db_name = $this->get_arg($kwargs, 'db_name', $this->get_arg($_ENV, 'DB_NAME'));

        $this->database = $db_name;
        $this->dsn = "$type:host=$server;dbname=$db_name";

        try {
            $this->conn = new PDO($this->dsn, $user, $password);
        } catch (PDOException $e) {
            parent::_debug_handler($e->getMessage());
        }

        $this->debug_queries = $kwargs['debug_queries'] ? $kwargs['debug_queries'] : false;
    }

    function __destruct()
    {
        $this->db_client = null;
        parent::__destruct();
    }

    function encap_string($value) {
        if (strpos($value, $this->encap) === false) {
            $value = $this->encap . $value . $this->encap;
        }

        return $value;
    }

    function cursor($values=false)
    {
        if (gettype($values) == 'array') {
            $this->count = $this->statement->execute($values);
        } else {
            $this->count = $this->statement->execute();
        }

        if (!$this->count) {
            throw new OperationalError("Datbase Operation failed.");
        }
    }

    function _fetch_one()
    {
        $this->result = $this->statement->fetch(PDO::FETCH_ASSOC);
        return $this->result;
    }

    function _fetch_all()
    {
        $this->result = $this->statement->fetchAll(PDO::FETCH_ASSOC);
        return $this->result;
    }

    function _db_query($query, $values=false)
    {
        if ($this->debug_queries) {
            parent::_debug_handler($query);
        }

        $this->statement = $this->conn->prepare($query);

        try {
            $this->cursor($values);
        } catch (OperationalError $e) {
            parent::_debug_handler($e->getMessage());
        }
    }

    function _db_name()
    {
        return $this->database;
    }
}
