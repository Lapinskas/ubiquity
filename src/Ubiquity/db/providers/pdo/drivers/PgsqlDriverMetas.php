<?php

namespace Ubiquity\db\providers\pdo\drivers;

/**
 * Ubiquity\db\providers\pdo\drivers$PgsqlDriverMetas
 * This class is part of Ubiquity
 *
 * @author
 * @version 1.0.0
 *
 */
class PgsqlDriverMetas extends AbstractDriverMetaDatas {

	public function getForeignKeys($tableName, $pkName, $dbName = null): array {
		// TODO
	}

	public function getTablesName(): array {
		// TODO
	}

	public function getPrimaryKeys($tableName): array {
		// TODO
	}

	public function getFieldsInfos($tableName): array {
		// TODO
	}
}
