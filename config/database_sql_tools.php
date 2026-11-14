<?php

function ems_database_sql_backup_directory(): string
{
    $directory = __DIR__ . '/../storage/database_backups';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder cadangan database tidak dapat disiapkan.');
    }
    @chmod($directory, 0700);
    return $directory;
}

function ems_database_sql_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function ems_database_sql_order_create_statements(array $createByName): array
{
    $dependencies = [];
    foreach ($createByName as $key => $statement) {
        $dependencies[$key] = [];
        if (preg_match_all('/\\bREFERENCES\\s+(`(?:``|[^`])+`|[A-Za-z0-9_$]+)/i', $statement, $matches)) {
            foreach ($matches[1] as $reference) {
                $parent = strtolower(trim(str_replace('``', '`', $reference), '`'));
                if ($parent !== $key) $dependencies[$key][$parent] = true;
            }
        }
    }

    $ordered = [];
    $remaining = $dependencies;
    while ($remaining !== []) {
        $ready = [];
        foreach ($remaining as $table => $parents) {
            foreach (array_keys($parents) as $parent) {
                if (!array_key_exists($parent, $dependencies)) {
                    throw new RuntimeException('Dump memiliki foreign key ke tabel yang tidak ikut disertakan: ' . $parent . '.');
                }
            }
            if (array_diff_key($parents, array_fill_keys($ordered, true)) === []) $ready[] = $table;
        }
        if ($ready === []) throw new RuntimeException('Dump memiliki relasi tabel melingkar yang tidak dapat dipulihkan dengan aman.');
        sort($ready, SORT_STRING);
        foreach ($ready as $table) {
            $ordered[] = $table;
            unset($remaining[$table]);
        }
    }
    return array_map(static fn(string $table): string => $createByName[$table], $ordered);
}

function ems_database_sql_write(PDO $pdo, string $path): void
{
    $handle = @fopen($path, 'wb');
    if (!$handle) throw new RuntimeException('Berkas SQL tidak dapat dibuat.');
    $startedTransaction = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        fwrite($handle, "-- Cadangan tabel EMS2. Dibuat " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $viewRows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);
        if ($viewRows !== []) throw new RuntimeException('Database berisi view. Ekspor SQL saat ini hanya mendukung tabel dasar.');
        $tableRows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $tables = array_map(static fn(array $row): string => (string) $row[0], $tableRows);
        sort($tables, SORT_STRING);
        foreach ($tables as $table) {
            fwrite($handle, 'DROP TABLE IF EXISTS ' . ems_database_sql_quote_identifier($table) . ";\n");
        }
        fwrite($handle, "\n");

        $createStatements = [];
        foreach ($tables as $table) {
            $createRow = $pdo->query('SHOW CREATE TABLE ' . ems_database_sql_quote_identifier($table))->fetch(PDO::FETCH_NUM);
            if (!$createRow || empty($createRow[1])) throw new RuntimeException('Struktur tabel tidak dapat diekspor: ' . $table);
            $createStatements[strtolower($table)] = (string) $createRow[1] . ';';
        }
        foreach (ems_database_sql_order_create_statements($createStatements) as $createStatement) {
            fwrite($handle, $createStatement . "\n\n");
        }

        foreach ($tables as $table) {
            $quotedTable = ems_database_sql_quote_identifier($table);
            $binaryTypes = [];
            $columnStmt = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table));
            foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if (in_array(strtolower((string) $column['DATA_TYPE']), ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit'], true)) {
                    $binaryTypes[(string) $column['COLUMN_NAME']] = true;
                }
            }

            $rows = $pdo->query('SELECT * FROM ' . $quotedTable);
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columnNames = array_keys($row);
                $values = [];
                foreach ($row as $column => $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (isset($binaryTypes[$column])) {
                        $values[] = '0x' . bin2hex((string) $value);
                    } else {
                        $values[] = $pdo->quote((string) $value);
                    }
                }
                $columns = implode(', ', array_map('ems_database_sql_quote_identifier', $columnNames));
                fwrite($handle, 'INSERT INTO ' . $quotedTable . ' (' . $columns . ') VALUES (' . implode(', ', $values) . ");\n");
            }
            $rows->closeCursor();
            fwrite($handle, "\n");
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        if ($startedTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
        fclose($handle);
        @unlink($path);
        throw $e;
    }
    fclose($handle);
}

function ems_database_sql_statements(string $path): Generator
{
    $sql = @file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException('Berkas SQL tidak dapat dibaca.');
    $length = strlen($sql);
    $statement = '';
    $quote = '';
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';
        if ($lineComment) {
            if ($char === "\n" || $char === "\r") $lineComment = false;
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') { $blockComment = false; $i++; }
            continue;
        }
        if ($quote !== '') {
            $statement .= $char;
            if ($char === '\\' && $next !== '') { $statement .= $next; $i++; continue; }
            if ($char === $quote) {
                if ($next === $quote) { $statement .= $next; $i++; }
                else $quote = '';
            }
            continue;
        }
        if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
            $lineComment = true;
            if ($char === '-') $i++;
            continue;
        }
        if ($char === '/' && $next === '*') { $blockComment = true; $i++; continue; }
        if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $statement .= $char; continue; }
        if ($char === ';') {
            $trimmed = trim($statement);
            if ($trimmed !== '') yield $trimmed;
            $statement = '';
            continue;
        }
        $statement .= $char;
    }
    if ($quote !== '' || $blockComment) throw new RuntimeException('Dump SQL terpotong atau memiliki kutip yang tidak berpasangan.');
    $trimmed = trim($statement);
    if ($trimmed !== '') yield $trimmed;
}

function ems_database_sql_table_name(string $statement, string $verb): ?string
{
    $verbPattern = str_replace(' ', '\\s+', preg_quote($verb, '/'));
    if (!preg_match('/^' . $verbPattern . '\\s+(?:IF\\s+(?:NOT\\s+)?EXISTS\\s+)?(`(?:``|[^`])+`|[A-Za-z0-9_$]+)/i', trim($statement), $matches)) return null;
    return trim($matches[1], '`');
}

function ems_database_sql_validate(PDO $pdo, string $path): array
{
    $created = [];
    $statementCount = 0;
    foreach (ems_database_sql_statements($path) as $statement) {
        $statementCount++;
        if (preg_match('/^(CREATE\\s+TABLE|DROP\\s+TABLE|INSERT\\s+INTO|REPLACE\\s+INTO|ALTER\\s+TABLE|SET\\s+|LOCK\\s+TABLES\\b|UNLOCK\\s+TABLES\\b)/i', $statement, $kind) !== 1) {
            throw new RuntimeException('Dump memuat perintah yang tidak didukung. Gunakan dump tabel SQL MySQL/MariaDB.');
        }
        if (preg_match('/^\\s*(?:CREATE|DROP)\\s+(?:DATABASE|SCHEMA)\\b|\\b(?:OUTFILE|DUMPFILE|LOAD_FILE|LOAD\\s+DATA)\\b/i', $statement)) {
            throw new RuntimeException('Dump berisi perintah di luar pemulihan tabel database aktif.');
        }
        if (preg_match('/^(?:CREATE\\s+TABLE|DROP\\s+TABLE|INSERT\\s+INTO|REPLACE\\s+INTO|ALTER\\s+TABLE)\\b/i', $statement)
            && preg_match('/^(?:CREATE\\s+TABLE|DROP\\s+TABLE|INSERT\\s+INTO|REPLACE\\s+INTO|ALTER\\s+TABLE)\\s+(?:IF\\s+(?:NOT\\s+)?EXISTS\\s+)?(?:`[^`]+`|[A-Za-z0-9_$]+)\\s*\\./i', $statement)) {
            throw new RuntimeException('Dump merujuk database lain; hanya nama tabel tanpa awalan database yang diperbolehkan.');
        }
        if (str_starts_with(strtoupper($kind[1]), 'CREATE TABLE')) {
            $name = ems_database_sql_table_name($statement, 'CREATE TABLE');
            if ($name === null) throw new RuntimeException('Nama tabel pada dump tidak terbaca.');
            $created[strtolower($name)] = $statement;
        }
    }
    if ($statementCount === 0 || $created === []) throw new RuntimeException('Berkas tidak berisi definisi tabel SQL yang dapat diimpor.');

    $current = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    if ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) !== []) {
        throw new RuntimeException('Database saat ini berisi view yang belum didukung alat impor SQL ini.');
    }
    $missing = [];
    foreach ($current as $row) {
        $name = (string) $row[0];
        if (!isset($created[strtolower($name)])) $missing[] = $name;
    }
    if ($missing !== []) {
        throw new RuntimeException('Dump tidak lengkap. Tabel aktif yang tidak ada di dump: ' . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ', …' : ''));
    }
    ems_database_sql_order_create_statements($created);
    return ['tables' => count($created), 'statements' => $statementCount];
}

function ems_database_sql_drop_current_tables(PDO $pdo): void
{
    $views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM);
    foreach ($views as $row) $pdo->exec('DROP VIEW ' . ems_database_sql_quote_identifier((string) $row[0]));
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $row) $pdo->exec('DROP TABLE ' . ems_database_sql_quote_identifier((string) $row[0]));
}

function ems_database_sql_check_relations(PDO $pdo): void
{
    $foreignKeys = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];
    foreach ($foreignKeys as $key) $groups[$key['TABLE_NAME'] . '.' . $key['CONSTRAINT_NAME']][] = $key;
    foreach ($groups as $keys) {
        $child = ems_database_sql_quote_identifier((string) $keys[0]['TABLE_NAME']);
        $parent = ems_database_sql_quote_identifier((string) $keys[0]['REFERENCED_TABLE_NAME']);
        $conditions = [];
        $notNull = [];
        foreach ($keys as $key) {
            $childColumn = 'c.' . ems_database_sql_quote_identifier((string) $key['COLUMN_NAME']);
            $parentColumn = 'p.' . ems_database_sql_quote_identifier((string) $key['REFERENCED_COLUMN_NAME']);
            $conditions[] = $childColumn . ' = ' . $parentColumn;
            $notNull[] = $childColumn . ' IS NOT NULL';
        }
        $sql = 'SELECT 1 FROM ' . $child . ' c LEFT JOIN ' . $parent . ' p ON ' . implode(' AND ', $conditions)
            . ' WHERE ' . implode(' AND ', $notNull) . ' AND p.' . ems_database_sql_quote_identifier((string) $keys[0]['REFERENCED_COLUMN_NAME']) . ' IS NULL LIMIT 1';
        if ($pdo->query($sql)->fetchColumn()) throw new RuntimeException('Data dump memiliki relasi foreign key yang tidak cocok pada tabel ' . $keys[0]['TABLE_NAME'] . '.');
    }
}

function ems_database_sql_apply(PDO $pdo, string $path): array
{
    $summary = ems_database_sql_validate($pdo, $path);
    $createStatements = [];
    foreach (ems_database_sql_statements($path) as $statement) {
        if (preg_match('/^CREATE\\s+TABLE/i', $statement)) {
            $name = ems_database_sql_table_name($statement, 'CREATE TABLE');
            if ($name !== null) $createStatements[strtolower($name)] = $statement;
        }
    }
    foreach (ems_database_sql_order_create_statements($createStatements) as $statement) $pdo->exec($statement);
    foreach (ems_database_sql_statements($path) as $statement) {
        if (preg_match('/^(?:SET\\s+|CREATE\\s+TABLE|DROP\\s+TABLE)/i', $statement)
            || preg_match('/^(?:LOCK\\s+TABLES|UNLOCK\\s+TABLES)\\b/i', $statement)) continue;
        $pdo->exec($statement);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    ems_database_sql_check_relations($pdo);
    return $summary;
}
