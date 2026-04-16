<?php

namespace CowClone;

/**
 * Lazily fetches and caches table schemas from the remote connection.
 */
class SchemaCache
{
    private RemoteMySQLConnectionInterface $remote;

    /** @var array<string, array> Cached schemas: tableName => schema */
    private array $cache = [];

    /** @var array<string, string> Override primary key columns per table */
    private array $pkOverrides = [];

    public function __construct(RemoteMySQLConnectionInterface $remote)
    {
        $this->remote = $remote;
    }

    /**
     * Get the schema for a table. Fetches from remote on first access.
     *
     * @param string $table
     * @return array|null Schema with 'columns' and 'primary_key' keys, or null
     */
    public function getSchema(string $table): ?array
    {
        if (isset($this->cache[$table])) {
            return $this->cache[$table];
        }

        $schema = $this->remote->getTableSchema($table);
        if ($schema === null) {
            return null;
        }

        // Apply PK override if set
        if (isset($this->pkOverrides[$table])) {
            $schema['primary_key'] = $this->pkOverrides[$table];
        }

        $this->cache[$table] = $schema;
        return $schema;
    }

    /**
     * Get the primary key column for a table.
     *
     * @param string $table
     * @return string Defaults to 'id' if schema not available
     */
    public function getPrimaryKey(string $table): string
    {
        if (isset($this->pkOverrides[$table])) {
            return $this->pkOverrides[$table];
        }

        $schema = $this->getSchema($table);
        if ($schema && isset($schema['primary_key'])) {
            return $schema['primary_key'];
        }

        return 'id';
    }

    /**
     * Get column names for a table.
     *
     * @param string $table
     * @return array|null
     */
    public function getColumns(string $table): ?array
    {
        $schema = $this->getSchema($table);
        if ($schema && isset($schema['columns'])) {
            return array_keys($schema['columns']);
        }
        return null;
    }

    /**
     * Override the primary key column for a table.
     *
     * @param string $table
     * @param string $pkColumn
     */
    public function setPrimaryKey(string $table, string $pkColumn): void
    {
        $this->pkOverrides[$table] = $pkColumn;
        // Invalidate cached schema so it picks up the override
        unset($this->cache[$table]);
    }

    /**
     * Manually set a schema (useful for local-only tables).
     *
     * @param string $table
     * @param array $schema
     */
    public function setSchema(string $table, array $schema): void
    {
        $this->cache[$table] = $schema;
    }

    /**
     * Check if a table schema is cached.
     */
    public function isCached(string $table): bool
    {
        return isset($this->cache[$table]);
    }

    /**
     * Clear the cache for a table or all tables.
     */
    public function clearCache(?string $table = null): void
    {
        if ($table !== null) {
            unset($this->cache[$table]);
        } else {
            $this->cache = [];
        }
    }
}
