<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests;

use PhpMyAdmin\ConfigStorage\Relation;
use PhpMyAdmin\Database\DatabaseList;
use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Dbal\ResultInterface;
use PhpMyAdmin\Query\Utilities;
use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SystemDatabase;
use PhpMyAdmin\Utils\SessionCache;
use stdClass;

/**
 * @covers \PhpMyAdmin\DatabaseInterface
 */
class DatabaseInterfaceTest extends AbstractTestCase
{
    /**
     * Configures test parameters.
     */
    protected function setUp(): void
    {
        parent::setUp();
        parent::setGlobalDbi();
        $GLOBALS['server'] = 0;
    }

    /**
     * Tear down function for mockResponse method
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        unset($GLOBALS['lang']);
        unset($GLOBALS['cfg']['Server']['SessionTimeZone']);
        Context::load();
    }

    /**
     * Tests for DBI::getCurrentUser() method.
     *
     * @param array|false $value           value
     * @param string      $string          string
     * @param array       $expected        expected result
     * @param bool        $needsSecondCall The test will need to call another time the DB
     *
     * @dataProvider currentUserData
     */
    public function testGetCurrentUser($value, string $string, array $expected, bool $needsSecondCall): void
    {
        SessionCache::remove('mysql_cur_user');

        $this->dummyDbi->addResult('SELECT CURRENT_USER();', $value);
        if ($needsSecondCall) {
            $this->dummyDbi->addResult('SELECT CURRENT_USER();', $value);
        }

        $this->assertEquals(
            $expected,
            $this->dbi->getCurrentUserAndHost()
        );

        $this->assertEquals(
            $string,
            $this->dbi->getCurrentUser()
        );

        $this->assertAllQueriesConsumed();
    }

    /**
     * Data provider for getCurrentUser() tests.
     *
     * @return array
     */
    public function currentUserData(): array
    {
        return [
            [
                [['pma@localhost']],
                'pma@localhost',
                [
                    'pma',
                    'localhost',
                ],
                false,
            ],
            [
                [['@localhost']],
                '@localhost',
                [
                    '',
                    'localhost',
                ],
                false,
            ],
            [
                false,
                '@',
                [
                    '',
                    '',
                ],
                true,
            ],
        ];
    }

    /**
     * Tests for DBI::getColumnMapFromSql() method.
     */
    public function testPMAGetColumnMap(): void
    {
        $this->dummyDbi->addResult(
            'PMA_sql_query',
            [true],
            [],
            [
                (object) [
                    'table' => 'meta1_table',
                    'name' => 'meta1_name',
                ],
                (object) [
                    'table' => 'meta2_table',
                    'name' => 'meta2_name',
                ],
            ]
        );

        $sql_query = 'PMA_sql_query';
        $view_columns = [
            'view_columns1',
            'view_columns2',
        ];

        $column_map = $this->dbi->getColumnMapFromSql($sql_query, $view_columns);

        $this->assertEquals(
            [
                'table_name' => 'meta1_table',
                'refering_column' => 'meta1_name',
                'real_column' => 'view_columns1',
            ],
            $column_map[0]
        );
        $this->assertEquals(
            [
                'table_name' => 'meta2_table',
                'refering_column' => 'meta2_name',
                'real_column' => 'view_columns2',
            ],
            $column_map[1]
        );

        $this->assertAllQueriesConsumed();
    }

    /**
     * Tests for DBI::getSystemDatabase() method.
     */
    public function testGetSystemDatabase(): void
    {
        $sd = $this->dbi->getSystemDatabase();
        $this->assertInstanceOf(SystemDatabase::class, $sd);
    }

    /**
     * Tests for DBI::postConnectControl() method.
     */
    public function testPostConnectControl(): void
    {
        parent::setGlobalDbi();
        $this->dummyDbi->addResult(
            'SHOW TABLES FROM `phpmyadmin`;',
            []
        );
        $GLOBALS['db'] = '';
        $GLOBALS['cfg']['Server']['only_db'] = [];
        $this->dbi->postConnectControl(new Relation($this->dbi));
        $this->assertInstanceOf(DatabaseList::class, $GLOBALS['dblist']);
    }

    /**
     * Tests for DBI::postConnect() method.
     * should not call setVersion method if cannot fetch version
     */
    public function testPostConnectShouldNotCallSetVersionIfNoVersion(): void
    {
        $GLOBALS['lang'] = 'en';
        $GLOBALS['cfg']['Server']['SessionTimeZone'] = '';

        $mock = $this->getMockBuilder(DatabaseInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchSingleRow', 'query', 'setVersion'])
            ->getMock();

        $mock->expects($this->once())
            ->method('fetchSingleRow')
            ->will($this->returnValue(null));

        $mock->expects($this->never())->method('setVersion');

        $mock->postConnect();
    }

    /**
     * Tests for DBI::postConnect() method.
     * should call setVersion method if $version has value
     */
    public function testPostConnectShouldCallSetVersionOnce(): void
    {
        $GLOBALS['lang'] = 'en';
        $GLOBALS['cfg']['Server']['SessionTimeZone'] = '';
        $versionQueryResult = [
            '@@version' => '10.20.7-MariaDB-1:10.9.3+maria~ubu2204',
            '@@version_comment' => 'mariadb.org binary distribution',
        ];

        $mock = $this->getMockBuilder(DatabaseInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchSingleRow', 'query', 'setVersion'])
            ->getMock();

        $mock->expects($this->once())
            ->method('fetchSingleRow')
            ->will($this->returnValue($versionQueryResult));

        $mock->expects($this->once())->method('setVersion')->with($versionQueryResult);

        $mock->postConnect();
    }

    /**
     * Tests for DBI::postConnect() method.
     * should set version int, isMariaDB and isPercona
     *
     * @param array $version    Database version
     * @param int   $versionInt Database version as integer
     * @param bool  $isMariaDb  True if mariadb
     * @param bool  $isPercona  True if percona
     * @phpstan-param array<array-key, mixed> $version
     *
     * @dataProvider provideDatabaseVersionData
     */
    public function testPostConnectShouldSetVersion(
        array $version,
        int $versionInt,
        bool $isMariaDb,
        bool $isPercona
    ): void {
        $GLOBALS['lang'] = 'en';
        $GLOBALS['cfg']['Server']['SessionTimeZone'] = '';

        $mock = $this->getMockBuilder(DatabaseInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchSingleRow', 'query'])
            ->getMock();

        $mock->expects($this->once())
            ->method('fetchSingleRow')
            ->will($this->returnValue($version));

        $mock->postConnect();

        $this->assertEquals($mock->getVersion(), $versionInt);
        $this->assertEquals($mock->isMariaDB(), $isMariaDb);
        $this->assertEquals($mock->isPercona(), $isPercona);
    }

    /**
     * Test for getDbCollation
     */
    public function testGetDbCollation(): void
    {
        $GLOBALS['server'] = 1;
        // test case for system schema
        $this->assertEquals(
            'utf8_general_ci',
            $this->dbi->getDbCollation('information_schema')
        );

        $GLOBALS['cfg']['Server']['DisableIS'] = false;
        $GLOBALS['cfg']['DBG']['sql'] = false;

        $this->assertEquals(
            'utf8_general_ci',
            $this->dbi->getDbCollation('pma_test')
        );
    }

    /**
     * Test for getServerCollation
     */
    public function testGetServerCollation(): void
    {
        $GLOBALS['server'] = 1;
        $GLOBALS['cfg']['DBG']['sql'] = true;
        $this->assertEquals('utf8_general_ci', $this->dbi->getServerCollation());
    }

    /**
     * Test error formatting
     *
     * @param int    $error_number  Error code
     * @param string $error_message Error message as returned by server
     * @param string $match         Expected text
     *
     * @dataProvider errorData
     */
    public function testFormatError(int $error_number, string $error_message, string $match): void
    {
        $this->assertStringContainsString(
            $match,
            Utilities::formatError($error_number, $error_message)
        );
    }

    public function errorData(): array
    {
        return [
            [
                2002,
                'msg',
                'The server is not responding',
            ],
            [
                2003,
                'msg',
                'The server is not responding',
            ],
            [
                1698,
                'msg',
                'index.php?route=/logout',
            ],
            [
                1005,
                'msg',
                'index.php?route=/server/engines',
            ],
            [
                1005,
                'errno: 13',
                'Please check privileges',
            ],
            [
                -1,
                'error message',
                'error message',
            ],
        ];
    }

    /**
     * Tests for DBI::isAmazonRds() method.
     *
     * @param array $value    value
     * @param bool  $expected expected result
     *
     * @dataProvider isAmazonRdsData
     */
    public function testIsAmazonRdsData(array $value, bool $expected): void
    {
        SessionCache::remove('is_amazon_rds');

        $this->dummyDbi->addResult('SELECT @@basedir', $value);

        $this->assertEquals(
            $expected,
            $this->dbi->isAmazonRds()
        );

        $this->assertAllQueriesConsumed();
    }

    /**
     * Data provider for isAmazonRds() tests.
     *
     * @return array
     */
    public function isAmazonRdsData(): array
    {
        return [
            [
                [['/usr']],
                false,
            ],
            [
                [['E:/mysql']],
                false,
            ],
            [
                [['/rdsdbbin/mysql/']],
                true,
            ],
            [
                [['/rdsdbbin/mysql-5.7.18/']],
                true,
            ],
        ];
    }

    /**
     * Test for version parsing
     *
     * @param string $version  version to parse
     * @param int    $expected expected numeric version
     * @param int    $major    expected major version
     * @param bool   $upgrade  whether upgrade should ne needed
     *
     * @dataProvider versionData
     */
    public function testVersion(string $version, int $expected, int $major, bool $upgrade): void
    {
        $ver_int = Utilities::versionToInt($version);
        $this->assertEquals($expected, $ver_int);
        $this->assertEquals($major, (int) ($ver_int / 10000));
        $this->assertEquals($upgrade, $ver_int < $GLOBALS['cfg']['MysqlMinVersion']['internal']);
    }

    public function versionData(): array
    {
        return [
            [
                '5.0.5',
                50005,
                5,
                true,
            ],
            [
                '5.05.01',
                50501,
                5,
                false,
            ],
            [
                '5.6.35',
                50635,
                5,
                false,
            ],
            [
                '10.1.22-MariaDB-',
                100122,
                10,
                false,
            ],
        ];
    }

    /**
     * Tests for DBI::setCollation() method.
     */
    public function testSetCollation(): void
    {
        $this->dummyDbi->addResult('SET collation_connection = \'utf8_czech_ci\';', [true]);
        $this->dummyDbi->addResult('SET collation_connection = \'utf8mb4_bin_ci\';', [true]);
        $this->dummyDbi->addResult('SET collation_connection = \'utf8_czech_ci\';', [true]);
        $this->dummyDbi->addResult('SET collation_connection = \'utf8_bin_ci\';', [true]);

        $GLOBALS['charset_connection'] = 'utf8mb4';
        $this->dbi->setCollation('utf8_czech_ci');
        $this->dbi->setCollation('utf8mb4_bin_ci');
        $GLOBALS['charset_connection'] = 'utf8';
        $this->dbi->setCollation('utf8_czech_ci');
        $this->dbi->setCollation('utf8mb4_bin_ci');

        $this->assertAllQueriesConsumed();
    }

    public function testGetTablesFull(): void
    {
        $GLOBALS['cfg']['Server']['DisableIS'] = true;

        $expected = [
            'test_table' => [
                'Name' => 'test_table',
                'Engine' => 'InnoDB',
                'Version' => '10',
                'Row_format' => 'Dynamic',
                'Rows' => '3',
                'Avg_row_length' => '5461',
                'Data_length' => '16384',
                'Max_data_length' => '0',
                'Index_length' => '0',
                'Data_free' => '0',
                'Auto_increment' => '4',
                'Create_time' => '2011-12-13 14:15:16',
                'Update_time' => null,
                'Check_time' => null,
                'Collation' => 'utf8mb4_general_ci',
                'Checksum' => null,
                'Create_options' => '',
                'Comment' => '',
                'Max_index_length' => '0',
                'Temporary' => 'N',
                'Type' => 'InnoDB',
                'TABLE_SCHEMA' => 'test_db',
                'TABLE_NAME' => 'test_table',
                'ENGINE' => 'InnoDB',
                'VERSION' => '10',
                'ROW_FORMAT' => 'Dynamic',
                'TABLE_ROWS' => '3',
                'AVG_ROW_LENGTH' => '5461',
                'DATA_LENGTH' => '16384',
                'MAX_DATA_LENGTH' => '0',
                'INDEX_LENGTH' => '0',
                'DATA_FREE' => '0',
                'AUTO_INCREMENT' => '4',
                'CREATE_TIME' => '2011-12-13 14:15:16',
                'UPDATE_TIME' => null,
                'CHECK_TIME' => null,
                'TABLE_COLLATION' => 'utf8mb4_general_ci',
                'CHECKSUM' => null,
                'CREATE_OPTIONS' => '',
                'TABLE_COMMENT' => '',
                'TABLE_TYPE' => 'BASE TABLE',
            ],
        ];

        $actual = $this->dbi->getTablesFull('test_db');
        $this->assertEquals($expected, $actual);
    }

    public function testGetTablesFullWithInformationSchema(): void
    {
        $GLOBALS['cfg']['Server']['DisableIS'] = false;

        $expected = [
            'test_table' => [
                'TABLE_CATALOG' => 'def',
                'TABLE_SCHEMA' => 'test_db',
                'TABLE_NAME' => 'test_table',
                'TABLE_TYPE' => 'BASE TABLE',
                'ENGINE' => 'InnoDB',
                'VERSION' => '10',
                'ROW_FORMAT' => 'Dynamic',
                'TABLE_ROWS' => '3',
                'AVG_ROW_LENGTH' => '5461',
                'DATA_LENGTH' => '16384',
                'MAX_DATA_LENGTH' => '0',
                'INDEX_LENGTH' => '0',
                'DATA_FREE' => '0',
                'AUTO_INCREMENT' => '4',
                'CREATE_TIME' => '2011-12-13 14:15:16',
                'UPDATE_TIME' => null,
                'CHECK_TIME' => null,
                'TABLE_COLLATION' => 'utf8mb4_general_ci',
                'CHECKSUM' => null,
                'CREATE_OPTIONS' => '',
                'TABLE_COMMENT' => '',
                'MAX_INDEX_LENGTH' => '0',
                'TEMPORARY' => 'N',
                'Db' => 'test_db',
                'Name' => 'test_table',
                'Engine' => 'InnoDB',
                'Type' => 'InnoDB',
                'Version' => '10',
                'Row_format' => 'Dynamic',
                'Rows' => '3',
                'Avg_row_length' => '5461',
                'Data_length' => '16384',
                'Max_data_length' => '0',
                'Index_length' => '0',
                'Data_free' => '0',
                'Auto_increment' => '4',
                'Create_time' => '2011-12-13 14:15:16',
                'Update_time' => null,
                'Check_time' => null,
                'Collation' => 'utf8mb4_general_ci',
                'Checksum' => null,
                'Create_options' => '',
                'Comment' => '',
            ],
        ];

        $actual = $this->dbi->getTablesFull('test_db');
        $this->assertEquals($expected, $actual);
    }

    /**
     * Test for queryAsControlUser
     */
    public function testQueryAsControlUser(): void
    {
        $sql = 'insert into PMA_bookmark A,B values(1, 2)';
        $this->dummyDbi->addResult($sql, [true]);
        $this->dummyDbi->addResult($sql, [true]);
        $this->dummyDbi->addResult('Invalid query', false);

        $this->assertInstanceOf(
            ResultInterface::class,
            $this->dbi->queryAsControlUser($sql)
        );
        $this->assertInstanceOf(
            ResultInterface::class,
            $this->dbi->tryQueryAsControlUser($sql)
        );
        $this->assertFalse($this->dbi->tryQueryAsControlUser('Invalid query'));
    }

    public function testGetDatabasesFullDisabledISAndSortIntColumn(): void
    {
        parent::setGlobalDbi();

        $GLOBALS['db'] = '';
        $GLOBALS['table'] = '';
        $GLOBALS['server'] = 1;
        $GLOBALS['cfg']['Server']['DisableIS'] = true;
        $GLOBALS['cfg']['NaturalOrder'] = true;
        $GLOBALS['dblist'] = new stdClass();
        $GLOBALS['dblist']->databases = [
            'db1',
            'db2',
        ];
        $this->dummyDbi->removeDefaultResults();
        $this->dummyDbi->addResult(
            'SELECT @@collation_database',
            [
                ['utf8_general_ci'],
            ],
            ['@@collation_database']
        );
        $this->dummyDbi->addResult(
            'SELECT @@collation_database',
            [
                ['utf8_general_ci'],
            ],
            ['@@collation_database']
        );
        $this->dummyDbi->addResult(
            'SHOW TABLE STATUS FROM `db1`;',
            [
                [
                    'pma__bookmark',
                    'InnoDB',
                    10,
                    'Dynamic',
                    0,
                    0,
                    16384,
                    0,
                    0,
                    0,
                    1,
                    '2021-08-27 14:11:52',
                    null,
                    null,
                    'utf8_bin',
                    null,
                    'Bookmarks',
                ],
                [
                    'pma__central_columns',
                    'InnoDB',
                    10,
                    'Dynamic',
                    0,
                    0,
                    16384,
                    0,
                    0,
                    0,
                    null,
                    '2021-08-27 14:11:52',
                    null,
                    null,
                    'utf8_bin',
                    null,
                    'Central list of columns',
                ],
            ],
            [
                'Name',
                'Engine',
                'Version',
                'Row_format',
                'Rows',
                'Avg_row_length',
                'Data_length',
                'Max_data_length',
                'Index_length',
                'Data_free',
                'Auto_increment',
                'Create_time',
                'Update_time',
                'Check_time',
                'Collation',
                'Checksum',
                'Create_options',
                'Comment',
            ]
        );

        $this->dummyDbi->addResult(
            'SHOW TABLE STATUS FROM `db2`;',
            [
                [
                    'pma__bookmark',
                    'InnoDB',
                    10,
                    'Dynamic',
                    0,
                    0,
                    16324,
                    0,
                    0,
                    0,
                    1,
                    '2021-08-27 14:11:52',
                    null,
                    null,
                    'utf8_bin',
                    null,
                    'Bookmarks',
                ],
                [
                    'pma__central_columns',
                    'InnoDB',
                    10,
                    'Dynamic',
                    0,
                    0,
                    14384,
                    0,
                    0,
                    0,
                    null,
                    '2021-08-27 14:11:52',
                    null,
                    null,
                    'utf8_bin',
                    null,
                    'Central list of columns',
                ],
            ],
            [
                'Name',
                'Engine',
                'Version',
                'Row_format',
                'Rows',
                'Avg_row_length',
                'Data_length',
                'Max_data_length',
                'Index_length',
                'Data_free',
                'Auto_increment',
                'Create_time',
                'Update_time',
                'Check_time',
                'Collation',
                'Checksum',
                'Create_options',
                'Comment',
            ]
        );
        $this->dummyDbi->addSelectDb('');
        $this->dummyDbi->addSelectDb('');
        $this->dummyDbi->addSelectDb('db1');
        $this->dummyDbi->addSelectDb('db2');

        $databaseList = $this->dbi->getDatabasesFull(
            null,
            true,
            DatabaseInterface::CONNECT_USER,
            'SCHEMA_DATA_LENGTH',
            'ASC',
            0,
            100
        );

        $this->assertSame([
            [
                'SCHEMA_NAME' => 'db2',
                'DEFAULT_COLLATION_NAME' => 'utf8_general_ci',
                'SCHEMA_TABLES' => 2,
                'SCHEMA_TABLE_ROWS' => 0,
                'SCHEMA_DATA_LENGTH' => 30708,
                'SCHEMA_MAX_DATA_LENGTH' => 0,
                'SCHEMA_INDEX_LENGTH' => 0,
                'SCHEMA_LENGTH' => 30708,
                'SCHEMA_DATA_FREE' => 0,
            ],
            [
                'SCHEMA_NAME' => 'db1',
                'DEFAULT_COLLATION_NAME' => 'utf8_general_ci',
                'SCHEMA_TABLES' => 2,
                'SCHEMA_TABLE_ROWS' => 0,
                'SCHEMA_DATA_LENGTH' => 32768,
                'SCHEMA_MAX_DATA_LENGTH' => 0,
                'SCHEMA_INDEX_LENGTH' => 0,
                'SCHEMA_LENGTH' => 32768,
                'SCHEMA_DATA_FREE' => 0,
            ],
        ], $databaseList);

        $this->assertAllQueriesConsumed();
    }

    /**
     * Tests for setVersion method.
     *
     * @param array $version    Database version
     * @param int   $versionInt Database version as integer
     * @param bool  $isMariaDb  True if mariadb
     * @param bool  $isPercona  True if percona
     * @phpstan-param array<array-key, mixed> $version
     *
     * @dataProvider provideDatabaseVersionData
     */
    public function testSetVersion(
        array $version,
        int $versionInt,
        bool $isMariaDb,
        bool $isPercona
    ): void {
        $this->dbi->setVersion($version);

        $this->assertEquals($versionInt, $this->dbi->getVersion());
        $this->assertEquals($isMariaDb, $this->dbi->isMariaDB());
        $this->assertEquals($isPercona, $this->dbi->isPercona());
        $this->assertEquals($version['@@version'], $this->dbi->getVersionString());
    }

    /**
     * Data provider for setVersion() tests.
     *
     * @return array
     * @psalm-return array<int, array{array<array-key, mixed>, int, bool, bool}>
     */
    public function provideDatabaseVersionData(): array
    {
        return [
            [
                [
                    '@@version' => '6.1.0',
                    '@@version_comment' => "Percona Server (GPL), Release '11', Revision 'c1y2gr1df4a'",
                ],
                60100,
                false,
                true,
            ],
            [
                [
                    '@@version' => '10.01.40-MariaDB-1:10.01.40+maria~ubu2204',
                    '@@version_comment' => 'mariadb.org binary distribution',
                ],
                100140,
                true,
                false,
            ],
            [
                [
                    '@@version' => '7.10.3',
                    '@@version_comment' => 'MySQL Community Server (GPL)',
                ],
                71003,
                false,
                false,
            ],
            [
                [
                    '@@version' => '5.5.0',
                    '@@version_comment' => '',
                ],
                50500,
                false,
                false,
            ],
        ];
    }
}