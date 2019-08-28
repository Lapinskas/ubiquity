<?php

namespace Ubiquity\orm;

use Ubiquity\db\Database;
use Ubiquity\log\Logger;
use Ubiquity\orm\parser\ManyToManyParser;
use Ubiquity\db\SqlUtils;
use Ubiquity\orm\traits\DAOUpdatesTrait;
use Ubiquity\orm\traits\DAORelationsTrait;
use Ubiquity\orm\parser\ConditionParser;
use Ubiquity\orm\traits\DAOUQueries;
use Ubiquity\orm\traits\DAOCoreTrait;
use Ubiquity\orm\traits\DAORelationsPrepareTrait;
use Ubiquity\exceptions\DAOException;
use Ubiquity\orm\traits\DAORelationsAssignmentsTrait;
use Ubiquity\orm\parser\Reflexion;
use Ubiquity\orm\traits\DAOTransactionsTrait;
use Ubiquity\controllers\Startup;
use Ubiquity\cache\CacheManager;
use Ubiquity\db\providers\swoole\DatabasePool;

/**
 * Gateway class between database and object model.
 * This class is part of Ubiquity
 *
 * @author jcheron <myaddressmail@gmail.com>
 * @version 1.2.2
 *
 */
class DAO {
	use DAOCoreTrait,DAOUpdatesTrait,DAORelationsTrait,DAORelationsPrepareTrait,DAORelationsAssignmentsTrait,DAOUQueries,DAOTransactionsTrait;

	/**
	 *
	 * @var DatabasePool[]
	 */
	public static $pools = [ ];
	public static $useTransformers = false;
	public static $transformerOp = 'transform';
	private static $conditionParsers = [ ];
	protected static $modelsDatabase = [ ];

	protected static function getDb($model) {
		return self::getDatabase ( self::$modelsDatabase [$model] ?? 'default');
	}

	public static function getPool(&$config, ?string $offset = null) {
		if (! isset ( self::$pools [$offset ?? 'default'] )) {
			self::$pools [$offset ?? 'default'] = new DatabasePool ( $config, $offset );
		}
		return self::$pools [$offset ?? 'default'];
	}

	/**
	 * Loads member associated with $instance by a ManyToOne relationship
	 *
	 * @param object|array $instance The instance object or an array with [classname,id]
	 * @param string $member The member to load
	 * @param boolean|array $included if true, loads associate members with associations, if array, example : ["client.*","commands"]
	 * @param boolean|null $useCache
	 */
	public static function getManyToOne($instance, $member, $included = false, $useCache = NULL) {
		$classname = self::getClass_ ( $instance );
		if (is_array ( $instance )) {
			$instance = self::getById ( $classname, $instance [1], false, $useCache );
		}
		$fieldAnnot = OrmUtils::getMemberJoinColumns ( $classname, $member );
		if ($fieldAnnot !== null) {
			$annotationArray = $fieldAnnot [1];
			$member = $annotationArray ["member"];
			$value = Reflexion::getMemberValue ( $instance, $member );
			$key = OrmUtils::getFirstKey ( $annotationArray ["className"] );
			$kv = array ($key => $value );
			$obj = self::getById ( $annotationArray ["className"], $kv, $included, $useCache );
			if ($obj !== null) {
				Logger::info ( "DAO", "Loading the member " . $member . " for the object " . $classname, "getManyToOne" );
				$accesseur = "set" . ucfirst ( $member );
				if (is_object ( $instance ) && method_exists ( $instance, $accesseur )) {
					$instance->$accesseur ( $obj );
					$instance->_rest [$member] = $obj->_rest;
				}
				return $obj;
			}
		}
	}

	/**
	 * Assign / load the child records in the $member member of $instance.
	 *
	 * @param object|array $instance The instance object or an array with [classname,id]
	 * @param string $member Member on which a oneToMany annotation must be present
	 * @param boolean|array $included if true, loads associate members with associations, if array, example : ["client.*","commands"]
	 * @param boolean $useCache
	 * @param array $annot used internally
	 */
	public static function getOneToMany($instance, $member, $included = true, $useCache = NULL, $annot = null) {
		$ret = array ();
		$class = self::getClass_ ( $instance );
		if (! isset ( $annot )) {
			$annot = OrmUtils::getAnnotationInfoMember ( $class, "#oneToMany", $member );
		}
		if ($annot !== false) {
			$fkAnnot = OrmUtils::getAnnotationInfoMember ( $annot ["className"], "#joinColumn", $annot ["mappedBy"] );
			if ($fkAnnot !== false) {
				$fkv = self::getFirstKeyValue_ ( $instance );
				$db = self::getDb ( $annot ["className"] );
				$ret = self::_getAll ( $db, $annot ["className"], ConditionParser::simple ( $db->quote . $fkAnnot ["name"] . $db->quote . "= ?", $fkv ), $included, $useCache );
				if (is_object ( $instance ) && $modifier = self::getAccessor ( $member, $instance, 'getOneToMany' )) {
					self::setToMember ( $member, $instance, $ret, $modifier );
				}
			}
		}
		return $ret;
	}

	/**
	 * Assigns / loads the child records in the $member member of $instance.
	 * If $array is null, the records are loaded from the database
	 *
	 * @param object|array $instance The instance object or an array with [classname,id]
	 * @param string $member Member on which a ManyToMany annotation must be present
	 * @param boolean|array $included if true, loads associate members with associations, if array, example : ["client.*","commands"]
	 * @param array $array optional parameter containing the list of possible child records
	 * @param boolean $useCache
	 */
	public static function getManyToMany($instance, $member, $included = false, $array = null, $useCache = NULL) {
		$ret = [ ];
		$class = self::getClass_ ( $instance );
		$parser = new ManyToManyParser ( $class, $member );
		if ($parser->init ()) {
			if (is_null ( $array )) {
				$pk = self::getFirstKeyValue_ ( $instance );
				$quote = SqlUtils::$quote;
				$condition = " INNER JOIN " . $quote . $parser->getJoinTable () . $quote . " on " . $quote . $parser->getJoinTable () . $quote . "." . $quote . $parser->getFkField () . $quote . "=" . $quote . $parser->getTargetEntityTable () . $quote . "." . $quote . $parser->getPk () . $quote . " WHERE " . $quote . $parser->getJoinTable () . $quote . "." . $quote . $parser->getMyFkField () . $quote . "= ?";
				$targetEntityClass = $parser->getTargetEntityClass ();
				$ret = self::_getAll ( self::getDb ( $targetEntityClass ), $targetEntityClass, ConditionParser::simple ( $condition, $pk ), $included, $useCache );
			} else {
				$ret = self::getManyToManyFromArray ( $instance, $array, $class, $parser );
			}
			if (is_object ( $instance ) && $modifier = self::getAccessor ( $member, $instance, 'getManyToMany' )) {
				self::setToMember ( $member, $instance, $ret, $modifier );
			}
		}
		return $ret;
	}

	/**
	 *
	 * @param object $instance
	 * @param array $array
	 * @param boolean $useCache
	 */
	public static function affectsManyToManys($instance, $array = NULL, $useCache = NULL) {
		$metaDatas = OrmUtils::getModelMetadata ( \get_class ( $instance ) );
		$manyToManyFields = $metaDatas ["#manyToMany"];
		if (\sizeof ( $manyToManyFields ) > 0) {
			foreach ( $manyToManyFields as $member ) {
				self::getManyToMany ( $instance, $member, false, $array, $useCache );
			}
		}
	}

	/**
	 * Returns an array of $className objects from the database
	 *
	 * @param string $className class name of the model to load
	 * @param string $condition Part following the WHERE of an SQL statement
	 * @param boolean|array $included if true, loads associate members with associations, if array, example : ["client.*","commands"]
	 * @param array|null $parameters
	 * @param boolean $useCache use the active cache if true
	 * @return array
	 */
	public static function getAll($className, $condition = '', $included = true, $parameters = null, $useCache = NULL) {
		return self::_getAll ( self::getDb ( $className ), $className, new ConditionParser ( $condition, null, $parameters ), $included, $useCache );
	}

	public static function paginate($className, $page = 1, $rowsPerPage = 20, $condition = null, $included = true) {
		if (! isset ( $condition )) {
			$condition = "1=1";
		}
		return self::getAll ( $className, $condition . " LIMIT " . $rowsPerPage . " OFFSET " . (($page - 1) * $rowsPerPage), $included );
	}

	public static function getRownum($className, $ids) {
		$tableName = OrmUtils::getTableName ( $className );
		$db = self::getDb ( $className );
		$quote = $db->quote;
		self::parseKey ( $ids, $className, $quote );
		$condition = SqlUtils::getCondition ( $ids, $className );
		$keyFields = OrmUtils::getKeyFields ( $className );
		if (is_array ( $keyFields )) {
			$keys = implode ( ",", $keyFields );
		} else {
			$keys = "1";
		}

		return $db->queryColumn ( "SELECT num FROM (SELECT *, @rownum:=@rownum + 1 AS num FROM {$quote}{$tableName}{$quote}, (SELECT @rownum:=0) r ORDER BY {$keys}) d WHERE " . $condition );
	}

	/**
	 * Returns the number of objects of $className from the database respecting the condition possibly passed as parameter
	 *
	 * @param string $className complete classname of the model to load
	 * @param string $condition Part following the WHERE of an SQL statement
	 * @param array|null $parameters The query parameters
	 * @return int|false count of objects
	 */
	public static function count($className, $condition = '', $parameters = null) {
		$tableName = OrmUtils::getTableName ( $className );
		if ($condition != '') {
			$condition = " WHERE " . $condition;
		}
		$db = self::getDb ( $className );
		$quote = $db->quote;
		return $db->prepareAndFetchColumn ( "SELECT COUNT(*) FROM " . $quote . $tableName . $quote . $condition, $parameters );
	}

	/**
	 * Returns an instance of $className from the database, from $keyvalues values of the primary key or with a condition
	 *
	 * @param String $className complete classname of the model to load
	 * @param Array|string $condition condition or primary key values
	 * @param boolean|array $included if true, charges associate members with association
	 * @param array|null $parameters the request parameters
	 * @param boolean|null $useCache use cache if true
	 * @return object the instance loaded or null if not found
	 */
	public static function getOne($className, $condition, $included = true, $parameters = null, $useCache = NULL) {
		$conditionParser = new ConditionParser ();
		if (! isset ( $parameters )) {
			$conditionParser->addKeyValues ( $condition, $className );
		} elseif (! is_array ( $condition )) {
			$conditionParser->setCondition ( $condition );
			$conditionParser->setParams ( $parameters );
		} else {
			throw new DAOException ( "The \$keyValues parameter should not be an array if \$parameters is not null" );
		}
		return self::_getOne ( self::getDb ( $className ), $className, $conditionParser, $included, $useCache );
	}

	/**
	 * Returns an instance of $className from the database, from $keyvalues values of the primary key
	 *
	 * @param String $className complete classname of the model to load
	 * @param Array|string $keyValues primary key values or condition
	 * @param boolean|array $included if true, charges associate members with association
	 * @param array|null $parameters the request parameters
	 * @param boolean|null $useCache use cache if true
	 * @return object the instance loaded or null if not found
	 */
	public static function getById($className, $keyValues, $included = true, $useCache = NULL) {
		return self::_getOne ( self::getDb ( $className ), $className, self::getConditionParser ( $className, $keyValues ), $included, $useCache );
	}

	protected static function getConditionParser($className, $keyValues) {
		if (! isset ( self::$conditionParsers [$className] )) {
			$conditionParser = new ConditionParser ();
			$conditionParser->addKeyValues ( $keyValues, $className );
			self::$conditionParsers [$className] = $conditionParser;
		} else {
			self::$conditionParsers [$className]->setKeyValues ( $keyValues );
		}
		return self::$conditionParsers [$className];
	}

	/**
	 * Establishes the connection to the database using the $config array
	 */
	public static function startDatabase($offset = null) {
		self::getDatabase ( $offset );
	}

	public static function getDbOffset(&$config, $offset = null) {
		return $offset ? ($config ['database'] [$offset] ?? ($config ['database'] ?? [ ])) : ($config ['database'] ['default'] ?? $config ['database']);
	}

	/**
	 * Returns true if the connection to the database is established
	 *
	 * @return boolean
	 */
	public static function isConnected($offset = 'default') {
		$db = self::getDatabase ( $offset );
		return $db && ($db instanceof Database) && $db->isConnected ();
	}

	/**
	 * Sets the transformer operation
	 *
	 * @param string $op
	 */
	public static function setTransformerOp($op) {
		self::$transformerOp = $op;
	}

	/**
	 * Closes the active pdo connection to the database
	 */
	public static function closeDb($offset = 'default') {
		$db = self::getDatabase ( $offset );
		if ($db !== false) {
			$db->close ();
		}
	}

	/**
	 * Defines the database connection to use for $model class
	 *
	 * @param string $model a model class
	 * @param string $database a database connection defined in config.php
	 */
	public static function setModelDatabase($model, $database = 'default') {
		self::$modelsDatabase [$model] = $database;
	}

	/**
	 * Defines the database connections to use for models classes
	 *
	 * @param array $modelsDatabase
	 */
	public static function setModelsDatabases($modelsDatabase) {
		self::$modelsDatabase = $modelsDatabase;
	}

	/**
	 * Returns the database instance defined at $offset key in config
	 *
	 * @param string $offset
	 * @return \Ubiquity\db\Database
	 */
	public static function getDatabase($offset = 'default') {
		$db = self::getPool ( Startup::$config, $offset )->get ();
		SqlUtils::$quote = $db->quote;
		return $db;
	}

	public static function getDatabases() {
		$config = Startup::getConfig ();
		if (isset ( $config ['database'] )) {
			if (isset ( $config ['database'] ['dbName'] )) {
				return [ 'default' ];
			} else {
				return \array_keys ( $config ['database'] );
			}
		}
		return [ ];
	}

	public static function updateDatabaseParams(array &$config, array $parameters, $offset = 'default') {
		if ($offset === 'default') {
			if (isset ( $config ['database'] [$offset] )) {
				foreach ( $parameters as $k => $param ) {
					$config ['database'] [$offset] [$k] = $param;
				}
			} else {
				foreach ( $parameters as $k => $param ) {
					$config ['database'] [$k] = $param;
				}
			}
		} else {
			if (isset ( $config ['database'] [$offset] )) {
				foreach ( $parameters as $k => $param ) {
					$config ['database'] [$offset] [$k] = $param;
				}
			}
		}
	}

	public static function start() {
		self::$modelsDatabase = CacheManager::getModelsDatabases ();
	}

	/**
	 * gets a new DbConnection from pool
	 *
	 * @param string $offset
	 * @return mixed
	 */
	public static function pool($offset = 'default') {
		return self::getDatabase ( $offset );
	}

	public static function freePool($db, $offset = 'default') {
		$pool = self::getPool ( Startup::$config, $offset );
		$pool->put ( $db );
	}
}
