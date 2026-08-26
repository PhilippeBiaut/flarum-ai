<?php

namespace Pbiaut\AiSeeder\Creator;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * Turns a tag path written by the admin ("Voyage > Voyages France") into the
 * tag ids to attach to a discussion, reusing what already exists and creating
 * only what is missing.
 *
 * flarum/tags allows exactly two levels: a primary tag (position set, no
 * parent) and its children (position set, parent set). A child cannot itself
 * have children, so a deeper path is folded down to two.
 *
 * A discussion tagged with a child must carry its parent too, otherwise it
 * counts as having no primary tag: it would not show up when browsing the
 * parent, and Flarum's own tag rules would consider it invalid.
 *
 * Written against the tables rather than flarum/tags' own classes, so this file
 * loads fine on a forum where the extension is absent.
 */
class TagProvisioner
{
    public const MAX_DEPTH = 2;

    /** Colours handed to tags this class creates, picked from the name. */
    private const PALETTE = ['#4b7bec', '#26a69a', '#e67e22', '#8e44ad', '#c0392b', '#16a085', '#2c3e50', '#d35400'];

    /** @var array<string, int> "parentId:lowercased name" => tag id */
    private array $cache = [];

    private ?bool $available = null;

    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function available(): bool
    {
        if ($this->available === null) {
            /** @var \Illuminate\Database\Connection $connection */
            $connection = $this->db;
            $this->available = $connection->getSchemaBuilder()->hasTable('tags');
        }

        return $this->available;
    }

    /**
     * Splits "Voyage > Voyages France" into its segments, at most two.
     *
     * @return array<int, string>
     */
    public static function segments(string $path): array
    {
        $parts = [];

        foreach (preg_split('/\s*>\s*/', trim($path)) ?: [] as $segment) {
            $segment = trim(preg_replace('/\s+/u', ' ', (string) $segment) ?? '');

            if ($segment !== '') {
                $parts[] = mb_substr($segment, 0, 100);
            }
        }

        if (count($parts) <= self::MAX_DEPTH) {
            return $parts;
        }

        // Deeper than Flarum supports: keep the outermost and the innermost,
        // which is the pair that carries the most meaning.
        return [$parts[0], $parts[count($parts) - 1]];
    }

    public static function normalise(string $path): string
    {
        return implode(' > ', self::segments($path));
    }

    /**
     * @return array<int, int>  tag ids to attach, parent before child
     */
    public function resolve(string $path): array
    {
        if (! $this->available()) {
            return [];
        }

        $segments = self::segments($path);

        if ($segments === []) {
            return [];
        }

        $ids = [];
        $parentId = null;

        foreach ($segments as $depth => $name) {
            $id = $this->findOrCreate($name, $parentId, $depth > 0);
            $ids[] = $id;
            $parentId = $id;
        }

        return $ids;
    }

    /**
     * @param  bool  $isChild  children hang off their parent; top-level entries are primary tags
     */
    protected function findOrCreate(string $name, ?int $parentId, bool $isChild): int
    {
        $key = ($parentId ?? 0).':'.mb_strtolower($name);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $existing = $this->find($name, $parentId, $isChild);

        if ($existing !== null) {
            return $this->cache[$key] = $existing;
        }

        return $this->cache[$key] = $this->create($name, $parentId);
    }

    /**
     * Matches on name, case-insensitively, among the right siblings.
     *
     * A top-level segment also matches an existing *secondary* tag of the same
     * name (position null): reusing it is far better than creating a duplicate,
     * even though the seeder would not have created it that way.
     */
    protected function find(string $name, ?int $parentId, bool $isChild): ?int
    {
        $query = $this->db->table('tags')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($isChild) {
            $query->where('parent_id', $parentId);
        } else {
            $query->whereNull('parent_id');
        }

        $id = $query->value('id');

        return $id === null ? null : (int) $id;
    }

    protected function create(string $name, ?int $parentId): int
    {
        $row = [
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'description' => '',
            'color' => self::PALETTE[abs(crc32($name)) % count(self::PALETTE)],
            'parent_id' => $parentId,
            // Both primary tags and children carry a position; only secondary
            // tags leave it null, and the seeder never creates those.
            'position' => $this->nextPosition($parentId),
            'is_restricted' => 0,
            'is_hidden' => 0,
            'discussion_count' => 0,
        ];

        /** @var \Illuminate\Database\Connection $connection */
        $connection = $this->db;
        $schema = $connection->getSchemaBuilder();

        // Timestamps were added to the tags table in 2022; older installs of
        // flarum/tags do not have them.
        foreach (['created_at', 'updated_at'] as $column) {
            if ($schema->hasColumn('tags', $column)) {
                $row[$column] = Carbon::now();
            }
        }

        return (int) $this->db->table('tags')->insertGetId($row);
    }

    protected function nextPosition(?int $parentId): int
    {
        $query = $this->db->table('tags')->whereNotNull('position');

        if ($parentId === null) {
            $query->whereNull('parent_id');
        } else {
            $query->where('parent_id', $parentId);
        }

        return (int) $query->max('position') + 1;
    }

    protected function uniqueSlug(string $name): string
    {
        $slug = mb_strtolower($name);

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);

            if (is_string($ascii)) {
                $slug = $ascii;
            }
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'tag';
        }

        $slug = substr($slug, 0, 90);
        $base = $slug;
        $suffix = 1;

        while ($this->db->table('tags')->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
