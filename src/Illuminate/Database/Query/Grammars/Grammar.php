<?php namespace Illuminate\Database\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Grammar as BaseGrammar;

class Grammar extends BaseGrammar {

	/**
	 * The keyword identifier wrapper format.
	 *
	 * @var string
	 */
	protected $wrapper = '"%s"';

	/**
	 * The components that make up a select clause.
	 *
	 * @var array
	 */
	protected $selectComponents = array(
		'aggregate',
		'columns',
		'from',
		'joins',
		'wheres',
		'groups',
		'havings',
		'orders',
		'limit',
		'offset',
	);

	/**
	 * Compile a select query into SQL.
	 *
	 * @param  Illuminate\Database\Query\Builder
	 * @return string
	 */
	public function compileSelect(Builder $query)
	{
		return trim($this->concatenate($this->compileComponents($query)));
	}

	/**
	 * Compile the components necessary for a select clause.
	 *
	 * @param  Illuminate\Database\Query\Builder
	 * @return array
	 */
	protected function compileComponents(Builder $query)
	{
		$sql = array();

		foreach ($this->selectComponents as $component)
		{
			// To compile the query, we'll spin through each component of the query and
			// see if that component exists. If it does, we'll just call the compiler
			// function for that component which is responsible for making the SQL.
			if ( ! is_null($query->$component))
			{
				$method = 'compile'.ucfirst($component);

				$sql[$component] = $this->$method($query, $query->$component);
			}
		}

		return $sql;
	}

	/**
	 * Compile an aggregated select clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $aggregate
	 * @return string
	 */
	protected function compileAggregate(Builder $query, $aggregate)
	{
		$column = $this->columnize($aggregate['columns']);

		// If the query has a "distinct" constraint and we're not asking for all columns
		// we need to prepend "distinct" onto the column name so that the query takes
		// it into account when it performs the aggregating operations on the data.
		if ($query->distinct and $column !== '*')
		{
			$column = 'distinct '.$column;
		}

		return 'select '.$aggregate['function'].'('.$column.') as aggregate';
	}

	/**
	 * Compile the "select *" portion of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $columns
	 * @return string
	 */
	protected function compileColumns(Builder $query, $columns)
	{
		// If the query is actually performing an aggregating select, we will let that
		// compiler handle the building of the select clauses, as it will need some
		// more syntax that is best handled by that function to keep things neat.
		if ( ! is_null($query->aggregate)) return;

		$select = $query->distinct ? 'select distinct ' : 'select ';

		return $select.$this->columnize($columns);
	}

	/**
	 * Compile the "from" portion of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  string  $table
	 * @return string
	 */
	protected function compileFrom(Builder $query, $table)
	{
		return 'from '.$this->wrapTable($table);
	}

	/**
	 * Compile the "join" portions of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $joins
	 * @return string
	 */
	protected function compileJoins(Builder $query, $joins)
	{
		$sql = array();

		foreach ($joins as $join)
		{
			$table = $this->wrapTable($join->table);

			// First we need to build all of the "on" clauses for the join. There may be many
			// of these clauses so we will need to iterate through each one and build them
			// separately, then we'll join them up into a single string when we're done.
			$clauses = array();

			foreach ($join->clauses as $clause)
			{
				$clauses[] = $this->compileJoinConstraint($clause);
			}

			// Once we have constructed the clauses, we'll need to take the boolean connector
			// off of the first clause as it obviously will not be required on that clause
			// because it leads the rest of the clauses, thus not requiring any boolean.
			$search = array('and ', 'or ');

			$clauses[0] = str_replace($search, '', $clauses[0]);

			$clauses = implode(' ', $clauses);

			$type = $join->type;

			// Once we have everything ready to go, we will just concatenate all the parts to
			// build the final join statement SQL for the query and we can then return the
			// final clause back to the callers as a single, stringified join statement.
			$sql[] = "$type join $table on $clauses";
		}

		return implode(' ', $sql);
	}

	/**
	 * Create a join clause constraint segment.
	 *
	 * @param  array   $clause
	 * @return string
	 */
	protected function compileJoinConstraint(array $clause)
	{
		$first = $this->wrap($clause['first']);

		$second = $this->wrap($clause['second']);

		return "{$clause['boolean']} $first {$clause['operator']} $second";
	}

	/**
	 * Compile the "where" portions of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @return string
	 */
	protected function compileWheres(Builder $query)
	{
		$sql = array();

		// Each type of where clauses has its own compiler function which is responsible
		// for actually creating the where clauses SQL. This helps keep the code nice
		// and maintainable since each clause has a very small method that it uses.
		foreach ($query->wheres as $where)
		{
			$method = "where{$where['type']}";

			$sql[] = $where['boolean'].' '.$this->$method($query, $where);
		}

		// If we actually have some where clauses, we will strip off the first boolean
		// opeartor, which is added by the query builders for convenience so we can
		// avoid checking for the first clauses in each of the compilers methods.
		if (count($sql) > 0)
		{
			$sql = implode(' ', $sql);

			return 'where '.preg_replace('/and |or /', '', $sql, 1);
		}

		return '';
	}

	/**
	 * Compile a nested where clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNested(Builder $query, $where)
	{
		$nested = $where['query'];

		return '('.substr($this->compileWheres($nested), 6).')';
	}

	/**
	 * Compile a where condition with a sub-select.
	 *
	 * @param  Illuminate\Database\Query\Builder $query
	 * @param  array   $where
	 * @return string
	 */
	protected function whereSub(Builder $query, $where)
	{
		$select = $this->compileSelect($where['query']);

		return $this->wrap($where['column']).' '.$where['operator']." ($select)";
	}

	/**
	 * Compile a basic where clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereBasic(Builder $query, $where)
	{
		$value = $this->parameter($where['value']);

		return $this->wrap($where['column']).' '.$where['operator'].' '.$value;
	}

	/**
	 * Compile a where exists clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereExists(Builder $query, $where)
	{
		return 'exists ('.$this->compileSelect($where['query']).')';
	}

	/**
	 * Compile a where exists clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotExists(Builder $query, $where)
	{
		return 'not exists ('.$this->compileSelect($where['query']).')';
	}

	/**
	 * Compile a "where in" clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereIn(Builder $query, $where)
	{
		$values = $this->parameterize($where['values']);

		return $this->wrap($where['column']).' in ('.$values.')';
	}

	/**
	 * Compile a "where not in" clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotIn(Builder $query, $where)
	{
		$values = $this->parameterize($where['values']);

		return $this->wrap($where['column']).' not in ('.$values.')';
	}

	/**
	 * Compile a where in sub-select clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereInSub(Builder $query, $where)
	{
		$select = $this->compileSelect($where['query']);

		return $this->wrap($where['column']).' in ('.$select.')';
	}

	/**
	 * Compile a where not in sub-select clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotInSub(Builder $query, $where)
	{
		$select = $this->compileSelect($where['query']);

		return $this->wrap($where['column']).' not in ('.$select.')';
	}

	/**
	 * Compile a "where null" clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNull(Builder $query, $where)
	{
		return $this->wrap($where['column']).' is null';
	}

	/**
	 * Compile a "where not null" clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotNull(Builder $query, $where)
	{
		return $this->wrap($where['column']).' is not null';
	}

	/**
	 * Compile a raw where clause.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereRaw(Builder $query, $where)
	{
		return $where['sql'];
	}

	/**
	 * Compile the "group by" portions of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $groups
	 * @return string
	 */
	protected function compileGroups(Builder $query, $groups)
	{
		return 'group by '.$this->columnize($groups);
	}

	/**
	 * Compile the "having" portions of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $havings
	 * @return string
	 */
	protected function compileHavings(Builder $query, $havings)
	{
		$me = $this;

		$sql = implode(' ', array_map(function($having) use ($me)
		{
			return 'and '.$me->wrap($having['column']).' '.$having['operator'].' '.$this->parameter($having['value']);
		}, $havings));

		return 'having '.preg_replace('/and /', '', $sql, 1);
	}

	/**
	 * Compile the "order by" portions of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $orders
	 * @return string
	 */
	protected function compileOrders(Builder $query, $orders)
	{
		$me = $this;

		return 'order by '.implode(', ', array_map(function($order) use ($me)
		{
			return $me->wrap($order['column']).' '.$order['direction'];
		}
		, $orders));
	}

	/**
	 * Compile the "limit" portions of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  int  $limit
	 * @return string
	 */
	protected function compileLimit(Builder $query, $limit)
	{
		return "limit $limit";
	}

	/**
	 * Compile the "offset" portions of the query.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  int  $offset
	 * @return string
	 */
	protected function compileOffset(Builder $query, $offset)
	{
		return "offset $offset";
	}

	/**
	 * Compile an insert statement into SQL.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $values
	 * @return string
	 */
	public function compileInsert(Builder $query, array $values)
	{
		// Essentially we will force every insert to be treated as a batch insert which
		// simply makes creating the SQL easier for us since we can utilize the same
		// basic routine regardless of an amount of records given to us to insert.
		$table = $this->wrapTable($query->from);

		if ( ! is_array(reset($values)))
		{
			$values = array($values);
		}

		$columns = $this->columnize(array_keys(reset($values)));

		// We need to build a list of parameter place-holders of values that are bound
		// to the query. Each insert should have the exact same amount of parameter
		// bindings so we can just go off the first list of values in this array.
		$parameters = $this->parameterize(reset($values));

		$value = array_fill(0, count($values), "($parameters)");

		$parameters = implode(', ', $value);

		return "insert into $table ($columns) values $parameters";
	}

	/**
	 * Compile an insert and get ID statement into SQL.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array   $values
	 * @param  string  $sequence
	 * @return string
	 */
	public function compileInsertGetId(Builder $query, $values, $sequence)
	{
		return $this->compileInsert($query, $values);
	}

	/**
	 * Compile an update statement into SQL.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $values
	 * @return string
	 */
	public function compileUpdate(Builder $query, $values)
	{
		$table = $this->wrapTable($query->from);

		// Each one of the columns in the update statements needs to be wrapped in the
		// keyword identifiers, also a place-holder needs to be created for each of
		// the values in the list of bindings so we can make the sets statements.
		$columns = array();

		foreach ($values as $key => $value)
		{
			$columns[] = $this->wrap($key).' = '.$this->parameter($value);
		}

		$columns = implode(', ', $columns);

		// Of course, update queries may also be constrained by where clauses so we'll
		// need to compile the where clauses and attach it to the query so only the
		// intended records are updated by the SQL statements we generate to run.
		$where = $this->compileWheres($query);

		return trim("update $table set $columns $where");
	}

	/**
	 * Compile a delete statement into SQL.
	 *
	 * @param  Illuminate\Database\Query\Builder  $query
	 * @param  array  $values
	 * @return string
	 */
	public function compileDelete(Builder $query)
	{
		$table = $this->wrapTable($query->from);

		$where = is_array($query->wheres) ? $this->compileWheres($query) : '';

		return trim("delete from $table ".$where);
	}

	/**
	 * Concatenate an array of segments, removing empties.
	 *
	 * @param  array   $segments
	 * @return string
	 */
	protected function concatenate($segments)
	{
		return implode(' ', array_filter($segments, function($value)
		{
			return (string) $value !== '';
		}));
	}

}