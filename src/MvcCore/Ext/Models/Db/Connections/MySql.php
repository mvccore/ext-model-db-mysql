<?php

/**
 * MvcCore
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flidr (https://github.com/mvccore)
 * @license		https://mvccore.github.io/docs/mvccore/5.0.0/LICENSE.md
 */

namespace MvcCore\Ext\Models\Db\Connections;

class		MySql 
extends		\MvcCore\Ext\Models\Db\Connection
implements	\MvcCore\Ext\Models\Db\Model\IConstants,
			\MvcCore\Ext\Models\Db\Models\MySql\IConstants {

	/**
	 * MySQL autocommit for current session.
	 * @var bool
	 */
	protected $autocommit = TRUE;
	
	/**
	 * `TRUE` for MariaDB server connection, FALSE for MySQL or Percona Server connections.
	 * @var bool|NULL
	 */
	protected $mariadb = NULL;

	/**
	 * `TRUE` for SQL `READ WRITE` or `READ ONLY` start transaction property support.
	 * @var bool|NULL
	 */
	protected $transReadWriteSupport = NULL;


	/**
	 * Return `TRUE` if server is MariaDB.
	 * @return bool|null
	 */
	public function IsMariaDb () {
		return $this->mariadb;
	}

	/**
	 * Return `TRUE` if server is MySQL or Percona Server.
	 * @return bool
	 */
	public function IsMySqlOrPercona () {
		return !$this->mariadb;
	}

	
	/**
	 * @inheritDocs
	 * @param  string $identifierName
	 * @return string
	 */
	public function QuoteName ($identifierName) {
		if (mb_substr($identifierName, 0, 1) !== '`' && mb_substr($identifierName, -1, 1) !== '`') {
			if (mb_strpos($identifierName, '.') !== FALSE) 
				return '`'.str_replace('.', '`.`', $identifierName).'`';
			return '`'.$identifierName.'`';
		}
		return $identifierName;
	}

	
	/**
	 * @inheritDocs
	 * @param  int    $flags Transaction isolation, read/write mode and consistent snapshot option.
	 * @param  string $name  String without spaces to identify transaction in logs.
	 * @throws \PDOException|\RuntimeException
	 * @return bool
	 */
	public function BeginTransaction ($flags = 0, $name = NULL) {
		if ($flags === 0) $flags = self::TRANS_READ_WRITE;

		$transRepeatableRead = ($flags & self::TRANS_ISOLATION_REPEATABLE_READ) > 0;
		$consistentSnapshot = (
			$transRepeatableRead &&
			($flags & self::TRANS_CONSISTENT_SHAPSHOT) > 0
		);

		$readWrite = NULL;
		if (($flags & self::TRANS_READ_WRITE) > 0) {
			$readWrite = TRUE;
		} else if (($flags & self::TRANS_READ_ONLY) > 0) {
			$readWrite = FALSE;
		}

		if ($this->inTransaction) {
			$cfg = $this->GetConfig();
			unset($cfg->password);
			$toolClass = \MvcCore\Application::GetInstance()->GetToolClass();
			throw new \RuntimeException(
				'Connection has opened transaction already ('.($toolClass::JsonEncode($cfg)).').'
			);
		}

		$sqlItems = [];

		$startTransPropsSeparator = '';
		$snapshotStr = '';
		$writeStr = '';
		
		if ($consistentSnapshot) 
			$snapshotStr = ' WITH CONSISTENT SNAPSHOT';
		
		if ($this->autocommit) {
			$this->autocommit = FALSE;
			$sqlItems[] = 'SET SESSION autocommit = 0;';
		}

		if ($this->transReadWriteSupport) {
			if ($readWrite === TRUE) {
				$writeStr = ' READ WRITE';
				if ($consistentSnapshot)
					$startTransPropsSeparator = ',';
			} else if ($readWrite === FALSE) {
				$writeStr = ' READ ONLY';
				if ($consistentSnapshot)
					$startTransPropsSeparator = ',';
			}
		}

		$transStartProperties = implode($startTransPropsSeparator, [$snapshotStr, $writeStr]);

		if ($transRepeatableRead) {
			$sqlItems[] = 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;';
		} else if (($flags & self::TRANS_ISOLATION_READ_COMMITTED) > 0) {
			$sqlItems[] = 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;';
		} else if (($flags & self::TRANS_ISOLATION_READ_UNCOMMITTED) > 0) {
			$sqlItems[] = 'SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;';
		} else if (($flags & self::TRANS_ISOLATION_SERIALIZABLE) > 0) {
			$sqlItems[] = 'SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE;';
		}

		if ($name === NULL) {
			$this->transactionName = 'mysql_tran_' . str_replace('.', '', uniqid('', TRUE));
		} else {
			$toolClass = \MvcCore\Application::GetInstance()->GetToolClass();
			$this->transactionName = $toolClass::GetUnderscoredFromPascalCase($name);
		}
		$sqlItems[] = "/* trans_start:{$this->transactionName} */";

		// examples:"START TRANSACTION WITH CONSISTENT SNAPSHOT, READ WRITE;" or
		//			"START TRANSACTION READ WRITE;" or
		//			"START TRANSACTION READ ONLY;" or ...
		$sqlItems[] = "START TRANSACTION{$transStartProperties};";
		
		$debugging = $this->debugger !== NULL;
		if ($this->multiStatements) {
			if ($debugging) $reqTime = microtime(TRUE);
			$this->provider->exec(implode(" \n", $sqlItems));
			if ($debugging) 
				$this->debugger->AddQuery(
					implode("\n", $sqlItems), [], $reqTime, microtime(TRUE), $this
				);
		} else {
			foreach ($sqlItems as $sqlItem) {
				if ($debugging) $reqTime = microtime(TRUE);
				$this->provider->exec($sqlItem);
				if ($debugging) 
					$this->debugger->AddQuery(
						$sqlItem, [], $reqTime, microtime(TRUE), $this
					);
			}
		}

		$this->inTransaction = TRUE;

		return TRUE;
	}

	/**
	 * @inheritDocs
	 * @param  int $flags Transaction chaininig.
	 * @throws \PDOException
	 * @return bool
	 */
	public function Commit ($flags = 0) {
		if (!$this->inTransaction) return FALSE;
		$sqlItems = [];
		$chain = NULL;
		$chainSql = '';

		if (($flags & self::TRANS_CHAIN) > 0) {
			$chain = TRUE;
			$chainSql = ' AND CHAIN';
		} else if (($flags & self::TRANS_NO_CHAIN) > 0) {
			$chain = FALSE;
			$chainSql = ' AND NO CHAIN';
		}

		$sqlItems[] = "/* trans_commit:{$this->transactionName} */";
		$sqlItems[] = "COMMIT{$chainSql};";

		if (!$chain && !$this->autocommit) {
			$this->autocommit = TRUE;
			$sqlItems[] = 'SET SESSION autocommit = 1;';
		}
		
		$debugging = $this->debugger !== NULL;
		if ($this->multiStatements) {
			if ($debugging) $reqTime = microtime(TRUE);
			$this->provider->exec(implode(" \n", $sqlItems));
			if ($debugging) 
				$this->debugger->AddQuery(
					implode("\n", $sqlItems), [], $reqTime, microtime(TRUE), $this
				);
		} else {
			foreach ($sqlItems as $sqlItem) {
				if ($debugging) $reqTime = microtime(TRUE);
				$this->provider->exec($sqlItem);
				if ($debugging) 
					$this->debugger->AddQuery(
						$sqlItem, [], $reqTime, microtime(TRUE), $this
					);
			}
		}
		
		if ($chain) {
			$this->inTransaction  = TRUE;
		} else {
			$this->inTransaction  = FALSE;
			$this->transactionName = NULL;
		}

		return TRUE;
	}

	/**
	 * Rolls back a transaction.
	 * @param  int $flags Transaction chaininig.
	 * @throws \PDOException
	 * @return bool
	 */
	public function RollBack ($flags = NULL) {
		if (!$this->inTransaction) return FALSE;
		$sqlItems = [];
		$chain = NULL;
		$chainSql = '';

		if (($flags & self::TRANS_CHAIN) > 0) {
			$chain = TRUE;
			$chainSql = ' AND CHAIN';
		} else if (($flags & self::TRANS_NO_CHAIN) > 0) {
			$chain = FALSE;
			$chainSql = ' AND NO CHAIN';
		}

		$sqlItems[] = "/* trans_rollback:{$this->transactionName} */";
		$sqlItems[] = "ROLLBACK{$chainSql};";

		if (!$chain && !$this->autocommit) {
			$this->autocommit = TRUE;
			$sqlItems[] = 'SET SESSION autocommit = 1;';
		}
		
		$debugging = $this->debugger !== NULL;
		if ($this->multiStatements) {
			if ($debugging) $reqTime = microtime(TRUE);
			$this->provider->exec(implode(" \n", $sqlItems));
			if ($debugging) 
				$this->debugger->AddQuery(
					implode("\n", $sqlItems), [], $reqTime, microtime(TRUE), $this
				);
		} else {
			foreach ($sqlItems as $sqlItem) {
				if ($debugging) $reqTime = microtime(TRUE);
				$this->provider->exec($sqlItem);
				if ($debugging) 
					$this->debugger->AddQuery(
						$sqlItem, [], $reqTime, microtime(TRUE), $this
					);
			}
		}

		if ($chain) {
			$this->inTransaction  = TRUE;
		} else {
			$this->inTransaction  = FALSE;
			$this->transactionName = NULL;
		}

		return TRUE;
	}



	/**
	 * @inheritDocs
	 * @see https://stackoverflow.com/questions/7942154/mysql-error-2006-mysql-server-has-gone-away
	 * @param  \Throwable $e 
	 * @return bool
	 */
	protected function isConnectionLost (\Throwable $e) {
		$prevError = $e->getPrevious();
		$error = $prevError instanceof \PDOException
			? $prevError
			: $e;
		return (
			$error instanceof \PDOException &&
			mb_strpos(mb_strtolower($e->getMessage()), 'server has gone away') !== FALSE
		);
	}

	/**
	 * Set up connection specific properties depends on this driver.
	 * @return void
	 */
	protected function setUpConnectionSpecifics () {
		parent::setUpConnectionSpecifics();

		$mariaDbPos = mb_strpos($this->version, 'mariadb');
		if ($mariaDbPos !== FALSE) {
			$this->mariadb = TRUE;
			$this->version = trim(mb_substr($this->version, 0, $mariaDbPos), '-');
			$dashPos = mb_strrpos($this->version, '-');
			if ($dashPos !== FALSE) 
				$this->version = mb_substr($this->version, $dashPos + 1);
		} else {
			$this->mariadb = FALSE;
			$dashPos = mb_strrpos($this->version, '-');
			if ($dashPos !== FALSE) 
				$this->version = mb_substr($this->version, $dashPos + 1);
		}

		$this->transReadWriteSupport = (
			$this->mariadb || (
				!$this->mariadb &&
				version_compare($this->version, '5.6.0', '>')
			)
		);

		$multiStatementsConst = '\PDO::MYSQL_ATTR_MULTI_STATEMENTS';
		$multiStatementsConstVal = defined($multiStatementsConst) 
			? constant($multiStatementsConst) 
			: 0;
		$this->multiStatements = isset($this->options[$multiStatementsConstVal]);

		if ($this->usingOdbcDriver) 
			$this->metaDataStatement = "SELECT ROW_COUNT() AS `AffectedRows`, LAST_INSERT_ID() AS `LastInsertId;";
	}
}