<?php

namespace Database;



/**
 * Representation of filtered table grouped by some column.
 *
 * @author     Jan-Sebastian Fabík
 */
class GroupedSelection extends \Nette\Database\Table\GroupedSelection implements IModelManagerAccessor
{
	/** @var IModelManager */
	protected $manager;



	/**
	 * @param  Nette\Database\Table\Selection
	 * @param  string
	 * @param  string
	 */
	public function __construct(\Nette\Database\Table\Selection $refTable, $name, $column)
	{
		parent::__construct($refTable, $name, $column);
		if (!$refTable instanceof IModelManagerAccessor) {
			throw new \Nette\InvalidArgumentException('Argument $refTable must be a IModelManagerAccessor descendant.');
		}
		$this->manager = $this->refTable->getModelManager();
	}



	/********** Database\Selection behaviour **********/



	/** @return IModelManager */
	public function getModelManager()
	{
		return $this->manager;
	}



	/**
	 * Creates a new row.
	 * @param  mixed[]
	 * @return Nette\Database\Table\ActiveRow
	 */
	protected function createRow(array $data)
	{
		return $this->manager->getRowFactory()->createRow($data, $this);
	}



	/**
	 * Creates a selection.
	 * @param  string
	 * @return Nette\Database\Table\Selection
	 */
	protected function createSelection($table)
	{
		return new Selection($table, $this->manager);
	}



	/**
	 * Creates a new grouped selection.
	 * @param  string
	 * @param  string
	 * @return Nette\Database\Table\GroupedSelection
	 */
	protected function createGroupedSelection($table, $column)
	{
		return new GroupedSelection($this, $table, $column);
	}



	/********** Nette\Database\Table\GroupedSelection behaviour **********/



	public function aggregation($function)
	{
		$aggregation = & $this->aggregation[$function . implode('', $this->where) . implode('', $this->conditions)];
		if ($aggregation === NULL) {
			$aggregation = array();

			$selection = $this->createSelection($this->name); // HACK
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



	protected function execute()
	{
		if ($this->rows !== NULL) {
			return;
		}

		$hash = md5($this->getSql() . json_encode($this->parameters));
		$referencing = & $this->referencing[$hash];
		if ($referencing === NULL) {
			$limit = $this->limit;
			$this->refTable->execute(); // HACK
			$rows = count($this->refTable->data); // HACK
			if ($this->limit && $rows > 1) {
				$this->limit = NULL;
			}
			$this->_execute(); // HACK
			$this->limit = $limit;
			$referencing = array();
			$offset = array();
			foreach ($this->rows as $key => $row) {
				$ref = & $referencing[$row[$this->column]];
				$skip = & $offset[$row[$this->column]];
				if ($limit === NULL || $rows <= 1 || (count($ref) < $limit && $skip >= $this->offset)) {
					$ref[$key] = $row;
				} else {
					unset($this->rows[$key]);
				}
				$skip++;
				unset($ref, $skip);
			}
		}

		$this->data = & $referencing[$this->active];
		if ($this->data === NULL) {
			$this->data = array();
		} else {
			reset($this->data);
		}
	}



	/********** Nette\Database\Table\Selection behaviour **********/



	/**
	 * Returns referencing rows.
	 * @param  string
	 * @param  string
	 * @param  int  primary key
	 * @param  bool force new instance
	 * @return Nette\Database\Table\GroupedSelection
	 */
	public function getReferencingTable($table, $column, $active = NULL, $forceNewInstance = FALSE)
	{
		$referencing = & $this->referencing["$table:$column"];
		if (!$referencing || $forceNewInstance) {
			$referencing = $this->createGroupedSelection($table, $column); // HACK
		}

		$this->execute(); // HACK
		return $referencing->setActive($active)->where("$table.$column", array_keys((array) $this->data)); // HACK
	}



	/**
	 * Returns referenced row.
	 * @param  string
	 * @param  string
	 * @param  bool  checks if rows contains the same primary value relations
	 * @return Nette\Database\Table\Selection or array() if the row does not exist
	 */
	public function getReferencedTable($table, $column, $checkReferenceNewKeys = FALSE)
	{
		$referenced = & $this->referenced["$table.$column"];
		if ($referenced === NULL || $checkReferenceNewKeys || $this->checkReferenceNewKeys) {
			$keys = array();
			$this->execute();
			foreach ($this->rows as $row) {
				if ($row[$column] === NULL)
					continue;

				$key = $row[$column] instanceof \Nette\Database\Table\ActiveRow ? $row[$column]->getPrimary() : $row[$column];
				$keys[$key] = TRUE;
			}

			if ($referenced !== NULL && $keys === array_keys($this->rows)) {
				$this->checkReferenceNewKeys = FALSE;
				return $referenced;
			}

			if ($keys) {
				$referenced = $this->createSelection($table); // HACK
				$referenced->where($table . '.' . $referenced->primary, array_keys($keys));
			} else {
				$referenced = array();
			}
		}

		return $referenced;
	}



	/**
	 * Executes built query.
	 * @return NULL
	 */
	protected function _execute()
	{
		if ($this->rows !== NULL) {
			return;
		}

		try {
			$result = $this->query($this->getSql());

		} catch (\PDOException $exception) {
			if (!$this->select && $this->prevAccessed) {
				$this->prevAccessed = '';
				$this->accessed = array();
				$result = $this->query($this->getSql());
			} else {
				throw $exception;
			}
		}

		$this->rows = array();
		$result->setFetchMode(\PDO::FETCH_ASSOC);
		foreach ($result as $key => $row) {
			$row = $result->normalizeRow($row);
			$this->rows[isset($row[$this->primary]) ? $row[$this->primary] : $key] = $this->createRow($row);
		}
		$this->data = $this->rows;

		if (isset($row[$this->primary]) && !is_string($this->accessed)) {
			$this->accessed[$this->primary] = TRUE;
		}
	}
}
