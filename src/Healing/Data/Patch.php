<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Healing\Data;

use Alashqar\Lazarus\Llm\Contracts\StructuredResponse;
use Alashqar\Lazarus\Llm\InvalidLlmResponse;
use Alashqar\Lazarus\Llm\Payload;

/**
 * A minimal fix expressed as search/replace edits, never as whole files.
 */
final readonly class Patch implements StructuredResponse
{
    /**
     * @param  list<FileEdit>  $edits
     */
    public function __construct(
        public string $summary,
        public array $edits,
    ) {}

    public static function schema(): string
    {
        return <<<'JSON'
        {
          "summary": "imperative one-line description, e.g. Guard against a zero quantity",
          "edits": [
            {
              "path": "app/Path/To/File.php",
              "search": "exact lines copied from the current file, including indentation",
              "replace": "the lines that replace them"
            }
          ]
        }
        JSON;
    }

    public static function fromLlm(array $data): static
    {
        $edits = [];

        foreach (Payload::objects($data, 'edits') as $index => $edit) {
            $search = Payload::string($edit, 'search');

            $edits[] = new FileEdit(
                ltrim(str_replace('\\', '/', Payload::string($edit, 'path')), '/'),
                $search,
                Payload::string($edit, 'replace', allowEmpty: true),
            );

            if ($search === $edits[$index]->replace) {
                throw new InvalidLlmResponse(sprintf('edit %d does not change anything.', $index));
            }
        }

        return new self(trim(Payload::string($data, 'summary')), $edits);
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return array_values(array_unique(array_map(static fn (FileEdit $edit): string => $edit->path, $this->edits)));
    }
}
