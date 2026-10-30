<?php

declare(strict_types=1);

/**
 * Schema migration runner.
 *
 * `init.sql` only runs on an empty data volume, so an existing installation
 * needs the numbered files in `migrations/` applied in order. Those files are
 * written to be idempotent; this runner additionally records what it applied in
 * `schema_migrations` so a re-run is a no-op instead of a full replay.
 *
 *   docker compose run --rm migrate
 *   docker compose exec app php src/migrate.php
 *   php backend/src/migrate.php            # needs DB_* pointing at the database
 *
 * Must run inside a coroutine: the pool creates Channels, which is only allowed
 * there, so the work is wrapped in Coroutine::create().
 */

require __DIR__ . '/rt.php';
require __DIR__ . '/autoload.php';

use App\Lib\Config;
use App\Lib\Db;
use App\Rt\Coroutine;

/**
 * Locate the migrations directory. In the image the code lives in /app/src and
 * the SQL next to it in /app/migrations; in the repository it is one level up
 * from backend/src. MIGRATIONS_DIR overrides both.
 */
function migrationsDir(): string
{
    $override = getenv('MIGRATIONS_DIR');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    $candidates = [
        __DIR__ . '/../migrations',
        __DIR__ . '/../../migrations',
    ];
    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }

    return $candidates[0];
}

/**
 * @return list<string>
 */
function migrationFiles(string $dir): array
{
    if (!is_dir($dir)) {
        fwrite(STDERR, "[migrate] no migrations directory at {$dir}\n");
        return [];
    }

    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);

    return $files;
}

function appliedMigrations(): array
{
    $rows = Db::query('SELECT version FROM schema_migrations ORDER BY version');
    $applied = [];
    foreach ($rows as $row) {
        $applied[(string)$row['version']] = true;
    }

    return $applied;
}

function applyFile(string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('cannot read ' . $path);
    }

    // The files carry their own BEGIN/COMMIT, so they are executed verbatim
    // rather than inside a runner-managed transaction.
    Db::exec($sql);
}

$exitCode = 0;

// Coroutine\run starts (and waits for) the scheduler, so the process does not
// exit while the pool and the query coroutines are still pending.
Coroutine::run(static function () use (&$exitCode): void {
    try {
        Db::ensurePool();

        printf(
            "[migrate] database %s@%s:%s\n",
            Config::get('DB_NAME', 'kanban'),
            Config::get('DB_HOST', 'db'),
            Config::get('DB_PORT', '5432'),
        );

        Db::exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                 version    TEXT PRIMARY KEY,
                 applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
             )',
        );

        $applied = appliedMigrations();
        $files = migrationFiles(migrationsDir());
        if ($files === []) {
            fwrite(STDERR, "[migrate] nothing to do\n");
            return;
        }

        $ran = 0;
        foreach ($files as $file) {
            $version = basename($file);
            if (isset($applied[$version])) {
                echo "[migrate] skip  {$version} (already applied)\n";
                continue;
            }
            echo "[migrate] apply {$version}\n";
            applyFile($file);
            Db::exec('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
            $ran++;
        }

        echo $ran === 0 ? "[migrate] schema is up to date\n" : "[migrate] applied {$ran} migration(s)\n";
    } catch (Throwable $e) {
        fwrite(STDERR, '[migrate] failed: ' . $e->getMessage() . "\n");
        $exitCode = 1;
    }
});

exit($exitCode);
