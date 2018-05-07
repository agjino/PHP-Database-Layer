<?php

/**
 * Exception helper for the Database class
 */
class DatabaseException extends Exception
{
  // Default Exception class handles everything
}
/**
 * A basic database interface using MySQLi
 */
class Database
{
  private $sql;
  private $mysql;
  private $result;
  private $result_rows;
  private $database_name;
  private static $instance;
  /**
   * Query history
   *
   * @var array
   */
  static $queries = array();
  /**
   * Database() constructor
   *
   * @param string $database_name
   * @param string $username
   * @param string $password
   * @param string $host
   * @throws DatabaseException
   */
  function __construct($database_name, $username, $password, $host = 'localhost')
  {
    self::$instance = $this;
    $this->database_name = $database_name;
    $this->mysql = mysqli_connect($host, $username, $password, $database_name);
    if (!$this->mysql) {
      throw new DatabaseException('Database connection error: ' . mysqli_connect_error());
    }
  }
  /**
   * Get instance
   *
   * @param string $database_name
   * @param string $username
   * @param string $password
   * @param string $host
   * @return Database
   */
  final public static function instance($database_name = null, $username = null, $password = null, $host = 'localhost')
  {
    if (!isset(self::$instance)) {
      self::$instance = new Database($database_name, $username, $password, $host);
    }
    return self::$instance;
  }
  /**
   * Helper for throwing exceptions
   *
   * @param $error
   * @throws Exception
   */
  private function _error($error)
  {
    if (\Config::get('domain.debug')) {
      dd($this->sql);
    }
      
    throw new DatabaseException('Database error: ' . $error);
  }
  /**
   * Turn an array into a where statement
   *
   * @param mixed $where
   * @param string $where_mode
   * @return string
   * @throws Exception
   */
  public function process_where($where, $where_mode = 'AND')
  {
    $query = '';
    if (is_array($where)) {
      $num = 0;
      $where_count = count($where);
      foreach ($where as $k => $v) {
        if (is_array($v)) {
          $w = array_keys($v);
          if (reset($w) != 0) {
            throw new Exception('Can not handle associative arrays');
          }
          if (empty($v)) { //If array is empty, do not process it and remove a joining keyword ( AND) from the query
                           //because there is no condition for this element
            if (\StringUtils::endsWith($query, " $where_mode"))
              $query = substr($query, 0, strlen($query) - strlen(" $where_mode"));
          } else if (\StringUtils::endsWith($k, '!')) {
            $k = trim(substr($k, 0, strlen($k) - 1));
            $query .= " `" . $k . "` NOT IN (" . $this->join_array($v) . ")";
          } else { //Default
            $query .= " `" . $k . "` IN (" . $this->join_array($v) . ")";
          }
        } elseif (!is_integer($k)) {
          $tk = trim(substr($k, 0, strlen($k) - 1));
          if (\StringUtils::endsWith($k, '<')) $query .= ' `' . $tk . "`<'" . $this->escape($v) . "'";
          else if (\StringUtils::endsWith($k, '>')) $query .= ' `' . $tk . "`>'" . $this->escape($v) . "'";
          else if (\StringUtils::endsWith($k, '~')) $query .= ' `' . $tk . "` LIKE '%" . $this->escape($v) . "%'";
          else if (\StringUtils::endsWith($k, '!')) {
            if ($v !== null) $query .= ' `' . $tk . "`<> '" . $this->escape($v) . "'";
            else $query .= ' `' . $tk . "` IS NOT NULL";
          } else { //Default
            if ($v !== null) $query .= ' `' . $k . "`='" . $this->escape($v) . "'";
            else $query .= ' `' . $k . "` IS NULL";
          }
        } else {
          $query .= ' ' . $v;
        }
        $num++;
        if ($num != $where_count) {
          $query .= ' ' . $where_mode;
        }
      }
    } else {
      $query .= ' ' . $where;
    }
    return $query;
  }
  /**
   * Perform a SELECT operation
   *
   * @param string $table
   * @param array $where
   * @param bool $limit
   * @param bool $order
   * @param string $where_mode
   * @param string $select_fields
   * @return Database
   * @throws DatabaseException
   */
  public function select($table, $where = array(), $limit = false, $order = false, $where_mode = "AND", $select_fields = '*')
  {
    $this->result = null;
    $this->sql = null;
    if (is_array($select_fields)) {
      $fields = '';
      foreach ($select_fields as $s) {
        //Check if it has an alias
        $asPosition = stripos($s, ' as ');
        if ($asPosition !== false) $fields .= "$s, ";
        else $fields .= '`' . $s . '`, ';
      }
      $select_fields = rtrim($fields, ', ');
    }
    $query = 'SELECT ' . $select_fields . ' FROM `' . $table . '`';
    if (!empty($where)) {
      $whereStr = $this->process_where($where, $where_mode);
      if (!empty($whereStr))
        $query .= ' WHERE ' . $whereStr;
    }
    if ($order) {
      $query .= ' ORDER BY ' . $order;
    }
    if ($limit) {
      $query .= ' LIMIT ' . $limit;
    }

    return $this->query($query);
  }

  /**
   * Gets a single instance of a row
   *
   * @param string $table
   * @param array $where
   * @param bool $exceptionOnMultiple
   * @return Row as object if it exists, otherwise null
   * @throws DatabaseException
   */
  public function get($table, $where = array(), $exceptionOnMultiple = false)
  {
    $result = $this->select($table, $where, 2, false)->result();

    if (sizeof($result) > 1 && $exceptionOnMultiple)
      throw new \DomainException('More than one row satisfies the criteria.');

    if (empty($result)) return null;
    return $result[0];
  }

  /**
   * Perform a query
   *
   * @param string $query
   * @return $this|Database
   * @throws Exception
   */
  public function query($query)
  {
    self::$queries[] = $query;
    $this->sql = $query;
    $this->result_rows = null;
    $this->result = mysqli_query($this->mysql, $query);
    if (mysqli_error($this->mysql) != '') {
      echo "Query error:<br>" . PHP_EOL;
      $this->_error(mysqli_error($this->mysql));
      $this->result = null;
      return $this;
    }
    return $this;
  }
  /**
   * Get last executed query
   *
   * @return string|null
   */
  public function sql()
  {
    return $this->sql;
  }
  /**
   * Get an array of objects with the query result
   *
   * @param string|null $key_field
   * @return array
   */
  public function result($key_field = null)
  {
    if (!$this->result_rows) {
      $this->result_rows = array();
      while ($row = mysqli_fetch_assoc($this->result)) {
        $this->result_rows[] = $row;
      }
    }
    $result = array();
    $index = 0;
    foreach ($this->result_rows as $row) {
      $key = $index;
      if (!empty($key_field) && isset($row[$key_field])) {
        $key = $row[$key_field];
      }
      $result[$key] = new stdClass();
      foreach ($row as $column => $value) {
        $this->is_serialized($value, $value);
        $result[$key]->{$column} = $this->clean($value);
      }
      $index++;
    }
    return $result;
  }
  /**
   * Get an array of arrays with the query result
   *
   * @return array
   */
  public function result_array()
  {
    if (!$this->result_rows) {
      $this->result_rows = array();
      while ($row = mysqli_fetch_assoc($this->result)) {
        $this->result_rows[] = $row;
      }
    }
    $result = array();
    $n = 0;
    foreach ($this->result_rows as $row) {
      $result[$n] = array();
      foreach ($row as $k => $v) {
        $this->is_serialized($v, $v);
        $result[$n][$k] = $this->clean($v);
      }
      $n++;
    }
    return $result;
  }
  /**
   * Get a specific row from the result as an object
   *
   * @param int $index
   * @return stdClass
   */
  public function row($index = 0)
  {
    if (!$this->result_rows) {
      $this->result_rows = array();
      while ($row = mysqli_fetch_assoc($this->result)) {
        $this->result_rows[] = $row;
      }
    }
    $num = 0;
    foreach ($this->result_rows as $column) {
      if ($num == $index) {
        $row = new stdClass();
        foreach ($column as $key => $value) {
          $this->is_serialized($value, $value);
          $row->{$key} = $this->clean($value);
        }
        return $row;
      }
      $num++;
    }
    return new stdClass();
  }
  /**
   * Get a specific row from the result as an array
   *
   * @param int $index
   * @return array
   */
  public function row_array($index = 0)
  {
    if (!$this->result_rows) {
      $this->result_rows = array();
      while ($row = mysqli_fetch_assoc($this->result)) {
        $this->result_rows[] = $row;
      }
    }
    $num = 0;
    foreach ($this->result_rows as $column) {
      if ($num == $index) {
        $row = array();
        foreach ($column as $key => $value) {
          $this->is_serialized($value, $value);
          $row[$key] = $this->clean($value);
        }
        return $row;
      }
      $num++;
    }
    return array();
  }
  /**
   * Get the number of result rows
   *
   * @return bool|int
   */
  public function count()
  {
    if ($this->result) {
      return mysqli_num_rows($this->result);
    } elseif (isset($this->result_rows)) {
      return count($this->result_rows);
    } else {
      return false;
    }
  }
  /**
   * Execute a SELECT COUNT(*) query on a table
   *
   * @param null $table
   * @param array $where End a field name with !, <, > or ~ to perform a NOT, LESS, GREATER on LIKE '%%' on that field.
   *                     ! is also valid for nulls and NOT IN arrays
   * @param bool $limit
   * @param bool $order
   * @param string $where_mode
   * @return mixed
   */
  public function num($table = null, $where = array(), $limit = false, $order = false, $where_mode = "AND")
  {
    if (!empty($table)) {
      $this->select($table, $where, $limit, $order, $where_mode, 'COUNT(*)');
    }
    $res = $this->row();
    return $res->{'COUNT(*)'};
  }
  /**
   * Check if a table with a specific name exists
   *
   * @param $name
   * @return bool
   */
  function table_exists($name)
  {
    $res = mysqli_query($this->mysql, "SELECT COUNT(*) AS count FROM information_schema.tables WHERE table_schema = '" . $this->escape($this->database_name) . "' AND table_name = '" . $this->escape($name) . "'");
    return ($this->mysqli_result($res, 0) == 1);
  }
  /**
   * Helper function for process_where
   *
   * @param $array
   * @return string
   */
  private function join_array($array)
  {
    $nr = 0;
    $query = '';
    foreach ($array as $key => $value) {
      if (is_object($value) || is_array($value) || is_bool($value)) {
        $value = serialize($value);
      }
      if (is_null($value))
        $query .= " null";
      else
        $query .= " '" . $this->escape($value) . "'";
      $nr++;
      if ($nr != count($array)) {
        $query .= ',';
      }
    }
    return trim($query);
  }
  /* Insert/update functions */
  /**
   * Insert a row in a table
   *
   * @param $table
   * @param array $fields
   * @param bool|false $appendix
   * @param bool|false $ret
   * @return bool|Database
   * @throws Exception
   */
  function insert($table, $fields = array(), $appendix = false, $ret = false)
  {
    $this->result = null;
    $this->sql = null;
    $query = 'INSERT INTO';
    $query .= ' `' . $this->escape($table) . "`";
    if (is_array($fields)) {
      $query .= ' (';
      $num = 0;
      foreach ($fields as $key => $value) {
        $query .= ' `' . $key . '`';
        $num++;
        if ($num != count($fields)) {
          $query .= ',';
        }
      }
      $query .= ' ) VALUES ( ' . $this->join_array($fields) . ' )';
    } else {
      $query .= ' ' . $fields;
    }
    if ($appendix) {
      $query .= ' ' . $appendix;
    }
    if ($ret) {
      return $query;
    }
    $this->sql = $query;
    $this->result = mysqli_query($this->mysql, $query);
    if (mysqli_error($this->mysql) != '') {
      $this->_error(mysqli_error($this->mysql));
      $this->result = null;
      return false;
    } else {
      return $this;
    }
  }
  /**
   * Execute an UPDATE statement
   *
   * @param $table
   * @param array $fields End a field's name with ! to ommit the quotes in the value. Useful when you
   *            want to set a field's value in relation to it's previous value or to null (!null)
   * @param array $where
   * @param bool $limit
   * @param bool $order
   * @return $this|bool
   * @throws DatabaseException
   */
  function update($table, $fields = array(), $where = array(), $limit = false, $order = false, $escape = true)
  {
    if (empty($where)) {
      throw new DatabaseException('Where clause is empty for update method');
    }
    $this->result = null;
    $this->sql = null;
    $query = 'UPDATE `' . $table . '` SET';
    if (is_array($fields)) {
      $nr = 0;
      foreach ($fields as $k => $v) {
        if (is_object($v) || is_array($v) || is_bool($v)) {
          $v = serialize($v);
        }
        if (\StringUtils::endsWith($k, '!')) {
          $query .= ' `' . substr($k, 0, -1) . "`=" . $v;
        } else if ($v === null) {  // Do not quote null
          $query .= ' `' . $k . "`=null";
        } else {
          $query .= ' `' . $k . "`='" . $this->escape($v) . "'";
        }
        $nr++;
        if ($nr != count($fields)) {
          $query .= ',';
        }
      }
    } else {
      $query .= ' ' . $fields;
    }
    if (!empty($where)) {
      $query .= ' WHERE' . $this->process_where($where);
    }
    if ($order) {
      $query .= ' ORDER BY ' . $order;
    }
    if ($limit) {
      $query .= ' LIMIT ' . $limit;
    }
    $this->sql = $query;

    $this->result = mysqli_query($this->mysql, $query);
    if (mysqli_error($this->mysql) != '') {
      $this->_error(mysqli_error($this->mysql));
      $this->result = null;
      return false;
    } else {
      return $this;
    }
  }
  /**
   * Execute a DELETE statement
   *
   * @param $table
   * @param array $where
   * @param string $where_mode
   * @param bool $limit
   * @param bool $order
   * @return $this|bool
   * @throws DatabaseException
   * @throws Exception
   */
  function delete($table, $where = array(), $where_mode = "AND", $limit = false, $order = false)
  {
    if (empty($where)) {
      throw new DatabaseException('Where clause is empty for update method');
    }
    // Notice: different syntax to keep backwards compatibility
    $this->result = null;
    $this->sql = null;
    $query = 'DELETE FROM `' . $table . '`';
    if (!empty($where)) {
      $query .= ' WHERE' . $this->process_where($where, $where_mode);
    }
    if ($order) {
      $query .= ' ORDER BY ' . $order;
    }
    if ($limit) {
      $query .= ' LIMIT ' . $limit;
    }
    $this->sql = $query;
    $this->result = mysqli_query($this->mysql, $query);
    if (mysqli_error($this->mysql) != '') {
      $this->_error(mysqli_error($this->mysql));
      $this->result = null;
      return false;
    } else {
      return $this;
    }
  }
  /**
   * Get the primary key of the last inserted row
   *
   * @return int|string
   */
  public function id()
  {
    return mysqli_insert_id($this->mysql);
  }
  /**
   * Get the number of rows affected by your last query
   *
   * @return int
   */
  public function affected()
  {
    return mysqli_affected_rows($this->mysql);
  }
  /**
   * Escape a parameter
   *
   * @param $str
   * @return string
   */
  public function escape($str)
  {
    if (is_array($str)) {
      dd($str);
    }
    return mysqli_real_escape_string($this->mysql, $str);
  }
  /**
   * Get the last error message
   *
   * @return string
   */
  public function error()
  {
    return mysqli_error($this->mysql);
  }
  /**
   * Fix UTF-8 encoding problems
   *
   * @param $str
   * @return string
   */
  private function clean($str)
  {
    if (is_string($str)) {
      if (!mb_detect_encoding($str, 'UTF-8', TRUE)) {
        $str = utf8_encode($str);
      }
    }
    return $str;
  }
  /**
   * Check if a variable is serialized
   *
   * @param mixed $data
   * @param null $result
   * @return bool
   */
  public function is_serialized($data, &$result = null)
  {
    if (!is_string($data)) {
      return false;
    }
    $data = trim($data);
    if (empty($data)) {
      return false;
    }
    if ($data === 'b:0;') {
      $result = false;
      return true;
    }
    if ($data === 'b:1;') {
      $result = true;
      return true;
    }
    if ($data === 'N;') {
      $result = null;
      return true;
    }
    if (strlen($data) < 4) {
      return false;
    }
    if ($data[1] !== ':') {
      return false;
    }
    $lastc = substr($data, -1);
    if (';' !== $lastc && '}' !== $lastc) {
      return false;
    }
    $token = $data[0];
    switch ($token) {
      case 's' :
        if ('"' !== substr($data, -2, 1)) {
          return false;
        }
        break;
      case 'a' :
      case 'O' :
        if (!preg_match("/^{$token}:[0-9]+:/s", $data)) {
          return false;
        }
        break;
      case 'b' :
      case 'i' :
      case 'd' :
        if (!preg_match("/^{$token}:[0-9.E-]+;/", $data)) {
          return false;
        }
    }
    try {
      if (($res = @unserialize($data)) !== false) {
        $result = $res;
        return true;
      }
      if (($res = @unserialize(utf8_encode($data))) !== false) {
        $result = $res;
        return true;
      }
    } catch (Exception $e) {
      return false;
    }
    return false;
  }
  /**
   * MySQL compatibility method mysqli_result
   * http://www.php.net/manual/en/class.mysqli-result.php#109782
   *
   * @param mysqli_result $res
   * @param int $row
   * @param int $field
   */
  private function mysqli_result($res, $row, $field = 0)
  {
    $res->data_seek($row);
    $datarow = $res->fetch_array();
    return $datarow[$field];
  }
}

class StringUtils {
  /**
   * Generate random string
   *
   * @param int $length Length of the random string to be generated.
   * @param string $characters Set of characters to use for the string.
   *
   * @return string The random string.
   */
  static public function randomString($length = 16, $characters =
    '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ' ) {
   
    if (!is_int($length) || $length < 0) {
      return false;
    }
    
    $characters_length = strlen($characters) - 1;
    $string = '' ;
    
    for ($i = $length; $i > 0; $i-- ) {
      $string .= $characters[mt_rand(0, $characters_length)];
    }
    
    return $string;
  }
  
  static public function utf8_for_xml($string) {
    return preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $string);
  }

  static public function createGUID()
  {
    return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
           mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479),
           mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
  }

  static public function startsWith($haystack, $needle)
  {
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
  }

  static public function endsWith($haystack, $needle)
  {
    $length = strlen($needle);
    if ($length == 0) {
      return true;
    }

    return (substr($haystack, -$length) === $needle);
  }

  static public function left($str, $length) {
    return substr($str, 0, $length);
  }

  static public function right($str, $length) {
    return substr($str, -$length);
  }

}
