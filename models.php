<?php
require_once(__DIR__ . DIRECTORY_SEPARATOR . "exceptions.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "base_class.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . "queries.php");


class Field extends BaseClass
{
    /* *** *** ***
     *    This class exists to bind a field within your database to a variable with a different name in your model.
     *
     *    $member_id = new Field(['db_field' => 'MemberID'])
     *
     *    This is also the best way to map values from multiple joined database tables back to a single model variable
     *
     *    $member_id = new Field(['db_field' => 'MemberID', 'db_table' => 'Members']);
     *    $city = new Field(['db_field' => 'City', 'db_table' => 'Addresses']);
     *
    */
    var $value = null;
    var $db_table = null;
    var $real_field = null;
    var $field_type = '';
    var $max_length = null;
    var $null_field = false;
    var $field_auto_increment = false;
    var $field_default_value = false;

    function __construct($kwargs = [])
    {
        parent::__construct($kwargs);

        $this->real_field = $this->get_arg($kwargs, 'db_field', false, true);
        $this->db_table = $this->get_arg($kwargs, 'db_table', false, true);

        $this->field_type = $this->get_arg($kwargs, 'field_type', null);
        $this->max_length = $this->get_arg($kwargs, 'max_length', null);
        $this->null_field = $this->get_arg($kwargs, 'null', false);
        $this->field_auto_increment = $this->get_arg($kwargs, 'auto_increment', false);
        $this->field_default_value = $this->get_arg($kwargs, 'default', false);
    }

    function __toString()
    {
        $retn = $this->db_table . '.' . $this->real_field;

        if ($this->value) {
            $retn = $this->value;
        }

        return $retn;
    }
}

class Model extends BaseClass
{
    /* *** *** ***
     * This helps to define a model, and bind it to one or more database tables.

        The easiest type of model is just bound to a single database table.

        If the table is already named 'mymodel' in the database (postgres, for example) the db_table in Meta isn't needed.

        class MyModel extends Model {
            var $field_one = null;
            var $field_two = null;

            function __construct() {
                $this->>field_two = new Field('db_field' => 'MyDatabaseField']);

                $this->>meta = ['db_table'=>'MyTable'];

                parent::construct();
            }
        }

        More complex is a Model defined with one or more joined tables database tables.

        class MyModel(Model) {
            var $field_one = null;
            var $field_two = null;

            function __construct() {
                $this->>field_one = new Field(['db_field' => 'Field']);
                $this->>field_two = new Field(['db_field' => 'MyDatabaseField', 'db_table' => 'AnotherTable']);

                $this->>meta = [
                    'joined' => true,
                    'db_table' => ['MyTable', 'AnotherTable', 'YetAnotherTable', ...],
                    'joined_on' => 'MyTable.field = AnotherTable.field, MyTable.another_field = YetAnotherTable.field'
                ];

                parent::construct();
            }
        }

        Notice that in the joined_on and db_table variables, I don't specify any AS something... that is handled
        by the Objects class automatically, and will simply convert the table name to a SQL compatible slug for the
        namespace.

        If you haven't defined fields, your result will include the database namespace in it.

        anothertable.Field or yetanothertable.field

        The first table in the db_table list is special, and if a db_table is not specified for a Field, the Object
        mapper will try to map the field to that database table.
    */
    var $meta = null;

    var $objects = null;
    var $class_slug = null;
    var $class_name = null;
    var $db_table = null;

    var $joined = false;
    var $joined_on = '';

    var $pk = 'ID';

    function __construct($kwargs = [])
    {
        parent::__construct($kwargs);

        $this->class_name = get_class($this);
        $this->class_slug = $this->_db_slug($this->class_name);
        $this->db_table = $this->class_slug;

        if ($this->meta != null) {
            $this->db_table = $this->get_arg($this->meta, 'db_table', $this->db_table);
            $this->pk = $this->get_arg($this->meta, 'pk', $this->pk);

            $this->joined = $this->get_arg($this->meta, 'joined', $this->joined);
            $this->joined_on = $this->get_arg($this->meta, 'joined_on', $this->joined_on);
        }

        $kwargs = array_merge($kwargs, [
            'table' => $this->db_table,
            'model_instance' => &$this,
            'joined'=>$this->joined,
            'joined_on'=>$this->joined_on]);

        $this->objects = new Objects($kwargs);
    }

    function _db_slug($value)
    {
        if (!$value) {
            $value = $this->class_name;
        }

        if (gettype($value) != 'string') {
            $value = strval($value);
        }

        $cleaned_value = strtolower(str_replace(' ', '_', $value));
        $new_value = preg_replace('/[^a-z0-9-_ ]/', '', $cleaned_value);

        return $new_value;
    }

    function __toString()
    {
        $retn = $this->class_name;

        if ($this->pk) {
            $retn = $this->class_name . ' ' . strval($this->pk);
        }

        return $retn;
    }
}