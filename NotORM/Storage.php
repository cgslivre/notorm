<?php

/** Data storage
*/
interface NotORM_Storage {
	
	/** Execute query generated by select()
	* @param string
	* @param array
	* @return array|Traversable contains associative arrays
	*/
	function query($query, array $parameters = array());
	
	/** Generate query string
	* @param string
	* @param string
	* @param array of array($condition[, $parameter])
	* @param string
	* @param string
	* @param array
	* @param int
	* @param int
	* @return string
	*/
	function select($columns, $table, array $where = array(), $group = "", $having = "", array $order = array(), $limit = null, $offset = null);
	
	/** Insert data to storage
	* @param string table name
	* @param mixed
	* @return auto increment value or false in case of an error
	*/
	function insert($table, $data);
	
	/** Update data in storage
	* @param string
	* @param array
	* @param array conditions with ? or :
	* @param array where values
	* @param string
	* @param int
	* @param int
	* @return int number of affected rows or false in case of an error
	*/
	function update($table, array $data, array $where, array $parameters = array(), $order = "", $limit = null, $offset = null);
	
	/** Delete data from storage
	* @param string
	* @param array conditions with ? or :
	* @param array where values
	* @param string
	* @param int
	* @param int
	* @return int number of affected rows or false in case of an error
	*/
	function delete($table, array $where, array $parameters = array(), $order = "", $limit = null, $offset = null);
	
}



/** Storage using PDO
*/
class NotORM_Storage_PDO implements NotORM_Storage {
	protected $connection, $driver;
	
	/** Enable debuging queries
	* @var mixed true for fwrite(STDERR, $query), callback($query, $parameters) otherwise
	* @access public write-only
	*/
	public $debug = false;
	
	/** Initialize storage
	* @param PDO
	* @param bool
	*/
	function __construct(PDO $connection, $debug = false) {
		$this->connection = $connection;
		$this->debug = $debug;
		$this->driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
	}
	
	function query($query, array $parameters = array()) {
		if ($this->debug) {
			if (is_callable($this->debug)) {
				call_user_func($this->debug, $query, $parameters);
			} else {
				fwrite(STDERR, "-- $query;\n");
			}
		}
		$return = $this->connection->prepare($query);
		if (!$return || !$return->execute($parameters)) {
			return false;
		}
		$return->setFetchMode(PDO::FETCH_ASSOC);
		return $return;
	}
	
	protected function quote($val) {
		if (!isset($val)) {
			return "NULL";
		}
		if (is_int($val) || is_float($val) || $val instanceof NotORM_Literal) { // number or SQL code - for example "NOW()"
			return (string) $val;
		}
		return $this->connection->quote($val);
	}
	
	protected function topString($limit) {
		if (isset($limit) && $this->driver == "dblib") {
			return " TOP ($limit)"; //! offset is not supported
		}
		return "";
	}
	
	protected function whereString(array $where = array(), $group = "", $having = "", array $order = array(), $limit = null, $offset = null) {
		$return = "";
		if (isset($limit) && $this->driver == "oci") {
			$where[] = ($offset ? "rownum > $offset AND " : "") . "rownum <= " . ($limit + $offset);
		}
		if ($where) {
			$conditions = array();
			foreach ($where as $val) {
				$condition = $val[0];
				if (count($val) > 1) {
					$parameters = $val[1];
					if (is_null($parameters)) { // where("column", null)
						$condition .= " IS NULL";
					} elseif ($parameters instanceof NotORM_Result) { // where("column", $db->$table())
						if ($this->driver != "mysql") {
							$condition .= " IN ($parameters)";
						} else { // MySQL can not use indexes for subselects
							$in = array();
							foreach ($parameters as $row) {
								$val = implode(", ", array_map(array($this, 'quote'), iterator_to_array($row)));
								$in[] = (count($row) == 1 ? $val : "($val)");
							}
							$condition .= " IN (" . ($in ? implode(", ", $in) : "NULL") . ")";
						}
					} elseif (!is_array($parameters)) { // where("column", "x")
						$condition .= " = " . $this->quote($parameters);
					} else { // where("column", array(1, 2))
						$in = "NULL";
						if ($parameters) {
							$in = implode(", ", array_map(array($this, 'quote'), $parameters));
						}
						$condition .= " IN ($in)";
					}
				}
				$conditions[] = $condition;
			}
			$return .= " WHERE (" . implode(") AND (", $conditions) . ")";
		}
		if ($group) {
			$return .= " GROUP BY $group";
		}
		if ($having) {
			$return .= " HAVING $having";
		}
		if ($order) {
			$return .= " ORDER BY " . implode(", ", $order);
		}
		if (isset($limit) && $this->driver != "oci" && $this->driver != "dblib") {
			$return .= " LIMIT $limit";
			if (isset($offset)) {
				$return .= " OFFSET $offset";
			}
		}
		return $return;
	}
	
	function select($columns, $table, array $where = array(), $group = "", $having = "", array $order = array(), $limit = null, $offset = null) {
		return "SELECT" . $this->topString($limit) . " $columns FROM $table" . $this->whereString($where, $group, $having, $order, $limit, $offset);
	}
	
	function insert($table, $data) {
		if ($data instanceof NotORM_Result) {
			$data = (string) $data;
		} elseif ($data instanceof Traversable) {
			$data = iterator_to_array($data);
		}
		if (is_array($data)) {
			//! driver specific empty $data
			$data = "(" . implode(", ", array_keys($data)) . ") VALUES (" . implode(", ", array_map(array($this, 'quote'), $data)) . ")";
		}
		// requiers empty $this->parameters
		if (!$this->query("INSERT INTO $table $data")) {
			return false;
		}
		return $this->connection->lastInsertId();
	}
	
	function update($table, array $data, array $where, array $parameters = array(), $order = array(), $limit = null, $offset = null) {
		$values = array();
		foreach ($data as $key => $val) {
			$values[] = "$key = " . $this->quote($val);
		}
		// joins in UPDATE are supported only in MySQL, ORDER causes error in most engines which is required
		$return = $this->query("UPDATE" . $this->topString($limit) . " $table SET " . implode(", ", $values) . $this->whereString($where, "", "", ($limit ? $order : array()), $limit, $offset), $parameters);
		if (!$return) {
			return false;
		}
		return $return->rowCount();
	}
	
	function delete($table, array $where, array $parameters = array(), $order = array(), $limit = null, $offset = null) {
		$return = $this->query("DELETE" . $this->topString($limit) . " FROM $table" . $this->whereString($where, "", "", ($limit ? $order : array()), $limit, $offset), $parameters);
		if (!$return) {
			return false;
		}
		return $return->rowCount();
	}
	
}



/** Query cache with automatic invalidation
*/
class NotORM_Storage_PDO_Cache extends NotORM_Storage_PDO {
	protected $cache;
	protected $queries = array();
	
	function __construct(PDO $pdo, $debug = false, NotORM_Cache $cache) {
		$this->cache = $cache;
		$this->queries = $cache->load("queries");
		parent::__construct($pdo, $debug);
	}
	
	function __destruct() {
		$this->cache->save("queries", $this->queries);
	}
	
	function query($query, array $parameters = array()) {
		if (preg_match('~^SELECT\\s.+?\\sFROM\\s+(\\S+)~i', $query, $match)) { //! simplification //! no invalidation for joined tables
			$table = $match[1];
			$return = &$this->queries[$table][$query][serialize($parameters)];
			if (!isset($return)) {
				$return = parent::query($query, $parameters);
				if ($return instanceof Traversable) {
					$return = iterator_to_array($return);
				}
			}
			return $return;
		}
		return parent::query($query, $parameters);
	}
	
	function insert($table, $data) {
		unset($this->queries[$table]);
		return parent::insert($table, $data);
	}
	
	function update($table, array $data, array $where, array $parameters = array(), $order = "", $limit = null, $offset = null) {
		unset($this->queries[$table]);
		return parent::update($table, $data, $where, $parameters, $order, $limit, $offset);
	}
	
	function delete($table, array $where, array $parameters = array(), $order = "", $limit = null, $offset = null) {
		unset($this->queries[$table]);
		return parent::delete($table, $where, $parameters, $order, $limit, $offset);
	}
	
}
