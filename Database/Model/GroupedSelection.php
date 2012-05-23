<?php

namespace Database\Model;



/**
 * Representation of filtered table grouped by some column.
 *
 * @author     Jan-Sebastian Fabík
 */
class GroupedSelection extends \Nette\Database\Table\GroupedSelection
{
	/**
	 * Creates a new row.
	 * @return \Database\Model\ActiveRow
	 */
	protected function createRow(array $row)
	{
		$class = $this->connection->getRowClass($this->name);
		return new $class($row, $this);
	}



	/**
	 * Creates a new grouped selection.
	 * @param  string
	 * @param  string
	 * @return \Database\Model\GroupedSelection
	 */
	protected function createGroupedSelection($table, $column)
	{
		return new GroupedSelection($table, $this, $column);
	}



	/******** Nette\Database\Table\GroupedSelection behavior ********/



	public function aggregation($function)
	{
		$aggregation = & $this->aggregation[$function . implode('', $this->where) . implode('', $this->conditions)];
		if ($aggregation === NULL) {
			$aggregation = array();

			$selection = $this->connection->table($this->name); // HACK
			$selection->where = $this->where;
			$selection->parameters = $this->parameters;
			$selection->conditions = $this->conditions;

			$selection->select($function);
			$selection->select("{$this->name}.{$this->column}");
			$selection->group("{$this->name}.{$this->column}");

			foreach ($selection as $row) {
				$aggregation[$row[$this->column]] = $row;
			}
		}

		if (isset($aggregation[$this->active])) {
			foreach ($aggregation[$this->active] as $val) {
				return $val;
			}
		}
	}



	public function insert($data)
	{
		if ($data instanceof \Traversable && !$data instanceof \Nette\Database\Table\Selection) {
			$data = iterator_to_array($data);
		}

		if (Nette\Utils\Validators::isList($data)) {
			foreach (array_keys($data) as $key) {
				$data[$key][$this->column] = $this->active;
			}
		} else {
			$data[$this->column] = $this->active;
		}

		return $this->_insert($data); // HACK
	}



	/******** Nette\Database\Table\Selection behavior ********/



	/**
	 * Returns referencing rows.
	 * @param  string
	 * @param  string
	 * @param  int  primary key
	 * @param  bool force new instance
	 * @return \Database\Model\GroupedSelection
	 */
	public function getReferencingTable($table, $column, $active = NULL, $forceNewInstance = FALSE)
	{
		$referencing = & $this->referencing["$table:$column"];
		if (!$referencing || $forceNewInstance) {
			$referencing = $this->createGroupedSelection($table, $column); // HACK
		}

		return $referencing->setActive($active)->where("$table.$column", array_keys((array) $this->rows));
	}



	/**
	 * Inserts row in a table.
	 * @param  mixed array($column => $value)|Traversable for single row insert or Selection|string for INSERT ... SELECT
	 * @return \Database\Model\ActiveRow or FALSE in case of an error or number of affected rows for INSERT ... SELECT
	 */
	private function _insert($data)
	{
		if ($data instanceof \Nette\Database\Table\Selection) {
			$data = $data->getSql();

		} elseif ($data instanceof \Traversable) {
			$data = iterator_to_array($data);
		}

		$return = $this->connection->query("INSERT INTO $this->delimitedName", $data);

		if (!is_array($data)) {
			return $return->rowCount();
		}

		$this->checkReferenceNewKeys = TRUE;

		if (!isset($data[$this->primary]) && ($id = $this->connection->lastInsertId())) {
			$data[$this->primary] = $id;
			return $this->rows[$id] = $this->createRow($data); // HACK

		} else {
			return $this->createRow($data); // HACK

		}
	}



	/**
	 * Returns referenced row.
	 * @param  string
	 * @param  string
	 * @param  bool  checks if rows contains the same primary value relations
	 * @return \Database\Model\Selection or array() if the row does not exist
	 */
	public function getReferencedTable($table, $column, $checkReferenceNewKeys = FALSE)
	{
		$referenced = & $this->referenced["$table.$column"];
		if ($referenced === NULL || $checkReferenceNewKeys || $this->checkReferenceNewKeys) {
			$keys = array();
			$this->execute(); // HACK
			foreach ($this->rows as $row) {
				if ($row[$column] === NULL)
					continue;

				$key = $row[$column] instanceof ActiveRow ? $row[$column]->getPrimary() : $row[$column];
				$keys[$key] = TRUE;
			}

			if ($referenced !== NULL && $keys === array_keys($this->rows)) {
				$this->checkReferenceNewKeys = FALSE;
				return $referenced;
			}

			if ($keys) {
				$referenced = $this->connection->table($table); // HACK
				$referenced->where($table . '.' . $referenced->primary, array_keys($keys));
			} else {
				$referenced = array();
			}
		}

		return $referenced;
	}
}