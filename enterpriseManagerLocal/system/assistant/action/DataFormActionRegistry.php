<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Action;

final class DataFormActionRegistry implements \JsonSerializable
{
    /** @var array<string, ActionDefinition> */
    private array $definitions = [];
    /** @var array<string, string> */
    private array $aliases = [];

    public function __construct()
    {
        $this->register(new ActionDefinition('new', 'Neu', 'new', 'Neuen Datensatz anlegen', 'Neuen Datensatz anlegen', false, ['browse', 'show', 'edit'], ['create']));
        $this->register(new ActionDefinition('show', 'Anzeigen', 'show', 'Datensatz anzeigen', 'Datensatz anzeigen', true, ['browse', 'show', 'edit'], ['open', 'view']));
        $this->register(new ActionDefinition('edit', 'Bearbeiten', 'edit', 'Datensatz bearbeiten', 'Datensatz bearbeiten', true, ['browse', 'show', 'edit']));
        $this->register(new ActionDefinition('save', 'Speichern', 'save', 'Datensatz speichern', 'Datensatz speichern', false, ['new', 'edit']));
        $this->register(new ActionDefinition('delete', 'Löschen', 'delete', 'Datensatz löschen', 'Datensatz löschen', true, ['browse', 'show', 'edit']));
        $this->register(new ActionDefinition('first', 'Erster Datensatz', 'first-record', 'Zum ersten Datensatz', 'Zum ersten Datensatz', false, ['browse', 'show', 'edit']));
        $this->register(new ActionDefinition('previous', 'Vorheriger Datensatz', 'previous-record', 'Zum vorherigen Datensatz', 'Zum vorherigen Datensatz', false, ['browse', 'show', 'edit'], ['prev']));
        $this->register(new ActionDefinition('next', 'Nächster Datensatz', 'next-record', 'Zum nächsten Datensatz', 'Zum nächsten Datensatz', false, ['browse', 'show', 'edit']));
        $this->register(new ActionDefinition('last', 'Letzter Datensatz', 'last-record', 'Zum letzten Datensatz', 'Zum letzten Datensatz', false, ['browse', 'show', 'edit']));
    }

    public function register(ActionDefinition $definition): void
    {
        $id = trim($definition->getId());
        if ($id === '') {
            throw new \InvalidArgumentException('Action id must not be empty.');
        }
        if (isset($this->definitions[$id])) {
            throw new \LogicException('DataForm action already registered: ' . $id);
        }
        $this->definitions[$id] = $definition;
        foreach ($definition->getAliases() as $alias) {
            $alias = trim($alias);
            if ($alias === '' || isset($this->definitions[$alias]) || isset($this->aliases[$alias])) {
                throw new \LogicException('Duplicate DataForm action alias: ' . $alias);
            }
            $this->aliases[$alias] = $id;
        }
    }

    public function normalize(string $id): string
    {
        $id = trim($id);
        return $this->aliases[$id] ?? $id;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$this->normalize($id)]);
    }

    public function get(string $id): ActionDefinition
    {
        $normalized = $this->normalize($id);
        if (!isset($this->definitions[$normalized])) {
            throw new \OutOfBoundsException('Unknown DataForm action: ' . $id);
        }
        return $this->definitions[$normalized];
    }

    /** @return array<string, ActionDefinition> */
    public function all(): array { return $this->definitions; }

    /** @return array<string, string> */
    public function aliases(): array { return $this->aliases; }

    public function jsonSerialize(): array
    {
        return [
            'schema' => 'easyit.dataform.action-registry.v1',
            'registry' => 'central',
            'definitions' => $this->definitions,
            'aliases' => $this->aliases,
        ];
    }
}
