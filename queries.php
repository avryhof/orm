<?php
require_once(__DIR__ . DIRECTORY_SEPARATOR . "exceptions.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "base_class.php");

class QueryObject implements ArrayAccess {
    var $container = array();
    var $objects_instance = null;
    var $pk = null;

    function __construct($items = array(), $objects_instance)
    {

        $this->objects_instance = $objects_instance;
        $this->pk = $this->objects_instance->model_instance->pk;

        if (!$items) {
            $this->container = [];
        } else {
            $this->container = $items;
        }
    }

    function __toString()
    {
        return json_encode($this->container);
    }

    function __get($name)
    {
        return $this->container[$name];
    }

    public function offsetSet($offset, $value) {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    public function offsetExists($offset) {
        return isset($this->container[$offset]);
    }

    public function offsetUnset($offset) {
        unset($this->container[$offset]);
    }

    public function offsetGet($offset) {
        return isset($this->container[$offset]) ? $this->container[$offset] : null;
    }

    function update($kwargs = []) {
        foreach($kwargs as $field => $value) {
            if (array_key_exists($field, $this->container)) {
                $this->container[$field] = $value;
            }
        }
        // So we can do a one-liner $object->update([...])->save();
        return $this;
    }

    function delete() {
        $this->objects_instance->delete($this->container);
    }

    function save() {
        return $this->objects_instance->update($this->container);
    }
}

class Objects extends BaseDBClass
{
    /*
    This is kind of an ugly hacked out mess...but it works how it is supposed to.

    It only implements filter, and get so far, since that's all I have needed to use up to this point.

    --------------------------------------------------------------------------------------------------------------------
    list_of_objects = Model().objects.filter(**filter_args)

    filter_args is just kwargs, and implements several of the filter suffixes used by Django.
        See _process_filters() for more details. (including the SQL generated)

    There are a few keyword arguments that can be passed in with your filter_args, that are removed and processed before
    the query is generated.

        return_dicts   - (Boolean) Rather than returning your query results as a list of Objects, they will be
                         returned as a list of dicts.
        return_set     - (Boolean) This will cause your database resulds to be returned as a list of values
                         from a single column. This is poorly named, and should really be values_list, since it
                         actually returns a list rather than a set.
        return_set_key - (String) This specified the column name for the results in return_set.

    --------------------------------------------------------------------------------------------------------------------
    object = Model().objects.get(**filter_args)

    Works exactly like filter, but returns a single object rather than a list of objects.
    If more than one Object is found, a MultipleObjectsReturned exception is raised.

    */

    var $table = null;
    var $model_instance = null;

    var $joined = false;
    var $joined_on = '';
    var $join_where = null;

    var $tables = [];
    var $table_namespaces = [];
    var $table_namespaces_lookup = [];
    var $columns = ['*'];
    var $column_lookup = [];
    var $column_lookup_reverse = [];

    var $table_definition = [];
    var $db_values;

    function __construct($kwargs = [])
    {
        parent::__construct($kwargs);

        $this->table = $this->get_arg($kwargs, 'table', false);
        $this->model_instance = $this->get_arg($kwargs, 'model_instance', false);

        $this->joined = $this->get_arg($kwargs, 'joined', false);
        $this->joined_on = $this->get_arg($kwargs, 'joined_on', false);

        $defined_fields = [];
        $classdir = get_class_vars(get_class($this->model_instance));
        foreach ($classdir as $field_name => $field_attr) {
            if (!array_search(trim($field_name), ['classinstance', 'meta', 'objects', 'class_slug', 'class_name', 'db_table', 'joined', 'joined_on', 'pk', 'pkID', 'debug', 'debug_stdout', 'init_time'])) {
                $defined_fields[] = $field_name;
            }
        }

        if ($this->joined) {
            $this->table = $this->tables;
            $this->_init_join();
        }

        if (count($defined_fields) > 0) {
            $this->columns = [];
            $has_pk = false;
            $pk_name = $this->model_instance->pk;
            foreach ($defined_fields as $attr_name) {
                $attr = $this->model_instance->{$attr_name};

                $attr_db_table = false;
                $attr_db_field = $attr_name;

                if (gettype($attr) == 'array') {
                    $attr_db_table = $this->get_arg($attr, 'db_table', false);
                    $attr_real_field = $this->get_arg($attr, 'db_field', $attr_name);

                    $field_definition = $this->get_arg($attr, 'field_type', 'TEXT');
                    $field_length = $this->get_arg($attr, 'max_length', null);
                    $field_allow_null = $this->get_arg($attr, 'null', false);
                    $field_auto_increment = $this->get_arg($attr, 'auto_increment', false);
                    $field_default_value = $this->get_arg($attr, 'default', false);

                } elseif (gettype($attr) == 'object') {
                    $attr_db_table = $attr->db_table;
                    $attr_real_field = $attr->real_field;

                    $field_definition = $attr->field_type;
                    $field_length = $attr->max_length;
                    $field_allow_null = $attr->null_field;
                    $field_auto_increment = $attr->auto_increment;
                    $field_default_value = $attr->default_value;
                }

                if ($attr_db_table && count($this->tables) > 0) {
                    $real_column = $this->table_namespaces_lookup[$attr_db_table] . '.' . $this->encap_string($attr_real_field);
                } else {
                    $real_column = $this->encap_string($attr_real_field);
                }

                if ($attr_real_field == $pk_name) {
                    $has_pk = true;
                }

                $tabledef = "$real_column $field_definition";

                if ($field_length !== null) {
                    $tabledef = "$tabledef($field_length)";
                }

                if (!$field_allow_null) {
                    $tabledef = "$tabledef NOT NULL";
                }

                if ($field_auto_increment) {
                    $tabledef = "$tabledef AUTO_INCREMENT";
                }

                if ($field_default_value != false) {
                    $tabledef = "$tabledef DEFAULT '$field_default_value'";
                }

                $this->table_definition[] = $tabledef;

                $column_name = "$real_column AS $attr_name";

                $this->column_lookup[$attr_name] = $real_column;
                $this->column_lookup_reverse[$real_column] = $attr_name;
                $this->columns[] = $column_name;
            }

            if (!$has_pk) {
                $this->table_definition[] = $this->encap_string($pk_name) . " BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT";
            }
            $this->table_definition[] = "KEY(" . $this->encap_string($pk_name) . ")";
        }

        if (!$this->table || !$this->model_instance) {
            throw new FailedToBind('You must pass in a table and the model instance.');
        }
    }

    function _init_join()
    {
        $join_strings = [];
        $join_on = $this->joined_on;

        foreach ($this->tables as $table_name) {
            $namespace_key = preg_replace('/[^a-z]/', '', strtolower($table_name));
            $this->table_namespaces[$namespace_key] = $table_name;
            $this->table_namespaces_lookup[$table_name] = $namespace_key;
            $join_strings[] = $this->database . "." . $table_name . " " . $namespace_key;
            $join_on = str_replace(',', ' AND ', str_replace($table_name, $namespace_key, $join_on));
        }

        $join_string = implode(', ', $join_strings);

        $this->join_where = $join_on;
        $this->tble = $join_string;
    }

    function _set_values($kwargs=[]) {
        $real_insert_values = [];
        foreach ($kwargs as $field => $value) {
            $value_name = ":$field";
            $real_insert_values[$value_name] = $value;
        }
        $this->db_values = $real_insert_values;

        return $real_insert_values;
    }

    function _build_query($kwargs = ['columns' => false, 'where' => false, 'order_by' => false, 'limit' => false])
    {
        $columns = $this->get_arg($kwargs, 'columns', $this->columns);
        $where = $this->get_arg($kwargs, 'where', false);
        $order_by = $this->get_arg($kwargs, 'order_by', false);
        $limit = $this->get_arg($kwargs, 'limit', false);

        if ($this->debug_queries) {
            parent::_debug_handler('SELECT: ' . implode(",", $columns));
            parent::_debug_handler('FROM: ' . $this->table);
            if ($where) {
                parent::_debug_handler("WHERE: $where");
            }
            if ($order_by) {
                parent::_debug_handler("ORDER BY: $order_by");
            }
            if ($limit) {
                parent::_debug_handler('LIMIT: ' . strval($limit));
            }
            parent::_debug_handler(str_repeat('-', 80));
        }

        if (gettype($columns) == 'array') {
            $query = 'SELECT ' . implode(',', $columns) . ' FROM ' . $this->table;
        } else {
            $query = 'SELECT * FROM ' . $this->table;
        }

        if ($where) {
            $query = $query . " WHERE " . $where;
        }

        if ($this->join_where) {
            if ($where) {
                $query = $query . " AND " . $this->join_where;

            } else {
                $query = $query . " WHERE " . $this->join_where;
            }
        }

        if ($order_by) {
            $query = $query . " ORDER BY " . $order_by;
        }

        if ($limit) {
            $query = "$query LIMIT $limit";
        }

        return $query;
    }

    function _process_filters($kwargs = [])
    {
        $wheres = [];

        foreach ($kwargs as $k => $v) {
            $key_parts = explode("__", $k);
            $key = $key_parts[0];
            $key_function = count($key_parts) > 1 ? $key_parts[1] : null;
            $key_operator = count($key_parts) > 2 ? $key_parts[2] : 'and';

            if (array_key_exists($key, $this->column_lookup)) {
                $key = $this->column_lookup[$key];
            }

            switch ($key_function) {
                case "iexact":
                    $where_append = "UPPER(" . strval($key) . ") = '" . strtoupper(strval($v)) . "'";
                    break;
                case 'icontains':
                    $where_append = "UPPER(" . strval($key) . ") LIKE '%" . strtoupper(strval($v)) . "%'";
                    break;
                case 'contains':
                    $where_append = strval($key) . " LIKE '%" . strval($v) . "%'";
                    break;
                case "startswith":  # Seems *slightly* faster than LIKE '...%'
                    $where_append = "LEFT(" . strval($key) . ", " . strlen(strval($v)) . ") = '" . strval($v) . "'";
                    break;
                case "endswith":
                    $where_append = "RIGHT(" . strval($key) . ", " . strlen(strval($v)) . ") = '" . strval($v) . "'";
                    break;
                case "istartswith":
                    $where_append = "UPPER(LEFT(" . strval($key) . ", " . strlen(strval($v)) . ")) = '" . strtoupper(strval($v)) . "'";
                    break;
                case "iendswith":
                    $where_append = "UPPER(RIGHT(" . strval($key) . ", " . strlen(strval($v)) . "i)) = '" . strtoupper(strval($v)) . "'";
                    break;
                case 'not_like':
                    $where_append = strval($key) . " NOT LIKE '" . strval($v) . "'";
                    break;
                case 'isnull':
                    $comparison = !$v ? 'IS NOT' : 'IS';
                    $where_append = strval($key) . " " . $comparison . " NULL";
                    break;
                case 'lt':
                    $where_append = strval($key) . " < " . strval($v);
                    break;
                case 'lte':
                    $where_append = strval($key) . " <= " . strval($v);
                    break;
                case 'gt':
                    $where_append = strval($key) . " > " . strval($v);
                    break;
                case 'gte':
                    $where_append = strval($key) . " >= " . strval($v);
                    break;
                case 'in':
                    $where_append = strval($key) . " IN (" . implode(",", $v) . ")";
                    break;
                default:
                    $where_append = strval($key) . " = '" . strval($v) . "'";
                    break;
            }

            $where_string = '';

            if ($key_operator) {
                $key_operator_parts = explode("_", $key_operator);
                $operator_length = count($key_operator_parts);

                if ($operator_length > 0) {
                    $key_operator = strtoupper($key_operator_parts[0]);
                }

                $second_key_operator = null;
                if ($operator_length > 1) {
                    $second_key_operator = strtoupper($key_operator_parts[1]);
                }

                $key_operator_action = 'START';
                if ($operator_length > 2) {
                    $key_operator_action = strtoupper($key_operator_parts[2]);
                }
            }

            if (count($wheres) > 0) {
                if ($operator_length == 1) {
                    $where_string = "$key_operator $where_append";
                } elseif ($operator_length == 2) {
                    $where_string = "$key_operator ($where_append";
                } elseif ($operator_length == 3 && $key_operator_action == 'END') {
                    $where_string = "$second_key_operator $where_append)";
                }

                $where_append = "";
            }

            $where_string = trim("$where_string $where_append");
            $wheres[] = $where_string;
        }

        $where_return = trim(str_replace('  ', ' ', implode(' ', $wheres)));
        return $where_return;
    }

    function create_table() {
        $query = "CREATE TABLE IF NOT EXISTS " . $this->encap_string($this->table) . " (\n";
        $query .= implode(",\n", $this->table_definition);
        $query .= "\n) ENGINE=MyISAM DEFAULT CHARSET=utf8;\n";

        $this->_db_query($query);
    }

    function create($kwargs=[]) {
        $query_parts = [
            'INSERT INTO',
            $this->encap_string($this->table)
        ];

        $real_insert_values = $this->_set_values($kwargs);
        $insert_values = array_keys($real_insert_values);

        $insert_fields = [];
        foreach ($kwargs as $field => $value) {
            $real_column = $this->get_arg($this->column_lookup, $field, $field);
            $insert_fields[] = $this->encap_string($real_column);
        }

        $query_parts[] = "(" . implode(",", $insert_fields) . ")";
        $query_parts[] = "VALUES";
        $query_parts[] = "(" . implode(",", $insert_values) . ")";

        $query = implode(" ", $query_parts) . ";";

        $this->_db_query($query, $real_insert_values);

        return $this->get($kwargs);
    }

    function update($fields) {
        $query_parts = [
            'UPDATE',
            $this->encap_string($this->table),
            "SET"
        ];
        $real_insert_values = $this->_set_values($fields);

        $update_values = [];
        foreach($fields as $field => $value) {
            $real_column = $this->get_arg($this->column_lookup, $field, $field);
            $update_values[] = $this->encap_string($real_column) . "=:$field";
        }

        $query_parts[] = implode(",", $update_values);
        $query_parts[] = "WHERE";
        $query_parts[] = $this->encap_string($this->model_instance->pk) . " = :" . $this->model_instance->pk;

        $query = implode(" ", $query_parts) . ";";

//        $this->_debug_handler($query);
        $this->_db_query($query, $real_insert_values);

        return $this->get([$this->model_instance->pk => $fields[$this->model_instance->pk]]);
    }

    function delete($fields=[]) {
        $query_parts = [
            'DELETE FROM',
            $this->encap_string($this->table),
        ];
        $query_parts[] = "WHERE";
        $query_parts[] = $this->encap_string($this->model_instance->pk) . " = :" . $this->model_instance->pk;

        $query = implode(" ", $query_parts) . ";";

        $real_insert_values = $this->_set_values([$this->model_instance->pk => $fields[$this->model_instance->pk]]);

//        $this->_debug_handler($query);
        $this->_db_query($query, $real_insert_values);
    }

    function filter($kwargs = ['return_field'=>false, 'result_limit'=>false, 'order_by'=>false,'select_all'=>false]) {
        $return_field = $this->get_arg($kwargs, 'return_field', false, true);
        $result_limit = $this->get_arg($kwargs, 'result_limit', false, true);
        $order_by = $this->get_arg($kwargs, 'order_by', false, true);
        $select_all = $this->get_arg($kwargs, 'select_all', false, true);

        if (!$select_all) {
            $where = $this->_process_filters($kwargs);
            $query = $this->_build_query([
                'where'=>$where,
                'limit'=>$result_limit,
                'order_by'=>$order_by
            ]);
        } else {
            $query = $this->_build_query();
        }

//        parent::_debug_handler($query);

        $this->_db_query($query);

        $query_results = $this->_fetch_all();

        if ($return_field) {
            $field_results = [];
            foreach ($query_results as $query_result) {
                $field_results = $query_result[$return_field];
            }
            $query_results = $field_results;
        } else {
            $object_results = [];
            foreach($query_results as $query_result) {
                $object_results[] = new QueryObject($query_result, $this);
            }
            $query_results = $object_results;
        }

        return $query_results;
    }

    function get($kwargs=[]) {
        $query_results = $this->filter($kwargs);

        if (count($query_results) == 0) {
            throw new ObjectDoesNotExist("No objects matching query.");
        } elseif (count($query_results) > 1) {
            throw new MultipleObjectsReturned("Multiple objects matching query.");
        } else {
            return $query_results[0];
        }
    }

    function all($kwargs=[]) {
        $kwargs['select_all'] = true;
        $query_results = $this->filter($kwargs);

        return $query_results;
    }
}